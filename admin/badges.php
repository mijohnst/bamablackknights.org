<?php
// Parents Club Badges tracker — one row per parent-on-file (not per cadet;
// cadets don't get a badge, both parents on a member record can). Deliberately
// its own table (member_badges) rather than columns on `members`, keyed by
// (member_id, parent_slot) so it just reads parent names/cities live off the
// member record instead of duplicating them.
require_once __DIR__ . '/auth.php';
require_member_admin();
$pdo = get_pdo();

$errors = [];

// badge_slots() (the authoritative parent-slot list, rebuilt from `members`
// rather than trusted from a form) lives in lib.php now — shared with
// badges-report.php so both pages' notion of "who's a badge candidate"
// can't drift apart.

$year = $_GET['year'] ?? '';
if (!in_array($year, CLASS_YEAR_LIST, true)) {
    $year = ''; // '' = default view below (current classes + Prep School)
}
$default_view = ($year === '');
$current_years = array_merge(current_class_years(), ['Prep School']);
$search = trim($_GET['q'] ?? '');
$paid_only = isset($_GET['paid']);
$needs_only = isset($_GET['needs']);
$not_delivered_only = isset($_GET['not_delivered']);

// Builds a badges.php URL that keeps every current filter except the one
// named in $toggle_key, which is set to $new_value — used to render the
// Paid/Needs/Not-Delivered controls as toggle buttons rather than a form.
function badge_filter_url(string $year, string $search, bool $paid, bool $needs, bool $not_delivered, string $toggle_key, bool $new_value): string {
    $params = [
        'year' => $year !== '' ? $year : null,
        'q' => $search !== '' ? $search : null,
        'paid' => $paid ? '1' : null,
        'needs' => $needs ? '1' : null,
        'not_delivered' => $not_delivered ? '1' : null,
    ];
    $params[$toggle_key] = $new_value ? '1' : null;
    $qs = array_filter($params);
    return 'badges.php' . ($qs ? '?' . http_build_query($qs) : '');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    // Validate against the full roster regardless of the filter the form was
    // viewed under, so a filtered view can never accidentally wipe rows it
    // didn't render (the loop below only touches slots present in $_POST anyway).
    $all_slots = badge_slots($pdo);
    $posted = [];

    foreach ($all_slots as $s) {
        $key = $s['member_id'] . ':' . $s['slot'];
        if (!isset($_POST['seen'][$key])) continue; // slot wasn't on the submitted page

        $done        = isset($_POST['done'][$key]) ? 1 : 0;
        $done_date   = trim($_POST['done_date'][$key] ?? '');
        $mailed      = isset($_POST['mailed'][$key]) ? 1 : 0;
        $mailed_date = trim($_POST['mailed_date'][$key] ?? '');
        $comment     = mb_substr(trim($_POST['comment'][$key] ?? ''), 0, 255);

        if ($done && $done_date === '') $errors[] = $s['name'] . ' (' . $s['cadet'] . '): "Done" is checked but no Done Date was entered.';
        if ($mailed && $mailed_date === '') $errors[] = $s['name'] . ' (' . $s['cadet'] . '): "Delivered" is checked but no Delivered Date was entered.';

        if (!$done) $done_date = '';
        if (!$mailed) $mailed_date = '';

        $posted[$key] = [
            'member_id' => $s['member_id'], 'slot' => $s['slot'],
            'done' => $done, 'done_date' => $done_date ?: null,
            'mailed' => $mailed, 'mailed_date' => $mailed_date ?: null,
            'comment' => $comment !== '' ? $comment : null,
        ];
    }

    if (empty($errors)) {
        $pdo->beginTransaction();
        $up = $pdo->prepare('INSERT INTO member_badges (member_id, parent_slot, done, done_date, mailed, mailed_date, comment)
            VALUES (:member_id, :slot, :done, :done_date, :mailed, :mailed_date, :comment)
            ON DUPLICATE KEY UPDATE done=VALUES(done), done_date=VALUES(done_date), mailed=VALUES(mailed), mailed_date=VALUES(mailed_date), comment=VALUES(comment)');
        foreach ($posted as $r) $up->execute($r);
        $pdo->commit();
        flash('success', 'Badge tracker saved — ' . count($posted) . ' row' . (count($posted) != 1 ? 's' : '') . ' updated.');
        $qs = array_filter(['year' => $year !== '' ? $year : null, 'q' => $search !== '' ? $search : null, 'paid' => $paid_only ? '1' : null, 'needs' => $needs_only ? '1' : null, 'not_delivered' => $not_delivered_only ? '1' : null]);
        header('Location: badges.php' . ($qs ? '?' . http_build_query($qs) : ''));
        exit;
    }
}

// ── Render ──────────────────────────────────────────────────────────────────
// Existing badge state, keyed the same way as $posted above. Loaded before
// filtering (not just for whatever survives below) since the "needs a
// badge" filter and the CSV export both need to know saved state regardless
// of which slots are currently shown.
$existing = [];
$existing_rows = $pdo->query('SELECT * FROM member_badges')->fetchAll(PDO::FETCH_ASSOC);
foreach ($existing_rows as $r) $existing[$r['member_id'] . ':' . $r['parent_slot']] = $r;

// badge_slots() only filters on a single class_year value; the default view
// spans several, so fetch everything and filter here in PHP instead.
$slots = badge_slots($pdo);
if ($default_view) {
    $slots = array_values(array_filter($slots, fn($s) => in_array($s['class_year'], $current_years, true)));
} else {
    $slots = array_values(array_filter($slots, fn($s) => $s['class_year'] === $year));
}
if ($search !== '') {
    $slots = array_values(array_filter($slots, fn($s) =>
        stripos($s['cadet'], $search) !== false || stripos($s['name'], $search) !== false));
}
if ($paid_only) {
    $slots = array_values(array_filter($slots, fn($s) => $s['paid']));
}
if ($needs_only) {
    $slots = array_values(array_filter($slots, function($s) use ($existing) {
        if (!$s['paid']) return false;
        $st = $existing[$s['member_id'] . ':' . $s['slot']] ?? null;
        return !($st && $st['done']);
    }));
}
if ($not_delivered_only) {
    $slots = array_values(array_filter($slots, function($s) use ($existing) {
        if (!$s['paid']) return false;
        $st = $existing[$s['member_id'] . ':' . $s['slot']] ?? null;
        return !($st && $st['mailed']);
    }));
}

// ── CSV export — the currently filtered view, not the whole roster ─────────
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="badges-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Class Year', 'Cadet', 'Parent', 'City', 'Done', 'Done Date', 'Delivered', 'Delivered Date', 'Comment']);
    foreach ($slots as $s) {
        $st = $existing[$s['member_id'] . ':' . $s['slot']] ?? null;
        fputcsv($out, array_map(fn($v) => is_string($v) ? csv_formula_safe($v) : $v, [
            $s['class_year'], $s['cadet'], $s['name'], $s['city'] ?? '',
            ($st && $st['done']) ? 'Yes' : 'No', $st['done_date'] ?? '',
            ($st && $st['mailed']) ? 'Yes' : 'No', $st['mailed_date'] ?? '',
            $st['comment'] ?? '',
        ]));
    }
    fclose($out);
    exit;
}

