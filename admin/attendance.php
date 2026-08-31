<?php
require_once __DIR__ . '/auth.php';
require_login();
if (!can_manage_members() && !is_secretary() && !is_treasurer()) { header('Location: dashboard.php?denied=1'); exit; }
$pdo = get_pdo();

$msg   = '';
$error = '';

// ── Save attendance ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $meeting_id = (int)($_POST['meeting_id'] ?? 0);
    if ($meeting_id < 1) { $error = 'Invalid meeting.'; }
    else {
        // Verify meeting exists
        $chk = $pdo->prepare("SELECT id FROM club_meetings WHERE id=?");
        $chk->execute([$meeting_id]);
        if (!$chk->fetch()) { $error = 'Meeting not found.'; }
        else {
            $checked = isset($_POST['attended']) ? (array)$_POST['attended'] : [];
            // Replace all attendance for this meeting
            $pdo->prepare("DELETE FROM meeting_attendance WHERE meeting_id=?")->execute([$meeting_id]);
            $ins = $pdo->prepare("INSERT IGNORE INTO meeting_attendance (meeting_id, member_id, parent_slot) VALUES (?,?,?)");
            $saved = 0;
            foreach ($checked as $val) {
                if (!preg_match('/^(\d+):([12])$/', (string)$val, $mtch)) continue;
                $mid = (int)$mtch[1];
                $slot = (int)$mtch[2];
                if ($mid > 0) { $ins->execute([$meeting_id, $mid, $slot]); $saved++; }
            }
            $msg = 'Attendance saved — ' . $saved . ' member' . ($saved!=1?'s':'') . ' recorded.';
        }
    }
    if (!$error) {
        header('Location: attendance.php?meeting_id=' . ($meeting_id ?: '') . '&msg=' . urlencode($msg));
        exit;
    }
}

if (isset($_GET['msg'])) $msg = htmlspecialchars($_GET['msg']);

$meeting_id = (int)($_GET['meeting_id'] ?? 0);

