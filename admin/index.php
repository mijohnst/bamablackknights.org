<?php
require_once __DIR__ . '/auth.php';
require_login();
// This page shows un-redacted contact info (parent emails/cells, dues
// status) for every member regardless of directory_consent — that consent
// field is only honored by directory.php, the member-safe printable
// roster. Gate here to whoever may see that PII (see can_view_member_pii()).
if (!can_view_member_pii()) { header('Location: dashboard.php?denied=1'); exit; }
$pdo = get_pdo();

// ── Filters ────────────────────────────────────────────────────────────────
$search  = trim($_GET['q']       ?? '');
// Accepts either the new multi-select year[]=... or an old bookmarked
// single year=... link — both land here as an array either way. A
// completely bare load (no query string at all — e.g. clicking "Members"
// in the nav) defaults to current cadets + Prep School rather than every
// graduate ever entered into the system. Any query string at all —
// including other pages' links like index.php?dup=1, ?split=1, or
// income.php's ?year=2024 — is treated as an explicit request and left
// alone, so those existing links keep working exactly as before.
if (empty($_GET)) {
    $years = array_merge(current_class_years(), ['Prep School']);
} else {
    $years = array_values(array_filter((array)($_GET['year'] ?? [])));
}
$region  = $_GET['region']       ?? '';
$paid    = $_GET['paid']         ?? '';
$squadron  = trim($_GET['squadron']  ?? '');
$split_only = isset($_GET['split']);
$dup_only   = isset($_GET['dup']);
$archived  = $_GET['archived']   ?? '0';
$sort      = $_GET['sort']       ?? 'class_year';
$dir       = $_GET['dir']        ?? 'asc';

$allowed_sorts = ['class_year','cadet_last_name','al_region','membership_paid'];
if (!in_array($sort, $allowed_sorts)) $sort = 'class_year';
if (!in_array($dir, ['asc','desc']))  $dir  = 'asc';
$next_dir = $dir === 'asc' ? 'desc' : 'asc';

// Possible-duplicate detection — same last name + class year among members
// in the archived state currently being viewed (active by default). Compared
// in PHP against a normalized last name (punctuation and whitespace stripped)
// rather than SQL `=`, so "Jimmerson, Jr" and "Jimmerson, Jr." are still
// caught as likely the same family — the same gap that let that exact pair
// slip through the public application form's dedup check. Scoped to match
// $archived (rather than hardcoded to active-only) so the DUP? badge and the
// ?dup=1 filter both still work when browsing the archived list, and so
// ?dup=1&archived=1 isn't a guaranteed-empty contradiction. Computed once
// here and reused by the ?dup=1 filter, the per-row "DUP?" badge, and the
// alert count below.
$dup_ids = []; // member id => true, for every member that's part of a group
$dup_groups = [];
$dup_scan = $pdo->prepare('SELECT id, cadet_last_name, class_year FROM members WHERE archived = ?');
$dup_scan->execute([$archived === '1' ? 1 : 0]);
foreach ($dup_scan->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $key = normalize_name($row['cadet_last_name']) . '|' . $row['class_year'];
    $dup_groups[$key][] = (int)$row['id'];
}
foreach ($dup_groups as $ids) {
    if (count($ids) > 1) foreach ($ids as $id) $dup_ids[$id] = true;
}
$dup_count = count($dup_ids);

$where  = ['1=1'];
$params = [];

if ($search !== '') {
    $where[] = '(cadet_last_name LIKE :q OR cadet_first_name LIKE :q OR cadet_middle_name LIKE :q
                 OR parent1_last_name LIKE :q OR parent1_first_name LIKE :q
                 OR parent2_last_name LIKE :q OR parent2_first_name LIKE :q
                 OR cadet_email LIKE :q OR parent1_email LIKE :q OR parent2_email LIKE :q
                 OR cadet_cell LIKE :q OR parent1_cell LIKE :q)';
    $params[':q'] = '%' . $search . '%';
}
$safe_years = array_intersect($years, CLASS_YEAR_LIST);
if (!empty($safe_years)) {
    $ph = [];
    foreach (array_values($safe_years) as $i => $y) { $ph[] = ":yr$i"; $params[":yr$i"] = $y; }
    $where[] = 'class_year IN (' . implode(',', $ph) . ')';
}
if ($region !== '') { $where[] = 'al_region  = :region'; $params[':region'] = $region; }
if ($paid     === '1') { $where[] = 'membership_paid = 1'; }
if ($paid     === '0') { $where[] = 'membership_paid = 0'; }
if ($squadron !== '') {
    $where[] = '(bct_squadron = :sqd OR fall_squadron = :sqd OR squadron_yr2_4 = :sqd)';
    $params[':sqd'] = $squadron;
}
if ($split_only) { $where[] = "cadet_first_name LIKE '% %'"; }
if ($dup_only) {
    if ($dup_ids) {
        $ph = [];
        foreach (array_keys($dup_ids) as $i => $id) { $ph[] = ":dup$i"; $params[":dup$i"] = $id; }
        $where[] = 'id IN (' . implode(',', $ph) . ')';
    } else {
        $where[] = '1=0';
    }
}
$where[] = $archived === '1' ? 'archived = 1' : 'archived = 0';

$order = $dup_only
    ? 'cadet_last_name asc, class_year asc, cadet_first_name asc'
    : ($sort === 'cadet_last_name'
        ? "cadet_last_name $dir, cadet_first_name $dir"
        : "$sort $dir, cadet_last_name asc");

