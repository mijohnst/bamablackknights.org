<?php
require_once __DIR__ . '/auth.php';
require_finance();
if (!is_treasurer() && !is_super_admin()) { header('Location: dashboard.php?denied=1'); exit; }
$pdo = get_pdo();

$year = (int)($_GET['year'] ?? date('Y'));
if ($year < 2020 || $year > 2100) $year = (int)date('Y'); // sanity clamp
$years_avail = $pdo->query("SELECT DISTINCT YEAR(purchase_date) y FROM purchases ORDER BY y DESC")->fetchAll(PDO::FETCH_COLUMN);
if (empty($years_avail)) $years_avail = [date('Y')];

// All purchases for selected year
$stmt = $pdo->prepare("SELECT * FROM purchases WHERE YEAR(purchase_date) = ? ORDER BY purchase_date");
$stmt->execute([$year]);
$all = $stmt->fetchAll();

// Totals
$total    = array_sum(array_column($all, 'amount_total'));
$by_cat   = []; $by_event = []; $by_month = array_fill(1,12,0); $by_status = [];
foreach ($all as $p) {
    $cat   = $p['category'] ?: 'Uncategorised';
    $ev    = $p['event']    ?: 'General';
    $mo    = (int)date('n', strtotime($p['purchase_date']));
    $by_cat[$cat]              = ($by_cat[$cat]   ?? 0) + $p['amount_total'];
    $by_event[$ev]             = ($by_event[$ev]  ?? 0) + $p['amount_total'];
    $by_month[$mo]             += $p['amount_total'];
    $by_status[$p['status']]   = ($by_status[$p['status']] ?? 0) + $p['amount_total'];
}
arsort($by_cat); arsort($by_event);
$max_month = max($by_month) ?: 1;

// Budgets
$budgets = [];
$bstmt = $pdo->prepare("SELECT event, budget FROM event_budgets WHERE fiscal_year = '' OR fiscal_year = ?");
$bstmt->execute([(string)$year]);
$brows = $bstmt->fetchAll();
foreach ($brows as $b) $budgets[$b['event']] = (float)$b['budget'];

$months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
$status_colors = ['pending'=>'#f57c00','approved'=>'#1b5e20','submitted'=>'#6a1b9a','paid'=>'#003594'];

