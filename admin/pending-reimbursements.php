<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/mailer.php';
require_finance();
$pdo = get_pdo();

// A plain member only ever sees their own pending payments; leadership sees
// everyone's. Covers both steps still awaiting Treasurer action: 'approved'
// (payment not yet submitted) and 'submitted' (payment sent, not yet
// confirmed paid) — each gets its own action button below.
$sql = "SELECT p.*, u.name as submitted_by_name, u.email as submitted_by_email
        FROM purchases p
        LEFT JOIN users u ON p.submitted_by = u.id
        WHERE p.status IN ('approved','submitted')" . (is_member() ? ' AND p.submitted_by = :me' : '') . "
        ORDER BY p.purchase_date ASC, p.id ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute(is_member() ? ['me' => $_SESSION['user_id'] ?? 0] : []);
$purchases = $stmt->fetchAll();

$total = array_sum(array_column($purchases, 'amount_total'));
$status_colors = ['approved'=>'#1b5e20','submitted'=>'#6a1b9a'];

admin_header('Pending Payments');
echo show_flash();
?>
<style>
.status-badge{display:inline-block;padding:.15rem .5rem;border-radius:3px;font-size:.7rem;font-weight:700;white-space:nowrap}
.reimb-card{background:#fff;border-radius:6px;box-shadow:0 1px 4px rgba(0,0,0,.1);padding:1.1rem 1.25rem;margin-bottom:.75rem;display:flex;flex-wrap:wrap;gap:1rem;align-items:flex-start;justify-content:space-between}
.reimb-main{flex:1;min-width:220px}
.reimb-meta{font-size:.78rem;color:#5a6a7a;margin-top:.25rem;line-height:1.7}
.reimb-amount{font-size:1.3rem;font-weight:700;color:#002554;white-space:nowrap}
.reimb-actions{display:flex;flex-direction:column;gap:.4rem;align-items:flex-end}
.total-bar{background:#fff;border-radius:6px;box-shadow:0 1px 4px rgba(0,0,0,.1);padding:1rem 1.5rem;margin-bottom:1.25rem;display:flex;justify-content:space-between;align-items:center}
</style>

<div class="page-head">
  <h1>Pending Payments</h1>
  <a href="purchases.php" class="btn btn-secondary">← Finance</a>
</div>

<?php if (empty($purchases)): ?>
  <div class="card" style="padding:2rem;text-align:center;color:#5a6a7a">
    ✅ No purchases are awaiting payment.
  </div>
<?php else: ?>

<div class="total-bar">
  <span style="font-size:.82rem;font-weight:700;color:#5a6a7a;text-transform:uppercase;letter-spacing:.05em">
    <?= count($purchases) ?> purchase<?= count($purchases)!==1?'s':'' ?> awaiting payment
  </span>
  <span style="font-size:1.3rem;font-weight:700;color:#A6192E">
    Total: $<?= number_format($total, 2) ?>
  </span>
</div>

<?php foreach ($purchases as $p):
  $days = (int)floor((time() - strtotime($p['purchase_date'])) / 86400);
  $age_color = $days > 30 ? '#A6192E' : ($days > 14 ? '#f57c00' : '#5a6a7a');
?>
<div class="reimb-card">
  <div class="reimb-main">
    <strong style="color:#002554;font-size:1rem"><?= h($p['vendor']) ?></strong>
    <span class="status-badge" style="background:<?= $status_colors[$p['status']] ?>22;color:<?= $status_colors[$p['status']] ?>;margin-left:.5rem;font-size:.68rem">
      <?= h(PURCHASE_STATUSES[$p['status']]) ?>
    </span>
    <div class="reimb-meta">
      <?= h(date('M j, Y', strtotime($p['purchase_date']))) ?>
      <span style="color:<?= $age_color ?>"> · <?= $days ?>d ago</span>
      <?php if ($p['event']): ?> · <?= h($p['event']) ?><?php endif; ?>
      <?php if ($p['category']): ?> · <?= h($p['category']) ?><?php endif; ?>
      <br>
      <?= h($p['description']) ?>
      <?php if ($p['payment_method']): ?>
        <br>Paid via: <strong><?= h($p['payment_method']) ?></strong>
      <?php endif; ?>
      <?php if ($p['submitted_by_name']): ?>
        <br>Submitted by: <?= h($p['submitted_by_name']) ?>
        <?php if ($p['submitted_by_email']): ?>
          · <a href="mailto:<?= h($p['submitted_by_email']) ?>" style="color:#003594"><?= h($p['submitted_by_email']) ?></a>
        <?php endif; ?>
      <?php endif; ?>
      <?php if ($p['approved_note']): ?>
        <br><em style="color:#1b5e20">Approval note: <?= h($p['approved_note']) ?></em>
      <?php endif; ?>
    </div>
  </div>
  <div style="text-align:right">
    <div class="reimb-amount">$<?= number_format($p['amount_total'], 2) ?></div>
    <div style="font-size:.72rem;color:#5a6a7a">
      $<?= number_format($p['amount_pretax'],2) ?> + $<?= number_format($p['amount_tax'],2) ?> tax
      <?php if ($p['amount_shipping'] > 0): ?> + $<?= number_format($p['amount_shipping'],2) ?> ship<?php endif; ?>
    </div>
    <div class="reimb-actions" style="margin-top:.6rem">
      <a href="purchase-form.php?id=<?= (int)$p['id'] ?>" class="btn btn-secondary btn-sm">View</a>
      <?php if (is_treasurer() && $p['status'] === 'approved'): ?>
      <form id="rf-pr-<?= (int)$p['id'] ?>" method="POST" action="purchase-action.php" style="margin:0">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
        <input type="hidden" name="action" value="submit">
        <input type="hidden" name="note" id="rn-pr-<?= (int)$p['id'] ?>">
            <input type="hidden" name="payment_method" id="rpm-pr-<?= (int)$p['id'] ?>">
        <button type="button" class="btn btn-sm" style="background:#6a1b9a;color:#fff"
          onclick="openPrModal(<?= (int)$p['id'] ?>, '<?= h(addslashes($p['vendor'])) ?>', '$<?= number_format($p['amount_total'],2) ?>', '<?= h(addslashes($p['payment_method'] ?? '')) ?>')">
          💸 Submit Payment
        </button>
      </form>
      <?php elseif (is_treasurer() && $p['status'] === 'submitted'): ?>
      <?php $pp_status = $p['paypal_payout_status'] ?? ''; ?>
      <?php if ($pp_status === 'NEEDS_MANUAL_CHECK'): ?>
      <span class="btn btn-sm" style="background:#b02a37;color:#fff" title='PayPal already has a record of a payout attempt for this purchase — check the PayPal dashboard for "purchase-<?= (int)$p['id'] ?>" before doing anything else.'>⚠️ Check PayPal manually</span>
      <?php elseif ($pp_status === 'SENDING'): ?>
      <span class="btn btn-sm" style="background:#8A8D8F;color:#fff">🅿️ Sending…</span>
      <?php elseif (str_starts_with($p['payment_method'] ?? '', 'PayPal ') && empty($p['paypal_payout_batch_id'])): ?>
      <form method="POST" action="purchase-action.php" style="margin:0"
        onsubmit="return confirm('Send $<?= number_format($p['amount_total'],2) ?> via PayPal to <?= h(addslashes(trim(substr($p['payment_method'], 7)))) ?>?')">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
        <input type="hidden" name="action" value="send_paypal">
        <button type="submit" class="btn btn-sm" style="background:#0070ba;color:#fff">🅿️ Send via PayPal</button>
      </form>
      <?php elseif (!empty($p['paypal_payout_batch_id'])): ?>
      <form method="POST" action="purchase-action.php" style="margin:0">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
        <input type="hidden" name="action" value="check_paypal_status">
        <button type="submit" class="btn btn-secondary btn-sm">🔄 PayPal: <?= h($p['paypal_payout_status'] ?? 'Sent') ?></button>
      </form>
      <?php endif; ?>
      <form id="pf-pr-<?= (int)$p['id'] ?>" method="POST" action="purchase-action.php" style="margin:0">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
        <input type="hidden" name="action" value="paid">
        <input type="hidden" name="note" id="pn-pr-<?= (int)$p['id'] ?>">
        <button type="button" class="btn btn-sm" style="background:#003594;color:#fff"
          onclick="doAction('pf-pr-<?= (int)$p['id'] ?>','pn-pr-<?= (int)$p['id'] ?>','Note (optional):','Confirm this purchase has been paid?')">
          ✓ Mark Paid
        </button>
      </form>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php endforeach; ?>

<!-- Reimburse modal (reused from purchases.php) -->
<div id="pr-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9999;align-items:center;justify-content:center">
  <div style="background:#fff;border-radius:8px;box-shadow:0 8px 32px rgba(0,0,0,.25);padding:1.75rem;max-width:420px;width:90%;margin:1rem">
    <h2 style="font-size:1rem;color:#002554;margin-bottom:.25rem">Submit Payment</h2>
    <p id="pr-modal-desc" style="font-size:.85rem;color:#5a6a7a;margin-bottom:1.25rem"></p>
    <div style="margin-bottom:.9rem">
      <label style="display:block;font-size:.78rem;font-weight:700;color:#5a6a7a;text-transform:uppercase;letter-spacing:.04em;margin-bottom:.3rem">Payment Method *</label>
      <select id="pr-modal-method" onchange="updatePrFields()" style="width:100%;padding:.6rem .75rem;border:1px solid #d0d5dd;border-radius:4px;font-family:inherit;font-size:.9rem">
        <?php foreach (PAYMENT_METHODS as $pm): ?>
          <option value="<?= h($pm) ?>"><?= $pm === '' ? '— select method —' : h($pm) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div id="pr-check-row" style="display:none;margin-bottom:.9rem">
      <label style="display:block;font-size:.78rem;font-weight:700;color:#5a6a7a;text-transform:uppercase;letter-spacing:.04em;margin-bottom:.3rem">Check Number *</label>
      <input type="text" id="pr-check-number" placeholder="e.g. 1042" style="width:100%;padding:.6rem .75rem;border:1px solid #d0d5dd;border-radius:4px;font-family:inherit;font-size:.9rem">
    </div>
    <div id="pr-venmo-row" style="display:none;margin-bottom:.9rem">
      <label style="display:block;font-size:.78rem;font-weight:700;color:#5a6a7a;text-transform:uppercase;letter-spacing:.04em;margin-bottom:.3rem">Venmo ID *</label>
      <input type="text" id="pr-venmo-id" placeholder="@username" style="width:100%;padding:.6rem .75rem;border:1px solid #d0d5dd;border-radius:4px;font-family:inherit;font-size:.9rem">
    </div>
    <div id="pr-paypal-row" style="display:none;margin-bottom:.9rem">
      <label style="display:block;font-size:.78rem;font-weight:700;color:#5a6a7a;text-transform:uppercase;letter-spacing:.04em;margin-bottom:.3rem">PayPal Email *</label>
      <input type="email" id="pr-paypal-email" placeholder="name@example.com" style="width:100%;padding:.6rem .75rem;border:1px solid #d0d5dd;border-radius:4px;font-family:inherit;font-size:.9rem">
    </div>
    <div style="margin-bottom:.9rem">
      <label id="pr-note-label" style="display:block;font-size:.78rem;font-weight:700;color:#5a6a7a;text-transform:uppercase;letter-spacing:.04em;margin-bottom:.3rem">Note (optional)</label>
      <input type="text" id="pr-modal-note" placeholder="Optional note…" style="width:100%;padding:.6rem .75rem;border:1px solid #d0d5dd;border-radius:4px;font-family:inherit;font-size:.9rem">
    </div>
    <div style="display:flex;gap:.75rem;margin-top:1.25rem">
      <button onclick="confirmPrReimburse()" style="flex:1;padding:.7rem;background:#003594;color:#fff;border:none;border-radius:4px;font-size:.9rem;font-weight:700;cursor:pointer">Confirm Payment Submitted</button>
      <button onclick="document.getElementById('pr-modal').style.display='none'" style="padding:.7rem 1.25rem;background:#f0f2f5;color:#333;border:1px solid #d0d5dd;border-radius:4px;font-size:.9rem;cursor:pointer">Cancel</button>
    </div>
  </div>
</div>
<script>
function doAction(formId, noteId, notePrompt, confirmMsg) {
  var note = prompt(notePrompt, '');
  if (note === null) return;
  document.getElementById(noteId).value = note;
  if (!confirm(confirmMsg)) return;
  document.getElementById(formId).submit();
}
var _prId = null;
function updatePrFields() {
  var m = document.getElementById('pr-modal-method').value;
  document.getElementById('pr-check-row').style.display   = m === 'Check'    ? 'block' : 'none';
  document.getElementById('pr-venmo-row').style.display   = m === 'Venmo'    ? 'block' : 'none';
  document.getElementById('pr-paypal-row').style.display  = m === 'PayPal'   ? 'block' : 'none';
}
function parsePaymentMethod(stored) {
  if (stored.indexOf('Check #') === 0) return {method: 'Check',  value: stored.slice(7)};
  if (stored.indexOf('Venmo ') === 0)  return {method: 'Venmo',  value: stored.slice(6)};
  if (stored.indexOf('PayPal ') === 0) return {method: 'PayPal', value: stored.slice(7)};
  return {method: stored, value: ''};
}

function openPrModal(id, vendor, amount, storedMethod) {
  _prId = id;
  document.getElementById('pr-modal-desc').textContent = vendor + ' — ' + amount;
  var parsed = parsePaymentMethod(storedMethod || '');
  document.getElementById('pr-modal-method').value = parsed.method;
  document.getElementById('pr-check-number').value = parsed.method === 'Check'  ? parsed.value : '';
  document.getElementById('pr-venmo-id').value      = parsed.method === 'Venmo'  ? parsed.value : '';
  document.getElementById('pr-paypal-email').value  = parsed.method === 'PayPal' ? parsed.value : '';
  document.getElementById('pr-modal-note').value = '';
  updatePrFields();
  document.getElementById('pr-modal').style.display = 'flex';
}
function confirmPrReimburse() {
  var method = document.getElementById('pr-modal-method').value;
  if (!method) { alert('Please select a payment method.'); return; }
  var fullMethod = method;
  if (method === 'Check') {
    var num = document.getElementById('pr-check-number').value.trim();
    if (!num) { alert('Please enter the check number.'); return; }
    fullMethod = 'Check #' + num;
  } else if (method === 'Venmo') {
    var venmo = document.getElementById('pr-venmo-id').value.trim();
    if (!venmo) { alert('Please enter the Venmo ID.'); return; }
    if (venmo.charAt(0) !== '@') venmo = '@' + venmo;
    fullMethod = 'Venmo ' + venmo;
  } else if (method === 'PayPal') {
    var paypal = document.getElementById('pr-paypal-email').value.trim();
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(paypal)) { alert('Please enter a valid PayPal email address.'); return; }
    fullMethod = 'PayPal ' + paypal;
  }
  var prNote = document.getElementById('pr-modal-note').value.trim();
  var submitId = _prId;
  document.getElementById('rpm-pr-' + submitId).value = fullMethod;
  document.getElementById('rn-pr-'  + submitId).value = prNote;
  document.getElementById('pr-modal').style.display = 'none';
  _prId = null;
  document.getElementById('rf-pr-' + submitId).submit();
}
document.getElementById('pr-modal').addEventListener('click', function(e){ if(e.target===this) this.style.display='none'; });
</script>
<?php endif; ?>

<?php admin_footer(); ?>