$sql = 'SELECT * FROM members WHERE ' . implode(' AND ', $where) . " ORDER BY $order";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$members = $stmt->fetchAll();

// ── CSV export ─────────────────────────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="members-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Year','Last Name','Suffix','First Name','Middle Name','Squadron','Region',
                   'P1 Name','P1 Email','P1 Cell','P2 Name','P2 Email','P2 Cell',
                   'Dues','Dues Year','Remarks']);
    foreach ($members as $m) {
        $sqd = $m['squadron_yr2_4'] ?: ($m['fall_squadron'] ?: $m['bct_squadron']);
        fputcsv($out, array_map(fn($v) => is_string($v) ? csv_formula_safe($v) : $v, [
            $m['class_year'], $m['cadet_last_name'], $m['cadet_suffix'] ?? '', $m['cadet_first_name'], $m['cadet_middle_name'],
            $sqd, $m['al_region'],
            trim($m['parent1_first_name'].' '.$m['parent1_last_name']),
            $m['parent1_email'], $m['parent1_cell'],
            trim($m['parent2_first_name'].' '.$m['parent2_last_name']),
            $m['parent2_email'], $m['parent2_cell'],
            $m['membership_paid'] ? 'Paid' : 'Unpaid',
            $m['membership_year'], $m['remarks']
        ]));
    }
    fclose($out);
    exit;
}

// ── Distinct squadrons for filter dropdown ─────────────────────────────────
$squadrons = $pdo->query(
    "SELECT DISTINCT s FROM (
        SELECT squadron_yr2_4 s FROM members WHERE squadron_yr2_4 != ''
        UNION SELECT fall_squadron FROM members WHERE fall_squadron != ''
        UNION SELECT bct_squadron  FROM members WHERE bct_squadron  != ''
    ) sq ORDER BY s"
)->fetchAll(PDO::FETCH_COLUMN);

// ── Dashboard stats ────────────────────────────────────────────────────────
// Graduated classes (class_year = 'Graduate') are excluded from these
// totals — graduating a class relabels it rather than archiving it, so
// without this exclusion "active roster" would keep counting families
// whose cadet has already graduated.
$stats_rows = $pdo->query(
    "SELECT class_year, membership_paid, COUNT(*) as cnt
     FROM members WHERE archived = 0 AND class_year <> 'Graduate' GROUP BY class_year, membership_paid ORDER BY class_year"
)->fetchAll();

$stat_total = 0; $stat_paid = 0; $stat_by_year = []; $stat_paid_by_year = [];
foreach ($stats_rows as $s) {
    $stat_total += $s['cnt'];
    if ($s['membership_paid']) $stat_paid += $s['cnt'];
    $stat_by_year[$s['class_year']] = ($stat_by_year[$s['class_year']] ?? 0) + $s['cnt'];
    if ($s['membership_paid']) $stat_paid_by_year[$s['class_year']] = ($stat_paid_by_year[$s['class_year']] ?? 0) + $s['cnt'];
}
$stat_unpaid = (int)$pdo->query(
    "SELECT COUNT(*) FROM members WHERE archived = 0 AND class_year <> 'Graduate' AND membership_paid = 0"
)->fetchColumn();

// Class-year breakdown row shows only the 4 currently-enrolled classes plus
// Prep School — not years that have already graduated or haven't arrived yet.
$current_years = array_merge(current_class_years(), ['Prep School']);
$other_years   = array_values(array_diff(CLASS_YEAR_LIST, $current_years));

// New members this month
$new_this_month = (int)$pdo->query(
    "SELECT COUNT(*) FROM members WHERE archived = 0 AND created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')"
)->fetchColumn();

// Cadet gender split (for the summary card) — cadets with Gender left blank
// count toward the roster total but not toward either percentage.
$gender_rows = $pdo->query(
    "SELECT cadet_gender, COUNT(*) as cnt FROM members
     WHERE archived = 0 AND class_year <> 'Graduate' GROUP BY cadet_gender"
)->fetchAll();
$stat_male = $stat_female = 0;
foreach ($gender_rows as $g) {
    if ($g['cadet_gender'] === 'Male')   $stat_male   = (int)$g['cnt'];
    if ($g['cadet_gender'] === 'Female') $stat_female = (int)$g['cnt'];
}
$stat_gender_known = $stat_male + $stat_female;
$male_pct   = $stat_gender_known > 0 ? round($stat_male / $stat_gender_known * 100) : 0;
$female_pct = $stat_gender_known > 0 ? 100 - $male_pct : 0;

// Cadet records where First Name still has a space — likely an unsplit
// "First Middle" value left over from before the name fields were separated.
$needs_split_count = (int)$pdo->query(
    "SELECT COUNT(*) FROM members WHERE archived = 0 AND cadet_first_name LIKE '% %'"
)->fetchColumn();

// Dues progress bar (active years 2027-2030 only)
$active_total = $stat_paid + $stat_unpaid;
$dues_pct     = $active_total > 0 ? round($stat_paid / $active_total * 100) : 0;

// Finance widget data (for admins and treasurers)
$fin_pending = $fin_approved = $fin_ytd = 0;
if (can_manage_finances() && !is_member()) {
    try {
        $fin_pending  = (int)$pdo->query("SELECT COUNT(*) FROM purchases WHERE status='pending'")->fetchColumn();
        $fin_approved = (int)$pdo->query("SELECT COUNT(*) FROM purchases WHERE status IN ('approved','submitted')")->fetchColumn();
        $fin_ytd      = (float)$pdo->query("SELECT COALESCE(SUM(amount_total),0) FROM purchases WHERE YEAR(purchase_date)=YEAR(NOW()) AND status='paid'")->fetchColumn();
    } catch (Exception $e) {}
}

