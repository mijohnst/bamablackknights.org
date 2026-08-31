<?php
require_once __DIR__ . '/auth.php';
require_finance();
if (!is_treasurer() && !is_super_admin()) { header('Location: dashboard.php?denied=1'); exit; }
$pdo = get_pdo();

// All years with purchases
$all_years = $pdo->query("SELECT DISTINCT YEAR(purchase_date) y FROM purchases ORDER BY y ASC")->fetchAll(PDO::FETCH_COLUMN);
if (empty($all_years)) $all_years = [(int)date('Y')];
$cur_year = (int)date('Y');

// Show up to 4 most-recent years as columns
$show_years = array_slice($all_years, -4);

// Build spend per vendor per year
$stmt = $pdo->query("SELECT vendor,
    YEAR(purchase_date) AS yr,
    COUNT(*) AS cnt,
    SUM(amount_total) AS total
    FROM purchases
    WHERE TRIM(vendor) != ''
    GROUP BY vendor, YEAR(purchase_date)
    ORDER BY vendor ASC");
$raw = $stmt->fetchAll(PDO::FETCH_ASSOC);

$vendors = [];
foreach ($raw as $r) {
    $v = trim($r['vendor']);
    if ($v === '') continue;
    $yr = (int)$r['yr'];
    if (!isset($vendors[$v])) {
        $vendors[$v] = ['years' => [], 'grand_total' => 0, 'grand_count' => 0, 'flag_1099' => false];
    }
    $vendors[$v]['years'][$yr] = ['total' => (float)$r['total'], 'count' => (int)$r['cnt']];
    $vendors[$v]['grand_total'] += (float)$r['total'];
    $vendors[$v]['grand_count'] += (int)$r['cnt'];
    if ($yr === $cur_year && (float)$r['total'] >= 600) {
        $vendors[$v]['flag_1099'] = true;
    }
}

// Sort by grand total descending
uasort($vendors, fn($a,$b) => $b['grand_total'] <=> $a['grand_total']);

$grand_all = array_sum(array_column($vendors,'grand_total'));

// CSV export
if (isset($_GET['export'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="vendor-summary-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output','w');
    $hdr = ['Vendor'];
    foreach ($show_years as $y) $hdr[] = "FY$y Amount";
    foreach ($show_years as $y) $hdr[] = "FY$y Count";
    $hdr[] = 'Grand Total';
    $hdr[] = '1099 Flag (' . $cur_year . ')';
    fputcsv($out, $hdr);
    foreach ($vendors as $vname => $vd) {
        $row = [$vname];
        foreach ($show_years as $y) $row[] = number_format($vd['years'][$y]['total'] ?? 0, 2);
        foreach ($show_years as $y) $row[] = (int)($vd['years'][$y]['count'] ?? 0);
        $row[] = number_format($vd['grand_total'],2);
        $row[] = $vd['flag_1099'] ? 'YES' : '';
        fputcsv($out, $row);
    }
    fclose($out); exit;
}

admin_header('Vendor Spend Summary');
echo show_flash();
?>
<style>
.vs-table{width:100%;border-collapse:collapse;font-size:.83rem}
.vs-table th{padding:.55rem 1rem;font-size:.67rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#5a6a7a;background:#f7f9fc;white-space:nowrap;text-align:left}
.vs-table th.num{text-align:right}
.vs-table td{padding:.6rem 1rem;border-top:1px solid #f0f2f5;vertical-align:top}
.vs-table td.num{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}
.vs-table tr:hover td{background:#fafbfc}
.yr-block{text-align:right}
.yr-amt{font-weight:600;color:#1a2332}
.yr-cnt{font-size:.7rem;color:#9aa5b4}
.flag-1099{display:inline-block;background:#fff3e0;color:#e65100;border:1px solid #ffcc80;border-radius:3px;font-size:.65rem;font-weight:700;padding:.1rem .35rem;letter-spacing:.04em}
.vs-total-row td{background:#f7f9fc;font-weight:700;font-size:.85rem;border-top:2px solid #e1e8f0}
</style>

<div class="page-head">
  <h1>Vendor Spend Summary</h1>
  <div style="display:flex;gap:.5rem;flex-wrap:wrap">
    <a href="vendor-summary.php?export=1" class="btn btn-secondary">Export CSV</a>
    <a href="purchases.php" class="btn btn-secondary">← Finance</a>
  </div>
</div>

<p style="font-size:.83rem;color:#5a6a7a;margin-bottom:1.25rem">
  All vendors from purchases, sorted by total spend. Vendors with ≥ $600 spent in <?= $cur_year ?> are flagged for 1099 consideration.
</p>

<?php if (empty($vendors)): ?>
  <p style="color:#9aa5b4">No vendor data found.</p>
<?php else: ?>
<div class="card" style="padding:0;overflow-x:auto">
<table class="vs-table">
  <thead>
    <tr>
      <th>Vendor</th>
      <?php foreach ($show_years as $y): ?>
      <th class="num"><?= $y ?></th>
      <?php endforeach; ?>
      <th class="num">All-Time Total</th>
      <th>Notes</th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($vendors as $vname => $vd): ?>
  <tr>
    <td>
      <a href="purchases.php?q=<?= urlencode($vname) ?>" style="color:#003594;font-weight:600;text-decoration:none"><?= h($vname) ?></a>
      <div style="font-size:.7rem;color:#9aa5b4"><?= (int)$vd['grand_count'] ?> purchase<?= $vd['grand_count']!=1?'s':'' ?></div>
    </td>
    <?php foreach ($show_years as $y): $yd = $vd['years'][$y] ?? null; ?>
    <td class="num">
      <?php if ($yd): ?>
      <div class="yr-amt">$<?= number_format($yd['total'],2) ?></div>
      <div class="yr-cnt"><?= $yd['count'] ?> txn<?= $yd['count']!=1?'s':'' ?></div>
      <?php else: ?>
      <span style="color:#e1e8f0">—</span>
      <?php endif; ?>
    </td>
    <?php endforeach; ?>
    <td class="num" style="font-weight:700;color:#A6192E">$<?= number_format($vd['grand_total'],2) ?></td>
    <td>
      <?php if ($vd['flag_1099']): ?>
      <span class="flag-1099">1099?</span>
      <?php endif; ?>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody>
  <tfoot>
    <tr class="vs-total-row">
      <td>Total — <?= count($vendors) ?> vendors</td>
      <?php foreach ($show_years as $y):
        $yr_sum = array_sum(array_column(array_map(fn($v)=>$v['years'][$y]??['total'=>0], $vendors),'total'));
      ?>
      <td class="num">$<?= number_format($yr_sum,2) ?></td>
      <?php endforeach; ?>
      <td class="num">$<?= number_format($grand_all,2) ?></td>
      <td></td>
    </tr>
  </tfoot>
</table>
</div>
<?php endif; ?>

<?php admin_footer(); ?>
