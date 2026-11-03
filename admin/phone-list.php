<?php
// Printable phone list for officers — cadet and/or parent cell numbers,
// filtered by class year, dues status and region; print or export to CSV.
// GET-only (no CSRF) and read-only, like birthdays.php.
require_once __DIR__ . '/auth.php';
require_member_admin();
$pdo = get_pdo();

$all_years = CLASS_YEAR_LIST;
$year_raw  = $_GET['year'] ?? 'current';
$year      = ($year_raw === 'current' || in_array($year_raw, $all_years, true)) ? $year_raw : '';
$paid      = in_array($_GET['paid'] ?? '', ['1', '0'], true) ? $_GET['paid'] : '';
$region    = in_array($_GET['region'] ?? '', ['North', 'Central', 'South'], true) ? $_GET['region'] : '';

// Which numbers to show. Nothing ticked (or first visit) means all three.
$who_labels = ['cadet' => 'Cadet', 'p1' => 'Parent 1', 'p2' => 'Parent 2'];
$show = array_values(array_intersect(array_keys($who_labels), (array)($_GET['show'] ?? [])));
if (!$show) $show = array_keys($who_labels);

$where  = ['archived = 0'];
$params = [];
if ($year === 'current') {
    $ph = [];
    foreach (array_merge(current_class_years(), ['USMAPS']) as $i => $y) { $ph[] = ":yr$i"; $params[":yr$i"] = $y; }
    $where[] = 'class_year IN (' . implode(', ', $ph) . ')';
} elseif ($year !== '') {
    $where[] = 'class_year = :year'; $params[':year'] = $year;
}
if ($paid !== '')   { $where[] = 'membership_paid = :paid'; $params[':paid']   = (int)$paid; }
if ($region !== '') { $where[] = 'al_region = :region';     $params[':region'] = $region; }

$stmt = $pdo->prepare(
    'SELECT id, cadet_last_name, cadet_suffix, cadet_first_name, cadet_middle_name, cadet_cell, class_year,
            parent1_first_name, parent1_last_name, parent1_cell,
            parent2_first_name, parent2_last_name, parent2_cell
     FROM members WHERE ' . implode(' AND ', $where) . '
     ORDER BY class_year, cadet_last_name, cadet_first_name'
);
$stmt->execute($params);
$rows = $stmt->fetchAll();

// [name, cell] for each ticked person in a row.
$people = function (array $r) use ($show): array {
    $out = [];
    foreach ($show as $w) {
        if ($w === 'cadet') $out[$w] = [trim($r['cadet_first_name'] . ' ' . cadet_last_name_suffixed($r)), (string)$r['cadet_cell']];
        else {
            $p = $w === 'p1' ? 'parent1' : 'parent2';
            $out[$w] = [trim($r[$p . '_first_name'] . ' ' . $r[$p . '_last_name']), (string)$r[$p . '_cell']];
        }
    }
    return $out;
};

// Every number in the list, once each, for the copy box (group texts).
$all_cells = [];
foreach ($rows as $r) {
    foreach ($people($r) as [, $cell]) if ($cell !== '') $all_cells[$cell] = true;
}
$all_cells = array_keys($all_cells);

$year_label = $year === 'current' ? 'Current classes' : ($year === '' ? 'All classes' : 'Class of ' . $year);
$who_label  = implode(', ', array_map(fn($w) => $who_labels[$w], $show));

if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="phone-list-' . preg_replace('/\W+/', '-', strtolower($year_label)) . '.csv"');
    $out = fopen('php://output', 'w');
    $head = ['Class', 'Cadet'];
    foreach ($show as $w) {
        if ($w !== 'cadet') $head[] = $who_labels[$w];
        $head[] = $who_labels[$w] . ' Cell';
    }
    fputcsv($out, $head);
    foreach ($rows as $r) {
        $line = [$r['class_year'], cadet_full_name($r)];
        foreach ($people($r) as $w => [$name, $cell]) {
            if ($w !== 'cadet') $line[] = $name;
            $line[] = $cell;
        }
        fputcsv($out, array_map(fn($v) => csv_formula_safe((string)$v), $line));
    }
    fclose($out);
    exit;
}

