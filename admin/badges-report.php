<?php
// Read-only status report for the Parents Club Badges tracker (badges.php) —
// who's paid, what stage each paid family's badge is in, and printable lists
// of what still needs to be done. No editing happens here; badges.php owns
// all writes to member_badges.
require_once __DIR__ . '/auth.php';
require_member_admin();
$pdo = get_pdo();

$year = $_GET['year'] ?? '';
if (!in_array($year, CLASS_YEAR_LIST, true)) {
    $year = ''; // '' = default view (current classes + Prep School)
}
$default_view = ($year === '');
$current_years = array_merge(current_class_years(), ['Prep School']);

$slots = badge_slots($pdo);
if ($default_view) {
    $slots = array_values(array_filter($slots, fn($s) => in_array($s['class_year'], $current_years, true)));
} else {
    $slots = array_values(array_filter($slots, fn($s) => $s['class_year'] === $year));
}

$existing = [];
$existing_rows = $pdo->query('SELECT * FROM member_badges')->fetchAll(PDO::FETCH_ASSOC);
foreach ($existing_rows as $r) $existing[$r['member_id'] . ':' . $r['parent_slot']] = $r;

// Classify every slot into exactly one stage. "Not paid" families are
// tracked separately from the three paid stages since they're not
// something the badge process needs to act on yet.
foreach ($slots as &$s) {
    $st = $existing[$s['member_id'] . ':' . $s['slot']] ?? null;
    $s['done']        = (bool)($st['done'] ?? false);
    $s['done_date']   = $st['done_date'] ?? '';
    $s['mailed']      = (bool)($st['mailed'] ?? false);
    $s['mailed_date'] = $st['mailed_date'] ?? '';
    $s['comment']     = $st['comment'] ?? '';
    if (!$s['paid'])            $s['stage'] = 'unpaid';
    elseif ($s['done'] && $s['mailed']) $s['stage'] = 'complete';
    elseif ($s['done'])         $s['stage'] = 'made';
    else                        $s['stage'] = 'not_started';
}
unset($s);

// Stage counts are per-badge (each parent's badge is tracked and can be in a
// different stage from the other parent's). Paid/not-paid, though, is a
// per-cadet attribute — both parent slots on a member always carry the same
// membership_paid value — so those are tallied by distinct member_id instead
// of by slot, or a two-parent family would double-count as two "paid families".
$counts = ['not_started' => 0, 'made' => 0, 'complete' => 0];
$by_year = [];
$paid_members = [];   // member_id => class_year
$unpaid_members = []; // member_id => class_year
$year_order = [];
foreach ($slots as $s) {
    if (!in_array($s['class_year'], $year_order, true)) $year_order[] = $s['class_year'];
    if ($s['stage'] !== 'unpaid') {
        $counts[$s['stage']]++;
        $by_year[$s['class_year']][$s['stage']] = ($by_year[$s['class_year']][$s['stage']] ?? 0) + 1;
    }
    if ($s['paid']) $paid_members[$s['member_id']] = $s['class_year'];
    else $unpaid_members[$s['member_id']] = $s['class_year'];
}
$paid_total   = count($paid_members);
$unpaid_total = count($unpaid_members);
$paid_by_year   = array_count_values($paid_members);
$unpaid_by_year = array_count_values($unpaid_members);

