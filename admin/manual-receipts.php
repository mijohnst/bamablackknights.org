<?php
/**
 * Standalone receipt-sending tool — separate from the Income Ledger on
 * purpose. The ledger's job is bookkeeping (every dollar in, regardless of
 * source); this page's job is communication (get a receipt to a payer),
 * and it works on ANY income_entries row, not just ones created here —
 * including PayPal-sourced dues/donation rows that never got a receipt_email
 * on file. Every send here still logs to/updates income_entries so the
 * ledger stays the single source of truth for what was actually received.
 */
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/mailer.php';
require_finance();
if (!is_treasurer() && !is_super_admin()) { header('Location: dashboard.php?denied=1'); exit; }
$pdo = get_pdo();

// ── Actions ─────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'send_new') {
        $date    = trim($_POST['entry_date']     ?? '');
        $source  = trim($_POST['source']         ?? '');
        $type    = $_POST['source_type']         ?? 'other';
        $amount  = round((float)str_replace(',','', $_POST['amount'] ?? '0'), 2);
        $method  = trim($_POST['payment_method'] ?? '');
        $desc    = trim($_POST['description']    ?? '');
        $notes   = trim($_POST['notes']          ?? '');
        $email   = trim($_POST['receipt_email']  ?? '');
        if (!in_array($type, array_keys(INCOME_SOURCE_TYPES))) $type = 'other';

        if (!$date || !$source || $amount <= 0 || $amount > 99999.99 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['manual_receipt_old_input'] = $_POST;
            flash('error', 'Date, source, a positive amount, and a valid payer email are all required.');
            header('Location: manual-receipts.php'); exit;
        }

        $pdo->prepare('INSERT INTO income_entries (entry_date,source,source_type,description,amount,payment_method,notes,received_by,receipt_email) VALUES (?,?,?,?,?,?,?,?,?)')
            ->execute([$date, $source, $type, $desc, $amount, $method, $notes, $_SESSION['user_id'] ?? null, $email]);
        $sent = send_manual_payment_receipt($email, $source, $amount, $date, $desc ?: INCOME_SOURCE_TYPES[$type]);
        flash($sent ? 'success' : 'error', $sent
            ? "Payment recorded and receipt emailed to $email."
            : "Payment recorded, but the receipt email failed to send. Find it below and use Send Receipt to try again.");
        header('Location: manual-receipts.php'); exit;

    } elseif ($action === 'send_existing') {
        $id    = (int)($_POST['id'] ?? 0);
        $email = trim($_POST['receipt_email'] ?? '');
        if (!$id || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'Enter a valid email address to send a receipt.');
            header('Location: manual-receipts.php'); exit;
        }
        $s = $pdo->prepare('SELECT * FROM income_entries WHERE id=?');
        $s->execute([$id]);
        $e = $s->fetch(PDO::FETCH_ASSOC);
        if (!$e) {
            flash('error', 'That entry no longer exists.');
            header('Location: manual-receipts.php'); exit;
        }
        // Save the (possibly corrected/first-time) address on the entry so
        // it's pre-filled next time, same as a new entry created here.
        $pdo->prepare('UPDATE income_entries SET receipt_email=? WHERE id=?')->execute([$email, $id]);
        $sent = send_manual_payment_receipt($email, $e['source'], (float)$e['amount'], $e['entry_date'], $e['description'] ?: (INCOME_SOURCE_TYPES[$e['source_type']] ?? 'Payment'));
        flash($sent ? 'success' : 'error', $sent
            ? "Receipt sent to $email."
            : "Receipt failed to send to $email.");
        header('Location: manual-receipts.php'); exit;

    } elseif ($action === 'delete') {
        // Same table/effect as Income Ledger's delete (no cascade to member
        // dues status or PayPal tracking tables) -- just available from here
        // too, since this page is often where a treasurer is already looking
        // at a row (e.g. a sandbox-test entry) when they decide to remove it.
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare('DELETE FROM income_entries WHERE id=?')->execute([$id]);
        flash('success', 'Entry deleted.');
        header('Location: manual-receipts.php'); exit;
    }
}

