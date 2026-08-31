<?php
require_once __DIR__ . '/auth.php';
require_finance();
$pdo = get_pdo();

$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: purchases.php'); exit; }

$s = $pdo->prepare("SELECT p.*, u.name AS submitted_by_name, u.email AS submitted_by_email
    FROM purchases p
    LEFT JOIN users u ON p.submitted_by = u.id
    WHERE p.id = ?");
$s->execute([$id]);
$p = $s->fetch(PDO::FETCH_ASSOC);
if (!$p) { header('Location: purchases.php'); exit; }
if (!can_view_purchase($p)) { header('Location: purchases.php'); exit; }

// Fetch club name / president from settings
$setting = function(string $key) use ($pdo): string {
    $r = $pdo->prepare("SELECT setting_value FROM site_settings WHERE setting_key=?");
    $r->execute([$key]);
    return (string)($r->fetchColumn() ?: '');
};
$club_name = $setting('club_name') ?: 'USAFA Parents Club of Alabama';

admin_header('Check Request #' . $id);
?>
<style>
@media print {
  .page-head .btn, .no-print { display: none !important; }
  body { background: #fff !important; }
  .cr-wrapper { box-shadow: none !important; border: none !important; max-width: 100% !important; }
  .print-btn { display: none !important; }
}
.cr-wrapper{max-width:720px;background:#fff;border:1px solid #d0d8e4;border-radius:8px;padding:2.25rem;box-shadow:0 2px 12px rgba(0,0,0,.08)}
.cr-header{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:1.75rem;gap:1rem;flex-wrap:wrap}
.cr-org{font-size:1.1rem;font-weight:700;color:#002554;letter-spacing:.02em}
.cr-org-sub{font-size:.78rem;color:#5a6a7a;margin-top:.2rem}
.cr-title{font-size:1.35rem;font-weight:700;color:#002554;margin:0 0 .15rem}
.cr-num{font-size:.75rem;color:#9aa5b4;letter-spacing:.04em}
.cr-divider{border:none;border-top:2px solid #e1e8f0;margin:1.25rem 0}
.cr-grid{display:grid;grid-template-columns:1fr 1fr;gap:.75rem 1.75rem;margin-bottom:1.25rem}
.cr-field{margin-bottom:.1rem}
.cr-lbl{font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#9aa5b4;margin-bottom:.2rem}
.cr-val{font-size:.92rem;color:#1a2332}
.cr-amount-box{background:#f7f9fc;border:1px solid #e1e8f0;border-radius:6px;padding:1rem 1.25rem;margin-bottom:1.25rem}
.cr-amount-row{display:flex;justify-content:space-between;align-items:center;padding:.3rem 0;font-size:.88rem}
.cr-amount-row.total{border-top:2px solid #003594;margin-top:.4rem;padding-top:.65rem;font-weight:700;font-size:1.05rem}
.cr-sig-grid{display:grid;grid-template-columns:1fr 1fr;gap:2rem;margin-top:2rem}
.cr-sig{border-top:1px solid #1a2332;padding-top:.5rem;font-size:.78rem;color:#5a6a7a}
.cr-sig span{display:block;margin-bottom:.3rem;color:#9aa5b4;font-size:.7rem;letter-spacing:.04em;text-transform:uppercase}
.cr-sig-line{height:2.2rem}
.cr-notes{background:#fffbe6;border:1px solid #ffe082;border-radius:4px;padding:.75rem 1rem;font-size:.85rem;color:#5f4c00;margin-bottom:1rem}
.cr-status{display:inline-block;padding:.2rem .65rem;border-radius:99px;font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em}
</style>

<div class="page-head no-print">
  <h1>Check / Reimbursement Request</h1>
  <div style="display:flex;gap:.5rem">
    <button onclick="window.print()" class="btn btn-primary print-btn">🖨️ Print</button>
    <a href="purchases.php" class="btn btn-secondary">← Back</a>
  </div>
</div>

<?php
$status_colors = ['pending'=>'#f57c00','approved'=>'#1b5e20','submitted'=>'#6a1b9a','paid'=>'#003594'];
$sc = $status_colors[$p['status']] ?? '#5a6a7a';
$line_items = [];
if ($p['amount_pretax']  > 0) $line_items[] = ['Subtotal (pre-tax)', $p['amount_pretax']];
if ($p['amount_tax']     > 0) $line_items[] = ['Sales Tax',          $p['amount_tax']];
if ($p['amount_shipping']> 0) $line_items[] = ['Shipping',           $p['amount_shipping']];
?>

<div class="cr-wrapper">
  <div class="cr-header">
    <div>
      <div class="cr-org"><?= h($club_name) ?></div>
      <div class="cr-org-sub">alabamafalcons.org &nbsp;·&nbsp; treasurer@alabamafalcons.org</div>
    </div>
    <div style="text-align:right">
      <div class="cr-title">Reimbursement / Check Request</div>
      <div class="cr-num">Request #<?= str_pad($p['id'],5,'0',STR_PAD_LEFT) ?> &nbsp;·&nbsp; <?= date('F j, Y') ?></div>
      <div style="margin-top:.4rem"><span class="cr-status" style="background:<?= $sc ?>22;color:<?= $sc ?>"><?= ucfirst($p['status']) ?></span></div>
    </div>
  </div>

  <hr class="cr-divider">

  <div class="cr-grid">
    <div class="cr-field">
      <div class="cr-lbl">Requested By</div>
      <div class="cr-val"><?= h($p['submitted_by_name'] ?? '—') ?></div>
      <?php if ($p['submitted_by_email']): ?>
      <div style="font-size:.78rem;color:#5a6a7a"><?= h($p['submitted_by_email']) ?></div>
      <?php endif; ?>
    </div>
    <div class="cr-field">
      <div class="cr-lbl">Purchase Date</div>
      <div class="cr-val"><?= date('F j, Y', strtotime($p['purchase_date'])) ?></div>
    </div>
    <div class="cr-field">
      <div class="cr-lbl">Vendor / Payee</div>
      <div class="cr-val"><?= h($p['vendor'] ?: '—') ?></div>
    </div>
    <div class="cr-field">
      <div class="cr-lbl">Event</div>
      <div class="cr-val"><?= h($p['event'] ?: '—') ?></div>
    </div>
    <div class="cr-field">
      <div class="cr-lbl">Category</div>
      <div class="cr-val"><?= h($p['category'] ?: '—') ?></div>
    </div>
    <?php if ($p['order_number']): ?>
    <div class="cr-field">
      <div class="cr-lbl">Order / Invoice #</div>
      <div class="cr-val"><?= h($p['order_number']) ?></div>
    </div>
    <?php endif; ?>
    <div class="cr-field" style="grid-column:1/-1">
      <div class="cr-lbl">Description / Purpose</div>
      <div class="cr-val"><?= nl2br(h($p['description'])) ?></div>
    </div>
  </div>

  <?php if ($p['notes']): ?>
  <div class="cr-notes">📝 <?= nl2br(h($p['notes'])) ?></div>
  <?php endif; ?>

  <!-- Amount breakdown -->
  <div class="cr-amount-box">
    <div style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#9aa5b4;margin-bottom:.5rem">Amount Breakdown</div>
    <?php foreach ($line_items as [$label, $amt]): ?>
    <div class="cr-amount-row">
      <span style="color:#5a6a7a"><?= h($label) ?></span>
      <span>$<?= number_format($amt,2) ?></span>
    </div>
    <?php endforeach; ?>
    <div class="cr-amount-row total">
      <span>Total Requested</span>
      <span style="color:#A6192E">$<?= number_format($p['amount_total'],2) ?></span>
    </div>
  </div>

  <!-- Receipt indicator -->
  <div style="font-size:.82rem;color:#5a6a7a;margin-bottom:1.5rem;display:flex;align-items:center;gap:.5rem">
    <?php if (!empty($p['receipt_filename'])): ?>
    ✅ Receipt attached &nbsp;
    <a href="receipt-view.php?id=<?= $p['id'] ?>" target="_blank" style="font-size:.78rem;color:#003594" class="no-print">View Receipt</a>
    <?php else: ?>
    ⚠️ <span style="color:#856404">No receipt on file</span>
    <?php endif; ?>
  </div>

  <!-- Signature lines -->
  <hr class="cr-divider">
  <div class="cr-sig-grid">
    <div class="cr-sig">
      <div class="cr-sig-line"></div>
      <span>Requested by</span>
      <?= h($p['submitted_by_name'] ?? 'Requestor') ?>
    </div>
    <div class="cr-sig">
      <div class="cr-sig-line"></div>
      <span>Approved by Treasurer</span>
      &nbsp;
    </div>
    <div class="cr-sig" style="margin-top:1.5rem">
      <div class="cr-sig-line"></div>
      <span>Date</span>
      &nbsp;
    </div>
    <div class="cr-sig" style="margin-top:1.5rem">
      <div class="cr-sig-line"></div>
      <span>Date</span>
      &nbsp;
    </div>
  </div>
  <div style="font-size:.7rem;color:#9aa5b4;margin-top:1.5rem;text-align:center">
    Retain this form and receipt for club financial records · <?= h($club_name) ?>
  </div>
</div>

<?php admin_footer(); ?>
