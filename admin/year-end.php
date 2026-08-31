<?php
require_once __DIR__ . '/auth.php';
require_finance();
if (!is_treasurer() && !is_super_admin()) { header('Location: dashboard.php?denied=1'); exit; }
$pdo = get_pdo();

// Fiscal year = July 1 – June 30
$current_fy_start = (int)date('n') >= 7 ? (int)date('Y') : (int)date('Y') - 1;
$fy = (int)($_GET['fy'] ?? $current_fy_start);
if ($fy < 2020 || $fy > 2100) $fy = $current_fy_start;
$fy_label  = $fy . '–' . ($fy + 1);
$date_from = $fy . '-07-01';
$date_to   = ($fy + 1) . '-06-30';

// All fully-paid purchases in fiscal year (for actual expenses)
$stmt = $pdo->prepare(
    "SELECT * FROM purchases WHERE purchase_date BETWEEN ? AND ? ORDER BY purchase_date"
);
$stmt->execute([$date_from, $date_to]);
$all = $stmt->fetchAll();

$reimbursed  = array_filter($all, fn($p) => $p['status'] === 'paid');
$total_spent = array_sum(array_column($reimbursed, 'amount_total'));
$total_tax   = array_sum(array_column($reimbursed, 'amount_tax'));
$total_ship  = array_sum(array_column($reimbursed, 'amount_shipping'));

$by_cat = []; $by_event = []; $by_month = [];
foreach ($reimbursed as $p) {
    $cat   = $p['category'] ?: 'Uncategorised';
    $ev    = $p['event']    ?: 'General';
    // Month index within fiscal year (Jul=1 … Jun=12)
    $m     = (int)date('n', strtotime($p['purchase_date']));
    $mo    = $m >= 7 ? $m - 6 : $m + 6;
    $by_cat[$cat]   = ($by_cat[$cat]   ?? 0) + $p['amount_total'];
    $by_event[$ev]  = ($by_event[$ev]  ?? 0) + $p['amount_total'];
    $by_month[$mo]  = ($by_month[$mo]  ?? 0) + $p['amount_total'];
}
arsort($by_cat); arsort($by_event);
$max_month = max(array_values($by_month) ?: [1]);
$fy_months = ['Jul','Aug','Sep','Oct','Nov','Dec','Jan','Feb','Mar','Apr','May','Jun'];

// Available fiscal years
$years_q = $pdo->query("SELECT DISTINCT YEAR(purchase_date) y FROM purchases ORDER BY y DESC")->fetchAll(PDO::FETCH_COLUMN);
$fy_years = [];
foreach ($years_q as $y) {
    $start = (int)$y >= 7 ? (int)$y : (int)$y - 1; // approximate
    $fy_years[$y] = $y; // just use calendar years for selector
}

