<?php
require_once __DIR__ . '/auth.php';
require_member_admin();
$pdo = get_pdo();

$all_years     = CLASS_YEAR_LIST;
$current_years = array_merge(current_class_years(), ['Prep School']);
$other_years   = array_values(array_diff($all_years, $current_years));

$selected_years = $_POST['years']  ?? [];
$region         = $_POST['region'] ?? '';
$type           = $_POST['type']   ?? 'emails';
$paid           = $_POST['paid']   ?? '';
$days_raw       = (int)($_POST['days'] ?? 30);
$days           = in_array($days_raw, [30,60,90,365]) ? $days_raw : 30;

$results = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $where  = [$type === 'archived_members' ? 'archived = 1' : 'archived = 0'];
    $params = [];

    $selected_years = array_intersect((array)$selected_years, $all_years);
    if (!empty($selected_years) && count($selected_years) < count($all_years)) {
        $ph = [];
        foreach (array_values($selected_years) as $i => $y) {
            $key = ':yr' . $i;
            $ph[]       = $key;
            $params[$key] = $y;
        }
        $where[] = 'class_year IN (' . implode(', ', $ph) . ')';
    }

    if ($region !== '') { $where[] = 'al_region = :region'; $params[':region'] = $region; }
    if ($paid === '1')  { $where[] = 'membership_paid = 1'; }
    if ($paid === '0')  { $where[] = 'membership_paid = 0'; }
    if ($type === 'new_members') { $where[] = "created_at >= DATE_SUB(NOW(), INTERVAL $days DAY)"; }

    $sql = 'SELECT cadet_last_name, cadet_suffix, cadet_first_name, cadet_middle_name, class_year, al_region,
                   cadet_gender, cadet_po_box, cadet_birthday, bct_squadron, bct_flight, fall_squadron, squadron_yr2_4,
                   parent1_first_name, parent1_last_name, parent1_email, parent1_cell,
                   parent1_street, parent1_city, parent1_state, parent1_zip,
                   parent2_first_name, parent2_last_name, parent2_email, parent2_cell,
                   parent2_street, parent2_city, parent2_state, parent2_zip,
                   cadet_email, cadet_cell, membership_paid, membership_year,
                   created_at, remarks
            FROM members WHERE ' . implode(' AND ', $where)
         . ' ORDER BY class_year, cadet_last_name';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $lines = [];
    $sqd_groups = [];
    foreach ($rows as $r) {
        $cadet_fm   = trim($r['cadet_first_name'] . ' ' . $r['cadet_middle_name']);
        $cadet_full = cadet_full_name($r);
        $cadet_last = trim(cadet_last_name_suffixed($r) . ', ' . $cadet_fm);

        $addr = function(string $prefix) use ($r): string {
            $street = trim($r[$prefix . '_street'] ?? '');
            $city   = trim($r[$prefix . '_city']   ?? '');
            $state  = trim($r[$prefix . '_state']  ?? '');
            $zip    = trim($r[$prefix . '_zip']    ?? '');
            if (!$street && !$city) return '';
            return "$street\n" . trim("$city, $state $zip");
        };

        switch ($type) {
            case 'emails':
                if ($r['parent1_email']) {
                    $name = trim($r['parent1_first_name'] . ' ' . $r['parent1_last_name']);
                    $lines[] = "$name <{$r['parent1_email']}>";
                }
                if ($r['parent2_email']) {
                    $name = trim($r['parent2_first_name'] . ' ' . $r['parent2_last_name']);
                    $lines[] = "$name <{$r['parent2_email']}>";
                }
                break;
            case 'emails_plain':
                if ($r['parent1_email']) $lines[] = $r['parent1_email'];
                if ($r['parent2_email']) $lines[] = $r['parent2_email'];
                break;
            case 'cadet_emails':
                if ($r['cadet_email']) $lines[] = "$cadet_last: {$r['cadet_email']}";
                break;
            case 'cadet_emails_plain':
                if ($r['cadet_email']) $lines[] = $r['cadet_email'];
                break;
            case 'cadet_cells':
                if ($r['cadet_cell']) $lines[] = "$cadet_last: {$r['cadet_cell']}";
                break;
            case 'cells':
                if ($r['parent1_cell']) {
                    $name = trim($r['parent1_first_name'] . ' ' . $r['parent1_last_name']);
                    $lines[] = "$name ({$cadet_last}): {$r['parent1_cell']}";
                }
                if ($r['parent2_cell']) {
                    $name = trim($r['parent2_first_name'] . ' ' . $r['parent2_last_name']);
                    $lines[] = "$name ({$cadet_last}): {$r['parent2_cell']}";
                }
                break;
            case 'addr1':
                $a = $addr('parent1');
                if ($a) {
                    $name = trim($r['parent1_first_name'] . ' ' . $r['parent1_last_name']);
                    $lines[] = "$name\n$a\n";
                }
                break;
            case 'addr2':
                $a = $addr('parent2');
                if ($a) {
                    $name = trim($r['parent2_first_name'] . ' ' . $r['parent2_last_name']);
                    $lines[] = "$name\n$a\n";
                }
                break;
            case 'addr_both':
                $a1 = $addr('parent1');
                if ($a1) {
                    $name = trim($r['parent1_first_name'] . ' ' . $r['parent1_last_name']);
                    $lines[] = "$name\n$a1\n";
                }
                $a2 = $addr('parent2');
                if ($a2) {
                    $name = trim($r['parent2_first_name'] . ' ' . $r['parent2_last_name']);
                    $lines[] = "$name\n$a2\n";
                }
                break;
            case 'birthdays':
                if ($r['cadet_birthday']) {
                    $bday = date('M j', strtotime($r['cadet_birthday']));
                    $box  = $r['cadet_po_box'] ? ' — PO Box ' . $r['cadet_po_box'] : '';
                    $lines[] = $bday . ' — ' . $cadet_last . $box;
                }
                break;
            case 'quick_contact':
                $p1first = trim($r['parent1_first_name']);
                $p1cell  = trim($r['parent1_cell']);
                if ($p1cell) $lines[] = $cadet_full . ' — ' . $p1first . ': ' . $p1cell;
                break;
            case 'new_members':
                $added = $r['created_at'] ? date('M j, Y', strtotime($r['created_at'])) : '';
                $p1 = trim($r['parent1_first_name'] . ' ' . $r['parent1_last_name']);
                $lines[] = $cadet_last . ' (' . $r['class_year'] . ') — ' . $p1
                         . ($r['parent1_email'] ? ' — ' . $r['parent1_email'] : '')
                         . ($added ? ' — Added ' . $added : '');
                break;
            case 'dues_paid':
                if ($r['membership_paid']) {
                    $p1 = trim($r['parent1_first_name'] . ' ' . $r['parent1_last_name']);
                    $lines[] = $cadet_last . ' — ' . $p1 . ' (' . $r['membership_year'] . ')';
                }
                break;
            case 'dues_unpaid':
                if (!$r['membership_paid']) {
                    $p1 = trim($r['parent1_first_name'] . ' ' . $r['parent1_last_name']);
                    $p1email = $r['parent1_email'] ? ' — ' . $r['parent1_email'] : '';
                    $lines[] = $cadet_last . ' — ' . $p1 . $p1email;
                }
                break;
            case 'missing_data':
                $missing = [];
                if (!$r['parent1_email'])  $missing[] = 'P1 email';
                if (!$r['parent1_cell'])   $missing[] = 'P1 cell';
                if (!$r['parent1_street']) $missing[] = 'P1 address';
                if (!$r['al_region'])      $missing[] = 'AL region';
                if (!$r['cadet_email'])    $missing[] = 'cadet email';
                if ($missing) $lines[] = $cadet_last . ' (' . $r['class_year'] . ') — Missing: ' . implode(', ', $missing);
                break;
            case 'missing_po_box':
                if (!trim($r['cadet_po_box'] ?? '')) {
                    $p1 = trim($r['parent1_first_name'] . ' ' . $r['parent1_last_name']);
                    $lines[] = $cadet_last . ' (' . $r['class_year'] . ')' . ($r['parent1_email'] ? ' — ' . $r['parent1_email'] : ($p1 ? ' — ' . $p1 : ''));
                }
                break;
            case 'missing_cadet_email':
                if (!trim($r['cadet_email'] ?? '')) {
                    $p1 = trim($r['parent1_first_name'] . ' ' . $r['parent1_last_name']);
                    $lines[] = $cadet_last . ' (' . $r['class_year'] . ')' . ($r['parent1_email'] ? ' — ' . $r['parent1_email'] : ($p1 ? ' — ' . $p1 : ''));
                }
                break;
            case 'missing_gender':
                if (!trim($r['cadet_gender'] ?? '')) {
                    $p1 = trim($r['parent1_first_name'] . ' ' . $r['parent1_last_name']);
                    $lines[] = $cadet_last . ' (' . $r['class_year'] . ')' . ($r['parent1_email'] ? ' — ' . $r['parent1_email'] : ($p1 ? ' — ' . $p1 : ''));
                }
                break;
            case 'archived_members':
                $p1 = trim($r['parent1_first_name'] . ' ' . $r['parent1_last_name']);
                $lines[] = $cadet_last . ' (' . $r['class_year'] . ')' . ($p1 ? ' — ' . $p1 : '')
                         . ($r['parent1_email'] ? ' — ' . $r['parent1_email'] : '');
                break;
            case 'care_labels':
                $box = trim($r['cadet_po_box'] ?? '');
                if ($box) {
                    $lines[] = 'Cadet ' . $cadet_full . "\n"
                             . 'P.O. Box ' . $box . "\n"
                             . 'USAF Academy, CO 80841-' . $box . "\n";
                }
                break;
            case 'sqd_roster':
                $sqd = $r['squadron_yr2_4'] ?: ($r['fall_squadron'] ?: $r['bct_squadron']);
                if (!isset($sqd_groups[$sqd])) $sqd_groups[$sqd] = [];
                $p1 = trim($r['parent1_first_name'] . ' ' . $r['parent1_last_name']);
                $sqd_groups[$sqd][] = $cadet_last . ' — ' . $p1
                    . ($r['parent1_cell'] ? ': ' . $r['parent1_cell'] : '')
                    . ($r['parent1_email'] ? ' / ' . $r['parent1_email'] : '');
                break;
            case 'cadet_addr':
                $box = trim($r['cadet_po_box'] ?? '');
                $lines[] = "Cadet $cadet_full"
                         . ($box ? "\nP.O. Box $box" : '')
                         . "\nUSAF Academy, CO 80841" . ($box ? "-$box" : '')
                         . "\n";
                break;
        }
    }

    // Flatten squadron grouping
    if ($type === 'sqd_roster') {
        ksort($sqd_groups);
        foreach ($sqd_groups as $sqd => $members) {
            $lines[] = '=== Squadron ' . ($sqd ?: 'Unknown') . ' ===';
            foreach ($members as $entry) $lines[] = $entry;
            $lines[] = '';
        }
    }

    // Sort birthday list by month/day
    if ($type === 'birthdays') {
        usort($lines, function($a, $b) {
            return strcmp(substr($a, 0, 6), substr($b, 0, 6));
        });
    }

    $is_current_selection = count($selected_years) === count($current_years)
        && empty(array_diff($current_years, $selected_years));
    $year_label = empty($selected_years) ? 'No Years' :
                  (count($selected_years) === count($all_years) ? 'All Years' :
                   ($is_current_selection ? 'Current Years' :
                   implode(', ', $selected_years)));
    $label   = $year_label . ' / ' . ($region ?: 'All Regions');
    $results = ['lines' => $lines, 'rows' => $rows, 'count' => count($rows), 'label' => $label];
}

