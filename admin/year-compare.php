<?php
require_once __DIR__ . '/auth.php';
require_finance();
if (!is_treasurer() && !is_super_admin()) { header('Location: dashboard.php?denied=1'); exit; }
$pdo = get_pdo();

$all_years = $pdo->query("SELECT DISTINCT YEAR(purchase_date) y FROM purchases ORDER BY y DESC")->fetchAll(PDO::FETCH_COLUMN);
if (empty($all_years)) $all_years = [(int)date('Y')];

// Default: up to 3 most recent years, user can pick any 2-3
$max_cols = 3;
$years_input = $_GET['years'] ?? [];
if (is_array($years_input)) {
    $selected_years = array_values(array_unique(array_filter(array_map('intval', $years_input))));
} else {
    $selected_years = array_values(array_unique(array_filter(array_map('intval', explode(',', (string)$years_input)))));
}
sort($selected_years);
if (count($selected_years) > $max_cols) $selected_years = array_slice($selected_years, -$max_cols);
if (empty($selected_years)) $selected_years = array_slice($all_years, 0, $max_cols);

// Load purchases for selected years
$placeholders = implode(',', array_fill(0, count($selected_years), '?'));
$stmt = $pdo->prepare("SELECT YEAR(purchase_date) AS yr, category, event,
    SUM(amount_total) AS total, COUNT(*) AS cnt
    FROM purchases
    WHERE YEAR(purchase_date) IN ($placeholders)
    GROUP BY YEAR(purchase_date), category, event");
$stmt->execute($selected_years);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Index: by_cat[category][year] and by_event[event][year]
$by_cat = []; $by_event = []; $year_totals = [];
foreach ($selected_years as $y) { $year_totals[$y] = 0; }

foreach ($rows as $r) {
    $yr  = (int)$r['yr'];
    $cat = $r['category'] ?: 'Uncategorised';
    $ev  = $r['event']    ?: 'General';
    $by_cat[$cat][$yr]   = ($by_cat[$cat][$yr]   ?? 0) + (float)$r['total'];
    $by_event[$ev][$yr]  = ($by_event[$ev][$yr]  ?? 0) + (float)$r['total'];
    $year_totals[$yr]   += (float)$r['total'];
}

// Sort by total spend across all selected years, descending
uasort($by_cat,   fn($a, $b) => array_sum($b) <=> array_sum($a));
uasort($by_event, fn($a, $b) => array_sum($b) <=> array_sum($a));

// Max bar reference (across all rows/years)
$max_cat   = max(array_merge([1], ...array_values(array_map(fn($v) => array_values($v), $by_cat))));
$max_event = max(array_merge([1], ...array_values(array_map(fn($v) => array_values($v), $by_event))));

// Year-over-year delta helpers
function delta_pct(float $prev, float $curr): ?float {
    if ($prev <= 0) return null;
    return round(($curr - $prev) / $prev * 100, 1);
}

$bar_colors = ['#003594','#A6192E','#1b5e20'];

admin_header('Multi-Year Spending Comparison');
?>
<style>
.yc-header-row{display:flex;align-items:flex-end;justify-content:space-between;margin-bottom:1.5rem;flex-wrap:wrap;gap:.75rem}
.yc-section{background:#fff;border-radius:8px;box-shadow:0 1px 4px rgba(0,0,0,.08);padding:1.5rem;margin-bottom:1.5rem}
.yc-section h2{font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#5a6a7a;margin-bottom:1.25rem}
.yc-table{width:100%;border-collapse:collapse;font-size:.83rem}
.yc-table th{padding:.5rem .9rem;font-size:.67rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#5a6a7a;background:#f7f9fc;white-space:nowrap;text-align:right}
.yc-table th:first-child{text-align:left}
.yc-table td{padding:.55rem .9rem;border-top:1px solid #f0f2f5;vertical-align:middle;text-align:right;font-variant-numeric:tabular-nums}
.yc-table td:first-child{text-align:left;font-weight:600}
.yc-table tr:hover td{background:#fafbfc}
.yc-total-row td{background:#f7f9fc;font-weight:700;font-size:.85rem;border-top:2px solid #e1e8f0}
.delta-up{color:#A6192E;font-size:.72rem}
.delta-down{color:#1b5e20;font-size:.72rem}
.delta-flat{color:#9aa5b4;font-size:.72rem}
.bar-cell{padding:.4rem .9rem;border-top:1px solid #f0f2f5;vertical-align:middle}
.mini-bar{display:inline-block;height:10px;border-radius:3px;min-width:2px;vertical-align:middle;margin-right:.3rem}
.summary-chips{display:flex;gap:.6rem;flex-wrap:wrap;margin-bottom:1.5rem}
.summary-chip{padding:.45rem .9rem;border-radius:6px;font-size:.8rem;font-weight:700;text-align:center;min-width:110px}
.yr-picker label{font-size:.72rem;color:#5a6a7a;text-transform:uppercase;letter-spacing:.05em;display:block;margin-bottom:.3rem}
.yr-picker select{padding:.35rem .6rem;font-size:.85rem;border:1px solid #d0d5dd;border-radius:4px}
</style>

<div class="page-head">
  <h1>Multi-Year Spending</h1>
  <a href="purchases.php" class="btn btn-secondary">← Finance</a>
</div>

<!-- Year selector -->
<form method="GET" style="display:flex;gap:.6rem;flex-wrap:wrap;align-items:flex-end;margin-bottom:1.5rem">
  <?php for ($i = 0; $i < $max_cols; $i++): ?>
  <div class="yr-picker">
    <label>Year <?= $i+1 ?></label>
    <select name="years[]">
      <option value="">(none)</option>
      <?php foreach ($all_years as $ay): ?>
      <option value="<?= $ay ?>" <?= isset($selected_years[$i]) && $selected_years[$i]==$ay ? 'selected' : '' ?>><?= $ay ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <?php endfor; ?>
  <button type="submit" class="btn btn-secondary">Compare</button>
</form>

<!-- Summary totals -->
<div class="summary-chips">
  <?php foreach ($selected_years as $i => $y): $bc = $bar_colors[$i % count($bar_colors)]; ?>
  <div class="summary-chip" style="background:<?= $bc ?>22;color:<?= $bc ?>">
    <div><?= $y ?></div>
    <div style="font-size:1.05rem">$<?= number_format($year_totals[$y],0) ?></div>
    <?php if ($i > 0 && $year_totals[$selected_years[$i-1]] > 0):
      $d = delta_pct($year_totals[$selected_years[$i-1]], $year_totals[$y]);
      if ($d !== null): $arrow = $d > 0 ? '▲' : '▼'; $dcol = $d > 0 ? '#A6192E' : '#1b5e20';
    ?>
    <div style="font-size:.72rem;color:<?= $dcol ?>"><?= $arrow ?> <?= abs($d) ?>% vs <?= $selected_years[$i-1] ?></div>
    <?php endif; endif; ?>
  </div>
  <?php endforeach; ?>
</div>

<!-- By Category -->
<div class="yc-section">
  <h2>Spending by Category</h2>
  <?php if (empty($by_cat)): ?>
  <p style="color:#9aa5b4;font-size:.85rem">No data.</p>
  <?php else: ?>
  <div style="overflow-x:auto">
  <table class="yc-table">
    <thead>
      <tr>
        <th style="text-align:left">Category</th>
        <?php foreach ($selected_years as $i => $y): $bc = $bar_colors[$i%count($bar_colors)]; ?>
        <th style="color:<?= $bc ?>"><?= $y ?></th>
        <?php endforeach; ?>
        <?php if (count($selected_years) >= 2): ?><th>YoY Change</th><?php endif; ?>
        <th>Chart</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($by_cat as $cat => $ydata): $cat_max = max(array_values($ydata)); ?>
    <tr>
      <td><?= h($cat) ?></td>
      <?php foreach ($selected_years as $y): ?>
      <td>
        <?php $a = $ydata[$y] ?? 0; echo $a > 0 ? '$'.number_format($a,2) : '<span style="color:#e1e8f0">—</span>'; ?>
      </td>
      <?php endforeach; ?>
      <?php if (count($selected_years) >= 2):
        $last2 = array_slice($selected_years, -2);
        $prev = $ydata[$last2[0]] ?? 0;
        $curr = $ydata[$last2[1]] ?? 0;
        $d = delta_pct($prev, $curr);
      ?>
      <td>
        <?php if ($d !== null): ?>
        <span class="<?= $d > 0 ? 'delta-up' : ($d < 0 ? 'delta-down' : 'delta-flat') ?>">
          <?= $d > 0 ? '▲' : ($d < 0 ? '▼' : '—') ?> <?= abs($d) ?>%
        </span>
        <?php else: ?><span class="delta-flat">—</span><?php endif; ?>
      </td>
      <?php endif; ?>
      <td class="bar-cell" style="text-align:left;min-width:100px">
        <?php foreach ($selected_years as $i => $y):
          $a = $ydata[$y] ?? 0;
          $w = $max_cat > 0 ? max(2, round($a/$max_cat*80)) : 0;
          $bc = $bar_colors[$i % count($bar_colors)];
        ?>
        <div title="<?= $y ?>: $<?= number_format($a,2) ?>">
          <span class="mini-bar" style="width:<?= $w ?>px;background:<?= $bc ?>"></span>
        </div>
        <?php endforeach; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr class="yc-total-row">
        <td>Total</td>
        <?php foreach ($selected_years as $y): ?>
        <td>$<?= number_format($year_totals[$y],2) ?></td>
        <?php endforeach; ?>
        <?php if (count($selected_years) >= 2): ?><td></td><?php endif; ?>
        <td></td>
      </tr>
    </tfoot>
  </table>
  </div>
  <?php endif; ?>
</div>

<!-- By Event -->
<div class="yc-section">
  <h2>Spending by Event</h2>
  <?php if (empty($by_event)): ?>
  <p style="color:#9aa5b4;font-size:.85rem">No data.</p>
  <?php else: ?>
  <div style="overflow-x:auto">
  <table class="yc-table">
    <thead>
      <tr>
        <th style="text-align:left">Event</th>
        <?php foreach ($selected_years as $i => $y): $bc = $bar_colors[$i%count($bar_colors)]; ?>
        <th style="color:<?= $bc ?>"><?= $y ?></th>
        <?php endforeach; ?>
        <?php if (count($selected_years) >= 2): ?><th>YoY Change</th><?php endif; ?>
        <th>Chart</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($by_event as $ev => $ydata): ?>
    <tr>
      <td><?= h($ev) ?></td>
      <?php foreach ($selected_years as $y): ?>
      <td>
        <?php $a = $ydata[$y] ?? 0; echo $a > 0 ? '$'.number_format($a,2) : '<span style="color:#e1e8f0">—</span>'; ?>
      </td>
      <?php endforeach; ?>
      <?php if (count($selected_years) >= 2):
        $last2 = array_slice($selected_years, -2);
        $prev = $ydata[$last2[0]] ?? 0;
        $curr = $ydata[$last2[1]] ?? 0;
        $d = delta_pct($prev, $curr);
      ?>
      <td>
        <?php if ($d !== null): ?>
        <span class="<?= $d > 0 ? 'delta-up' : ($d < 0 ? 'delta-down' : 'delta-flat') ?>">
          <?= $d > 0 ? '▲' : ($d < 0 ? '▼' : '—') ?> <?= abs($d) ?>%
        </span>
        <?php else: ?><span class="delta-flat">—</span><?php endif; ?>
      </td>
      <?php endif; ?>
      <td class="bar-cell" style="text-align:left;min-width:100px">
        <?php foreach ($selected_years as $i => $y):
          $a = $ydata[$y] ?? 0;
          $w = $max_event > 0 ? max(2, round($a/$max_event*80)) : 0;
          $bc = $bar_colors[$i % count($bar_colors)];
        ?>
        <div title="<?= $y ?>: $<?= number_format($a,2) ?>">
          <span class="mini-bar" style="width:<?= $w ?>px;background:<?= $bc ?>"></span>
        </div>
        <?php endforeach; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</div>

<?php admin_footer(); ?>
