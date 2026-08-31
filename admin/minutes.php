<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/mailer.php';
require_login();
if (!can_manage_members() && !is_secretary() && !is_treasurer()) { header('Location: dashboard.php?denied=1'); exit; }
$pdo = get_pdo();

$msg   = '';
$error = '';

// ── Directory for uploaded minutes files ──────────────────────────────────
$upload_dir = __DIR__ . '/minutes-files/';
if (!is_dir($upload_dir)) @mkdir($upload_dir, 0755, true);

// ── Minutes files are mirrored into the Document Vault (category "Meeting
// Minutes") so they're browsable there too, staying in sync automatically. ──
$vault_dir = __DIR__ . '/vault/';
if (!is_dir($vault_dir)) @mkdir($vault_dir, 0755, true);

function remove_vault_mirror(PDO $pdo, string $vault_dir, int $meeting_id): void {
    $row = $pdo->prepare('SELECT id, filename FROM vault_documents WHERE source_meeting_id=?');
    $row->execute([$meeting_id]);
    if ($v = $row->fetch(PDO::FETCH_ASSOC)) {
        if (preg_match('/^[a-zA-Z0-9._-]+$/', $v['filename'])) @unlink($vault_dir . $v['filename']);
        $pdo->prepare('DELETE FROM vault_documents WHERE id=?')->execute([$v['id']]);
    }
}