admin_header('Lists');
?>
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
</style>

<div class="page-head">
  <h1>Contact Lists</h1>
</div>

<div class="card">
  <form method="POST" id="listform">
    <div class="form-row" style="align-items:flex-end;grid-template-columns:1fr 1fr 1fr 1fr 1fr auto">

      <!-- Year multi-select dropdown -->
      <div class="form-group">
        <label>Class Year</label>
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
              <button type="button" class="btn btn-secondary btn-sm" onclick="setCurrentYears()">Current Years</button>
              <button type="button" class="btn btn-secondary btn-sm" onclick="setYears(false)">None</button>
            </div>
          </div>
        </div>
      </div>

      <div class="form-group">
        <label>AL Region</label>
        <select name="region">
          <option value="">All Regions</option>
          <?php foreach (['North','Central','South'] as $r): ?>
            <option value="<?= h($r) ?>" <?= $region === $r ? 'selected' : '' ?>><?= h($r) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-group">
        <label>Dues Status</label>
        <select name="paid">
          <option value="">All Members</option>
          <option value="1" <?= $paid==='1'?'selected':''?>>Paid Only</option>
          <option value="0" <?= $paid==='0'?'selected':''?>>Unpaid Only</option>
        </select>
      </div>

      <div class="form-group">
        <label>Output</label>
        <select name="type">
          <optgroup label="Emails">
            <option value="emails"       <?= $type==='emails'       ?'selected':''?>>Parent Emails (Name &lt;email&gt;)</option>
            <option value="emails_plain" <?= $type==='emails_plain' ?'selected':''?>>Parent Emails (plain list)</option>
            <option value="cadet_emails"       <?= $type==='cadet_emails'       ?'selected':''?>>Cadet Emails (with name)</option>
            <option value="cadet_emails_plain" <?= $type==='cadet_emails_plain' ?'selected':''?>>Cadet Emails (plain list)</option>
          </optgroup>
          <optgroup label="Phone">
            <option value="cells"        <?= $type==='cells'        ?'selected':''?>>Parent Cell Numbers</option>
            <option value="cadet_cells"  <?= $type==='cadet_cells'  ?'selected':''?>>Cadet Cell Numbers</option>
          </optgroup>
          <optgroup label="Addresses">
            <option value="addr1"        <?= $type==='addr1'        ?'selected':''?>>Parent 1 Addresses</option>
            <option value="addr2"        <?= $type==='addr2'        ?'selected':''?>>Parent 2 Addresses</option>
            <option value="addr_both"    <?= $type==='addr_both'    ?'selected':''?>>Both Parent Addresses</option>
            <option value="cadet_addr"   <?= $type==='cadet_addr'   ?'selected':''?>>Cadet Address at USAFA</option>
          </optgroup>
          <optgroup label="Quick Lists">
            <option value="birthdays"     <?= $type==='birthdays'     ?'selected':''?>>Birthday List (by date)</option>
            <option value="quick_contact" <?= $type==='quick_contact' ?'selected':''?>>Quick Contact (cadet + parent cell)</option>
            <option value="new_members"   <?= $type==='new_members'   ?'selected':''?>>New Members</option>
          </optgroup>
          <optgroup label="Membership">
            <option value="dues_paid"    <?= $type==='dues_paid'    ?'selected':''?>>Paid Members List</option>
            <option value="dues_unpaid"  <?= $type==='dues_unpaid'  ?'selected':''?>>Unpaid Members List</option>
          </optgroup>
          <optgroup label="Data &amp; Rosters">
            <option value="missing_data"    <?= $type==='missing_data'    ?'selected':''?>>Missing Data Report</option>
            <option value="missing_po_box"  <?= $type==='missing_po_box'  ?'selected':''?>>Missing PO Box</option>
            <option value="missing_cadet_email" <?= $type==='missing_cadet_email' ?'selected':''?>>Missing Cadet Email</option>
            <option value="missing_gender" <?= $type==='missing_gender' ?'selected':''?>>Missing Gender</option>
            <option value="care_labels"  <?= $type==='care_labels'  ?'selected':''?>>Care Package Labels</option>
            <option value="sqd_roster"   <?= $type==='sqd_roster'   ?'selected':''?>>Squadron Roster</option>
            <option value="archived_members" <?= $type==='archived_members' ?'selected':''?>>Archived Cadets</option>
          </optgroup>
          <optgroup label="Full Roster">
            <option value="full_roster"  <?= $type==='full_roster'  ?'selected':''?>>Full Roster (all fields)</option>
          </optgroup>
        </select>
      </div>

      <div class="form-group" id="days-group" style="display:none">
        <label>Added Within</label>
        <select name="days">
          <option value="30"  <?= $days===30 ?'selected':''?>>Last 30 days</option>
          <option value="60"  <?= $days===60 ?'selected':''?>>Last 60 days</option>
          <option value="90"  <?= $days===90 ?'selected':''?>>Last 90 days</option>
          <option value="365" <?= $days===365?'selected':''?>>Last year</option>
        </select>
      </div>

      <div class="form-group">
        <label>&nbsp;</label>
        <div style="display:flex;gap:.5rem">
          <button type="submit" class="btn btn-primary">Generate List</button>
          <a href="lists.php" class="btn btn-secondary">Reset</a>
        </div>
      </div>

    </div>
  </form>