$qs = fn(array $extra = []) => http_build_query(array_filter(
    ['year' => $year, 'paid' => $paid, 'region' => $region, 'show' => $show] + $extra, fn($v) => $v !== ''));
$cols = 1 + count($show) + count(array_diff($show, ['cadet'])); // cadet name + a cell per person + parent names

admin_header('Phone List');
?>
<style>
.filter-bar .form-group{max-width:190px}
/* Styled like the selects beside it so the row lines up */
.filter-bar .who-group{flex:0 0 auto;max-width:none}
.who-checks{display:flex;align-items:center;gap:1rem;height:calc(.9rem * 1.15 + 1.1rem + 2px);min-height:38px;padding:0 .85rem;border:1px solid #d0d5dd;border-radius:4px;background:#fff}
.who-checks label{display:flex;align-items:center;gap:.35rem;margin:0;white-space:nowrap;font-weight:400;text-transform:none;letter-spacing:0;font-size:.9rem;color:#1a2332;cursor:pointer}
.who-checks input{width:auto;margin:0;padding:0;accent-color:#000}
.phone-class{background:#f5f5f0;font-family:'Cinzel',serif;font-weight:700;color:#000;font-size:.85rem;letter-spacing:.04em}
.phone-none{color:#b0b6bd}
.copy-card{margin-bottom:1rem}
.copy-card textarea{font-size:.9rem;line-height:1.5;resize:vertical;background:#fafaf7}
.print-date{display:none}
@media print{
  @page{margin:.5in}
  .topbar,.no-print,.filter-bar{display:none!important}
  .print-date{display:inline}
  .card{box-shadow:none;border:1px solid #ccc}
  table{font-size:9.5pt}
  th,td{padding:.2rem .45rem!important}
  tr{break-inside:avoid}
  thead{display:table-header-group}
  a{color:#111!important;text-decoration:none}
}
</style>

<div class="page-head">
  <div>
    <h1>📞 Phone List</h1>
    <div style="color:#5a6a7a;font-size:.85rem"><?= h($year_label) ?> · <?= h($who_label) ?><?= $paid === '1' ? ' · Paid members' : ($paid === '0' ? ' · Unpaid members' : '') ?><?= $region ? ' · ' . h($region) . ' region' : '' ?> — <?= count($rows) ?> famil<?= count($rows) === 1 ? 'y' : 'ies' ?><span class="print-date"> · Printed <?= h(date('M j, Y')) ?></span></div>
  </div>
  <div class="no-print" style="display:flex;gap:.5rem">
    <button type="button" class="btn btn-primary" onclick="window.print()">Print / Save PDF</button>
    <a class="btn btn-secondary" href="phone-list.php?<?= h($qs(['export' => 'csv'])) ?>">Export CSV</a>
  </div>
</div>

<form method="GET" class="filter-bar">
  <div class="form-group">
    <label>Class Year</label>
    <select name="year">
      <option value="current" <?= $year === 'current' ? 'selected' : '' ?>>Current classes</option>
      <option value="" <?= $year === '' ? 'selected' : '' ?>>All years</option>
      <?php foreach ($all_years as $y): ?>
        <option value="<?= h($y) ?>" <?= $year === $y ? 'selected' : '' ?>><?= h($y) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="form-group">
    <label>Dues Status</label>
    <select name="paid">
      <option value="">All members</option>
      <option value="1" <?= $paid === '1' ? 'selected' : '' ?>>Paid</option>
      <option value="0" <?= $paid === '0' ? 'selected' : '' ?>>Not paid</option>
    </select>
  </div>
  <div class="form-group">
    <label>AL Region</label>
    <select name="region">
      <option value="">All regions</option>
      <?php foreach (['North', 'Central', 'South'] as $r): ?>
        <option value="<?= h($r) ?>" <?= $region === $r ? 'selected' : '' ?>><?= h($r) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="form-group who-group">
    <label>Include</label>
    <div class="who-checks">
      <?php foreach ($who_labels as $w => $label): ?>
        <label><input type="checkbox" name="show[]" value="<?= $w ?>" <?= in_array($w, $show, true) ? 'checked' : '' ?>> <?= h($label) ?></label>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="form-group" style="flex:0;min-width:170px">
    <label>&nbsp;</label>
    <div style="display:flex;gap:.5rem;flex-wrap:nowrap">
      <button type="submit" class="btn btn-primary">Filter</button>
      <a href="phone-list.php" class="btn btn-secondary">Reset</a>
    </div>
  </div>
</form>

<?php if ($all_cells): ?>
<div class="card no-print copy-card">
  <div style="display:flex;justify-content:space-between;align-items:center;gap:.75rem;flex-wrap:wrap;margin-bottom:.5rem">
    <div><strong>All numbers</strong> <span style="color:#5a6a7a;font-size:.85rem">— <?= count($all_cells) ?> number<?= count($all_cells) === 1 ? '' : 's' ?>. Tap the box to select them all, or use Copy, then paste into a group text.</span></div>
    <button type="button" class="btn btn-primary" id="copy-btn" onclick="copyNumbers()">Copy</button>
  </div>
  <textarea id="copy-numbers" readonly rows="3" onfocus="this.select()" onclick="this.setSelectionRange(0, this.value.length)"><?= h(implode(', ', $all_cells)) ?></textarea>
</div>
<script>
function copyNumbers() {
  var box = document.getElementById('copy-numbers'), btn = document.getElementById('copy-btn');
  var done = function () { btn.textContent = 'Copied ✓'; setTimeout(function () { btn.textContent = 'Copy'; }, 2000); };
  if (navigator.clipboard && window.isSecureContext) {
    navigator.clipboard.writeText(box.value).then(done, function () { box.focus(); box.setSelectionRange(0, box.value.length); document.execCommand('copy'); done(); });
  } else {
    box.focus(); box.setSelectionRange(0, box.value.length); document.execCommand('copy'); done();
  }
}
</script>
<?php endif; ?>

<div class="card" style="padding:0;overflow:auto">
<table>
  <thead>
    <tr>
      <th>Cadet</th>
      <?php foreach ($show as $w): ?>
        <?php if ($w !== 'cadet'): ?><th><?= h($who_labels[$w]) ?></th><?php endif; ?>
        <th><?= h($who_labels[$w]) ?> Cell</th>
      <?php endforeach; ?>
    </tr>
  </thead>
  <tbody>
  <?php if (empty($rows)): ?>
    <tr><td colspan="<?= $cols ?>" style="text-align:center;padding:2rem;color:#5a6a7a">No cadets match these filters.</td></tr>
  <?php endif; ?>
  <?php $last_class = null; foreach ($rows as $r): ?>
    <?php if ($r['class_year'] !== $last_class): $last_class = $r['class_year']; ?>
    <tr><td colspan="<?= $cols ?>" class="phone-class"><?= h(ctype_digit((string)$last_class) ? 'Class of ' . $last_class : $last_class) ?></td></tr>
    <?php endif; ?>
    <tr>
      <td><a href="view.php?id=<?= (int)$r['id'] ?>" style="font-weight:700;color:#000"><?= h(cadet_last_name_suffixed($r)) ?></a><?php $fm = trim($r['cadet_first_name'] . ' ' . $r['cadet_middle_name']); ?><?= $fm ? ', ' . h($fm) : '' ?></td>
      <?php foreach ($people($r) as $w => [$name, $cell]): ?>
        <?php if ($w !== 'cadet'): ?><td><?= $name !== '' ? h($name) : '<span class="phone-none">—</span>' ?></td><?php endif; ?>
        <td style="white-space:nowrap"><?= $cell !== '' ? '<a href="tel:' . h(preg_replace('/\D/', '', $cell)) . '" style="color:inherit">' . h($cell) . '</a>' : '<span class="phone-none">—</span>' ?></td>
      <?php endforeach; ?>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<p class="no-print" style="font-size:.8rem;color:#5a6a7a;margin-top:.75rem">Archived members are not listed. A dash means no number (or no second parent) is on file. Phone numbers are private — don't share this list outside the club's officers.</p>

<?php admin_footer(); ?>