// ── List view (no meeting selected) ──────────────────────────────────────
if ($meeting_id < 1) {
    $year = (int)($_GET['year'] ?? date('Y'));
    $years_q = $pdo->query("SELECT DISTINCT YEAR(meeting_date) y FROM club_meetings ORDER BY y DESC")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array($year, $years_q)) array_unshift($years_q, $year);

    $att_stmt = $pdo->query("SELECT meeting_id, COUNT(*) as cnt FROM meeting_attendance GROUP BY meeting_id");
    $att_counts = [];
    foreach ($att_stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $att_counts[(int)$r['meeting_id']] = (int)$r['cnt'];

    $parent_rows = $pdo->query("SELECT parent1_first_name, parent1_last_name, parent2_first_name, parent2_last_name FROM members WHERE archived=0")->fetchAll(PDO::FETCH_ASSOC);
    $total_mem = 0;
    foreach ($parent_rows as $pr) {
        if (trim($pr['parent1_first_name'] . $pr['parent1_last_name']) !== '') $total_mem++;
        if (trim(($pr['parent2_first_name']??'') . ($pr['parent2_last_name']??'')) !== '') $total_mem++;
    }

    $stmt = $pdo->prepare("SELECT * FROM club_meetings WHERE YEAR(meeting_date)=? ORDER BY meeting_date DESC");
    $stmt->execute([$year]);
    $meetings = $stmt->fetchAll(PDO::FETCH_ASSOC);

    admin_header('Meeting Attendance');
    ?>
<style>
.att-table{width:100%;border-collapse:collapse;font-size:.85rem}
.att-table th{padding:.55rem 1rem;font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#5a6a7a;background:#f7f9fc;text-align:left}
.att-table td{padding:.65rem 1rem;border-top:1px solid #f0f2f5;vertical-align:middle}
.att-table tr:hover td{background:#fafbfc}
.att-bar{height:8px;border-radius:4px;display:inline-block;vertical-align:middle}
</style>
<div class="page-head">
  <h1>Meeting Attendance</h1>
  <div style="display:flex;gap:.5rem">
    <a href="minutes.php" class="btn btn-secondary">📄 Minutes</a>
    <a href="dashboard.php" class="btn btn-secondary">← Dashboard</a>
  </div>
</div>

<div style="display:flex;align-items:center;gap:.6rem;margin-bottom:1rem;flex-wrap:wrap">
  <form method="GET" style="display:flex;align-items:center;gap:.5rem">
    <label style="font-size:.75rem;font-weight:700;color:#5a6a7a">Year:</label>
    <select name="year" onchange="this.form.submit()" style="padding:.35rem .6rem;font-size:.85rem;border:1px solid #d0d5dd;border-radius:4px">
      <?php foreach ($years_q as $y): ?>
      <option value="<?= $y ?>" <?= $y==$year?'selected':'' ?>><?= $y ?></option>
      <?php endforeach; ?>
    </select>
  </form>
  <span style="font-size:.8rem;color:#9aa5b4"><?= $total_mem ?> active members</span>
</div>

<?php if (empty($meetings)): ?>
  <p style="color:#9aa5b4">No meetings for <?= $year ?>. <a href="minutes.php?add=1">Add a meeting first.</a></p>
<?php else: ?>
<div class="card" style="padding:0;overflow-x:auto">
<table class="att-table">
  <thead>
    <tr>
      <th>Date</th>
      <th>Meeting</th>
      <th>Attendance</th>
      <th>Rate</th>
      <th></th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($meetings as $m):
    $cnt = $att_counts[(int)$m['id']] ?? 0;
    $pct = $total_mem > 0 ? min(100, round($cnt/$total_mem*100)) : 0;
    $bcol = $pct >= 60 ? '#1b5e20' : ($pct >= 30 ? '#f57c00' : '#9aa5b4');
  ?>
  <tr>
    <td style="white-space:nowrap;font-weight:600"><?= date('M j, Y', strtotime($m['meeting_date'])) ?></td>
    <td><?= h($m['title']) ?></td>
    <td>
      <?php if ($cnt > 0): ?>
      <span style="font-weight:700;color:<?= $bcol ?>"><?= $cnt ?></span>
      <span style="font-size:.75rem;color:#9aa5b4"> / <?= $total_mem ?></span>
      <?php else: ?>
      <span style="color:#9aa5b4;font-size:.8rem">Not taken</span>
      <?php endif; ?>
    </td>
    <td style="min-width:100px">
      <?php if ($cnt > 0): ?>
      <span style="font-size:.75rem;color:<?= $bcol ?>;font-weight:700"><?= $pct ?>%</span>
      <span class="att-bar" style="width:<?= $pct ?>px;background:<?= $bcol ?>22;border:1px solid <?= $bcol ?>55;margin-left:.4rem"></span>
      <?php endif; ?>
    </td>
    <td>
      <a href="attendance.php?meeting_id=<?= (int)$m['id'] ?>" class="btn btn-primary btn-sm">
        <?= $cnt > 0 ? 'Edit Attendance' : 'Take Attendance' ?>
      </a>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>
<?php
    admin_footer();
    exit;
}

// ── Attendance-taking view ────────────────────────────────────────────────
$mq = $pdo->prepare("SELECT * FROM club_meetings WHERE id=?");
$mq->execute([$meeting_id]);
$meeting = $mq->fetch(PDO::FETCH_ASSOC);
if (!$meeting) { header('Location: attendance.php'); exit; }

// Load members and build one attendee "slot" per parent on file
$members = $pdo->query("SELECT id, parent1_first_name, parent1_last_name, parent2_first_name, parent2_last_name, cadet_first_name, cadet_middle_name, cadet_last_name, cadet_suffix, parent1_is_board_member, parent2_is_board_member FROM members WHERE archived=0 ORDER BY parent1_last_name ASC, parent1_first_name ASC")->fetchAll(PDO::FETCH_ASSOC);

$slots = [];
foreach ($members as $mem) {
    $cadet = cadet_full_name($mem);
    $p1 = trim($mem['parent1_first_name'] . ' ' . $mem['parent1_last_name']);
    $p2 = trim(($mem['parent2_first_name']??'') . ' ' . ($mem['parent2_last_name']??''));
    if ($p1 !== '') $slots[] = ['member_id'=>(int)$mem['id'], 'slot'=>1, 'name'=>$p1, 'cadet'=>$cadet, 'board'=>!empty($mem['parent1_is_board_member'])];
    if ($p2 !== '') $slots[] = ['member_id'=>(int)$mem['id'], 'slot'=>2, 'name'=>$p2, 'cadet'=>$cadet, 'board'=>!empty($mem['parent2_is_board_member'])];
}
$board_slots  = array_values(array_filter($slots, fn($s) => $s['board']));
$cadet_slots  = array_values(array_filter($slots, fn($s) => !$s['board']));

// Load who already attended
$aq = $pdo->prepare("SELECT member_id, parent_slot FROM meeting_attendance WHERE meeting_id=?");
$aq->execute([$meeting_id]);
$attended_ids = [];
foreach ($aq->fetchAll(PDO::FETCH_ASSOC) as $r) $attended_ids[$r['member_id'] . ':' . $r['parent_slot']] = true;

$total = count($slots);
$present = count($attended_ids);

admin_header('Take Attendance — ' . h($meeting['title']));
?>
<style>
.att-row{display:flex;align-items:center;padding:.5rem .75rem;border-bottom:1px solid #f0f2f5;gap:.75rem;cursor:pointer}
.att-row:hover{background:#f7f9fc}
.att-row.checked{background:#f0faf2}
.att-cb{width:18px;height:18px;accent-color:#1b5e20;cursor:pointer;flex-shrink:0}
.att-name{font-size:.88rem;font-weight:600;color:#1a2332}
.att-cadet{font-size:.75rem;color:#9aa5b4}
.att-counter{font-size:1.1rem;font-weight:700;color:#1b5e20}
</style>

<div class="page-head">
  <h1>Take Attendance</h1>
  <a href="attendance.php" class="btn btn-secondary">← All Meetings</a>
</div>

<?php if ($error): ?><div class="alert alert-danger" style="margin-bottom:1rem"><?= h($error) ?></div><?php endif; ?>
<?php if ($msg):  ?><div class="alert alert-success" style="margin-bottom:1rem"><?= h($msg) ?></div><?php endif; ?>

<div class="card" style="padding:1.25rem;margin-bottom:1.25rem">
  <div style="font-size:.8rem;color:#5a6a7a;margin-bottom:.25rem"><?= date('l, F j, Y', strtotime($meeting['meeting_date'])) ?> · <?= h(ucfirst($meeting['meeting_type'])) ?> Meeting</div>
  <div style="font-size:1.1rem;font-weight:700;color:#002554"><?= h($meeting['title']) ?></div>
  <?php if ($meeting['location']): ?><div style="font-size:.8rem;color:#5a6a7a;margin-top:.2rem">📍 <?= h($meeting['location']) ?></div><?php endif; ?>
</div>

<?php if (empty($slots)): ?>
  <p style="color:#9aa5b4">No active members found.</p>
<?php else: ?>

<form method="POST" id="att-form">
  <?= csrf_field() ?>
  <input type="hidden" name="meeting_id" value="<?= (int)$meeting_id ?>">

  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.75rem;flex-wrap:wrap;gap:.5rem">
    <div>
      <span class="att-counter" id="att-count"><?= $present ?></span>
      <span style="font-size:.8rem;color:#9aa5b4"> / <?= $total ?> present</span>
    </div>
    <div style="display:flex;gap:.5rem;flex-wrap:wrap">
      <button type="button" class="btn btn-secondary btn-sm" onclick="markAll(true)">Mark All Present</button>
      <button type="button" class="btn btn-secondary btn-sm" onclick="markAll(false)">Clear All</button>
      <button type="submit" class="btn btn-primary btn-sm">Save Attendance</button>
    </div>
  </div>

  <?php
  $att_row = function(array $s) use ($attended_ids): void {
      $key = $s['member_id'] . ':' . $s['slot'];
      $is_checked = isset($attended_ids[$key]);
      ?>
    <label class="att-row <?= $is_checked ? 'checked' : '' ?>" onclick="toggleRow(this)">
      <input type="checkbox" name="attended[]" value="<?= h($key) ?>"
             class="att-cb" <?= $is_checked ? 'checked' : '' ?> onclick="event.stopPropagation()">
      <div>
        <div class="att-name"><?= h($s['name']) ?></div>
        <?php if ($s['cadet']): ?><div class="att-cadet">Cadet: <?= h($s['cadet']) ?></div><?php endif; ?>
      </div>
    </label>
      <?php
  };
  ?>

  <?php if ($board_slots): ?>
  <div style="font-size:.75rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#5a6a7a;margin-bottom:.4rem">Board Members</div>
  <div class="card" style="padding:0;overflow:hidden;margin-bottom:1rem">
    <?php foreach ($board_slots as $s) $att_row($s); ?>
  </div>
  <?php endif; ?>

  <?php if ($cadet_slots): ?>
  <?php if ($board_slots): ?><div style="font-size:.75rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#5a6a7a;margin-bottom:.4rem">Members</div><?php endif; ?>
  <div class="card" style="padding:0;overflow:hidden;margin-bottom:1rem">
    <?php foreach ($cadet_slots as $s) $att_row($s); ?>
  </div>
  <?php endif; ?>

  <button type="submit" class="btn btn-primary">Save Attendance</button>
  <a href="attendance.php" class="btn btn-secondary" style="margin-left:.5rem">Cancel</a>
</form>

<script>
function updateCount() {
    var count = document.querySelectorAll('.att-cb:checked').length;
    document.getElementById('att-count').textContent = count;
}
function toggleRow(label) {
    var cb = label.querySelector('.att-cb');
    cb.checked = !cb.checked;
    label.classList.toggle('checked', cb.checked);
    updateCount();
}
function markAll(checked) {
    document.querySelectorAll('.att-cb').forEach(function(cb) {
        cb.checked = checked;
        cb.closest('.att-row').classList.toggle('checked', checked);
    });
    updateCount();
}
document.querySelectorAll('.att-cb').forEach(function(cb) {
    cb.addEventListener('change', function() {
        this.closest('.att-row').classList.toggle('checked', this.checked);
        updateCount();
    });
});
</script>
<?php endif; ?>

<?php admin_footer(); ?>