</div>

<?php if ($results !== null): ?>
<?php if ($type === 'full_roster'): ?>

<?php
// Build TSV for copy-to-spreadsheet
$roster_cols = [
    'Year','Last Name','Suffix','First Name','Middle Name','Birthday','PO Box',
    'BCT Sqd','BCT Flight','Fall Sqd','Yr 2-4 Sqd',
    'Cadet Email','Cadet Cell',
    'P1 Last','P1 First','P1 Email','P1 Cell','P1 Street','P1 City','P1 State','P1 Zip',
    'P2 Last','P2 First','P2 Email','P2 Cell','P2 Street','P2 City','P2 State','P2 Zip',
    'AL Region','Remarks'
];
$roster_fields = [
    'class_year','cadet_last_name','cadet_suffix','cadet_first_name','cadet_middle_name','cadet_birthday','cadet_po_box',
    'bct_squadron','bct_flight','fall_squadron','squadron_yr2_4',
    'cadet_email','cadet_cell',
    'parent1_last_name','parent1_first_name','parent1_email','parent1_cell',
    'parent1_street','parent1_city','parent1_state','parent1_zip',
    'parent2_last_name','parent2_first_name','parent2_email','parent2_cell',
    'parent2_street','parent2_city','parent2_state','parent2_zip',
    'al_region','remarks'
];
$tsv_rows = [implode("\t", $roster_cols)];
foreach ($results['rows'] as $r) {
    $tsv_rows[] = implode("\t", array_map(fn($f) => csv_formula_safe(str_replace(["\t","\n","\r"], ' ', $r[$f] ?? '')), $roster_fields));
}
$tsv = implode("\n", $tsv_rows);
?>