// Upcoming birthdays (next 30 days) — currently-enrolled cadets only
// (the 4 active class years + Prep School), same scope as the automated
// birthday email cron. Graduates keep their birthday on file but don't
// belong in this panel anymore.
$bday_years = array_merge(current_class_years(), ['Prep School']);
$bday_ph    = [];
$bday_params = [];
foreach ($bday_years as $i => $y) {
    $key = ':by' . $i;
    $bday_ph[]        = $key;
    $bday_params[$key] = $y;
}
$bday_stmt = $pdo->prepare(
    "SELECT cadet_last_name, cadet_suffix, cadet_first_name, cadet_middle_name, cadet_birthday, cadet_po_box
     FROM members
     WHERE archived = 0 AND cadet_birthday IS NOT NULL AND cadet_birthday != ''
       AND class_year IN (" . implode(',', $bday_ph) . ")"
);
$bday_stmt->execute($bday_params);
$bday_rows = $bday_stmt->fetchAll();
$upcoming_bdays = [];
$today = new DateTime('today');
foreach ($bday_rows as $b) {
    try {
        $bday = new DateTime($b['cadet_birthday']);
        $next = new DateTime($today->format('Y') . '-' . $bday->format('m-d'));
        if ($next < $today) $next->modify('+1 year');
        $days = (int)$today->diff($next)->days;
        if ($days <= 30) {
            $upcoming_bdays[] = ['name'  => cadet_last_name_suffixed($b) . ', ' . trim($b['cadet_first_name'] . ' ' . $b['cadet_middle_name']),
                                  'box'   => $b['cadet_po_box'],
                                  'fmt'   => $next->format('M j'),
                                  'days'  => $days];
        }
    } catch (Exception $e) {}
}
usort($upcoming_bdays, fn($a, $b) => $a['days'] - $b['days']);

// Helper: build sort link preserving current filters
function sort_link(string $col, string $label, string $current_sort, string $current_dir, string $next_dir, array $get): string {
    $params = array_merge($get, ['sort' => $col, 'dir' => $col === $current_sort ? $next_dir : 'asc']);
    $arrow  = $col === $current_sort ? ($current_dir === 'asc' ? ' ↑' : ' ↓') : '';
    return '<a href="index.php?' . http_build_query($params) . '" style="color:inherit;text-decoration:none;white-space:nowrap">'
         . htmlspecialchars($label) . '<span style="opacity:.5">' . $arrow . '</span></a>';
}

$get_params = array_filter(['q'=>$search,'year'=>$years,'region'=>$region,'paid'=>$paid,'split'=>$split_only?'1':null,'dup'=>$dup_only?'1':null]);

admin_header('Members');
echo show_flash();
?>

<!-- ── Page header ──────────────────────────────────────────────────────── -->
<div class="page-head" style="margin-bottom:1rem">
  <h1>Members <span style="font-size:.85rem;font-weight:400;color:#5a6a7a">(<?= count($members) ?> shown of <?= $stat_total ?> total)</span></h1>
  <div style="display:flex;gap:.5rem;flex-wrap:wrap">
    <?php
    $csv_params = array_filter(['q'=>$search,'year'=>$years,'region'=>$region,'paid'=>$paid,'squadron'=>$squadron,'split'=>$split_only?'1':null,'dup'=>$dup_only?'1':null]);
    $csv_params['export'] = 'csv';
    ?>
    <a href="index.php?<?= http_build_query($csv_params) ?>" class="btn btn-secondary">Export CSV</a>
    <a href="directory.php" class="btn btn-secondary">Directory</a>
    <?php if (can_manage_members()): ?>
      <a href="graduate-class.php" class="btn btn-secondary">Graduate a Class</a>
      <a href="reset-dues.php" class="btn btn-secondary">Reset Dues</a>
      <a href="add.php" class="btn btn-primary">+ Add Member</a>
    <?php endif; ?>
  </div>
</div>

<!-- ── Alerts strip — things that need attention today ─────────────────── -->
<?php
$alerts = [];
if ($fin_pending)  $alerts[] = ['color'=>'#fff3cd','border'=>'#ffc107','text'=>'#5f4c00','icon'=>'⏳','msg'=>"$fin_pending purchase".($fin_pending>1?'s':'')." need approval",'href'=>'purchases.php?status=pending'];
if ($fin_approved) $alerts[] = ['color'=>'#e3f2fd','border'=>'#90caf9','text'=>'#0d47a1','icon'=>'💰','msg'=>"$fin_approved awaiting payment",'href'=>'pending-reimbursements.php'];
if ($new_this_month) $alerts[] = ['color'=>'#e8f5e9','border'=>'#a5d6a7','text'=>'#1b5e20','icon'=>'👤','msg'=>"$new_this_month new member".($new_this_month>1?'s':'')." this month",'href'=>'index.php?q='];
if (!empty($upcoming_bdays)) $alerts[] = ['color'=>'#f3e5f5','border'=>'#ce93d8','text'=>'#4a148c','icon'=>'🎂','msg'=>count($upcoming_bdays)." birthday".( count($upcoming_bdays)>1?'s':'')." in the next 30 days",'href'=>'#bday-panel','onclick'=>'openBirthdays()'];
if ($needs_split_count) $alerts[] = ['color'=>'#fff3cd','border'=>'#ffc107','text'=>'#5f4c00','icon'=>'✂️','msg'=>"$needs_split_count cadet name".($needs_split_count>1?'s':'')." still need First/Middle split",'href'=>'index.php?split=1'];
if ($dup_count) $alerts[] = ['color'=>'#fde0e0','border'=>'#e57373','text'=>'#8a1425','icon'=>'👥','msg'=>"$dup_count possible duplicate cadet".($dup_count>1?'s':'')." (same last name + class year)",'href'=>'index.php?dup=1'];
?>
<?php if (!empty($alerts)): ?>
<div style="display:grid;grid-template-columns:repeat(<?= count($alerts) ?>,1fr);gap:.6rem;margin-bottom:1.25rem">
  <?php foreach ($alerts as $a): ?>
  <a href="<?= h($a['href']) ?>" <?= !empty($a['onclick'])?'onclick="'.$a['onclick'].'; return false;"':'' ?>
     style="display:flex;align-items:center;justify-content:center;gap:.45rem;background:<?= $a['color'] ?>;border:1px solid <?= $a['border'] ?>;border-radius:6px;padding:.6rem .9rem;text-decoration:none;font-size:.82rem;font-weight:600;color:<?= $a['text'] ?>;text-align:center">
    <span><?= $a['icon'] ?></span> <?= h($a['msg']) ?>
  </a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ── Summary stats row ────────────────────────────────────────────────── -->
