<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/mailer.php';
require_finance();
$old_event = ''; // track event before edit for threshold check
$pdo = get_pdo();

$id        = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$p         = [];
$errors    = [];
$is_edit   = false;
$read_only = false;

if ($id) {
    $stmt = $pdo->prepare('SELECT p.*, u.name as submitted_by_name FROM purchases p LEFT JOIN users u ON p.submitted_by=u.id WHERE p.id=?');
    $stmt->execute([$id]);
    $p = $stmt->fetch();
    if (!$p) { flash('error','Purchase not found.'); header('Location: purchases.php'); exit; }
    if (!can_view_purchase($p)) { flash('error','You do not have permission to view that purchase.'); header('Location: purchases.php'); exit; }
    $old_event   = $p['event'] ?? '';
    $is_edit     = true;
    $read_only   = !can_edit_purchase($p);
}

// Upload helper
function handle_receipt_upload(string $key = 'receipt'): ?string {
    if (empty($_FILES[$key]['name'])) return null;
    $file = $_FILES[$key];
    if ($file['error'] !== UPLOAD_ERR_OK) return null;
    // Includes HEIC/HEIF since that's the default photo format on iPhones —
    // a receipt photo taken via the camera capture button would otherwise
    // get silently rejected on any iPhone that hasn't changed that setting.
    $allowed = ['image/jpeg','image/png','image/gif','image/webp','image/heic','image/heif','application/pdf'];
    $finfo   = finfo_open(FILEINFO_MIME_TYPE);
    $mime    = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    if (!in_array($mime, $allowed)) return null;
    if ($file['size'] > 10 * 1024 * 1024) return null; // 10MB max
    $ext_map  = ['application/pdf'=>'pdf','image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp','image/heic'=>'heic','image/heif'=>'heif'];
    $ext      = $ext_map[$mime] ?? 'jpg';
    $filename = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $dir      = __DIR__ . '/receipts/';
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) return null;
    if (!move_uploaded_file($file['tmp_name'], $dir . $filename)) return null;
    return $filename;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($is_edit && ($read_only ?? false)) {
        flash('error','You can only edit your own purchases.');
        header('Location: purchases.php'); exit;
    }
    csrf_verify();

    $vendor        = trim($_POST['vendor']        ?? '');
    $order_number  = trim($_POST['order_number']  ?? '');
    $description   = trim($_POST['description']   ?? '');
    $event         = trim($_POST['event']         ?? '');
    $category      = trim($_POST['category']      ?? '');
    $date          = trim($_POST['purchase_date'] ?? '');
    $pretax        = (float)str_replace(',','', $_POST['amount_pretax']    ?? '0');
    $tax           = (float)str_replace(',','', $_POST['amount_tax']       ?? '0');
    $shipping      = (float)str_replace(',','', $_POST['amount_shipping']  ?? '0');
    $total         = round($pretax + $tax + $shipping, 2);
    // ?: not ?? — an empty string (e.g. a disabled placeholder option
    // submitting with no value) must also fall back to 'pending', not
    // persist as a permanently blank/unrecognized status.
    $status           = $_POST['status']                    ?: 'pending';
    $notes            = trim($_POST['notes']               ?? '');
    $payment_method   = trim($_POST['payment_method']      ?? '');
    if ($payment_method === '') $errors[] = 'Payment Method is required.';
    // Venmo/PayPal fold their extra field into payment_method itself, same
    // format the "Mark as Reimbursed" modal already writes (e.g. "Venmo
    // @jsmith") — keeps every display of this field (receipts-by.php,
    // year-end.php, pending-reimbursements.php) working without changes.
    if ($payment_method === 'Venmo') {
        $venmo_id = trim($_POST['venmo_id'] ?? '');
        if ($venmo_id === '') $errors[] = 'Venmo ID is required.';
        else {
            if ($venmo_id[0] !== '@') $venmo_id = '@' . $venmo_id;
            $payment_method = 'Venmo ' . $venmo_id;
        }
    } elseif ($payment_method === 'PayPal') {
        $paypal_email = trim($_POST['paypal_email'] ?? '');
        if (!filter_var($paypal_email, FILTER_VALIDATE_EMAIL)) $errors[] = 'A valid PayPal email is required.';
        else $payment_method = 'PayPal ' . $paypal_email;
    }
    // Only admins/treasurers may re-attribute; everyone else is locked to their own ID
    $submitted_by = (is_admin() || is_treasurer())
        ? (int)($_POST['submitted_by'] ?? $_SESSION['user_id'] ?? 0)
        : (int)($_SESSION['user_id'] ?? 0);
    $confirmed_dup    = !empty($_POST['confirmed_duplicate']);

    // Duplicate detection (same vendor, amount within 10%, within 30 days)
    $dup_warning = null;
    if (!$confirmed_dup && $vendor && $pretax > 0) {
        $dup_stmt = $pdo->prepare(
            "SELECT id, vendor, purchase_date, amount_pretax FROM purchases
             WHERE vendor = ? AND ABS(amount_pretax - ?) / ? <= 0.10
             AND purchase_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
             AND id != ?
             LIMIT 1"
        );
        $dup_stmt->execute([$vendor, $pretax, $pretax, $id ?: 0]);
        $dup = $dup_stmt->fetch();
        if ($dup) {
            $dup_warning = 'A similar purchase already exists: '
                . h($dup['vendor']) . ' on ' . date('M j, Y', strtotime($dup['purchase_date']))
                . ' for $' . number_format($dup['amount_pretax'], 2) . '.';
        }
    }

    if (!$vendor)      $errors[] = 'Vendor is required.';
    if (!$description) $errors[] = 'Description is required.';
    if (!$date)        $errors[] = 'Date is required.';
    if ($pretax < 0)   $errors[] = 'Pre-tax amount cannot be negative.';
    if (!in_array($status, array_keys(PURCHASE_STATUSES))) $status = 'pending';
    // Status may only be changed by Treasurer/Admin — block server-side
    // regardless of what the form rendered, in case of a tampered POST.
    // (Normal transitions should go through the Approve/Submit Payment/Mark
    // Paid buttons on purchase-action.php, which also enforce the
    // receipt-required check, the President/VP cross-approval rule, and —
    // for Paid — the paid_at/paid_note/notify_paid() bookkeeping.)
    $prior_status = $is_edit ? ($p['status'] ?? 'pending') : 'pending';
    if (!is_treasurer() && !is_admin()) {
        $status = $prior_status;
    } elseif (!is_admin() && isset(STATUS_ORDER[$prior_status], STATUS_ORDER[$status])
              && STATUS_ORDER[$status] > STATUS_ORDER[$prior_status]) {
        // Treasurer (non-admin) may only use this field to correct a status
        // backward/sideways, never to advance the workflow — that would skip
        // the checks above. Skipped when the prior status is unrecognized
        // (corrupted data), so it can still be corrected to any valid value.
        // Admin keeps full override freedom, consistent with elsewhere in this app.
        $status = $prior_status;
    }

    // Accept from either camera or file picker input
    $new_receipt = null;
    $upload_key  = !empty($_FILES['receipt']['name']) ? 'receipt' : (!empty($_FILES['receipt_file']['name']) ? 'receipt_file' : null);
    if ($upload_key) {
        $new_receipt = handle_receipt_upload($upload_key);
        if (!$new_receipt) $errors[] = 'Receipt upload failed. Use a photo (JPG, PNG, HEIC, WEBP, GIF) or PDF under 10MB.';
    }
    // A file input can't be repopulated after a page reload — if this request
    // got blocked by something else (e.g. the duplicate-purchase warning
    // below) after a receipt was already successfully uploaded, carry that
    // filename forward via a hidden field instead of making the user
    // re-select the same file just to get past an unrelated warning.
    if (!$new_receipt) {
        $carried = trim($_POST['pending_receipt_filename'] ?? '');
        if ($carried !== '' && preg_match('/^[a-zA-Z0-9._-]+$/', $carried) && is_file(__DIR__ . '/receipts/' . $carried)) {
            $new_receipt = $carried;
        }
    }
    // Required on a new purchase, or an edit that's still Pending (i.e. it
    // never had one attached before its first approval). Once a purchase has
    // moved past Pending, the receipt-required gate already ran at approval
    // time — re-demanding one on every later edit would lock legacy
    // purchases (predating this rule) out of even a one-word correction.
    if (!$new_receipt && empty($p['receipt_filename'] ?? null) && (!$is_edit || $prior_status === 'pending')) {
        $errors[] = 'A receipt is required.';
    }

    // Block save if duplicate warning and not confirmed
    if ($dup_warning && !$confirmed_dup) {
        $errors[] = '__dup__'; // handled specially in template
    }

    if (empty($errors)) {
        $receipt_filename = $new_receipt ?? ($p['receipt_filename'] ?? null);

        // Delete old receipt if replaced
        if ($new_receipt && $is_edit && !empty($p['receipt_filename'])) {
            @unlink(__DIR__ . '/receipts/' . $p['receipt_filename']);
        }

        if ($is_edit) {
            // Capture old status before update for change detection
            $old = $pdo->prepare('SELECT status FROM purchases WHERE id=?');
            $old->execute([$id]);
            $old_status = $old->fetchColumn();

            // If this raw status edit is what's setting status to Paid (rather
            // than the dedicated Mark Paid button, which sets this itself),
            // still fill in paid_at so it doesn't stay permanently NULL —
            // COALESCE leaves it alone if it was already set for real.
            $paid_at_if_new = ($status === 'paid' && $old_status !== 'paid') ? date('Y-m-d H:i:s') : null;
            $pdo->prepare('UPDATE purchases SET vendor=?,order_number=?,description=?,event=?,category=?,purchase_date=?,amount_pretax=?,amount_tax=?,amount_shipping=?,amount_total=?,receipt_filename=?,submitted_by=?,status=?,notes=?,payment_method=?,paid_at=COALESCE(paid_at,?),updated_at=NOW() WHERE id=?')
                ->execute([$vendor,$order_number,$description,$event,$category,$date,$pretax,$tax,$shipping,$total,$receipt_filename,$submitted_by,$status,$notes,$payment_method,$paid_at_if_new,$id]);
            flash('success','Purchase updated.');

            // Check budget thresholds (check both old and new event if event changed)
            check_budget_thresholds($pdo, $event);
            if ($old_event && $old_event !== $event) check_budget_thresholds($pdo, $old_event);

            // Notify submitter if status changed
            if ($old_status !== $status) {
                $updated = $pdo->prepare('SELECT * FROM purchases WHERE id=?');
                $updated->execute([$id]);
                notify_status_change($pdo, $updated->fetch(), $old_status, $status, current_user_name());
            }
        } else {
            $pdo->prepare('INSERT INTO purchases (vendor,order_number,description,event,category,purchase_date,amount_pretax,amount_tax,amount_shipping,amount_total,receipt_filename,submitted_by,status,notes,payment_method) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$vendor,$order_number,$description,$event,$category,$date,$pretax,$tax,$shipping,$total,$receipt_filename,$submitted_by ?: null,$status,$notes,$payment_method]);
            $new_id = (int)$pdo->lastInsertId();
            flash('success','Purchase added.');

            // Check budget threshold for this event
            check_budget_thresholds($pdo, $event);

            // Notify treasurers/admins of new submission
            $new_p = $pdo->prepare('SELECT * FROM purchases WHERE id=?');
            $new_p->execute([$new_id]);
            notify_new_purchase($pdo, $new_p->fetch(), current_user_name());
        }
        header('Location: purchases.php'); exit;
    }

    // Re-populate from POST on error
    $p = array_merge($p, compact('vendor','description','event','category','date','pretax','tax','total','status','notes','submitted_by','payment_method'));
    $p['purchase_date']  = $date;
    // Surfaced in the template as a hidden field so a receipt uploaded this
    // attempt (but blocked from saving by some other error) survives the
    // next resubmit — see the carry-forward check above.
    $carried_receipt = $new_receipt;
    $p['amount_pretax']  = $pretax;
    $p['amount_tax']     = $tax;
    $p['amount_total']   = $total;
}