<div class="card" style="padding:0;overflow-x:auto">
  <div style="display:flex;justify-content:space-between;align-items:center;padding:1rem 1.25rem;flex-wrap:wrap;gap:.5rem;border-bottom:1px solid #f0f2f5">
    <h2 style="margin:0"><?= h($results['label']) ?> — <?= $results['count'] ?> members</h2>
    <button class="btn btn-secondary" onclick="copyTSV(this)">Copy to Clipboard (for Excel / Sheets)</button>
  </div>
  <textarea id="tsv-data" readonly style="position:absolute;left:-9999px;top:-9999px" aria-hidden="true"><?= h($tsv) ?></textarea>
  <table style="font-size:.78rem;white-space:nowrap">
    <thead>
      <tr>
        <?php foreach ($roster_cols as $c): ?><th><?= h($c) ?></th><?php endforeach; ?>
      </tr>
    </thead>
    <tbody>
    <?php if (empty($results['rows'])): ?>
      <tr><td colspan="<?= count($roster_cols) ?>" style="text-align:center;padding:2rem;color:#5a6a7a">No members found.</td></tr>
    <?php endif; ?>
    <?php foreach ($results['rows'] as $r): ?>
      <tr>
        <?php foreach ($roster_fields as $f): ?>
          <td><?= h($r[$f] ?? '') ?></td>
        <?php endforeach; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php else: ?>