admin_header('Badges Status Report');
?>
<style>
@media print {
  .no-print{display:none!important}
  body{background:#fff!important;font-size:11pt}
  .card{box-shadow:none!important;border:1px solid #ccc}
  .main{max-width:100%!important;margin:0!important;padding:0!important}
  h1{font-size:1.2rem}
}
.rep-table{width:100%;border-collapse:collapse;font-size:.85rem}
.rep-table th{padding:.5rem .75rem;font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#5a6a7a;background:#f7f9fc;text-align:left;white-space:nowrap}
.rep-table td{padding:.5rem .75rem;border-top:1px solid #f0f2f5}
.rep-table td.num{text-align:right}
</style>

<div class="page-head no-print">
  <h1>Badges Status Report</h1>
  <div style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap">
    <form method="GET" style="margin:0;display:flex;gap:.5rem;align-items:center">
      <label style="font-size:.82rem;color:#5a6a7a">Class:</label>
      <select name="year" onchange="this.form.submit()" style="padding:.4rem .7rem;border:1px solid #d0d5dd;border-radius:4px;font-size:.85rem">
        <option value="" <?= $default_view ? 'selected' : '' ?>>All classes (<?= h(implode(', ', $current_years)) ?>)</option>
        <?php foreach (CLASS_YEAR_LIST as $y): if ($y === '' || $y === 'Graduate') continue; ?>
          <option value="<?= h($y) ?>" <?= $year === $y ? 'selected' : '' ?>><?= h($y) ?></option>
        <?php endforeach; ?>
      </select>
    </form>
    <button onclick="window.print()" class="btn btn-secondary">🖨️ Print / PDF</button>
    <a href="badges.php" class="btn btn-secondary">← Badges</a>
  </div>
</div>

<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:.75rem;margin-bottom:1.5rem">
  <div class="card" style="padding:1rem;text-align:center;margin:0">
    <div style="font-size:1.5rem;font-weight:700;color:#002554"><?= $paid_total ?></div>
    <div style="font-size:.72rem;color:#5a6a7a;text-transform:uppercase">Paid Families</div>
  </div>
  <div class="card" style="padding:1rem;text-align:center;margin:0;border:2px solid #1b5e20">
    <div style="font-size:1.5rem;font-weight:700;color:#1b5e20"><?= $counts['complete'] ?></div>
    <div style="font-size:.72rem;color:#5a6a7a;text-transform:uppercase">Complete</div>
  </div>
  <div class="card" style="padding:1rem;text-align:center;margin:0;border:2px solid #f57f17">
    <div style="font-size:1.5rem;font-weight:700;color:#f57f17"><?= $counts['made'] ?></div>
    <div style="font-size:.72rem;color:#5a6a7a;text-transform:uppercase">Made, Not Delivered</div>
  </div>
  <div class="card" style="padding:1rem;text-align:center;margin:0;border:2px solid #A6192E">
    <div style="font-size:1.5rem;font-weight:700;color:#A6192E"><?= $counts['not_started'] ?></div>
    <div style="font-size:.72rem;color:#5a6a7a;text-transform:uppercase">Not Started</div>
  </div>
  <div class="card" style="padding:1rem;text-align:center;margin:0">
    <div style="font-size:1.5rem;font-weight:700;color:#9aa5b4"><?= $unpaid_total ?></div>
    <div style="font-size:.72rem;color:#5a6a7a;text-transform:uppercase">Not Paid</div>
  </div>
</div>

<div class="card" style="margin-bottom:1.5rem">
  <h2 style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#5a6a7a;margin-bottom:.9rem">By Class Year</h2>
  <div style="overflow-x:auto">
  <table class="rep-table">
    <thead>
      <tr>
        <th>Class Year</th>
        <th class="num">Paid Families</th>
        <th class="num">Complete</th>
        <th class="num">Made, Not Delivered</th>
        <th class="num">Not Started</th>
        <th class="num">Not Paid</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($year_order as $gyear):
          $c = ($by_year[$gyear] ?? []) + ['not_started' => 0, 'made' => 0, 'complete' => 0];
      ?>
      <tr>
        <td><?= h($gyear) ?></td>
        <td class="num"><?= $paid_by_year[$gyear] ?? 0 ?></td>
        <td class="num" style="color:#1b5e20;font-weight:700"><?= $c['complete'] ?></td>
        <td class="num" style="color:#f57f17;font-weight:700"><?= $c['made'] ?></td>
        <td class="num" style="color:#A6192E;font-weight:700"><?= $c['not_started'] ?></td>
        <td class="num" style="color:#9aa5b4"><?= $unpaid_by_year[$gyear] ?? 0 ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>

<?php
// One printable table per actionable stage — "Not Started" and "Made, Not
// Delivered" are what the maintainer needs to act on; "Complete" is a
// confirmation list rather than a to-do, but still useful to have on hand.
$sections = [
    'not_started' => ['title' => 'Needs a Badge Made', 'color' => '#A6192E', 'date_col' => null],
    'made'        => ['title' => 'Made — Needs Delivery', 'color' => '#f57f17', 'date_col' => 'done_date'],
    'complete'    => ['title' => 'Complete', 'color' => '#1b5e20', 'date_col' => 'mailed_date'],
];
foreach ($sections as $stage => $info):
    $rows = array_values(array_filter($slots, fn($s) => $s['stage'] === $stage));
?>
<div class="card" style="margin-bottom:1.5rem;border-left:4px solid <?= $info['color'] ?>">
  <h2 style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:<?= $info['color'] ?>;margin-bottom:.9rem">
    <?= h($info['title']) ?> (<?= count($rows) ?>)
  </h2>
  <?php if (empty($rows)): ?>
    <p style="color:#9aa5b4;font-size:.85rem">None.</p>
  <?php else: ?>
  <div style="overflow-x:auto">
  <table class="rep-table">
    <thead>
      <tr>
        <th>Class</th><th>Cadet</th><th>Parent</th><th>City</th>
        <?php if ($info['date_col']): ?><th><?= $info['date_col'] === 'done_date' ? 'Done Date' : 'Delivered Date' ?></th><?php endif; ?>
        <th>Comment</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $s): ?>
      <tr>
        <td><?= h($s['class_year']) ?></td>
        <td><?= h($s['cadet']) ?></td>
        <td><?= h($s['name']) ?></td>
        <td style="color:#5a6a7a;font-size:.8rem"><?= h($s['city'] ?? '') ?></td>
        <?php if ($info['date_col']): ?><td><?= h($s[$info['date_col']] ?: '') ?></td><?php endif; ?>
        <td style="color:#5a6a7a;font-size:.8rem"><?= h($s['comment']) ?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</div>
<?php endforeach; ?>

<?php admin_footer(); ?>