<div style="display:grid;grid-template-columns:repeat(5,1fr);gap:.6rem;margin-bottom:.6rem">
  <?php
  $summary_cards = [
    ['label'=>'Total Members',  'value'=>$stat_total,     'sub'=>'active roster',   'pct'=>100,      'color'=>'#002554'],
    ['label'=>'Dues Paid',      'value'=>$stat_paid,       'sub'=>membership_year(), 'pct'=>$dues_pct,'color'=>'#1b5e20'],
    ['label'=>'Dues Unpaid',    'value'=>$stat_unpaid,     'sub'=>'need to renew',   'pct'=>$active_total>0?round($stat_unpaid/$active_total*100):0,'color'=>'#c62828'],
  ];
  foreach ($summary_cards as $c): ?>
  <div class="card" style="padding:.75rem 1rem;margin:0;display:flex;flex-direction:column;justify-content:space-between;gap:.4rem;min-width:0">
    <div style="font-size:.65rem;font-weight:700;color:#5a6a7a;text-transform:uppercase;letter-spacing:.05em"><?= h($c['label']) ?></div>
    <div style="font-size:1.4rem;font-weight:700;color:<?= $c['color'] ?>;line-height:1"><?= h((string)$c['value']) ?></div>
    <div style="font-size:.65rem;color:#9aa5b4"><?= h($c['sub']) ?></div>
    <div style="background:#e1e5eb;border-radius:99px;height:4px;overflow:hidden">
      <div style="height:100%;width:<?= (int)$c['pct'] ?>%;background:<?= $c['color'] ?>;border-radius:99px"></div>
    </div>
  </div>
  <?php endforeach; ?>
  <!-- Cadet gender split card -->
  <div class="card" style="padding:.75rem 1rem;margin:0;display:flex;flex-direction:column;justify-content:space-between;gap:.4rem;min-width:0">
    <div style="font-size:.65rem;font-weight:700;color:#5a6a7a;text-transform:uppercase;letter-spacing:.05em">Cadet Gender</div>
    <div style="font-size:1.1rem;font-weight:700;line-height:1"><span style="color:#003594"><?= $male_pct ?>% M</span> <span style="color:#9aa5b4;font-weight:400">/</span> <span style="color:#A6192E"><?= $female_pct ?>% F</span></div>
    <div style="font-size:.65rem;color:#9aa5b4"><?= $stat_male ?> male, <?= $stat_female ?> female<?= $stat_total > $stat_gender_known ? ', ' . ($stat_total - $stat_gender_known) . ' unset' : '' ?></div>
    <div style="background:#e1e5eb;border-radius:99px;height:4px;overflow:hidden;display:flex">
      <div style="height:100%;width:<?= $male_pct ?>%;background:#003594"></div>
      <div style="height:100%;width:<?= $female_pct ?>%;background:#A6192E"></div>
    </div>
  </div>
  <!-- Dues progress card -->
  <div class="card" style="padding:.75rem 1rem;margin:0;display:flex;flex-direction:column;justify-content:space-between;gap:.4rem;min-width:0">
    <div style="font-size:.65rem;font-weight:700;color:#5a6a7a;text-transform:uppercase;letter-spacing:.05em">Dues <?= h(membership_year()) ?></div>
    <div style="font-size:1.3rem;font-weight:700;color:<?= $dues_pct>=75?'#2e7d32':($dues_pct>=40?'#f57c00':'#c62828') ?>;line-height:1"><?= $dues_pct ?>%</div>
    <div style="font-size:.65rem;color:#9aa5b4"><?= $stat_paid ?> of <?= $active_total ?></div>
    <div style="background:#e1e5eb;border-radius:99px;height:4px;overflow:hidden">
      <div style="height:100%;width:<?= $dues_pct ?>%;background:<?= $dues_pct>=75?'#2e7d32':($dues_pct>=40?'#f57c00':'#c62828') ?>;border-radius:99px"></div>
    </div>
  </div>
</div>