<div class="card">
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.75rem;flex-wrap:wrap;gap:.5rem">
    <h2 style="margin:0"><?= h($results['label']) ?> — <?= count($results['lines']) ?> entries from <?= $results['count'] ?> members</h2>
    <?php if (!empty($results['lines'])): ?>
      <div style="display:flex;gap:.5rem;flex-wrap:wrap">
        <button class="btn btn-secondary" onclick="copyList(this)">Copy to Clipboard</button>
        <?php $email_types = ['emails','emails_plain','cadet_emails','cadet_emails_plain','dues_unpaid'];
        if (in_array($type, $email_types)): ?>
        <form method="POST" action="email.php" style="margin:0">
          <input type="hidden" name="recipients" value="<?= h(implode("\n", $results['lines'])) ?>">
          <button type="submit" class="btn btn-primary">Compose Email →</button>
        </form>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
  <?php if (empty($results['lines'])): ?>
    <p style="color:#5a6a7a;font-size:.9rem">No results for this filter combination.</p>
  <?php else: ?>
    <textarea id="list-output" rows="<?= min(max(count($results['lines']), 5), 30) ?>"
      style="width:100%;font-family:monospace;font-size:.85rem;padding:.75rem;border:1px solid #d0d5dd;border-radius:4px;resize:vertical;background:#f9fafb"
      readonly><?= h(implode("\n", $results['lines'])) ?></textarea>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php endif; ?>