// ── Data ────────────────────────────────────────────────────────────────────
$year = (int)($_GET['year'] ?? date('Y'));
$ie_years    = $pdo->query("SELECT DISTINCT YEAR(entry_date) FROM income_entries")->fetchAll(PDO::FETCH_COLUMN);
$years_avail = array_values(array_unique(array_merge(array_map('intval', $ie_years), [(int)date('Y')])));
rsort($years_avail);

$stmt = $pdo->prepare("SELECT * FROM income_entries WHERE YEAR(entry_date) = ? ORDER BY entry_date DESC, id DESC");
$stmt->execute([$year]);
$entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

$old_input = null;
if (!empty($_SESSION['manual_receipt_old_input'])) {
    $old_input = $_SESSION['manual_receipt_old_input'];
    unset($_SESSION['manual_receipt_old_input']);
}
function mr_field(?array $old, string $key, string $default = ''): string {
    return (string)($old[$key] ?? $default);
}

admin_header('Manual Receipts');
echo show_flash();
?>
<style>
.mr-table td,.mr-table th{padding:.55rem .9rem}
.mr-table th{font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#5a6a7a;background:#f7f9fc;white-space:nowrap}
.mr-table td{border-top:1px solid #f0f2f5;font-size:.84rem;vertical-align:middle}
.mr-table tr:hover td{background:#fafbfc}
.type-pill{display:inline-block;padding:.15rem .5rem;border-radius:99px;font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em}
.mr-email-input{width:100%;min-width:190px;padding:.4rem .55rem;border:1px solid #d0d5dd;border-radius:4px;font-size:.82rem}
</style>

<div class="page-head">
  <h1>Manual Receipts</h1>
  <div style="display:flex;gap:.5rem;flex-wrap:wrap">
    <a href="income.php" class="btn btn-secondary">← Income Ledger</a>
  </div>
</div>
<p style="font-size:.78rem;color:#9aa5b4;margin-top:-.75rem;margin-bottom:1.25rem">Email a payer a receipt for anything the club received — a brand-new payment, or an existing ledger entry (including online PayPal dues/donations) that never got one.</p>

<script>
// Two-step delete: a plain confirm() is too easy to click through without
// reading, especially for a real financial record. Requires typing DELETE
// as a second, deliberate step before the form actually submits. Same
// pattern as Income Ledger's delete -- this page deletes from the same
// income_entries table.
function confirmDeleteIncomeEntry(label) {
  if (!confirm('Delete this income entry?\n\n' + label + '\n\nThis cannot be undone.')) return false;
  var typed = prompt('Type DELETE (all caps) to confirm.');
  if (typed !== 'DELETE') { alert('Not confirmed — nothing was deleted.'); return false; }
  return true;
}
</script>

<div class="card" style="max-width:920px;margin-bottom:1.75rem">
  <h2 style="margin-bottom:1rem">Record a New Payment &amp; Send a Receipt</h2>
  <form method="POST">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="send_new">
    <div class="form-row col-3">
      <div class="form-group">
        <label>Date <span style="color:#A6192E">*</span></label>
        <input type="date" name="entry_date" required value="<?= h(mr_field($old_input, 'entry_date', date('Y-m-d'))) ?>">
      </div>
      <div class="form-group">
        <label>Type <span style="color:#A6192E">*</span></label>
        <select name="source_type">
          <?php $cur_type = mr_field($old_input, 'source_type', 'other'); ?>
          <?php foreach (INCOME_SOURCE_TYPES as $k => $v): ?>
          <option value="<?= $k ?>" <?= $cur_type===$k?'selected':'' ?>><?= $v ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label>Amount <span style="color:#A6192E">*</span></label>
        <input name="amount" type="number" step="0.01" min="0.01" required placeholder="0.00" value="<?= h(mr_field($old_input, 'amount')) ?>">
      </div>
    </div>
    <div class="form-row col-2">
      <div class="form-group">
        <label>Source / Payer Name <span style="color:#A6192E">*</span></label>
        <input name="source" required placeholder="e.g. John Smith, Alabama Power Co." value="<?= h(mr_field($old_input, 'source')) ?>">
      </div>
      <div class="form-group">
        <label>Payment Method</label>
        <select name="payment_method">
          <option value="">—</option>
          <?php $cur_method = mr_field($old_input, 'payment_method'); ?>
          <?php foreach (INCOME_PAYMENT_METHODS as $m): ?>
          <option value="<?= $m ?>" <?= $cur_method===$m?'selected':'' ?>><?= $m ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="form-row col-2">
      <div class="form-group">
        <label>Description</label>
        <input name="description" placeholder="Brief description" value="<?= h(mr_field($old_input, 'description')) ?>">
      </div>
      <div class="form-group">
        <label>Notes <span style="font-weight:400;font-size:.72rem;color:#9aa5b4">optional</span></label>
        <input name="notes" placeholder="Additional notes" value="<?= h(mr_field($old_input, 'notes')) ?>">
      </div>
    </div>
    <div class="form-group" style="background:#f7f9fc;border-radius:6px;padding:.9rem 1rem;margin-bottom:1rem">
      <label>Payer Email <span style="color:#A6192E">*</span></label>
      <input type="email" name="receipt_email" required placeholder="donor@example.com" value="<?= h(mr_field($old_input, 'receipt_email')) ?>">
    </div>
    <button type="submit" class="btn btn-primary">Record &amp; Send Receipt</button>
  </form>
</div>

<div class="page-head" style="margin-bottom:1rem">
  <h2 style="font-size:1.1rem">Send a Receipt for an Existing Entry</h2>
  <form method="GET" style="margin:0">
    <select name="year" onchange="this.form.submit()">
      <?php foreach ($years_avail as $y): ?>
      <option value="<?= $y ?>" <?= $y==$year?'selected':'' ?>><?= $y ?></option>
      <?php endforeach; ?>
    </select>
  </form>
</div>

<?php if (empty($entries)): ?>
  <p style="color:#9aa5b4">No income recorded for <?= $year ?>.</p>
<?php else: ?>
<div class="card" style="padding:0;overflow-x:auto">
<table class="mr-table" style="width:100%;border-collapse:collapse">
  <thead>
    <tr>
      <th>Date</th>
      <th>Source</th>
      <th>Type</th>
      <th style="text-align:right">Amount</th>
      <th>Payer Email</th>
      <th></th>
      <th></th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($entries as $e): $tc = INCOME_TYPE_COLORS[$e['source_type']] ?? '#5a6a7a'; ?>
  <tr>
    <td style="white-space:nowrap"><?= date('M j, Y', strtotime($e['entry_date'])) ?></td>
    <td style="font-weight:600"><?= h($e['source']) ?></td>
    <td><span class="type-pill" style="background:<?= $tc ?>22;color:<?= $tc ?>"><?= INCOME_SOURCE_TYPES[$e['source_type']] ?? h($e['source_type']) ?></span></td>
    <td style="text-align:right;font-weight:700;color:#1b5e20;white-space:nowrap">$<?= number_format($e['amount'],2) ?></td>
    <td>
      <form method="POST" style="display:flex;gap:.5rem;align-items:center" onsubmit="return confirm('Send a receipt to this address?')">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="send_existing">
        <input type="hidden" name="id" value="<?= $e['id'] ?>">
        <input type="email" name="receipt_email" class="mr-email-input" placeholder="donor@example.com" value="<?= h($e['receipt_email'] ?? '') ?>" required>
        <button type="submit" class="btn btn-secondary btn-sm" style="white-space:nowrap"><?= !empty($e['receipt_email']) ? 'Resend' : 'Send' ?> Receipt</button>
      </form>
    </td>
    <td>
      <form method="POST" onsubmit="return confirmDeleteIncomeEntry(<?= h(json_encode($e['source'] . ' — $' . number_format($e['amount'],2) . ' (' . date('M j, Y', strtotime($e['entry_date'])) . ')')) ?>)" style="margin:0">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="id" value="<?= $e['id'] ?>">
        <button type="submit" class="btn btn-danger btn-sm">Delete</button>
      </form>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>

<?php admin_footer(); ?>