<!-- ── Class year breakdown row (currently-enrolled classes only) ─────────── -->
<div style="display:grid;grid-template-columns:repeat(<?= count($current_years) ?>,1fr);gap:.6rem;margin-bottom:1.25rem">
  <?php foreach ($current_years as $yr):
    $cnt  = $stat_by_year[$yr] ?? 0;
    $yp   = $stat_paid_by_year[$yr] ?? 0;
    $ypct = $cnt > 0 ? round($yp/$cnt*100) : 0;
    $col  = $ypct>=75?'#2e7d32':($ypct>=40?'#f57c00':'#c62828');
  ?>
  <div class="card" style="padding:.75rem 1rem;margin:0;display:flex;flex-direction:column;justify-content:space-between;gap:.4rem;min-width:0;cursor:pointer"
       onclick="document.querySelector('[name=year]').value='<?= h($yr) ?>'; document.querySelector('.filter-bar button[type=submit]').click()">
    <div style="font-size:.65rem;font-weight:700;color:#5a6a7a;text-transform:uppercase;letter-spacing:.05em"><?= $yr === 'Prep School' ? 'Prep School' : 'Class of ' . h($yr) ?></div>
    <div style="font-size:1.3rem;font-weight:700;color:<?= $col ?>;line-height:1"><?= $yp ?><span style="font-size:.8rem;color:#9aa5b4"> / <?= $cnt ?></span></div>
    <div style="font-size:.65rem;color:#9aa5b4"><?= $ypct ?>% paid</div>
    <div style="background:#e1e5eb;border-radius:99px;height:4px;overflow:hidden">
      <div style="height:100%;width:<?= $ypct ?>%;background:<?= $col ?>;border-radius:99px"></div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<?php if (!empty($upcoming_bdays)): ?>
<div class="card" style="padding:0;margin-bottom:1.25rem;overflow:hidden">
  <button onclick="toggleBdays(this)" style="width:100%;background:none;border:none;padding:.85rem 1.25rem;display:flex;justify-content:space-between;align-items:center;cursor:pointer;text-align:left;font-family:inherit">
    <span style="font-size:.78rem;font-weight:700;color:#5a6a7a;text-transform:uppercase;letter-spacing:.05em">
      🎂 Upcoming Birthdays
      <span style="background:<?= !empty(array_filter($upcoming_bdays,fn($b)=>$b['days']<=7))?'#f57c00':'#5a6a7a' ?>;color:#fff;font-size:.65rem;padding:.15rem .45rem;border-radius:99px;margin-left:.4rem;vertical-align:middle"><?= count($upcoming_bdays) ?></span>
    </span>
    <span id="bday-chevron" style="color:#5a6a7a;font-size:.85rem">▸ Show</span>
  </button>
  <div id="bday-panel" style="display:none;padding:.25rem 1.25rem 1.25rem">
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:.5rem">
      <?php foreach ($upcoming_bdays as $b): ?>
      <div style="background:#f0f4ff;border:1px solid #c7d4f5;border-radius:4px;padding:.5rem .85rem;font-size:.82rem;display:flex;justify-content:space-between;align-items:center">
        <div><strong style="color:#002554"><?= h($b['name']) ?></strong><br>
          <span style="color:#5a6a7a"><?= h($b['fmt']) ?></span>
          <?php if ($b['box']): ?><span style="color:#9aa5b4"> · PO <?= h($b['box']) ?></span><?php endif; ?></div>
        <div style="white-space:nowrap;padding-left:.5rem">
          <?php if ($b['days']===0): ?><span style="color:#A6192E;font-weight:700">🎉 Today!</span>
          <?php elseif($b['days']<=7): ?><span style="color:#f57c00;font-weight:700"><?= $b['days'] ?>d</span>
          <?php else: ?><span style="color:#9aa5b4"><?= $b['days'] ?>d</span><?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<script>
function toggleBdays(btn) {
  var panel=document.getElementById('bday-panel'), chev=document.getElementById('bday-chevron');
  var open=panel.style.display==='none';
  panel.style.display=open?'block':'none';
  chev.textContent=open?'▾ Hide':'▸ Show';
}
function openBirthdays() {
  var panel=document.getElementById('bday-panel'), chev=document.getElementById('bday-chevron');
  panel.style.display='block';
  chev.textContent='▾ Hide';
}
</script>
<?php endif; ?>