// ── Actions ───────────────────────────────────────────────────────────────
$action = $_POST['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    if ($action === 'add_meeting') {
        $date  = $_POST['meeting_date'] ?? '';
        $type  = $_POST['meeting_type'] ?? 'general';
        $title = trim($_POST['title'] ?? '');
        $loc   = trim($_POST['location'] ?? '');
        $link  = trim($_POST['meeting_link'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        if (!in_array($type, ['general','board','special','other'])) $type = 'general';
        if ($date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $error = 'Meeting date is required.';
        } elseif ($title === '') {
            $error = 'Title is required.';
        } elseif ($link !== '' && !is_safe_http_url($link)) {
            $error = 'Meeting link must start with https:// or http://';
        } else {
            $s = $pdo->prepare("INSERT INTO club_meetings (meeting_date, meeting_type, title, location, meeting_link, notes, created_by) VALUES (?,?,?,?,?,?,?)");
            $s->execute([$date, $type, $title, $loc, $link, $notes, $_SESSION['user_id']??null]);
            $msg = 'Meeting added.';
        }
    }

    elseif ($action === 'edit_meeting') {
        $id    = (int)($_POST['id'] ?? 0);
        $date  = $_POST['meeting_date'] ?? '';
        $type  = $_POST['meeting_type'] ?? 'general';
        $title = trim($_POST['title'] ?? '');
        $loc   = trim($_POST['location'] ?? '');
        $link  = trim($_POST['meeting_link'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        if (!in_array($type, ['general','board','special','other'])) $type = 'general';
        if ($id < 1 || $date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $error = 'Invalid input.';
        } elseif ($title === '') {
            $error = 'Title is required.';
        } elseif ($link !== '' && !is_safe_http_url($link)) {
            $error = 'Meeting link must start with https:// or http://';
        } else {
            $s = $pdo->prepare("UPDATE club_meetings SET meeting_date=?, meeting_type=?, title=?, location=?, meeting_link=?, notes=? WHERE id=?");
            $s->execute([$date, $type, $title, $loc, $link, $notes, $id]);
            $msg = 'Meeting updated.';
        }
    }

    elseif ($action === 'delete_meeting') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $row = $pdo->prepare("SELECT minutes_file FROM club_meetings WHERE id=?");
            $row->execute([$id]);
            $m = $row->fetch(PDO::FETCH_ASSOC);
            if ($m && $m['minutes_file']) {
                $fp = $upload_dir . basename($m['minutes_file']);
                if (is_file($fp)) @unlink($fp);
            }
            remove_vault_mirror($pdo, $vault_dir, $id);
            // Remove attendance records
            $pdo->prepare("DELETE FROM meeting_attendance WHERE meeting_id=?")->execute([$id]);
            $pdo->prepare("DELETE FROM club_meetings WHERE id=?")->execute([$id]);
            $msg = 'Meeting deleted.';
        }
    }

    elseif ($action === 'upload_minutes') {
        $id = (int)($_POST['meeting_id'] ?? 0);
        if ($id < 1) { $error = 'Invalid meeting.'; }
        elseif (!isset($_FILES['minutes_file']) || $_FILES['minutes_file']['error'] !== UPLOAD_ERR_OK) {
            $error = 'No file uploaded.';
        } else {
            $tmp  = $_FILES['minutes_file']['tmp_name'];
            $orig = $_FILES['minutes_file']['name'];
            $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
            if (!in_array($ext, ['pdf','doc','docx'])) {
                $error = 'Only PDF, DOC, or DOCX files allowed.';
            } else {
                $fi   = new finfo(FILEINFO_MIME_TYPE);
                $mime = $fi->file($tmp);
                $allowed_mimes = ['application/pdf','application/msword',
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
                if (!in_array($mime, $allowed_mimes)) {
                    $error = 'File type not allowed.';
                } else {
                    // Delete old file first
                    $old = $pdo->prepare("SELECT minutes_file FROM club_meetings WHERE id=?");
                    $old->execute([$id]);
                    $oldrow = $old->fetch(PDO::FETCH_ASSOC);
                    if ($oldrow && $oldrow['minutes_file']) {
                        $fp = $upload_dir . basename($oldrow['minutes_file']);
                        if (is_file($fp)) @unlink($fp);
                    }
                    $fname = 'minutes_' . $id . '_' . time() . '.' . $ext;
                    if (!move_uploaded_file($tmp, $upload_dir . $fname)) {
                        $error = 'Failed to save file.';
                    } else {
                        $pdo->prepare("UPDATE club_meetings SET minutes_file=? WHERE id=?")->execute([$fname, $id]);

                        // Mirror into the Document Vault (replaces any previous mirror for this meeting)
                        remove_vault_mirror($pdo, $vault_dir, $id);
                        $vault_name = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                        if (copy($upload_dir . $fname, $vault_dir . $vault_name)) {
                            $mi = $pdo->prepare('SELECT title, meeting_date, meeting_type FROM club_meetings WHERE id=?');
                            $mi->execute([$id]);
                            $minfo = $mi->fetch(PDO::FETCH_ASSOC);
                            $vault_title = trim(($minfo['title'] ?: 'Meeting Minutes') . ' — ' . date('F j, Y', strtotime($minfo['meeting_date'] ?? 'now')));
                            $vault_desc  = ucfirst($minfo['meeting_type'] ?? 'general') . ' meeting minutes';
                            $pdo->prepare('INSERT INTO vault_documents (title,category,description,filename,file_size,mime_type,uploaded_by,source_meeting_id) VALUES (?,?,?,?,?,?,?,?)')
                                ->execute([$vault_title, 'Meeting Minutes', $vault_desc, $vault_name, filesize($vault_dir . $vault_name), $mime, $_SESSION['user_id'] ?? null, $id]);
                        }

                        $msg = 'Minutes uploaded.';
                    }
                }
            }
        }
    }

    elseif ($action === 'delete_file') {
        $id = (int)($_POST['meeting_id'] ?? 0);
        if ($id > 0) {
            $row = $pdo->prepare("SELECT minutes_file FROM club_meetings WHERE id=?");
            $row->execute([$id]);
            $r = $row->fetch(PDO::FETCH_ASSOC);
            if ($r && $r['minutes_file']) {
                $fp = $upload_dir . basename($r['minutes_file']);
                if (is_file($fp)) @unlink($fp);
                remove_vault_mirror($pdo, $vault_dir, $id);
                $pdo->prepare("UPDATE club_meetings SET minutes_file='', minutes_token=NULL WHERE id=?")->execute([$id]);
                $msg = 'File removed.';
            }
        }
    }

    elseif ($action === 'notify_board') {
        $id = (int)($_POST['id'] ?? 0);
        $mq = $pdo->prepare("SELECT * FROM club_meetings WHERE id=?");
        $mq->execute([$id]);
        $meeting = $mq->fetch(PDO::FETCH_ASSOC);
        if (!$meeting || !$meeting['minutes_file']) {
            $error = 'Upload minutes before notifying the board.';
        } else {
            if (empty($meeting['minutes_token'])) {
                $meeting['minutes_token'] = bin2hex(random_bytes(24));
                $pdo->prepare("UPDATE club_meetings SET minutes_token=? WHERE id=?")
                    ->execute([$meeting['minutes_token'], $id]);
            }
            $sent = notify_board_minutes_posted($pdo, $meeting, $_SESSION['user_name'] ?? 'Secretary');
            $msg = $sent > 0
                ? "Notified $sent board member" . ($sent != 1 ? 's' : '') . '.'
                : 'No board members with an email on file were found.';
        }
    }

    if (!$error) {
        header('Location: minutes.php' . ($msg ? '?msg=' . urlencode($msg) : ''));
        exit;
    }
}

if (isset($_GET['msg'])) $msg = htmlspecialchars($_GET['msg']);

// ── Load meetings ─────────────────────────────────────────────────────────
$year  = (int)($_GET['year'] ?? date('Y'));
$edit_id = (int)($_GET['edit'] ?? 0);
$show_add = isset($_GET['add']) || $edit_id > 0;

$years_q = $pdo->query("SELECT DISTINCT YEAR(meeting_date) y FROM club_meetings ORDER BY y DESC")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array($year, $years_q)) array_unshift($years_q, $year);

// Attendance counts per meeting
$att_stmt = $pdo->query("SELECT meeting_id, COUNT(*) as cnt FROM meeting_attendance GROUP BY meeting_id");
$att_counts = [];
foreach ($att_stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $att_counts[(int)$r['meeting_id']] = (int)$r['cnt'];

$stmt = $pdo->prepare("SELECT * FROM club_meetings WHERE YEAR(meeting_date)=? ORDER BY meeting_date DESC");
$stmt->execute([$year]);
$meetings = $stmt->fetchAll(PDO::FETCH_ASSOC);

$edit_meeting = null;
if ($edit_id > 0) {
    $eq = $pdo->prepare("SELECT * FROM club_meetings WHERE id=?");
    $eq->execute([$edit_id]);
    $edit_meeting = $eq->fetch(PDO::FETCH_ASSOC);
}

$type_labels = ['general'=>'General','board'=>'Board','special'=>'Special','other'=>'Other'];
$type_colors = ['general'=>'#003594','board'=>'#1b5e20','special'=>'#A6192E','other'=>'#5a6a7a'];

admin_header('Meeting Minutes');
echo show_flash();
?>
<style>
.mm-form-box{background:#fff;border-radius:8px;box-shadow:0 1px 4px rgba(0,0,0,.08);padding:1.5rem;margin-bottom:1.5rem}
.mm-form-box h2{font-size:.8rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#5a6a7a;margin-bottom:1rem}
.mm-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:.75rem}
.mm-table{width:100%;border-collapse:collapse;font-size:.85rem}
.mm-table th{padding:.55rem 1rem;font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#5a6a7a;background:#f7f9fc;text-align:left}
.mm-table td{padding:.7rem 1rem;border-top:1px solid #f0f2f5;vertical-align:middle}
.mm-table tr:hover td{background:#fafbfc}
.type-pill{display:inline-block;padding:.13rem .5rem;border-radius:99px;font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em}
.mm-actions{display:flex;gap:.4rem;flex-wrap:wrap;align-items:center}
.att-count{font-size:.75rem;color:#5a6a7a}
</style>

<div class="page-head">
  <h1>Meeting Minutes</h1>
  <div style="display:flex;gap:.5rem;flex-wrap:wrap">
    <a href="attendance.php" class="btn btn-secondary">📋 Attendance</a>
    <a href="dashboard.php" class="btn btn-secondary">← Dashboard</a>
  </div>
</div>

<?php if ($error): ?>
<div class="alert alert-danger" style="margin-bottom:1rem"><?= h($error) ?></div>
<?php endif; ?>
<?php if ($msg): ?>
<div class="alert alert-success" style="margin-bottom:1rem"><?= h($msg) ?></div>
<?php endif; ?>

<?php if ($show_add || $edit_meeting): ?>
<!-- Add / Edit form -->
<div class="mm-form-box">
  <h2><?= $edit_meeting ? 'Edit Meeting' : 'Add Meeting' ?></h2>
  <form method="POST">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="<?= $edit_meeting ? 'edit_meeting' : 'add_meeting' ?>">
    <?php if ($edit_meeting): ?>
    <input type="hidden" name="id" value="<?= (int)$edit_meeting['id'] ?>">
    <?php endif; ?>
    <div class="mm-grid">
      <div class="form-group">
        <label>Date <span style="color:#A6192E">*</span></label>
        <input type="date" name="meeting_date" class="form-control" required
               value="<?= h($edit_meeting['meeting_date'] ?? date('Y-m-d')) ?>">
      </div>
      <div class="form-group">
        <label>Type</label>
        <select name="meeting_type" class="form-control">
          <?php foreach ($type_labels as $v => $l): ?>
          <option value="<?= $v ?>" <?= ($edit_meeting['meeting_type']??'general')===$v?'selected':'' ?>><?= $l ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label>Title <span style="color:#A6192E">*</span></label>
        <input type="text" name="title" class="form-control" required maxlength="200"
               value="<?= h($edit_meeting['title'] ?? '') ?>" placeholder="e.g. Monthly General Meeting">
      </div>
      <div class="form-group">
        <label>Location</label>
        <input type="text" name="location" class="form-control" maxlength="200"
               value="<?= h($edit_meeting['location'] ?? '') ?>" placeholder="Optional">
      </div>
      <div class="form-group">
        <label>Zoom / Google Meet Link</label>
        <input type="url" name="meeting_link" class="form-control" maxlength="500"
               value="<?= h($edit_meeting['meeting_link'] ?? '') ?>" placeholder="https://zoom.us/j/… or https://meet.google.com/…">
      </div>
    </div>
    <div class="form-group" style="margin-top:.5rem">
      <label>Notes</label>
      <textarea name="notes" class="form-control" rows="2" maxlength="2000"><?= h($edit_meeting['notes'] ?? '') ?></textarea>
    </div>
    <div style="display:flex;gap:.5rem;margin-top:1rem">
      <button type="submit" class="btn btn-primary"><?= $edit_meeting ? 'Save Changes' : 'Add Meeting' ?></button>
      <a href="minutes.php" class="btn btn-secondary">Cancel</a>
    </div>
  </form>
</div>
<?php endif; ?>

<!-- Year filter + Add button -->
<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.6rem;margin-bottom:1rem">
  <form method="GET" style="display:flex;align-items:center;gap:.5rem">
    <label style="font-size:.75rem;font-weight:700;color:#5a6a7a">Year:</label>
    <select name="year" onchange="this.form.submit()" style="padding:.35rem .6rem;font-size:.85rem;border:1px solid #d0d5dd;border-radius:4px">
      <?php foreach ($years_q as $y): ?>
      <option value="<?= $y ?>" <?= $y==$year?'selected':'' ?>><?= $y ?></option>
      <?php endforeach; ?>
    </select>
  </form>
  <a href="minutes.php?add=1" class="btn btn-primary">+ Add Meeting</a>
</div>

<?php if (empty($meetings)): ?>
  <p style="color:#9aa5b4">No meetings found for <?= $year ?>. <a href="minutes.php?add=1">Add one.</a></p>
<?php else: ?>
<div class="card" style="padding:0;overflow-x:auto">
<table class="mm-table">
  <thead>
    <tr>
      <th>Date</th>
      <th>Type</th>
      <th>Title</th>
      <th>Location</th>
      <th>Minutes</th>
      <th>Attendance</th>
      <th>Actions</th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($meetings as $m):
    $tc = $type_colors[$m['meeting_type']] ?? '#5a6a7a';
    $att = $att_counts[(int)$m['id']] ?? 0;
  ?>
  <tr>
    <td style="white-space:nowrap;font-weight:600"><?= date('M j, Y', strtotime($m['meeting_date'])) ?></td>
    <td>
      <span class="type-pill" style="background:<?= $tc ?>22;color:<?= $tc ?>"><?= h($type_labels[$m['meeting_type']] ?? $m['meeting_type']) ?></span>
    </td>
    <td>
      <?= h($m['title']) ?>
      <?php if ($m['notes']): ?><div style="font-size:.72rem;color:#9aa5b4;margin-top:.15rem"><?= h(mb_strimwidth($m['notes'],0,80,'…')) ?></div><?php endif; ?>
    </td>
    <td>
      <?= $m['location'] ? h($m['location']) : '<span style="color:#c0c8d4">—</span>' ?>
      <?php if (!empty($m['meeting_link'])): ?>
        <div><a href="<?= h($m['meeting_link']) ?>" target="_blank" rel="noopener" style="font-size:.75rem;color:#003594;font-weight:600;text-decoration:none">🔗 Join</a></div>
      <?php endif; ?>
    </td>
    <td>
      <?php if ($m['minutes_file']): ?>
        <a href="minutes-serve.php?id=<?= (int)$m['id'] ?>" target="_blank" style="color:#003594;font-size:.78rem;font-weight:600;text-decoration:none">📄 View</a>
        <form method="POST" style="display:inline" onsubmit="return confirm('Remove this file?')">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="delete_file">
          <input type="hidden" name="meeting_id" value="<?= (int)$m['id'] ?>">
          <button type="submit" class="btn btn-sm" style="padding:.1rem .4rem;font-size:.7rem;color:#A6192E;background:none;border:none;cursor:pointer">✕</button>
        </form>
        <form method="POST" style="display:block;margin-top:.25rem" onsubmit="return confirm('Email all board members that minutes for this meeting have been posted?')">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="notify_board">
          <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
          <button type="submit" class="btn btn-sm" style="padding:.1rem .4rem;font-size:.7rem;color:#003594;background:none;border:none;cursor:pointer;text-decoration:underline">📧 Notify Board</button>
        </form>
      <?php else: ?>
        <form method="POST" enctype="multipart/form-data" style="display:flex;align-items:center;gap:.3rem">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="upload_minutes">
          <input type="hidden" name="meeting_id" value="<?= (int)$m['id'] ?>">
          <input type="file" name="minutes_file" accept=".pdf,.doc,.docx" style="font-size:.72rem;width:120px">
          <button type="submit" class="btn btn-secondary btn-sm" style="white-space:nowrap">Upload</button>
        </form>
      <?php endif; ?>
    </td>
    <td>
      <a href="attendance.php?meeting_id=<?= (int)$m['id'] ?>" style="text-decoration:none" title="Take attendance">
        <span class="att-count" style="color:<?= $att>0?'#1b5e20':'#9aa5b4' ?>">
          <?= $att > 0 ? "✓ $att attended" : '📋 Take attendance' ?>
        </span>
      </a>
    </td>
    <td>
      <div class="mm-actions">
        <a href="minutes.php?edit=<?= (int)$m['id'] ?>" class="btn btn-secondary btn-sm">Edit</a>
        <form method="POST" onsubmit="return confirm('Delete this meeting and all its attendance records?')">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="delete_meeting">
          <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
          <button type="submit" class="btn btn-sm" style="padding:.25rem .6rem;background:#fff3f3;color:#A6192E;border:1px solid #f5c6cb;border-radius:4px;font-size:.75rem">Delete</button>
        </form>
      </div>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<div style="font-size:.75rem;color:#9aa5b4;margin-top:.75rem">
  <?= count($meetings) ?> meeting<?= count($meetings)!=1?'s':'' ?> in <?= $year ?>
</div>
<?php endif; ?>

<?php admin_footer(); ?>
