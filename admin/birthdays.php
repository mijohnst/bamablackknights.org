<?php
// Cadet birthday list for officers — filter by birth month (or the next
// 30 days), class year, dues status and region; print or export to CSV.
// The monthly cron email (birthday-report.php) and the automated birthday
// emails (automated-emails.php) are separate; this page only reads.
require_once __DIR__ . '/auth.php';
require_member_admin();
$pdo = get_pdo();

$all_years = CLASS_YEAR_LIST;
$month_raw = $_GET['month'] ?? (string)date('n');
$month     = ($month_raw === 'all' || $month_raw === 'next30') ? $month_raw
           : (((int)$month_raw >= 1 && (int)$month_raw <= 12) ? (string)(int)$month_raw : (string)date('n'));
$year      = in_array($_GET['year'] ?? '', $all_years, true) ? $_GET['year'] : '';
$paid      = in_array($_GET['paid'] ?? '', ['1', '0'], true) ? $_GET['paid'] : '';
$region    = in_array($_GET['region'] ?? '', ['North', 'Central', 'South'], true) ? $_GET['region'] : '';

$where  = ["archived = 0", "cadet_birthday IS NOT NULL", "cadet_birthday != ''"];
$params = [];
if (ctype_digit($month)) { $where[] = 'MONTH(cadet_birthday) = :month'; $params[':month'] = (int)$month; }
if ($year !== '')        { $where[] = 'class_year = :year';           $params[':year']   = $year; }
if ($paid !== '')        { $where[] = 'membership_paid = :paid';      $params[':paid']   = (int)$paid; }
if ($region !== '')      { $where[] = 'al_region = :region';          $params[':region'] = $region; }

$stmt = $pdo->prepare(
    'SELECT id, cadet_last_name, cadet_suffix, cadet_first_name, cadet_middle_name, cadet_birthday,
            cadet_po_box, class_year, company, al_region, membership_paid,
            parent1_first_name, parent1_last_name, parent1_email
     FROM members WHERE ' . implode(' AND ', $where)
);
$stmt->execute($params);

$today = new DateTime('today');
$rows  = [];
foreach ($stmt->fetchAll() as $r) {
    try { $bday = new DateTime($r['cadet_birthday']); } catch (Exception $e) { continue; }
    $md = $bday->format('m-d');
    // Feb 29 birthdays fall on Feb 28 in non-leap years.
    $next_of = function (int $y) use ($md): DateTime {
        if ($md === '02-29' && !checkdate(2, 29, $y)) $md = '02-28';
        return new DateTime("$y-$md");
    };
    $next = $next_of((int)$today->format('Y'));
    if ($next < $today) $next = $next_of((int)$today->format('Y') + 1);
    $days = (int)$today->diff($next)->days;
    if ($month === 'next30' && $days > 30) continue;

    $r['_bday'] = $bday;
    $r['_md']   = $md;
    $r['_days'] = $days;
    $r['_age']  = (int)$next->format('Y') - (int)$bday->format('Y');
    $rows[] = $r;
}
usort($rows, $month === 'next30'
    ? fn($a, $b) => $a['_days'] <=> $b['_days'] ?: strcasecmp($a['cadet_last_name'], $b['cadet_last_name'])
    : fn($a, $b) => strcmp($a['_md'], $b['_md']) ?: strcasecmp($a['cadet_last_name'], $b['cadet_last_name']));

$month_label = $month === 'all' ? 'All Months' : ($month === 'next30' ? 'Next 30 Days'
             : DateTime::createFromFormat('!n', $month)->format('F'));

if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="cadet-birthdays-' . preg_replace('/\W+/', '-', strtolower($month_label)) . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Birthday', 'Turning', 'Cadet', 'Class', 'Company', 'PO Box', 'Region', 'Dues', 'Parent 1', 'Parent 1 Email']);
    foreach ($rows as $r) {
        fputcsv($out, array_map(fn($v) => csv_formula_safe((string)$v), [
            $r['_bday']->format('F j'), $r['_age'], cadet_full_name($r), $r['class_year'], $r['company'],
            $r['cadet_po_box'], $r['al_region'], $r['membership_paid'] ? 'Paid' : 'Unpaid',
            trim($r['parent1_first_name'] . ' ' . $r['parent1_last_name']), $r['parent1_email'],
        ]));
    }
    fclose($out);
    exit;
}

$qs = fn(array $extra = []) => http_build_query(array_filter(
    ['month' => $month, 'year' => $year, 'paid' => $paid, 'region' => $region] + $extra, fn($v) => $v !== ''));