<style>
.cd{position:relative}
.cd-btn{width:100%;text-align:left;background:#fff;border:1px solid #d0d5dd;border-radius:4px;padding:.55rem .75rem;cursor:pointer;font-size:.9rem;color:#1a2332;display:flex;justify-content:space-between;align-items:center;font-family:inherit}
.cd-btn::after{content:'▾';font-size:.8rem;color:#5a6a7a;flex-shrink:0}
.cd-btn:focus{outline:none;border-color:#003594;box-shadow:0 0 0 2px rgba(0,53,148,.15)}
.cd-panel{display:none;position:absolute;top:calc(100% + 3px);left:0;right:0;background:#fff;border:1px solid #d0d5dd;border-radius:4px;box-shadow:0 4px 12px rgba(0,0,0,.12);z-index:200;padding:.4rem 0;min-width:160px}
.cd.open .cd-panel{display:block}
.cd-panel label{display:flex;align-items:center;gap:.55rem;padding:.38rem .8rem;cursor:pointer;font-size:.875rem;color:#1a2332;font-weight:400;text-transform:none;letter-spacing:0;white-space:nowrap}
.cd-panel label:hover{background:#f5f7fa}
.cd-panel input[type=checkbox]{width:auto;accent-color:#003594;cursor:pointer}
.cd-footer{border-top:1px solid #e1e5eb;padding:.4rem .8rem 0;display:flex;gap:.5rem;margin-top:.25rem}
.cd-group-label{font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#9aa5b4;padding:.5rem .8rem .2rem}
/* Cap filter field growth so the Search/Clear buttons fit on the same row instead of wrapping alone beneath six full-width fields */
.filter-bar .form-group{max-width:190px}
</style>

<!-- Filters -->
<div class="card" style="padding:1rem 1.5rem">
  <form method="GET" class="filter-bar">
    <?php if ($split_only): ?><input type="hidden" name="split" value="1"><?php endif; ?>
    <?php if ($dup_only): ?><input type="hidden" name="dup" value="1"><?php endif; ?>
    <div class="form-group" style="flex:2;min-width:200px">
      <label>Search name / email / phone</label>
      <input name="q" value="<?= h($search) ?>" placeholder="Type to search…">
    </div>
    <div class="form-group">
      <label>Class Year</label>
      <div class="cd" id="yr-cd">
        <button type="button" class="cd-btn" id="yr-btn">All Years</button>
        <div class="cd-panel">
          <div class="cd-group-label">Currently Enrolled</div>
          <?php foreach ($current_years as $y): ?>
            <label>
              <input type="checkbox" name="year[]" value="<?= h($y) ?>" data-current="1" <?= in_array($y,$years)?'checked':''?>>
              <?= h($y) ?>
            </label>
          <?php endforeach; ?>
          <?php if (!empty($other_years)): ?>
          <div class="cd-group-label">Other</div>
          <?php foreach ($other_years as $y): ?>
            <label>
              <input type="checkbox" name="year[]" value="<?= h($y) ?>" <?= in_array($y,$years)?'checked':''?>>
              <?= h($y) ?>
            </label>
          <?php endforeach; ?>
          <?php endif; ?>
          <div class="cd-footer">
            <button type="button" class="btn btn-secondary btn-sm" onclick="setCurrentYrs()">Current Years</button>
            <button type="button" class="btn btn-secondary btn-sm" onclick="setYrs(false)">None</button>
          </div>
        </div>
      </div>
    </div>
    <div class="form-group">
      <label>AL Region</label>
      <select name="region">
        <option value="">All regions</option>
        <?php foreach (['North','Central','South'] as $r): ?>
          <option value="<?= h($r) ?>" <?= $region===$r?'selected':''?>><?= h($r) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group">
      <label>Dues Status</label>
      <select name="paid">
        <option value="">All</option>
        <option value="1" <?= $paid==='1'?'selected':''?>>Paid</option>
        <option value="0" <?= $paid==='0'?'selected':''?>>Not Paid</option>
      </select>
    </div>
    <div class="form-group">
      <label>Status</label>
      <select name="archived">
        <option value="0" <?= $archived==='0'?'selected':''?>>Active</option>
        <option value="1" <?= $archived==='1'?'selected':''?>>Archived</option>
      </select>
    </div>
    <div class="form-group">
      <label>Squadron</label>
      <select name="squadron">
        <option value="">All</option>
        <?php foreach ($squadrons as $s): ?>
          <option value="<?= h($s) ?>" <?= $squadron===$s?'selected':''?>><?= h($s) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group" style="flex:0;min-width:170px">
      <label>&nbsp;</label>
      <div style="display:flex;gap:.5rem;flex-wrap:nowrap">
        <button type="submit" class="btn btn-primary">Search</button>
        <a href="index.php" class="btn btn-secondary">Clear</a>
      </div>
    </div>
  </form>
</div>

<script>
// ── Class Year checkbox dropdown ──────────────────────────────────────────
var yrCd  = document.getElementById('yr-cd');
var yrBtn = document.getElementById('yr-btn');
var yrCbs = yrCd.querySelectorAll('input[type=checkbox]');

function updateYrLabel() {
  var checked     = Array.from(yrCbs).filter(function(c){ return c.checked; }).map(function(c){ return c.value; });
  var currentVals = Array.from(yrCbs).filter(function(c){ return c.dataset.current; }).map(function(c){ return c.value; });
  var isCurrent   = checked.length === currentVals.length && currentVals.every(function(v){ return checked.indexOf(v) !== -1; });
  yrBtn.childNodes[0].textContent = checked.length === 0            ? 'All Years'     :
                                    checked.length === yrCbs.length ? 'All Years'     :
                                    isCurrent                        ? 'Current Years' :
                                    checked.join(', ');
}
yrBtn.addEventListener('click', function(e){ e.stopPropagation(); yrCd.classList.toggle('open'); });
document.addEventListener('click', function(){ yrCd.classList.remove('open'); });
yrCd.querySelector('.cd-panel').addEventListener('click', function(e){ e.stopPropagation(); });
yrCbs.forEach(function(cb){ cb.addEventListener('change', updateYrLabel); });
updateYrLabel();

function setYrs(state) {
  yrCbs.forEach(function(cb){ cb.checked = state; });
  updateYrLabel();
}
function setCurrentYrs() {
  yrCbs.forEach(function(cb){ cb.checked = !!cb.dataset.current; });
  updateYrLabel();
}
</script>

<?php if (can_mark_dues()): ?>
<!-- Bulk action form (inputs inside the table use form="bulk-form") -->
<form id="bulk-form" method="POST" action="bulk-action.php">
  <?= csrf_field() ?>
</form>
<?php endif; ?>

<div class="card" style="padding:0;overflow:auto">
<table>
  <thead>
    <tr>
      <?php if (can_mark_dues()): ?>
      <th style="width:36px"><input type="checkbox" id="select-all" style="width:auto;accent-color:#003594" title="Select all"></th>
      <?php endif; ?>
      <th><?= sort_link('class_year',    'Year',    $sort, $dir, $next_dir, $get_params) ?></th>
      <th><?= sort_link('cadet_last_name','Cadet',   $sort, $dir, $next_dir, $get_params) ?></th>
      <th><?= sort_link('al_region',     'Region',  $sort, $dir, $next_dir, $get_params) ?></th>
      <th>Parent 1</th>
      <th>Parent 2</th>
      <th><?= sort_link('membership_paid','Dues',    $sort, $dir, $next_dir, $get_params) ?></th>
      <th class="actions-head">Actions</th>
    </tr>
  </thead>
  <tbody>
  <?php if (empty($members)): ?>
    <tr><td colspan="<?= can_mark_dues()?8:7 ?>" style="text-align:center;padding:2rem;color:#5a6a7a">No members found.</td></tr>
  <?php endif; ?>
  <?php foreach ($members as $m): ?>
    <?php
      $sqd        = $m['squadron_yr2_4'] ?: ($m['fall_squadron'] ?: $m['bct_squadron']);
      $region_cls = $m['al_region'] ? 'badge-' . $m['al_region'] : '';
      $p1email    = $m['parent1_email'];
      $p1cell     = $m['parent1_cell'];
      $p2email    = $m['parent2_email'];
      $p2cell     = $m['parent2_cell'];
    ?>
    <tr>
      <?php if (can_mark_dues()): ?>
      <td><input type="checkbox" name="member_ids[]" value="<?= (int)$m['id'] ?>" form="bulk-form" style="width:auto;accent-color:#003594" class="row-cb"></td>
      <?php endif; ?>
      <td><?= h($m['class_year']) ?></td>
      <td>
        <a href="view.php?id=<?= (int)$m['id'] ?>" style="font-weight:700;color:#002554"><?= h($m['cadet_last_name']) ?></a><?= !empty($m['cadet_suffix']) ? ' ' . h($m['cadet_suffix']) : '' ?><?php $cadet_fm = trim($m['cadet_first_name'] . ' ' . $m['cadet_middle_name']); ?><?= $cadet_fm ? ', ' . h($cadet_fm) : '' ?><?php if (strpos(trim($m['cadet_first_name']), ' ') !== false): ?> <span title="First Name still contains a space — likely needs to be split into First/Middle" style="font-size:.65rem;font-weight:700;color:#5f4c00;background:#fff3cd;padding:.05rem .35rem;border-radius:3px">✂️ SPLIT?</span><?php endif; ?><?php if (isset($dup_ids[(int)$m['id']])): ?> <span title="Another active cadet shares this last name + class year — possible duplicate" style="font-size:.65rem;font-weight:700;color:#8a1425;background:#fde0e0;padding:.05rem .35rem;border-radius:3px">👥 DUP?</span><?php endif; ?><br>
        <?php if ($m['cadet_email']): ?><a href="mailto:<?= h($m['cadet_email']) ?>" style="font-size:.78rem;color:#5a6a7a"><?= h($m['cadet_email']) ?></a><?php endif; ?>
      </td>
      <td><?php if ($m['al_region']): ?><span class="badge <?= h($region_cls) ?>"><?= h($m['al_region']) ?></span><?php endif; ?></td>
      <td>
        <?= h(trim($m['parent1_first_name'] . ' ' . $m['parent1_last_name'])) ?><br>
        <?php if ($p1cell): ?><a href="tel:<?= h(preg_replace('/\D/','',$p1cell)) ?>" style="font-size:.78rem;color:#5a6a7a"><?= h($p1cell) ?></a><?php endif; ?>
        <?php if ($p1email): ?><br><a href="mailto:<?= h($p1email) ?>" style="font-size:.78rem;color:#5a6a7a"><?= h($p1email) ?></a><?php endif; ?>
      </td>
      <td>
        <?= h(trim($m['parent2_first_name'] . ' ' . $m['parent2_last_name'])) ?><br>
        <?php if ($p2cell): ?><a href="tel:<?= h(preg_replace('/\D/','',$p2cell)) ?>" style="font-size:.78rem;color:#5a6a7a"><?= h($p2cell) ?></a><?php endif; ?>
        <?php if ($p2email): ?><br><a href="mailto:<?= h($p2email) ?>" style="font-size:.78rem;color:#5a6a7a"><?= h($p2email) ?></a><?php endif; ?>
      </td>
      <?php $row_dues_years = parse_dues_years($m['membership_paid_years'] ?? ''); ?>
      <td>
        <?php if ($m['membership_paid']): ?>
          <span class="badge badge-paid">✓ Paid</span><br>
          <?php if (count($row_dues_years) > 1): ?>
            <span style="font-size:.7rem;color:#2e7d32;font-weight:700"><?= count($row_dues_years) ?> yrs thru <?= h($m['membership_paid_through']) ?></span>
          <?php else: ?>
            <span style="font-size:.72rem;color:#5a6a7a"><?= h($m['membership_paid_through']) ?></span>
          <?php endif; ?>
        <?php else: ?>
          <span class="badge badge-unpaid">✗ Unpaid</span>
          <?php if ($row_dues_years): ?>
            <br><span style="font-size:.68rem;color:#9aa5b4"><?= count($row_dues_years) ?> other yr<?= count($row_dues_years) !== 1 ? 's' : '' ?> on file</span>
          <?php endif; ?>
        <?php endif; ?>
      </td>
      <td class="actions">
        <div class="btn-group">
          <?php if (can_manage_members()): ?>
          <a href="edit.php?id=<?= (int)$m['id'] ?>" class="btn btn-secondary btn-sm">Edit</a>
          <form method="POST" action="archive-member.php">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
            <input type="hidden" name="archived" value="<?= $m['archived'] ? 0 : 1 ?>">
            <button type="submit" class="btn btn-secondary btn-sm"
              onclick="return confirm('<?= $m['archived'] ? 'Restore' : 'Archive' ?> <?= h(addslashes($m['cadet_last_name'])) ?>?')"
            ><?= $m['archived'] ? 'Restore' : 'Archive' ?></button>
          </form>
          <form method="POST" action="delete.php" onsubmit="return confirm('Delete <?= h(addslashes($m['cadet_last_name'])) ?>? This cannot be undone.')">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
            <button type="submit" class="btn btn-danger btn-sm">Delete</button>
          </form>
          <?php elseif (can_mark_dues()): ?>
          <form method="POST" action="bulk-action.php" style="display:flex;gap:.3rem;align-items:center;flex-wrap:wrap">
            <?= csrf_field() ?>
            <input type="hidden" name="member_ids[]" value="<?= (int)$m['id'] ?>">
            <?php if ($m['membership_paid']): ?>
              <button type="submit" name="action" value="mark_unpaid_current" class="btn btn-secondary btn-sm">✗ Unpaid</button>
            <?php else: ?>
              <button type="submit" name="action" value="mark_paid_current" class="btn btn-primary btn-sm">✓ Paid</button>
            <?php endif; ?>
            <a href="edit-dues.php?id=<?= (int)$m['id'] ?>" class="btn btn-secondary btn-sm" title="Pick specific years — including the 4-year bulk rate">Years…</a>
          </form>
          <?php else: ?>
          <a href="view.php?id=<?= (int)$m['id'] ?>" class="btn btn-secondary btn-sm">View</a>
          <?php endif; ?>
        </div>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<?php if (can_mark_dues() && !empty($members)): ?>
<!-- Bulk action bar -->
<div id="bulk-bar" style="display:none;position:sticky;bottom:1rem;background:#002554;color:#fff;padding:.85rem 1.25rem;border-radius:6px;box-shadow:0 4px 16px rgba(0,0,0,.3);align-items:center;gap:.65rem;flex-wrap:wrap;margin-top:.75rem">
  <span id="bulk-count" style="font-size:.9rem;font-weight:600;margin-right:.25rem"></span>
  <span style="font-size:.82rem;opacity:.7">Dues:</span>
  <button type="submit" form="bulk-form" name="action" value="mark_paid_current" class="btn btn-primary btn-sm">✓ Paid (this year)</button>
  <button type="submit" form="bulk-form" name="action" value="mark_paid_4year" class="btn btn-primary btn-sm">✓ Paid (4-Yr, $275)</button>
  <button type="submit" form="bulk-form" name="action" value="mark_unpaid_current" class="btn btn-secondary btn-sm" style="background:rgba(255,255,255,.15);border-color:rgba(255,255,255,.3);color:#fff">✗ Unpaid (this year)</button>
  <?php if (can_manage_members()): ?>
  <span style="font-size:.82rem;opacity:.7;margin-left:.25rem">Members:</span>
  <?php if ($archived === '1'): ?>
  <button type="submit" form="bulk-form" name="action" value="restore" class="btn btn-secondary btn-sm" style="background:rgba(255,255,255,.15);border-color:rgba(255,255,255,.3);color:#fff">↩ Restore</button>
  <?php else: ?>
  <button type="submit" form="bulk-form" name="action" value="archive" class="btn btn-secondary btn-sm" style="background:rgba(255,255,255,.15);border-color:rgba(255,255,255,.3);color:#fff">Archive</button>
  <?php endif; ?>
  <button type="submit" form="bulk-form" name="action" value="delete" class="btn btn-danger btn-sm" onclick="return confirm('Permanently delete selected members? This cannot be undone.')">Delete</button>
  <span style="font-size:.82rem;opacity:.7;margin-left:.25rem">Portal:</span>
  <button type="submit" form="bulk-form" name="action" value="portal_invite" class="btn btn-primary btn-sm" onclick="return confirm('Email a portal sign-up invite to each selected member\'s parent(s)? Anyone who already has a portal account is skipped.')">✉️ Send Portal Invite</button>
  <?php endif; ?>
</div>

<script>
var selectAll = document.getElementById('select-all');
var checkboxes = document.querySelectorAll('.row-cb');
var bulkBar = document.getElementById('bulk-bar');
var bulkCount = document.getElementById('bulk-count');

function updateBulkBar() {
  var checked = document.querySelectorAll('.row-cb:checked').length;
  bulkBar.style.display = checked > 0 ? 'flex' : 'none';
  bulkBar.style.alignItems = 'center';
  bulkCount.textContent = checked + ' member' + (checked !== 1 ? 's' : '') + ' selected';
  selectAll.indeterminate = checked > 0 && checked < checkboxes.length;
  selectAll.checked = checked === checkboxes.length;
}

selectAll.addEventListener('change', function() {
  checkboxes.forEach(function(cb) { cb.checked = selectAll.checked; });
  updateBulkBar();
});
checkboxes.forEach(function(cb) { cb.addEventListener('change', updateBulkBar); });
</script>
<?php endif; ?>

<?php admin_footer(); ?>
