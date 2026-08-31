<?php
require_once __DIR__ . '/auth.php';
require_login();
$pdo = get_pdo();

$all_years      = CLASS_YEAR_LIST;
$current_years  = array_merge(current_class_years(), ['Prep School']);
$other_years    = array_values(array_diff($all_years, $current_years));
$selected_years = array_intersect((array)($_GET['years'] ?? []), $all_years);

$search        = trim($_GET['q']       ?? '');
$region        = $_GET['region']       ?? '';
$missing       = $_GET['missing']      ?? '';
$bmonth        = $_GET['bmonth']       ?? '';
$member_status = $_GET['member']       ?? '';
$consent       = $_GET['consent']      ?? '';

// Families who explicitly opted out of the member directory are excluded —
// this page is meant to be shared/printed for members, unlike the admin
// member list, so directory_consent must be honored. Blank/unanswered
// (common for members who joined before this question existed) is treated
// as included, not opted out — but only for board/officer roles who already
// have PII access elsewhere. Regular members only see cadets who explicitly
// opted in.
$where  = ['archived = 0'];
if (can_view_member_pii()) {
    $where[] = "(directory_consent IS NULL OR directory_consent <> 'No')";
} else {
    $where[] = "directory_consent = 'Yes'";
}
$params = [];
if ($search !== '') {
    $where[] = '(cadet_last_name LIKE :q OR cadet_first_name LIKE :q OR cadet_middle_name LIKE :q
                 OR parent1_last_name LIKE :q OR parent1_first_name LIKE :q
                 OR parent2_last_name LIKE :q OR parent2_first_name LIKE :q
                 OR cadet_email LIKE :q OR parent1_email LIKE :q OR parent2_email LIKE :q
                 OR cadet_cell LIKE :q OR parent1_cell LIKE :q)';
    $params[':q'] = '%' . $search . '%';
}
if (!empty($selected_years) && count($selected_years) < count($all_years)) {
    $ph = [];
    foreach (array_values($selected_years) as $i => $y) {
        $key = ':yr' . $i;
        $ph[]         = $key;
        $params[$key] = $y;
    }
    $where[] = 'class_year IN (' . implode(', ', $ph) . ')';
}
if ($region !== '') { $where[] = 'al_region  = :region'; $params[':region'] = $region; }
$missing_sql = missing_data_sql($missing);
if ($missing_sql) { $where[] = $missing_sql; }
if ($bmonth !== '' && (int)$bmonth >= 1 && (int)$bmonth <= 12) {
    $where[] = 'MONTH(cadet_birthday) = :bmonth';
    $params[':bmonth'] = (int)$bmonth;
}
if ($member_status === 'board')  { $where[] = '(parent1_is_board_member = 1 OR parent2_is_board_member = 1)'; }
if ($member_status === 'paid')   { $where[] = 'membership_paid = 1'; }
if ($member_status === 'unpaid') { $where[] = 'membership_paid = 0'; }
if ($consent === 'photo_yes')     { $where[] = "photo_consent = 'Yes'"; }
if ($consent === 'photo_no')      { $where[] = "photo_consent = 'No'"; }
if ($consent === 'directory_yes') { $where[] = "directory_consent = 'Yes'"; }

$stmt = $pdo->prepare('SELECT * FROM members WHERE ' . implode(' AND ', $where)
    . ' ORDER BY class_year, cadet_last_name');