admin_header('Cadet Birthdays');
?>
<style>
.filter-bar .form-group{max-width:190px}
.bday-month{background:#f5f5f0;font-family:'Cinzel',serif;font-weight:700;color:#000;font-size:.85rem;letter-spacing:.04em}
.bday-soon{color:#f57c00;font-weight:700}
.bday-today{color:#A6192E;font-weight:700}
@media print{
  .topbar,.no-print,.filter-bar{display:none!important}
  .card{box-shadow:none;border:1px solid #ccc}
  a{color:#111!important;text-decoration:none}
}
</style>

<div class="page-head">
  <div>
    <h1>🎂 Cadet Birthdays</h1>
    <div style="color:#5a6a7a;font-size:.85rem"><?= h($month_label) ?><?= $year ? ' · Class of ' . h($year) : '' ?><?= $paid === '1' ? ' · Paid members' : ($paid === '0' ? ' · Unpaid members' : '') ?><?= $region ? ' · ' . h($region) . ' region' : '' ?> — <?= count($rows) ?> cadet<?= count($rows) === 1 ? '' : 's' ?></div>
  </div>
  <div class="no-print" style="display:flex;gap:.5rem">
    <button type="button" class="btn btn-primary" onclick="window.print()">Print</button>
    <a class="btn btn-secondary" href="birthdays.php?<?= h($qs(['export' => 'csv'])) ?>">Export CSV</a>
  </div>
</div>

<form method="GET" class="filter-bar">
  <div class="form-group">
    <label>Birth Month</label>
    <select name="month">
      <option value="next30" <?= $month === 'next30' ? 'selected' : '' ?>>Next 30 days</option>
      <option value="all" <?= $month === 'all' ? 'selected' : '' ?>>All months</option>
      <?php for ($m = 1; $m <= 12; $m++): ?>
        <option value="<?= $m ?>" <?= $month === (string)$m ? 'selected' : '' ?>><?= DateTime::createFromFormat('!n', (string)$m)->format('F') ?></option>
      <?php endfor; ?>
    </select>
  </div>
  <div class="form-group">
    <label>Class Year</label>
    <select name="year">
      <option value="">All years</option>
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
  <div class="form-group" style="flex:0;min-width:170px">
    <label>&nbsp;</label>
    <div style="display:flex;gap:.5rem;flex-wrap:nowrap">
      <button type="submit" class="btn btn-primary">Filter</button>
      <a href="birthdays.php" class="btn btn-secondary">Reset</a>
    </div>
  </div>
</form>

<div class="card" style="padding:0;overflow:auto">
<table>
  <thead>
    <tr>
      <th>Birthday</th>
      <th>Turning</th>
      <th>Cadet</th>
      <th>Class</th>
      <th>Company</th>
      <th>PO Box</th>
      <th>Region</th>
      <th>Dues</th>
    </tr>
  </thead>
  <tbody>
  <?php if (empty($rows)): ?>
    <tr><td colspan="8" style="text-align:center;padding:2rem;color:#5a6a7a">No cadet birthdays match these filters.</td></tr>
  <?php endif; ?>
  <?php $last_month = null; foreach ($rows as $r): ?>
    <?php $m_name = $r['_bday']->format('F'); ?>
    <?php if ($month === 'all' && $m_name !== $last_month): $last_month = $m_name; ?>
    <tr><td colspan="8" class="bday-month"><?= h($m_name) ?></td></tr>
    <?php endif; ?>
    <tr>
      <td style="white-space:nowrap">
        <?= h($r['_bday']->format('M j')) ?>
        <?php if ($r['_days'] === 0): ?><span class="bday-today"> · 🎉 Today</span>
        <?php elseif ($r['_days'] <= 7): ?><span class="bday-soon"> · in <?= $r['_days'] ?>d</span><?php endif; ?>
      </td>
      <td><?= $r['_age'] ?></td>
      <td><a href="view.php?id=<?= (int)$r['id'] ?>" style="font-weight:700;color:#000"><?= h(cadet_last_name_suffixed($r)) ?></a><?php $fm = trim($r['cadet_first_name'] . ' ' . $r['cadet_middle_name']); ?><?= $fm ? ', ' . h($fm) : '' ?></td>
      <td><?= h($r['class_year']) ?></td>
      <td><?= h($r['company'] ?? '') ?></td>
      <td><?= h($r['cadet_po_box'] ?? '') ?></td>
      <td><?php if ($r['al_region']): ?><span class="badge badge-<?= h($r['al_region']) ?>"><?= h($r['al_region']) ?></span><?php endif; ?></td>
      <td><?= $r['membership_paid'] ? '<span class="badge badge-paid">✓ Paid</span>' : '<span class="badge badge-unpaid">✗ Unpaid</span>' ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<p class="no-print" style="font-size:.8rem;color:#5a6a7a;margin-top:.75rem">Mail to cadets: Cadet [Name], PO Box [number], West Point, NY 10997. Archived members and cadets with no birthday on file are not listed.</p>

<?php admin_footer(); ?>