admin_header('Year-End Summary');
?>
<style>
@media print {
  .no-print{display:none!important}
  body{background:#fff!important;font-size:11pt}
  .card{box-shadow:none!important;border:1px solid #ccc}
  .main{max-width:100%!important;margin:0!important;padding:0!important}
  h1{font-size:1.2rem}
}
.ye-grid{display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;margin-bottom:1.5rem}
.ye-card{background:#fff;border-radius:6px;box-shadow:0 1px 4px rgba(0,0,0,.1);padding:1.25rem}
.ye-card h2{font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#5a6a7a;margin-bottom:.9rem}
.bar-row{display:flex;align-items:center;gap:.75rem;margin-bottom:.5rem;font-size:.82rem}
.bar-label{width:170px;flex-shrink:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.bar-track{flex:1;background:#f0f2f5;border-radius:99px;height:14px;overflow:hidden}
.bar-fill{height:100%;background:#003594;border-radius:99px}
.bar-amt{width:75px;text-align:right;font-weight:700;color:#002554;flex-shrink:0}
.bar-pct{width:38px;text-align:right;font-size:.72rem;color:#9aa5b4;flex-shrink:0}
.mb{display:flex;align-items:flex-end;gap:3px;height:80px}
.mb-col{display:flex;flex-direction:column;align-items:center;gap:2px;flex:1}
.mb-bar{width:100%;background:#003594;border-radius:3px 3px 0 0;min-height:2px}
.mb-lbl{font-size:.62rem;color:#9aa5b4}
@media(max-width:700px){.ye-grid{grid-template-columns:1fr}}
</style>

<div class="page-head no-print">
  <h1>Year-End Summary — FY <?= $fy_label ?></h1>
  <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap">
    <form method="GET" style="margin:0;display:flex;gap:.5rem;align-items:center">
      <label style="font-size:.82rem;color:#5a6a7a">Fiscal Year:</label>
      <select name="fy" onchange="this.form.submit()" style="padding:.4rem .7rem;border:1px solid #d0d5dd;border-radius:4px;font-size:.85rem">
        <?php for ($y = $current_fy_start; $y >= max($current_fy_start-5,2024); $y--): ?>
          <option value="<?= $y ?>" <?= $y===$fy?'selected':''?>>FY <?= $y ?>–<?= $y+1 ?></option>
        <?php endfor; ?>
      </select>
    </form>
    <button onclick="window.print()" class="btn btn-secondary no-print">🖨️ Print / PDF</button>
    <a href="purchases.php" class="btn btn-secondary no-print">← Finance</a>
  </div>
</div>

<!-- IRS note -->
<div style="background:#e3f2fd;border-left:4px solid #1976d2;padding:.75rem 1.1rem;border-radius:4px;margin-bottom:1.25rem;font-size:.85rem;color:#0d47a1">
  <strong>IRS Form 990-N reminder:</strong> If total gross receipts are under $50,000, file the annual e-Postcard at
  <a href="https://www.irs.gov/990n" target="_blank" rel="noopener" style="color:#0d47a1">irs.gov/990n</a> by the 15th day of the 5th month after your fiscal year ends (November 15 for a July–June fiscal year).
</div>

<!-- Summary cards -->
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:.75rem;margin-bottom:1.5rem">
  <div class="card" style="padding:1rem;text-align:center;margin:0">
    <div style="font-size:1.5rem;font-weight:700;color:#002554"><?= count($all) ?></div>
    <div style="font-size:.72rem;color:#5a6a7a;text-transform:uppercase">All Purchases</div>
  </div>
  <div class="card" style="padding:1rem;text-align:center;margin:0">
    <div style="font-size:1.5rem;font-weight:700;color:#1b5e20"><?= count($reimbursed) ?></div>
    <div style="font-size:.72rem;color:#5a6a7a;text-transform:uppercase">Paid</div>
  </div>
  <div class="card" style="padding:1rem;text-align:center;margin:0;border:2px solid #A6192E">
    <div style="font-size:1.5rem;font-weight:700;color:#A6192E">$<?= number_format($total_spent,2) ?></div>
    <div style="font-size:.72rem;color:#5a6a7a;text-transform:uppercase">Total Expenses</div>
  </div>
  <div class="card" style="padding:1rem;text-align:center;margin:0">
    <div style="font-size:1.5rem;font-weight:700;color:#5a6a7a">$<?= number_format($total_tax,2) ?></div>
    <div style="font-size:.72rem;color:#5a6a7a;text-transform:uppercase">Tax Paid</div>
  </div>
  <div class="card" style="padding:1rem;text-align:center;margin:0">
    <div style="font-size:1.5rem;font-weight:700;color:#5a6a7a">$<?= number_format($total_ship,2) ?></div>
    <div style="font-size:.72rem;color:#5a6a7a;text-transform:uppercase">Shipping</div>
  </div>
</div>

<div class="ye-grid">
  <!-- By Category -->
  <div class="ye-card">
    <h2>Expenses by Category</h2>
    <?php foreach ($by_cat as $cat => $amt):
      $pct = $total_spent > 0 ? round($amt/$total_spent*100) : 0;
    ?>
    <div class="bar-row">
      <div class="bar-label" title="<?= h($cat) ?>"><?= h($cat) ?></div>
      <div class="bar-track"><div class="bar-fill" style="width:<?= $pct ?>%"></div></div>
      <div class="bar-amt">$<?= number_format($amt,2) ?></div>
      <div class="bar-pct"><?= $pct ?>%</div>
    </div>
    <?php endforeach; ?>
    <?php if (empty($by_cat)): ?><p style="color:#9aa5b4;font-size:.85rem">No paid purchases.</p><?php endif; ?>
  </div>

  <!-- By Event -->
  <div class="ye-card">
    <h2>Expenses by Event</h2>
    <?php foreach ($by_event as $ev => $amt):
      $pct = $total_spent > 0 ? round($amt/$total_spent*100) : 0;
    ?>
    <div class="bar-row">
      <div class="bar-label" title="<?= h($ev) ?>"><?= h($ev) ?></div>
      <div class="bar-track"><div class="bar-fill" style="width:<?= $pct ?>%;background:#1b5e20"></div></div>
      <div class="bar-amt">$<?= number_format($amt,2) ?></div>
      <div class="bar-pct"><?= $pct ?>%</div>
    </div>
    <?php endforeach; ?>
    <?php if (empty($by_event)): ?><p style="color:#9aa5b4;font-size:.85rem">No paid purchases.</p><?php endif; ?>
  </div>

  <!-- Monthly chart -->
  <div class="ye-card" style="grid-column:1/-1">
    <h2>Monthly Expenses — FY <?= $fy_label ?> (July – June, paid only)</h2>
    <div class="mb">
      <?php for ($mo=1; $mo<=12; $mo++):
        $amt = $by_month[$mo] ?? 0;
        $h_px = $amt > 0 ? max(4, round($amt/$max_month*72)) : 0;
      ?>
      <div class="mb-col">
        <div style="font-size:.62rem;color:#002554;font-weight:700"><?= $amt>0?'$'.number_format($amt,0):'' ?></div>
        <div class="mb-bar" style="height:<?= $h_px ?>px;background:<?= $amt>0?'#003594':'#e1e5eb' ?>"></div>
        <div class="mb-lbl"><?= $fy_months[$mo-1] ?></div>
      </div>
      <?php endfor; ?>
    </div>
  </div>
</div>

<!-- Full purchase list for print -->
<?php if (!empty($reimbursed)): ?>
<div class="ye-card" style="grid-column:1/-1">
  <h2>Paid Purchases — FY <?= $fy_label ?></h2>
  <table>
    <thead>
      <tr>
        <th>Date</th><th>Vendor</th><th>Description</th><th>Event</th><th>Category</th>
        <th>Payment</th><th style="text-align:right">Pre-Tax</th><th style="text-align:right">Tax</th><th style="text-align:right">Total</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($reimbursed as $p): ?>
      <tr>
        <td><?= h(date('M j, Y', strtotime($p['purchase_date']))) ?></td>
        <td><?= h($p['vendor']) ?></td>
        <td><?= h($p['description']) ?></td>
        <td style="font-size:.78rem;color:#5a6a7a"><?= h($p['event']) ?></td>
        <td style="font-size:.78rem;color:#5a6a7a"><?= h($p['category']) ?></td>
        <td style="font-size:.78rem;color:#5a6a7a"><?= h($p['payment_method']) ?></td>
        <td style="text-align:right">$<?= number_format($p['amount_pretax'],2) ?></td>
        <td style="text-align:right;color:#5a6a7a">$<?= number_format($p['amount_tax'],2) ?></td>
        <td style="text-align:right;font-weight:700">$<?= number_format($p['amount_total'],2) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr style="background:#f5f7fa;font-weight:700">
        <td colspan="6" style="text-align:right;font-size:.8rem;color:#5a6a7a">TOTALS</td>
        <td style="text-align:right">$<?= number_format(array_sum(array_column($reimbursed,'amount_pretax')),2) ?></td>
        <td style="text-align:right;color:#5a6a7a">$<?= number_format($total_tax,2) ?></td>
        <td style="text-align:right;color:#A6192E">$<?= number_format($total_spent,2) ?></td>
      </tr>
    </tfoot>
  </table>
</div>
<?php endif; ?>

<?php admin_footer(); ?>