$stmt->execute($params);
$members = $stmt->fetchAll();
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Member Directory — USAFA Parents Club of Alabama</title>
<link rel="icon" type="image/png" href="../logo01.png" />
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:"Segoe UI",Arial,sans-serif;font-size:13px;color:#111;background:#fff;padding:1.5rem}
h1{font-size:1.2rem;color:#002554;margin-bottom:.25rem}
.subtitle{font-size:.82rem;color:#5a6a7a;margin-bottom:1.25rem}
.filters{display:flex;gap:.75rem;margin-bottom:1.25rem;flex-wrap:wrap;align-items:flex-end}
.filters select,.filters a{padding:.4rem .65rem;border:1px solid #d0d5dd;border-radius:4px;font-size:.82rem;font-family:inherit;background:#fff;color:#1a2332;cursor:pointer;text-decoration:none}
.filters button{padding:.4rem .9rem;background:#003594;color:#fff;border:none;border-radius:4px;font-size:.82rem;cursor:pointer}
.cd{position:relative;width:180px}
.cd-btn{width:100%;text-align:left;background:#fff;border:1px solid #d0d5dd;border-radius:4px;padding:.4rem .65rem;cursor:pointer;font-size:.82rem;color:#1a2332;display:flex;justify-content:space-between;align-items:center;font-family:inherit}
.cd-btn::after{content:'▾';font-size:.75rem;color:#5a6a7a;flex-shrink:0}
.cd-btn:focus{outline:none;border-color:#003594;box-shadow:0 0 0 2px rgba(0,53,148,.15)}
.cd-panel{display:none;position:absolute;top:calc(100% + 3px);left:0;right:0;background:#fff;border:1px solid #d0d5dd;border-radius:4px;box-shadow:0 4px 12px rgba(0,0,0,.12);z-index:200;padding:.4rem 0;min-width:160px}
.cd.open .cd-panel{display:block}
.cd-panel label{display:flex;align-items:center;gap:.55rem;padding:.32rem .7rem;cursor:pointer;font-size:.8rem;color:#1a2332;white-space:nowrap}
.cd-panel label:hover{background:#f5f7fa}
.cd-panel input[type=checkbox]{width:auto;accent-color:#003594;cursor:pointer}
.cd-footer{border-top:1px solid #e1e5eb;padding:.4rem .7rem 0;display:flex;gap:.4rem;margin-top:.25rem}
.cd-group-label{font-size:.62rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#9aa5b4;padding:.45rem .7rem .15rem}
.cd-footer button{padding:.28rem .55rem;font-size:.7rem;border-radius:4px;cursor:pointer;border:1px solid #d0d5dd;background:#f0f2f5;color:#333}
.yr-group{margin-bottom:1.5rem}
.yr-label{font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.12em;color:#003594;border-bottom:2px solid #003594;padding-bottom:.25rem;margin-bottom:.75rem}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:.65rem}
.card{border:1px solid #e1e5eb;border-radius:4px;padding:.7rem .85rem;page-break-inside:avoid}
.cadet-name{font-weight:700;font-size:.92rem;color:#002554}
.cadet-meta{font-size:.72rem;color:#5a6a7a;margin-bottom:.4rem}
.p-name{font-weight:600;font-size:.8rem;color:#1a2332;margin-top:.35rem}
.p-detail{font-size:.75rem;color:#333;line-height:1.6}
.p-detail a{color:#333;text-decoration:none}
.divider{border-top:1px solid #f0f2f5;margin:.35rem 0}
@media print{
  body{padding:.5rem}
  .no-print{display:none!important}
  .card{border-color:#ccc}
  a{color:#111!important}
  .grid{grid-template-columns:repeat(3,1fr)}
}
</style>
</head><body>

<div class="no-print" style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:1rem;margin-bottom:1rem">
  <div>
    <h1>Member Directory</h1>
    <div class="subtitle">USAFA Parents Club of Alabama &mdash; <?= date('F Y') ?> &mdash; <?= can_view_member_pii() ? 'excludes families who opted out of directory listing' : 'shows only families who opted in to directory listing' ?></div>
  </div>
  <div style="display:flex;gap:.5rem">
    <button onclick="window.print()" style="padding:.5rem 1.1rem;background:#003594;color:#fff;border:none;border-radius:4px;font-size:.85rem;cursor:pointer">Print / Save PDF</button>
    <a href="dashboard.php" style="padding:.5rem 1.1rem;background:#f0f2f5;color:#333;border:1px solid #d0d5dd;border-radius:4px;font-size:.85rem;text-decoration:none">← Back</a>
  </div>
</div>

<form class="filters no-print" method="GET">
  <div>
    <label style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#5a6a7a;display:block;margin-bottom:.2rem">Search</label>
    <input type="text" name="q" value="<?= h($search) ?>" placeholder="Name, email, phone…" style="padding:.4rem .65rem;border:1px solid #d0d5dd;border-radius:4px;font-size:.82rem;font-family:inherit">
  </div>
  <div>
    <label style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#5a6a7a;display:block;margin-bottom:.2rem">Year</label>
    <div class="cd" id="year-cd">
      <button type="button" class="cd-btn" id="year-btn">All Years</button>
      <div class="cd-panel">
        <div class="cd-group-label">Currently Enrolled</div>
        <?php foreach ($current_years as $y): ?>
        <label>
          <input type="checkbox" name="years[]" value="<?= h($y) ?>" data-current="1"
                 <?= in_array($y, $selected_years) ? 'checked' : '' ?>>
          <?= h($y) ?>
        </label>
        <?php endforeach; ?>
        <?php if (!empty($other_years)): ?>
        <div class="cd-group-label">Other</div>
        <?php foreach ($other_years as $y): ?>
        <label>
          <input type="checkbox" name="years[]" value="<?= h($y) ?>"
                 <?= in_array($y, $selected_years) ? 'checked' : '' ?>>
          <?= h($y) ?>
        </label>
        <?php endforeach; ?>
        <?php endif; ?>
        <div class="cd-footer">
          <button type="button" onclick="setCurrentYears()">Current Years</button>
          <button type="button" onclick="setYears(false)">None</button>
        </div>
      </div>
    </div>
  </div>
  <div>
    <label style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#5a6a7a;display:block;margin-bottom:.2rem">Region</label>
    <select name="region">
      <option value="">All Regions</option>
      <?php foreach (['North','Central','South'] as $r): ?>
        <option value="<?= h($r) ?>" <?= $region===$r?'selected':''?>><?= h($r) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div>
    <label style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#5a6a7a;display:block;margin-bottom:.2rem">Missing Data</label>
    <select name="missing">
      <?php foreach (MISSING_DATA_OPTIONS as $val => $label): ?>
        <option value="<?= h($val) ?>" <?= $missing===$val?'selected':''?>><?= h($label) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div>
    <label style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#5a6a7a;display:block;margin-bottom:.2rem">Birthday Month</label>
    <select name="bmonth">
      <option value="">Any month</option>
      <?php foreach (range(1,12) as $m): ?>
        <option value="<?= $m ?>" <?= $bmonth==(string)$m?'selected':''?>><?= h(date('F', mktime(0,0,0,$m,1))) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div>
    <label style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#5a6a7a;display:block;margin-bottom:.2rem">Consents</label>
    <select name="consent">
      <option value="">Any</option>
      <option value="photo_yes"     <?= $consent==='photo_yes'    ?'selected':''?>>Photo Consent: Yes</option>
      <option value="photo_no"      <?= $consent==='photo_no'     ?'selected':''?>>Photo Consent: No</option>
      <option value="directory_yes" <?= $consent==='directory_yes'?'selected':''?>>Directory Consent: Yes</option>
    </select>
  </div>
  <div>
    <label style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#5a6a7a;display:block;margin-bottom:.2rem">Members</label>
    <select name="member">
      <option value="">Any</option>
      <option value="board"  <?= $member_status==='board' ?'selected':''?>>Board Members</option>
      <option value="paid"   <?= $member_status==='paid'  ?'selected':''?>>Members</option>
      <option value="unpaid" <?= $member_status==='unpaid'?'selected':''?>>Non-Paid Members</option>
    </select>
  </div>
  <button type="submit">Filter</button>
  <a href="directory.php">Reset</a>
</form>

<?php
$by_year = [];
foreach ($members as $m) { $by_year[$m['class_year']][] = $m; }
foreach ($by_year as $yr => $group):
?>
<div class="yr-group">
  <div class="yr-label">Class of <?= h($yr) ?> &mdash; <?= count($group) ?> member<?= count($group)!==1?'s':''?></div>
  <div class="grid">
  <?php foreach ($group as $m):
    $sqd = $m['squadron_yr2_4'] ?: ($m['fall_squadron'] ?: $m['bct_squadron']);
    $v = fn(string $k) => h((string)($m[$k] ?? ''));
  ?>
  <div class="card">
    <div class="cadet-name"><?= h(cadet_last_name_suffixed($m)) ?>, <?= h(trim(($m['cadet_first_name'] ?? '') . ' ' . ($m['cadet_middle_name'] ?? ''))) ?></div>
    <div class="cadet-meta">
      <?= h((string)($sqd ?? '')) ?><?= $sqd && $m['al_region'] ? ' &bull; ' : '' ?><?= $v('al_region') ?>
      <?php if ($m['cadet_po_box']): ?>&bull; PO <?= $v('cadet_po_box') ?><?php endif; ?>
      <?php if ($m['cadet_birthday']): ?>&bull; 🎂 <?= h(date('M j', strtotime($m['cadet_birthday']))) ?><?php endif; ?>
    </div>

    <?php if ($m['parent1_first_name'] || $m['parent1_last_name']): ?>
    <div class="p-name"><?= h(trim(($m['parent1_first_name'] ?? '').' '.($m['parent1_last_name'] ?? ''))) ?></div>
    <div class="p-detail">
      <?php if ($m['parent1_cell']): ?><a href="tel:<?= h(preg_replace('/\D/','',$m['parent1_cell'])) ?>"><?= $v('parent1_cell') ?></a><br><?php endif; ?>
      <?php if ($m['parent1_email']): ?><a href="mailto:<?= $v('parent1_email') ?>"><?= $v('parent1_email') ?></a><?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($m['parent2_first_name'] || $m['parent2_last_name']): ?>
    <div class="divider"></div>
    <div class="p-name"><?= h(trim(($m['parent2_first_name'] ?? '').' '.($m['parent2_last_name'] ?? ''))) ?></div>
    <div class="p-detail">
      <?php if ($m['parent2_cell']): ?><a href="tel:<?= h(preg_replace('/\D/','',$m['parent2_cell'])) ?>"><?= $v('parent2_cell') ?></a><br><?php endif; ?>
      <?php if ($m['parent2_email']): ?><a href="mailto:<?= $v('parent2_email') ?>"><?= $v('parent2_email') ?></a><?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($m['parent1_street']): ?>
    <div class="divider"></div>
    <div class="p-detail"><?= $v('parent1_street') ?><br><?= $v('parent1_city') ?>, <?= $v('parent1_state') ?> <?= $v('parent1_zip') ?></div>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
  </div>
</div>
<?php endforeach; ?>

<?php if (empty($members)): ?>
<p style="color:#5a6a7a;font-size:.9rem">No members found.</p>
<?php endif; ?>

<script>
var cd  = document.getElementById('year-cd');
var btn = document.getElementById('year-btn');
var cbs = cd.querySelectorAll('input[type=checkbox]');

function updateLabel() {
  var checked     = Array.from(cbs).filter(function(c){ return c.checked; }).map(function(c){ return c.value; });
  var currentVals = Array.from(cbs).filter(function(c){ return c.dataset.current; }).map(function(c){ return c.value; });
  var isCurrent   = checked.length === currentVals.length && currentVals.every(function(v){ return checked.indexOf(v) !== -1; });
  btn.childNodes[0].textContent = checked.length === 0          ? 'All Years'     :
                                  checked.length === cbs.length  ? 'All Years'     :
                                  isCurrent                      ? 'Current Years' :
                                  checked.join(', ');
}

btn.addEventListener('click', function(e){
  e.stopPropagation();
  cd.classList.toggle('open');
});
document.addEventListener('click', function(){ cd.classList.remove('open'); });
cd.querySelector('.cd-panel').addEventListener('click', function(e){ e.stopPropagation(); });
cbs.forEach(function(cb){ cb.addEventListener('change', updateLabel); });
updateLabel();

function setYears(state) {
  cbs.forEach(function(cb){ cb.checked = state; });
  updateLabel();
}

function setCurrentYears() {
  cbs.forEach(function(cb){ cb.checked = !!cb.dataset.current; });
  updateLabel();
}
</script>

</body></html>