admin_header('Finance Report');
?>
<style>
.rep-grid{display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;margin-bottom:1.5rem}
.rep-card{background:#fff;border-radius:6px;box-shadow:0 1px 4px rgba(0,0,0,.1);padding:1.25rem}
.rep-card h2{font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#5a6a7a;margin-bottom:1rem}
.bar-row{display:flex;align-items:center;gap:.75rem;margin-bottom:.55rem;font-size:.82rem}
.bar-label{width:160px;flex-shrink:0;color:#333;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.bar-track{flex:1;background:#f0f2f5;border-radius:99px;height:16px;overflow:hidden;position:relative}
.bar-fill{height:100%;border-radius:99px;background:#003594;transition:width .4s}
.bar-fill.over{background:#A6192E}
.bar-amount{width:70px;text-align:right;font-weight:700;color:#002554;flex-shrink:0}
.bar-budget{font-size:.72rem;color:#9aa5b4;flex-shrink:0}
.month-bar{display:flex;align-items:flex-end;gap:4px;height:80px}
.mb-col{display:flex;flex-direction:column;align-items:center;gap:3px;flex:1}
.mb-bar{width:100%;background:#003594;border-radius:3px 3px 0 0;min-height:2px}
.mb-label{font-size:.65rem;color:#9aa5b4}
@media(max-width:700px){.rep-grid{grid-template-columns:1fr}}
</style>

<div class="page-head">
  <h1>Finance Report</h1>
  <div style="display:flex;gap:.5rem;align-items:center">
    <form method="GET" style="display:flex;gap:.5rem;align-items:center;margin:0">
      <select name="year" onchange="this.form.submit()" style="padding:.45rem .75rem;border:1px solid #d0d5dd;border-radius:4px;font-size:.9rem">
        <?php foreach ($years_avail as $y): ?>
          <option value="<?= $y ?>" <?= $y==$year?'selected':''?>><?= $y ?></option>
        <?php endforeach; ?>
      </select>
    </form>
    <a href="purchases.php" class="btn btn-secondary">← Finance</a>
  </div>
</div>

<!-- Summary cards -->
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:.75rem;margin-bottom:1.5rem">
  <div class="card" style="padding:1rem;text-align:center;margin:0">
    <div style="font-size:1.5rem;font-weight:700;color:#002554"><?= count($all) ?></div>
    <div style="font-size:.72rem;color:#5a6a7a;text-transform:uppercase">Purchases</div>
  </div>
  <div class="card" style="padding:1rem;text-align:center;margin:0;border:2px solid #A6192E">
    <div style="font-size:1.5rem;font-weight:700;color:#A6192E">$<?= number_format($total,2) ?></div>
    <div style="font-size:.72rem;color:#5a6a7a;text-transform:uppercase">Total Spent</div>
  </div>
  <?php foreach ($by_status as $st => $amt): ?>
  <div class="card" style="padding:1rem;text-align:center;margin:0">
    <div style="font-size:1.5rem;font-weight:700;color:<?= $status_colors[$st] ?? '#5a6a7a' ?>">$<?= number_format($amt,2) ?></div>
    <div style="font-size:.72rem;color:#5a6a7a;text-transform:uppercase"><?= ucfirst($st) ?></div>
  </div>
  <?php endforeach; ?>
</div>

<div class="rep-grid">

  <!-- By Category -->
  <div class="rep-card">
    <h2>Spending by Category</h2>
    <?php foreach ($by_cat as $cat => $amt):
      $pct = $total > 0 ? ($amt/$total*100) : 0;
    ?>
    <div class="bar-row">
      <div class="bar-label" title="<?= h($cat) ?>"><?= h($cat) ?></div>
      <div class="bar-track">
        <div class="bar-fill" style="width:<?= min(100,round($pct)) ?>%"></div>
      </div>
      <div class="bar-amount">$<?= number_format($amt,0) ?></div>
    </div>
    <?php endforeach; ?>
    <?php if (empty($by_cat)): ?><p style="color:#9aa5b4;font-size:.85rem">No data.</p><?php endif; ?>
  </div>

  <!-- By Event with budget -->
  <div class="rep-card">
    <h2>Spending by Event</h2>
    <?php foreach ($by_event as $ev => $amt):
      $budget = $budgets[$ev] ?? 0;
      $pct    = $budget > 0 ? min(100, ($amt/$budget*100)) : 50;
      $over   = $budget > 0 && $amt > $budget;
    ?>
    <div class="bar-row">
      <div class="bar-label" title="<?= h($ev) ?>"><?= h($ev) ?></div>
      <div class="bar-track">
        <div class="bar-fill <?= $over?'over':'' ?>" style="width:<?= round($pct) ?>%"></div>
      </div>
      <div class="bar-amount" style="<?= $over?'color:#A6192E':'' ?>">$<?= number_format($amt,0) ?></div>
      <?php if ($budget > 0): ?>
      <div class="bar-budget">/ $<?= number_format($budget,0) ?> <?= $over ? '⚠️' : '' ?></div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
    <?php if (empty($by_event)): ?><p style="color:#9aa5b4;font-size:.85rem">No data.</p><?php endif; ?>
  </div>

  <!-- Monthly breakdown -->
  <div class="rep-card" style="grid-column:1/-1">
    <h2>Monthly Spending — <?= $year ?></h2>
    <div class="month-bar">
      <?php for ($m=1; $m<=12; $m++):
        $h = $by_month[$m] > 0 ? max(4, round($by_month[$m]/$max_month*72)) : 0;
      ?>
      <div class="mb-col">
        <div style="font-size:.65rem;color:#002554;font-weight:700"><?= $by_month[$m]>0 ? '$'.number_format($by_month[$m],0) : '' ?></div>
        <div class="mb-bar" style="height:<?= $h ?>px;background:<?= $by_month[$m]>0?'#003594':'#e1e5eb' ?>"></div>
        <div class="mb-label"><?= $months[$m-1] ?></div>
      </div>
      <?php endfor; ?>
    </div>
  </div>

</div>

<div style="display:flex;gap:.6rem;flex-wrap:wrap;margin-top:1rem">
  <a href="receipts-by.php?by=event&year=<?= $year ?>" class="btn btn-secondary btn-sm">🗂️ Receipts by Event</a>
  <a href="receipts-by.php?by=vendor&year=<?= $year ?>" class="btn btn-secondary btn-sm">🗂️ Receipts by Vendor</a>
</div>

<?php admin_footer(); ?>