<script>
// ── Year dropdown ──────────────────────────────────────────────────────────
var cd  = document.getElementById('year-cd');
var btn = document.getElementById('year-btn');
var cbs = cd.querySelectorAll('input[type=checkbox]');

function updateLabel() {
  var checked     = Array.from(cbs).filter(function(c){ return c.checked; }).map(function(c){ return c.value; });
  var currentVals = Array.from(cbs).filter(function(c){ return c.dataset.current; }).map(function(c){ return c.value; });
  var isCurrent   = checked.length === currentVals.length && currentVals.every(function(v){ return checked.indexOf(v) !== -1; });
  btn.childNodes[0].textContent = checked.length === 0          ? 'No Years'      :
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

// Show "Added within" only for new_members output
var typeSelect = document.querySelector('select[name="type"]');
var daysGroup  = document.getElementById('days-group');
function toggleDays() {
  daysGroup.style.display = typeSelect.value === 'new_members' ? 'block' : 'none';
}
typeSelect.addEventListener('change', toggleDays);
toggleDays();

// ── Copy button ────────────────────────────────────────────────────────────
function copyTSV(btn) {
  var ta = document.getElementById('tsv-data');
  ta.style.position = 'static';
  ta.select(); ta.setSelectionRange(0, 99999);
  if (navigator.clipboard) {
    navigator.clipboard.writeText(ta.value).then(function(){
      btn.textContent = '✓ Copied! Paste into Excel or Sheets';
      setTimeout(function(){ btn.textContent = 'Copy to Clipboard (for Excel / Sheets)'; }, 3000);
    });
  } else { document.execCommand('copy'); }
  ta.style.position = 'absolute';
}
function copyList(btn) {
  var ta = document.getElementById('list-output');
  ta.select(); ta.setSelectionRange(0, 99999);
  if (navigator.clipboard) {
    navigator.clipboard.writeText(ta.value).then(function(){
      btn.textContent = '✓ Copied!';
      setTimeout(function(){ btn.textContent = 'Copy to Clipboard'; }, 2000);
    });
  } else { document.execCommand('copy'); }
}
</script>

<?php admin_footer(); ?>