// If the form was just re-rendered after a validation error, show what the
// user typed (not what's saved) so nothing they entered gets lost.
$repost = [];
if ($errors) {
    foreach ($_POST['seen'] ?? [] as $key => $_) {
        $repost[$key] = [
            'done' => isset($_POST['done'][$key]),
            'done_date' => $_POST['done_date'][$key] ?? '',
            'mailed' => isset($_POST['mailed'][$key]),
            'mailed_date' => $_POST['mailed_date'][$key] ?? '',
            'comment' => $_POST['comment'][$key] ?? '',
        ];
    }
}

$groups = [];
foreach ($slots as $s) $groups[$s['class_year']][] = $s;

admin_header('Parents Club Badges');
?>
<style>
.badge-table{width:100%;border-collapse:collapse;font-size:.85rem}
.badge-table th{padding:.5rem .75rem;font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#5a6a7a;background:#f7f9fc;text-align:left;white-space:nowrap}
.badge-table td{padding:.5rem .75rem;border-top:1px solid #f0f2f5;vertical-align:middle}
.badge-table tr:hover td{background:#fafbfc}
.badge-table th:nth-child(4), .badge-table td:nth-child(4),
.badge-table th:nth-child(5), .badge-table td:nth-child(5),
.badge-table th:nth-child(6), .badge-table td:nth-child(6),
.badge-table th:nth-child(7), .badge-table td:nth-child(7) { text-align:center }
.badge-cb{width:17px;height:17px;accent-color:#1b5e20;cursor:pointer}
.badge-date{padding:.3rem .4rem;font-size:.8rem;border:1px solid #d0d5dd;border-radius:4px;width:9.5rem}
.badge-date:disabled{background:#f7f9fc;color:#c3cad4}
.badge-comment{padding:.3rem .4rem;font-size:.8rem;border:1px solid #d0d5dd;border-radius:4px;width:100%;min-width:11rem}
.badge-city{font-size:.75rem;color:#9aa5b4}
/* Made + delivered = green; made but not yet delivered = yellow */
.badge-row-mailed td{background:#e8f5e9}
.badge-row-done td{background:#fff8e1}
.badge-table tr.badge-row-mailed:hover td{background:#d7ecda}
.badge-table tr.badge-row-done:hover td{background:#fbeecb}
</style>

<div class="page-head">
  <h1>Parents Club Badges</h1>
  <div style="display:flex;gap:.5rem">
    <a href="badges-report.php" class="btn btn-secondary">📊 Report</a>
    <a href="badges.php" class="btn btn-secondary">Reset Filters</a>
    <a href="dashboard.php" class="btn btn-secondary">← Dashboard</a>
  </div>
</div>

<?= show_flash() ?>

<?php if ($errors): ?>
  <div class="alert alert-danger" style="margin-bottom:1rem">
    Nothing was saved — fix the following and resubmit:
    <ul style="margin:.5rem 0 0 1.25rem">
      <?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<form method="GET" style="margin-bottom:.6rem">
  <div style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap">
    <label style="font-size:.75rem;font-weight:700;color:#5a6a7a;text-transform:none;letter-spacing:normal;margin:0">Class:</label>
    <select name="year" onchange="this.form.submit()" style="padding:.35rem .6rem;font-size:.85rem;border:1px solid #d0d5dd;border-radius:4px">
      <option value="" <?= $default_view ? 'selected' : '' ?>>All classes (<?= h(implode(', ', $current_years)) ?>)</option>
      <?php foreach (CLASS_YEAR_LIST as $y): if ($y === '' || $y === 'Graduate') continue; ?>
        <option value="<?= h($y) ?>" <?= $year === $y ? 'selected' : '' ?>><?= h($y) ?></option>
      <?php endforeach; ?>
    </select>
    <label style="font-size:.75rem;font-weight:700;color:#5a6a7a;text-transform:none;letter-spacing:normal;margin:0 0 0 .5rem">Name:</label>
    <input type="text" name="q" value="<?= h($search) ?>" placeholder="Cadet or parent name" style="padding:.35rem .6rem;font-size:.85rem;border:1px solid #d0d5dd;border-radius:4px">
    <button type="submit" class="btn btn-secondary btn-sm">Search</button>
  </div>
</form>

<div style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;margin-bottom:1.25rem">
  <a href="<?= h(badge_filter_url($year, $search, $paid_only, $needs_only, $not_delivered_only, 'paid', !$paid_only)) ?>"
     class="btn btn-sm <?= $paid_only ? 'btn-primary' : 'btn-secondary' ?>">Paid members only</a>
  <a href="<?= h(badge_filter_url($year, $search, $paid_only, $needs_only, $not_delivered_only, 'needs', !$needs_only)) ?>"
     class="btn btn-sm <?= $needs_only ? 'btn-primary' : 'btn-secondary' ?>">Needs a badge (paid, not done)</a>
  <a href="<?= h(badge_filter_url($year, $search, $paid_only, $needs_only, $not_delivered_only, 'not_delivered', !$not_delivered_only)) ?>"
     class="btn btn-sm <?= $not_delivered_only ? 'btn-primary' : 'btn-secondary' ?>">Paid, not delivered</a>
    <?php $export_qs = array_filter(['year' => $year !== '' ? $year : null, 'q' => $search !== '' ? $search : null, 'paid' => $paid_only ? '1' : null, 'needs' => $needs_only ? '1' : null, 'not_delivered' => $not_delivered_only ? '1' : null, 'export' => 'csv']); ?>
    <a href="badges.php?<?= http_build_query($export_qs) ?>" class="btn btn-secondary btn-sm">⬇ Export CSV</a>
</div>

<?php if (empty($slots)): ?>
  <p style="color:#9aa5b4">No members found for this filter.</p>
<?php else: ?>

<form method="POST" id="badges-form">
  <?= csrf_field() ?>

  <?php foreach ($groups as $gyear => $gslots):
      $done_cnt = 0; $mailed_cnt = 0;
      foreach ($gslots as $s) {
          $key = $s['member_id'] . ':' . $s['slot'];
          $st = $existing[$key] ?? null;
          if ($st && $st['done']) $done_cnt++;
          if ($st && $st['mailed']) $mailed_cnt++;
      }
  ?>
  <div style="display:flex;align-items:baseline;gap:.6rem;margin:1.25rem 0 .4rem;flex-wrap:wrap">
    <h3 style="margin:0;color:#002554"><?= h($gyear) ?></h3>
    <span style="font-size:.78rem;color:#9aa5b4"><?= $done_cnt ?>/<?= count($gslots) ?> done · <?= $mailed_cnt ?>/<?= count($gslots) ?> delivered</span>
  </div>
  <div class="card" style="padding:0;overflow-x:auto">
  <table class="badge-table">
    <thead>
      <tr>
        <th>Cadet</th>
        <th>Parent</th>
        <th>City</th>
        <th>Done</th>
        <th>Done Date</th>
        <th>Delivered</th>
        <th>Delivered Date</th>
        <th>Comment</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($gslots as $s):
          $key = $s['member_id'] . ':' . $s['slot'];
          $st = $existing[$key] ?? null;
          $rp = $repost[$key] ?? null;
          $done        = $rp ? $rp['done']        : (bool)($st['done'] ?? false);
          $done_date   = $rp ? $rp['done_date']   : ($st['done_date'] ?? '');
          $mailed      = $rp ? $rp['mailed']      : (bool)($st['mailed'] ?? false);
          $mailed_date = $rp ? $rp['mailed_date'] : ($st['mailed_date'] ?? '');
          $comment     = $rp ? $rp['comment']     : ($st['comment'] ?? '');
          $row_class   = $mailed ? 'badge-row-mailed' : ($done ? 'badge-row-done' : '');
      ?>
      <tr class="<?= $row_class ?>">
        <td><?= h($s['cadet']) ?></td>
        <td><?= h($s['name']) ?></td>
        <td class="badge-city"><?= h($s['city'] ?? '') ?></td>
        <td>
          <input type="hidden" name="seen[<?= h($key) ?>]" value="1">
          <input type="checkbox" class="badge-cb badge-done-cb" name="done[<?= h($key) ?>]" data-key="<?= h($key) ?>" <?= $done ? 'checked' : '' ?>>
        </td>
        <td><input type="date" class="badge-date badge-done-date" name="done_date[<?= h($key) ?>]" data-key="<?= h($key) ?>" value="<?= h($done_date) ?>" <?= $done ? '' : 'disabled' ?>></td>
        <td>
          <input type="checkbox" class="badge-cb badge-mailed-cb" name="mailed[<?= h($key) ?>]" data-key="<?= h($key) ?>" <?= $mailed ? 'checked' : '' ?>>
        </td>
        <td><input type="date" class="badge-date badge-mailed-date" name="mailed_date[<?= h($key) ?>]" data-key="<?= h($key) ?>" value="<?= h($mailed_date) ?>" <?= $mailed ? '' : 'disabled' ?>></td>
        <td><input type="text" class="badge-comment" name="comment[<?= h($key) ?>]" value="<?= h($comment) ?>" maxlength="255"></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endforeach; ?>

  <div style="margin-top:1.25rem">
    <button type="submit" class="btn btn-primary">Save Badge Tracker</button>
  </div>
</form>

<script>
// Checking "Done"/"Delivered" enables and requires its date field (and fills
// today's date as a starting point, editable for backfilling); unchecking
// disables it again — disabled fields don't get submitted, and the server
// independently blanks the date for any row whose checkbox came back unchecked.
function updateRowColor(cb) {
    var row = cb.closest('tr');
    var mailedCb = row.querySelector('.badge-mailed-cb');
    var doneCb = row.querySelector('.badge-done-cb');
    row.classList.remove('badge-row-done', 'badge-row-mailed');
    if (mailedCb.checked) row.classList.add('badge-row-mailed');
    else if (doneCb.checked) row.classList.add('badge-row-done');
}
function wireBadgeCheckbox(cbClass, dateClass) {
    document.querySelectorAll('.' + cbClass).forEach(function(cb) {
        cb.addEventListener('change', function() {
            var date = document.querySelector('.' + dateClass + '[data-key="' + this.dataset.key + '"]');
            date.disabled = !this.checked;
            date.required = this.checked;
            if (this.checked && !date.value) {
                var today = new Date().toISOString().slice(0, 10);
                date.value = today;
            }
            updateRowColor(this);
        });
    });
}
wireBadgeCheckbox('badge-done-cb', 'badge-done-date');
wireBadgeCheckbox('badge-mailed-cb', 'badge-mailed-date');
// Existing checked rows on page load also need `required` set, so the
// browser's own validation catches someone blanking a date without unchecking.
document.querySelectorAll('.badge-done-cb:checked').forEach(function(cb) {
    document.querySelector('.badge-done-date[data-key="' + cb.dataset.key + '"]').required = true;
});
document.querySelectorAll('.badge-mailed-cb:checked').forEach(function(cb) {
    document.querySelector('.badge-mailed-date[data-key="' + cb.dataset.key + '"]').required = true;
});
</script>

<?php endif; ?>
<?php admin_footer(); ?>