$v = fn(string $k) => h((string)($p[$k] ?? ''));

// Load users for submitted_by dropdown
$users_list = $pdo->query('SELECT id,name FROM users WHERE active=1 ORDER BY name')->fetchAll();

$read_only = $read_only ?? false;
$title = $read_only ? 'View Purchase' : ($is_edit ? 'Edit Purchase' : 'Add Purchase');
admin_header($title);
?>
<style>
.receipt-preview{margin-top:.5rem;max-width:100%;border-radius:4px;border:1px solid #e1e5eb}
.amount-row{display:grid;grid-template-columns:1fr 1fr 1fr;gap:.9rem}
@media(max-width:500px){.amount-row{grid-template-columns:1fr}}
.total-display{background:#f0f4ff;border:2px solid #003594;border-radius:4px;padding:.6rem .9rem;font-size:1.2rem;font-weight:700;color:#002554;text-align:center}
</style>

<div class="page-head">
  <h1><?= $title ?></h1>
  <a href="purchases.php" class="btn btn-secondary">← Back</a>
</div>
<?= show_flash() ?>

<?php
$real_errors = array_filter($errors, fn($e) => $e !== '__dup__');
if (!empty($real_errors)): ?>
  <div class="alert alert-error" style="max-width:700px"><?= implode('<br>', array_map('htmlspecialchars', $real_errors)) ?></div>
<?php endif; ?>
<?php if (!empty($dup_warning)): ?>
  <div style="max-width:700px;background:#fff8e1;border:1px solid #ffc107;border-radius:6px;padding:1rem 1.25rem;margin-bottom:1rem">
    <strong style="color:#5f4c00">⚠️ Possible Duplicate</strong>
    <p style="color:#5f4c00;margin:.4rem 0 .75rem;font-size:.9rem"><?= h($dup_warning) ?></p>
    <label style="display:flex;align-items:center;gap:.5rem;font-size:.9rem;cursor:pointer;font-weight:400;text-transform:none;letter-spacing:0;color:#333">
      <input type="checkbox" form="purchase-form" name="confirmed_duplicate" value="1" style="width:auto">
      This is not a duplicate — save anyway
    </label>
  </div>
<?php endif; ?>

<?php if ($read_only): ?>
<div class="alert alert-error" style="max-width:700px;background:#fff8e1;border-left-color:#ffc107;color:#5f4c00">
  👁 You are viewing this purchase in read-only mode.
</div>
<?php endif; ?>
<div class="card" style="max-width:700px">
  <form method="POST" enctype="multipart/form-data" id="purchase-form"><?php if ($read_only) echo '<fieldset disabled style="border:none;padding:0;margin:0">'; ?>
    <?= csrf_field() ?>
    <?php if ($is_edit): ?><input type="hidden" name="id" value="<?= $id ?>"><?php endif; ?>

    <fieldset><legend>Purchase Details</legend>
      <div class="form-row col-2">
        <div class="form-group">
          <label>Vendor *</label>
          <input name="vendor" value="<?= $v('vendor') ?>" required placeholder="e.g. Walmart, Amazon">
        </div>
        <div class="form-group">
          <label>Date *</label>
          <input type="date" name="purchase_date" value="<?= $v('purchase_date') ?>" required>
        </div>
      </div>
      <div class="form-row col-2">
        <div class="form-group">
          <label>Order Number <span style="font-weight:400;text-transform:none;letter-spacing:0;font-size:.72rem;color:#9aa5b4">optional</span></label>
          <input name="order_number" value="<?= $v('order_number') ?>" placeholder="e.g. 123-4567890-1234567">
        </div>
      </div>
      <div class="form-group">
        <label>Description *</label>
        <input name="description" value="<?= $v('description') ?>" required placeholder="What was purchased">
      </div>
      <div class="form-row col-2">
        <div class="form-group">
          <label>Event</label>
          <select name="event">
            <?php foreach (PURCHASE_EVENTS as $e): ?>
              <option value="<?= h($e) ?>" <?= ($p['event']??'')===$e?'selected':''?>><?= $e===''?'— select event —':h($e) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label>Category</label>
          <select name="category">
            <?php foreach (PURCHASE_CATEGORIES as $c): ?>
              <option value="<?= h($c) ?>" <?= ($p['category']??'')===$c?'selected':''?>><?= $c===''?'— select category —':h($c) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
    </fieldset>

    <fieldset><legend>Amounts</legend>
      <div class="amount-row">
        <div class="form-group">
          <label>Pre-Tax Amount *</label>
          <input type="number" name="amount_pretax" id="pretax" value="<?= $v('amount_pretax') ?>"
                 step="0.01" min="0" required placeholder="0.00" oninput="calcTotal()">
        </div>
        <div class="form-group">
          <label>Tax <span style="font-weight:400;text-transform:none;letter-spacing:0;font-size:.72rem;color:#9aa5b4">optional</span></label>
          <input type="number" name="amount_tax" id="tax_amt" value="<?= $v('amount_tax') ?>"
                 step="0.01" min="0" placeholder="0.00" oninput="calcTotal()">
        </div>
        <div class="form-group">
          <label>Shipping <span style="font-weight:400;text-transform:none;letter-spacing:0;font-size:.72rem;color:#9aa5b4">optional</span></label>
          <input type="number" name="amount_shipping" id="shipping_amt" value="<?= $v('amount_shipping') ?>"
                 step="0.01" min="0" placeholder="0.00" oninput="calcTotal()">
        </div>
      </div>
      <div class="form-group" style="margin-top:.25rem">
        <label>Total</label>
        <div class="total-display" id="total-display">
          $<?= number_format((float)($p['amount_total'] ?? 0), 2) ?>
        </div>
      </div>
    </fieldset>

    <fieldset><legend>Receipt &amp; Status</legend>
      <?php $cur_status = $p['status'] ?? 'pending'; ?>
      <?php if (!isset(STATUS_ORDER[$cur_status])): ?>
        <p style="font-size:.82rem;color:#c62828;margin-bottom:1rem">⚠️ Unrecognized status on this record: <strong><?= $cur_status !== '' ? h($cur_status) : '(empty)' ?></strong> — use the Status field below to correct it.</p>
      <?php else: ?>
      <div style="display:flex;align-items:center;gap:.5rem;margin-bottom:1rem;flex-wrap:wrap">
        <?php foreach (PURCHASE_STATUSES as $k => $label):
          $step = STATUS_ORDER[$k];
          $cur  = STATUS_ORDER[$cur_status];
          $done = $step < $cur; $active = $step === $cur;
          $col  = $done||$active ? '#003594' : '#d0d5dd';
          $bg   = $active ? '#003594' : ($done ? '#e8f0fe' : '#f5f7fa');
          $tc   = $active ? '#fff' : ($done ? '#003594' : '#9aa5b4');
        ?>
        <div style="display:flex;align-items:center;gap:.4rem">
          <?php if ($step > 0): ?><span style="color:<?= $col ?>;font-size:1rem">→</span><?php endif; ?>
          <span style="background:<?= $bg ?>;color:<?= $tc ?>;border:2px solid <?= $col ?>;border-radius:99px;padding:.25rem .85rem;font-size:.78rem;font-weight:700;white-space:nowrap">
            <?php if ($done): ?>✓ <?php endif; ?><?= h($label) ?>
          </span>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
      <div class="form-row col-2">
        <div class="form-group">
          <label>Status</label>
          <?php
          // Status only changes through the Approve/Submit Payment/Mark Paid
          // workflow buttons (purchase-action.php) — those enforce the
          // receipt-required check, the President/VP cross-approval rule, and
          // (for Paid) the paid_at/paid_note/notify_paid() bookkeeping. A raw
          // dropdown here would let anyone who can edit the purchase bypass
          // all of that, so only Treasurer/Admin get an editable one, and
          // even then only to correct a status backward/sideways — not to
          // advance the workflow. Admin keeps full override freedom.
          $can_edit_status = is_treasurer() || is_admin();
          if ($can_edit_status):
              $allowed_statuses = PURCHASE_STATUSES;
              if (!is_admin()) {
                  unset($allowed_statuses['approved']); // only admins can jump straight to approved
                  if (isset(STATUS_ORDER[$cur_status])) {
                      $allowed_statuses = array_filter($allowed_statuses, fn($k) => STATUS_ORDER[$k] <= STATUS_ORDER[$cur_status], ARRAY_FILTER_USE_KEY);
                  }
              }
          ?>
          <select name="status">
            <?php if (!isset(STATUS_ORDER[$cur_status])): ?>
              <option value="" selected disabled>— select to correct —</option>
            <?php endif; ?>
            <?php foreach ($allowed_statuses as $k => $v2): ?>
              <option value="<?= h($k) ?>" <?= $cur_status===$k?'selected':''?>><?= h($v2) ?></option>
            <?php endforeach; ?>
          </select>
          <?php else: ?>
            <input type="hidden" name="status" value="<?= h($cur_status) ?>">
            <p style="font-size:.75rem;color:#9aa5b4;margin-top:.25rem">Status changes are handled by the admin workflow.</p>
          <?php endif; ?>
        </div>
        <div class="form-group">
          <label>Submitted By</label>
          <select name="submitted_by">
            <?php foreach ($users_list as $u): ?>
              <option value="<?= $u['id'] ?>" <?= (int)($p['submitted_by']??$_SESSION['user_id']??0)===(int)$u['id']?'selected':''?>><?= h($u['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="form-group">
        <label>Receipt *</label>
        <!-- Hidden inputs: camera (photo only) and file picker (photo or PDF) -->
        <input type="file" id="receipt-camera" name="receipt" accept="image/*" capture="environment" style="display:none" onchange="previewReceipt(this)">
        <input type="file" id="receipt-file"   name="receipt_file" accept="image/*,application/pdf" style="display:none" onchange="previewReceipt(this)">
        <div style="display:flex;gap:.6rem;flex-wrap:wrap;margin-top:.25rem">
          <button type="button" onclick="document.getElementById('receipt-camera').click()"
            style="flex:1;min-width:140px;padding:.75rem;background:#003594;color:#fff;border:none;border-radius:6px;font-size:1rem;font-weight:600;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:.5rem">
            📷 Take Photo
          </button>
          <button type="button" onclick="document.getElementById('receipt-file').click()"
            style="flex:1;min-width:140px;padding:.75rem;background:#f0f2f5;color:#333;border:1px solid #d0d5dd;border-radius:6px;font-size:1rem;font-weight:600;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:.5rem">
            📁 Upload File
          </button>
        </div>
        <div id="receipt-preview-wrap" style="margin-top:.75rem;display:none">
          <img id="receipt-img-preview" src="" alt="Receipt preview" style="max-width:100%;max-height:240px;border-radius:4px;border:1px solid #e1e5eb;display:none">
          <div id="receipt-file-name" style="font-size:.82rem;color:#1b5e20;padding:.5rem;background:#e8f5e9;border-radius:4px;display:none">✓ File selected: <span></span></div>
        </div>
        <?php if ($is_edit && !empty($p['receipt_filename'])): ?>
          <div style="margin-top:.5rem;font-size:.82rem">
            Current receipt: <a href="receipt-view.php?id=<?= $id ?>" target="_blank" style="color:#003594">View</a>
            <span style="color:#9aa5b4"> — use buttons above to replace it</span>
          </div>
        <?php endif; ?>
        <?php if (!empty($carried_receipt)): ?>
          <input type="hidden" name="pending_receipt_filename" value="<?= h($carried_receipt) ?>">
          <div style="margin-top:.5rem;font-size:.82rem;color:#1b5e20">✓ Receipt already uploaded from your last attempt — no need to re-select it.</div>
        <?php endif; ?>
      </div>
      <div class="form-group">
        <label>Notes</label>
        <textarea name="notes" rows="3" placeholder="Any additional details…"><?= $v('notes') ?></textarea>
      </div>
      <?php
        // A composed value like "Venmo @handle" or "PayPal name@x.com" (the
        // same format the "Mark as Reimbursed" modal writes) doesn't match
        // any literal PAYMENT_METHODS option — split it back apart so the
        // dropdown and the Venmo ID / PayPal Email fields below pre-fill
        // correctly when editing a purchase that already has one on file.
        $stored_pm  = (string)($p['payment_method'] ?? '');
        $pm_selected = in_array($stored_pm, PAYMENT_METHODS, true) ? $stored_pm : '';
        $venmo_prefill = $paypal_prefill = '';
        if ($pm_selected === '') {
            if (str_starts_with($stored_pm, 'Venmo ')) { $pm_selected = 'Venmo'; $venmo_prefill = trim(substr($stored_pm, 6)); }
            elseif (str_starts_with($stored_pm, 'PayPal ')) { $pm_selected = 'PayPal'; $paypal_prefill = trim(substr($stored_pm, 7)); }
            elseif (str_starts_with($stored_pm, 'Check')) { $pm_selected = 'Check'; }
            // A stored "Cash App $handle" value from before that method was
            // removed falls through here unrecognized — the dropdown just
            // shows unselected, same graceful degradation as any other
            // retired payment method (e.g. the old "Internet Transfer").
        }
      ?>
      <div class="form-group">
        <label>Payment Method *</label>
        <?php
          // PayPal listed first (and called out as preferred) since it's the
          // fastest for the treasurer to pay out; PAYMENT_METHODS itself stays
          // in its original order for the other selects that reuse it.
          $pm_display_order = array_merge(['', 'PayPal'], array_diff(PAYMENT_METHODS, ['', 'PayPal']));
        ?>
        <select name="payment_method" id="pm_select" onchange="updatePmFields()" required>
          <?php foreach ($pm_display_order as $pm): ?>
            <option value="<?= h($pm) ?>" <?= $pm_selected===$pm?'selected':''?>>
              <?php if ($pm === ''): ?>— select —
              <?php elseif ($pm === 'PayPal'): ?>PayPal (Preferred method)
              <?php else: ?><?= h($pm) ?>
              <?php endif; ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group" id="pm_venmo_group" style="display:none">
        <label>Venmo ID *</label>
        <input type="text" name="venmo_id" id="pm_venmo_id" placeholder="@username" value="<?= h($venmo_prefill) ?>">
      </div>
      <div class="form-group" id="pm_paypal_group" style="display:none">
        <label>PayPal Email *</label>
        <input type="email" name="paypal_email" id="pm_paypal_email" placeholder="name@example.com" value="<?= h($paypal_prefill) ?>">
      </div>
      <script>
        function updatePmFields() {
          var m = document.getElementById('pm_select').value;
          document.getElementById('pm_venmo_group').style.display  = m === 'Venmo'  ? '' : 'none';
          document.getElementById('pm_paypal_group').style.display = m === 'PayPal' ? '' : 'none';
          document.getElementById('pm_venmo_id').required     = m === 'Venmo';
          document.getElementById('pm_paypal_email').required = m === 'PayPal';
        }
        updatePmFields();
      </script>
    </fieldset>

    <?php if ($is_edit && (!empty($p['approved_note']) || !empty($p['reimbursed_note']) || !empty($p['paid_note']))): ?>
    <fieldset><legend>Workflow Notes</legend>
      <?php if (!empty($p['approved_note'])): ?>
      <div style="background:#e8f5e9;border-left:3px solid #4caf50;padding:.6rem .9rem;border-radius:4px;margin-bottom:.5rem;font-size:.85rem">
        <strong style="color:#1b5e20">Approval note:</strong> <?= h($p['approved_note']) ?>
      </div>
      <?php endif; ?>
      <?php if (!empty($p['reimbursed_note'])): ?>
      <div style="background:#f3e5f5;border-left:3px solid #6a1b9a;padding:.6rem .9rem;border-radius:4px;margin-bottom:.5rem;font-size:.85rem">
        <strong style="color:#6a1b9a">Submitted note:</strong> <?= h($p['reimbursed_note']) ?>
      </div>
      <?php endif; ?>
      <?php if (!empty($p['paid_note'])): ?>
      <div style="background:#e3f2fd;border-left:3px solid #2196f3;padding:.6rem .9rem;border-radius:4px;font-size:.85rem">
        <strong style="color:#003594">Paid note:</strong> <?= h($p['paid_note']) ?>
      </div>
      <?php endif; ?>
    </fieldset>
    <?php endif; ?>

    <?php if ($read_only): ?>
    <?php else: ?>
    <div style="display:flex;gap:.75rem;flex-wrap:wrap">
      <button type="submit" class="btn btn-primary"><?= $is_edit ? 'Save Changes' : 'Add Purchase' ?></button>
      <a href="purchases.php" class="btn btn-secondary">Cancel</a>
    </div>
    <?php endif; ?>
    <?php if ($read_only) echo '</fieldset>'; ?>
  </form>
  <?php if ($read_only): ?>
  <div style="margin-top:1rem">
    <a href="purchases.php" class="btn btn-secondary">← Back to Finance</a>
  </div>
  <?php endif; ?>
</div>

<script>
function calcTotal() {
  var pre  = parseFloat(document.getElementById('pretax').value)       || 0;
  var tax  = parseFloat(document.getElementById('tax_amt').value)      || 0;
  var ship = parseFloat(document.getElementById('shipping_amt').value) || 0;
  document.getElementById('total-display').textContent = '$' + (pre + tax + ship).toFixed(2);
}
function previewReceipt(input) {
  var wrap = document.getElementById('receipt-preview-wrap');
  var img  = document.getElementById('receipt-img-preview');
  var fn   = document.getElementById('receipt-file-name');
  wrap.style.display = 'block';
  if (!input.files || !input.files[0]) return;
  var file = input.files[0];
  fn.querySelector('span').textContent = file.name;
  // HEIC/HEIF preview only renders in Safari — everywhere else an inline
  // <img> just shows broken, so fall back to the filename display instead.
  if (file.type.startsWith('image/') && file.type !== 'image/heic' && file.type !== 'image/heif') {
    var reader = new FileReader();
    reader.onload = function(e) { img.src = e.target.result; img.style.display='block'; fn.style.display='none'; };
    reader.readAsDataURL(file);
  } else {
    img.style.display = 'none';
    fn.style.display  = 'block';
  }
}
</script>

<?php admin_footer(); ?>
