<?php
require_once __DIR__ . '/auth.php';
require_login();

// Narrower than the general photo-submissions approval queue (any board/
// admin role there) — Job Drop Night is deliberately restricted to
// President, VP, or Secretary. officer_title is looked up fresh from the
// DB rather than trusted from $_SESSION, same reasoning as the President/VP
// cross-approval check in purchase-action.php: a title set/changed after
// this session started must still be honored immediately.
$pdo = get_pdo();
$title_check = $pdo->prepare('SELECT officer_title FROM users WHERE id = ?');
$title_check->execute([$_SESSION['user_id'] ?? 0]);
$my_title = (string)($title_check->fetchColumn() ?: '');
$can_approve = is_super_admin() || is_secretary() || in_array($my_title, ['President', 'VP'], true);
if (!$can_approve) { header('Location: dashboard.php?denied=1'); exit; }

$submissions_dir = __DIR__ . '/../job-drop-submissions/';
$photos_dir       = __DIR__ . '/../job-drop-photos/';
if (!is_dir($photos_dir)) mkdir($photos_dir, 0755, true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    $id     = (int)($_POST['id'] ?? 0);

    $s = $pdo->prepare(
        "SELECT js.*, m.class_year AS member_class_year,
                m.cadet_first_name, m.cadet_middle_name, m.cadet_last_name, m.cadet_suffix
         FROM job_drop_submissions js
         JOIN members m ON m.id = js.member_id
         WHERE js.id = ? AND js.status = 'pending'"
    );
    $s->execute([$id]);
    $sub = $s->fetch(PDO::FETCH_ASSOC);

    if ($sub) {
        if ($action === 'approve') {
            $src = $submissions_dir . basename($sub['filename']);
            $dest = $photos_dir . basename($sub['filename']);
            $moved = false;
            if (!is_file($src)) {
                flash('error', 'The submitted photo file is missing on the server — could not approve. Contact tech support.');
            } else {
              try {
                $pdo->beginTransaction();
                if (!rename($src, $dest)) throw new RuntimeException('Could not move approved photo.');
                $moved = true;
                $cadet_name = trim(preg_replace('/\s+/', ' ',
                    $sub['cadet_first_name'] . ' ' . $sub['cadet_middle_name'] . ' ' . $sub['cadet_last_name'] . ' ' . $sub['cadet_suffix']
                ));
                // Use the member's actual class rather than whichever class
                // happens to be eligible when an officer clicks Approve.
                $class_year = (string)$sub['member_class_year'];
                $next_sort = (int)$pdo->query('SELECT COALESCE(MAX(sort_order),0)+10 FROM job_drop_photos')->fetchColumn();
                $pdo->prepare('INSERT INTO job_drop_photos (filename, cadet_name, job_title, sort_order, active, class_year, youtube_id) VALUES (?,?,?,?,1,?,?)')
                    ->execute([$sub['filename'], $cadet_name, $sub['job_title'], $next_sort, $class_year, $sub['youtube_id'] ?? null]);
                $pdo->prepare("UPDATE job_drop_submissions SET status='approved', reviewed_by=?, reviewed_at=NOW() WHERE id=?")
                    ->execute([$_SESSION['user_id'] ?? null, $id]);
                $pdo->commit();
                flash('success', 'Job Drop approved and added to the homepage.');
              } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                if ($moved && is_file($dest)) @rename($dest, $src);
                error_log('job-drop approval failed: ' . $e->getMessage());
                flash('error', 'Could not approve the submission. Nothing was published; please try again or contact tech support.');
              }
            }
        } elseif ($action === 'reject') {
            $pdo->prepare("UPDATE job_drop_submissions SET status='rejected', reviewed_by=?, reviewed_at=NOW() WHERE id=?")
                ->execute([$_SESSION['user_id'] ?? null, $id]);
            $rejected_file = $submissions_dir . basename($sub['filename']);
            if (is_file($rejected_file)) @unlink($rejected_file);
            flash('success', 'Submission rejected.');
        }
    }

    if ($action === 'delete_all') {
        // Full reset for a new cadet class -- wipes every pending
        // submission and everything live on the homepage, plus their
        // photo files on disk (not just what class_year rotation would
        // otherwise still be showing here).
        $sub_files = $pdo->query('SELECT filename FROM job_drop_submissions')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($sub_files as $fn) { $p = $submissions_dir . basename($fn); if (is_file($p)) @unlink($p); }
        $live_files = $pdo->query('SELECT filename FROM job_drop_photos')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($live_files as $fn) { $p = $photos_dir . basename($fn); if (is_file($p)) @unlink($p); }
        $pending_count = count($sub_files);
        $live_count    = count($live_files);
        $pdo->exec('DELETE FROM job_drop_submissions');
        $pdo->exec('DELETE FROM job_drop_photos');
        flash('success', "Deleted all $pending_count pending submission(s) and $live_count live entr" . ($live_count === 1 ? 'y' : 'ies') . ".");
    }

    if ($action === 'toggle_section') {
        try {
            $cur = (bool)$pdo->query('SELECT section_visible FROM job_drop_settings WHERE id=1')->fetchColumn();
            $pdo->prepare('UPDATE job_drop_settings SET section_visible=? WHERE id=1')->execute([$cur ? 0 : 1]);
            flash('success', $cur ? 'Job Drop Night section hidden from the homepage.' : 'Job Drop Night section is back on the homepage.');
        } catch (PDOException $e) {
            flash('error', 'Could not update — run admin/migrate_add_job_drop_section_toggle.sql first.');
        }
    }

    if ($action === 'set_year_override') {
        $new_year = trim($_POST['override_class_year'] ?? '');
        if ($new_year !== '' && !preg_match('/^\d{4}$/', $new_year)) {
            flash('error', 'Class year override must be a 4-digit year, or left blank to go back to automatic.');
        } else {
            try {
                $pdo->prepare('UPDATE job_drop_settings SET override_class_year=? WHERE id=1')
                    ->execute([$new_year === '' ? null : $new_year]);
                flash('success', $new_year === '' ? 'Back to automatic — following the normal July rollover.' : "Job Drop Night is now manually set to the Class of $new_year.");
            } catch (PDOException $e) {
                flash('error', 'Could not save — run admin/migrate_add_job_drop_year_override.sql first.');
            }
        }
    }

    if ($action === 'toggle_live') {
        $cur = $pdo->prepare('SELECT active FROM job_drop_photos WHERE id=?');
        $cur->execute([$id]);
        $v = $cur->fetchColumn();
        if ($v !== false) {
            $pdo->prepare('UPDATE job_drop_photos SET active=? WHERE id=?')->execute([$v ? 0 : 1, $id]);
            flash('success', $v ? 'Hidden from the homepage.' : 'Back on the homepage.');
        }
    } elseif ($action === 'delete_live') {
        $row = $pdo->prepare('SELECT filename FROM job_drop_photos WHERE id=?');
        $row->execute([$id]);
        $filename = $row->fetchColumn();
        if ($filename) {
            $path = $photos_dir . basename($filename);
            if (is_file($path)) unlink($path);
            $pdo->prepare('DELETE FROM job_drop_photos WHERE id=?')->execute([$id]);
            // Permit the family to submit a replacement after an approved
            // entry is permanently removed.
            $pdo->prepare("UPDATE job_drop_submissions SET status='rejected' WHERE filename=? AND status='approved'")
                ->execute([$filename]);
            flash('success', 'Entry deleted.');
        }
    }

    if ($action === 'edit_pending' || $action === 'edit_live') {
        $job_title = trim($_POST['job_title'] ?? '');
        $cadet_name = trim($_POST['cadet_name'] ?? ''); // only used by edit_live
        $youtube_url = trim($_POST['youtube_url'] ?? '');
        $youtube_id = null;
        if ($youtube_url !== '') {
            $youtube_id = extract_youtube_id($youtube_url);
            if (!$youtube_id) {
                flash('error', "That doesn't look like a valid YouTube link.");
                header('Location: job-drop-submissions.php'); exit;
            }
        }

        $table = $action === 'edit_pending' ? 'job_drop_submissions' : 'job_drop_photos';
        $dir   = $action === 'edit_pending' ? $submissions_dir : $photos_dir;
        $row_stmt = $pdo->prepare("SELECT filename FROM $table WHERE id=?" . ($action === 'edit_pending' ? " AND status='pending'" : ''));
        $row_stmt->execute([$id]);
        $row = $row_stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            flash('error', 'That entry no longer exists.');
        } elseif ($job_title === '' || mb_strlen($job_title) > 150 || ($action === 'edit_live' && ($cadet_name === '' || mb_strlen($cadet_name) > 150))) {
            flash('error', 'Please fill in the required field(s) — job title (and cadet name for live entries) must be non-empty and under 150 characters.');
        } else {
            $filename = $row['filename'];
            $photo_warning = null;
            // Photo replacement is optional here — editing normally only
            // touches the text fields, but a mis-uploaded or blurry photo
            // can be swapped in the same place rather than deleting and
            // asking the family to resubmit from scratch.
            if (!empty($_FILES['photo']['name']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
                $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime  = finfo_file($finfo, $_FILES['photo']['tmp_name']);
                finfo_close($finfo);
                if (isset($allowed[$mime]) && $_FILES['photo']['size'] <= 10 * 1024 * 1024) {
                    $new_filename = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $allowed[$mime];
                    if (move_uploaded_file($_FILES['photo']['tmp_name'], $dir . $new_filename)) {
                        $old_path = $dir . basename($filename);
                        if (is_file($old_path)) @unlink($old_path);
                        $filename = $new_filename;
                    }
                } else {
                    $photo_warning = 'Photo must be a JPG, PNG, GIF, or WebP under 10MB — kept the existing photo, other changes were still saved.';
                }
            }

            if ($action === 'edit_pending') {
                $pdo->prepare('UPDATE job_drop_submissions SET job_title=?, youtube_id=?, filename=? WHERE id=?')
                    ->execute([$job_title, $youtube_id, $filename, $id]);
            } else {
                $pdo->prepare('UPDATE job_drop_photos SET cadet_name=?, job_title=?, youtube_id=?, filename=? WHERE id=?')
                    ->execute([$cadet_name, $job_title, $youtube_id, $filename, $id]);
            }
            flash($photo_warning ? 'error' : 'success', $photo_warning ?? 'Changes saved.');
        }
    }

    header('Location: job-drop-submissions.php'); exit;
}

$pending = $pdo->query(
    "SELECT js.id, js.filename, js.job_title, js.submitted_at, js.youtube_id,
            m.cadet_first_name, m.cadet_middle_name, m.cadet_last_name, m.cadet_suffix,
            m.parent1_first_name, m.parent1_last_name
     FROM job_drop_submissions js
     JOIN members m ON m.id = js.member_id
     WHERE js.status = 'pending' ORDER BY js.submitted_at ASC"
)->fetchAll(PDO::FETCH_ASSOC);

$live = $pdo->query(
    "SELECT id, filename, cadet_name, job_title, active, class_year, created_at, youtube_id
     FROM job_drop_photos ORDER BY class_year DESC, sort_order ASC, id ASC"
)->fetchAll(PDO::FETCH_ASSOC);

$section_visible = true;
try { $section_visible = (bool)$pdo->query('SELECT section_visible FROM job_drop_settings WHERE id=1')->fetchColumn(); }
catch (PDOException $e) { /* not migrated yet — treat as visible */ }

$auto_year = (string)((int)outgoing_class_year() + 1);
$year_override = null;
try { $year_override = $pdo->query('SELECT override_class_year FROM job_drop_settings WHERE id=1')->fetchColumn() ?: null; }
catch (PDOException $e) { /* not migrated yet */ }
$effective_year = $year_override ?: $auto_year;

admin_header('Job Drop Night Submissions');
echo show_flash();
?>
<style>
.sub-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:1rem;margin-top:1.25rem}
.sub-card{background:#fff;border-radius:6px;box-shadow:0 1px 4px rgba(0,0,0,.1);overflow:hidden}
.sub-card img{width:100%;height:180px;object-fit:cover;display:block}
.sub-body{padding:.85rem}
.sub-meta{font-size:.75rem;color:#5a6a7a;margin-bottom:.5rem}
.edit-form{display:none;margin-top:.5rem;padding-top:.6rem;border-top:1px solid #eee}
.edit-form label{display:block;font-size:.72rem;font-weight:600;color:#5a6a7a;margin-bottom:.15rem}
.edit-form input[type=text],.edit-form input[type=url]{width:100%;padding:.4rem;font-size:.82rem;margin-bottom:.5rem;border:1px solid #d0d5dd;border-radius:4px}
.edit-form input[type=file]{width:100%;font-size:.78rem;margin-bottom:.6rem}
</style>

<div class="page-head">
  <h1>Job Drop Night Submissions</h1>
  <div style="display:flex;gap:.5rem;flex-wrap:wrap">
    <form method="POST" style="margin:0">
      <?= csrf_field() ?><input type="hidden" name="action" value="toggle_section">
      <button type="submit" class="btn <?= $section_visible ? 'btn-secondary' : 'btn-primary' ?>">
        <?= $section_visible ? 'Hide Entire Section from Homepage' : '✓ Show Section on Homepage' ?>
      </button>
    </form>
    <?php if (!empty($pending) || !empty($live)): ?>
    <form method="POST" style="margin:0" onsubmit="return confirmDeleteAllJobDrop(<?= count($pending) ?>, <?= count($live) ?>)">
      <?= csrf_field() ?><input type="hidden" name="action" value="delete_all">
      <button type="submit" class="btn btn-danger">Delete All</button>
    </form>
    <?php endif; ?>
    <a href="dashboard.php" class="btn btn-secondary">← Dashboard</a>
  </div>
</div>

<script>
// Two-step delete for the full reset -- a plain confirm() is too easy to
// click through, and this permanently deletes every pending submission
// AND everything live on the homepage (all class years, not just the
// outgoing one), plus their photo files.
function confirmDeleteAllJobDrop(pendingCount, liveCount) {
  var msg = 'Permanently delete all ' + pendingCount + ' pending submission(s) and ' + liveCount + ' live entr' + (liveCount === 1 ? 'y' : 'ies') + '? This cannot be undone.';
  if (!confirm(msg)) return false;
  var typed = prompt('Type DELETE (all caps) to confirm.');
  if (typed !== 'DELETE') { alert('Not confirmed — nothing was deleted.'); return false; }
  return true;
}
</script>
<?php if (!$section_visible): ?>
<div class="alert alert-error" style="margin-bottom:1.25rem">The Job Drop Night section is currently hidden from the homepage, regardless of what's approved below.</div>
<?php endif; ?>

<div class="card" style="max-width:520px;margin-bottom:1.5rem">
  <h3 style="margin-bottom:.4rem;font-size:1rem;color:#002554">Which class is Job Drop Night open for?</h3>
  <p style="font-size:.8rem;color:#5a6a7a;margin-bottom:1rem">
    Currently: <strong>Class of <?= h($effective_year) ?></strong>
    <?= $year_override ? " (manually set — automatic would be $auto_year)" : ' (automatic — flips every July 1st)' ?>.
  </p>
  <form method="POST" style="display:flex;gap:.6rem;align-items:flex-end;flex-wrap:wrap">
    <?= csrf_field() ?><input type="hidden" name="action" value="set_year_override">
    <div class="form-group" style="margin:0">
      <label>Set manually</label>
      <input type="text" name="override_class_year" value="<?= h($year_override ?? '') ?>" placeholder="e.g. <?= h($auto_year) ?>" style="width:140px">
    </div>
    <button type="submit" class="btn btn-primary btn-sm">Save</button>
  </form>
  <?php if ($year_override): ?>
  <form method="POST" style="margin-top:.5rem">
    <?= csrf_field() ?><input type="hidden" name="action" value="set_year_override"><input type="hidden" name="override_class_year" value="">
    <button type="submit" class="btn btn-secondary btn-sm">Reset to Automatic</button>
  </form>
  <?php endif; ?>
</div>

<p style="font-size:.82rem;color:#5a6a7a;margin-bottom:1.25rem">Parent-submitted cadet job assignments awaiting review. Approving adds the photo to the homepage Job Drop Night rotation.</p>

<?php if (empty($pending)): ?>
  <p style="color:#9aa5b4">No submissions waiting for review.</p>
<?php else: ?>
<div class="sub-grid">
  <?php foreach ($pending as $s):
    $cadet_name = trim(preg_replace('/\s+/', ' ',
        $s['cadet_first_name'] . ' ' . $s['cadet_middle_name'] . ' ' . $s['cadet_last_name'] . ' ' . $s['cadet_suffix']
    ));
    $parent_name = trim($s['parent1_first_name'] . ' ' . $s['parent1_last_name']);
  ?>
  <div class="sub-card">
    <img src="/job-drop-submission-serve.php?id=<?= (int)$s['id'] ?>" alt="Preview of <?= h($cadet_name) ?>'s submitted Job Drop photo">
    <div class="sub-body">
      <div class="sub-meta">
        <strong style="color:#002554"><?= h($cadet_name) ?></strong> — <?= h($s['job_title']) ?><br>
        Submitted by <?= h($parent_name) ?> &bull; <?= date('M j, Y', strtotime($s['submitted_at'])) ?>
        <?php if (!empty($s['youtube_id'])): ?>
          <br><a href="https://www.youtube-nocookie.com/watch?v=<?= h($s['youtube_id']) ?>" target="_blank" rel="noopener">&#9654; Watch submitted video</a>
        <?php endif; ?>
      </div>
      <form method="POST">
        <?= csrf_field() ?><input type="hidden" name="action" value="approve"><input type="hidden" name="id" value="<?= $s['id'] ?>">
        <button type="submit" class="btn btn-primary btn-sm" style="width:100%">Approve</button>
      </form>
      <form method="POST" style="margin-top:.4rem" onsubmit="return confirm('Reject this submission?')">
        <?= csrf_field() ?><input type="hidden" name="action" value="reject"><input type="hidden" name="id" value="<?= $s['id'] ?>">
        <button type="submit" class="btn btn-danger btn-sm" style="width:100%">Reject</button>
      </form>
      <button type="button" class="btn btn-secondary btn-sm" style="width:100%;margin-top:.4rem" onclick="toggleEditForm('edit-pend-<?= $s['id'] ?>')">&#9998; Edit</button>
      <form method="POST" enctype="multipart/form-data" id="edit-pend-<?= $s['id'] ?>" class="edit-form">
        <?= csrf_field() ?><input type="hidden" name="action" value="edit_pending"><input type="hidden" name="id" value="<?= $s['id'] ?>">
        <label>Job Title</label>
        <input type="text" name="job_title" value="<?= h($s['job_title']) ?>" required>
        <label>YouTube Link</label>
        <input type="url" name="youtube_url" value="<?= !empty($s['youtube_id']) ? 'https://youtube.com/watch?v=' . h($s['youtube_id']) : '' ?>" placeholder="https://youtube.com/watch?v=...">
        <label>Replace Photo <span style="font-weight:400">(optional)</span></label>
        <input type="file" name="photo" accept="image/*">
        <button type="submit" class="btn btn-primary btn-sm" style="width:100%">Save Changes</button>
      </form>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<h2 style="margin-top:2.5rem;margin-bottom:.25rem;font-size:1.15rem;color:#002554">Live on Homepage</h2>
<p style="font-size:.82rem;color:#5a6a7a;margin-bottom:1.25rem">
  Approved entries. Grouped by class year — once a class graduates and the next one becomes eligible, its entries stop
  appearing on the homepage automatically, but stay listed here until you delete them.
</p>

<?php if (empty($live)): ?>
  <p style="color:#9aa5b4">Nothing approved yet.</p>
<?php else: ?>
<div class="sub-grid">
  <?php foreach ($live as $l): ?>
  <div class="sub-card" style="<?= $l['active'] ? '' : 'opacity:.5' ?>">
    <img src="/job-drop-photo-serve.php?id=<?= (int)$l['id'] ?>" alt="<?= h($l['cadet_name']) ?> — <?= h($l['job_title']) ?>">
    <div class="sub-body">
      <div class="sub-meta">
        <strong style="color:#002554"><?= h($l['cadet_name']) ?></strong> — <?= h($l['job_title']) ?><br>
        Class of <?= h($l['class_year']) ?><?= $l['active'] ? '' : ' &middot; Hidden' ?>
        <?php if (!empty($l['youtube_id'])): ?>
          <br><a href="https://www.youtube-nocookie.com/watch?v=<?= h($l['youtube_id']) ?>" target="_blank" rel="noopener">&#9654; Watch video</a>
        <?php endif; ?>
      </div>
      <form method="POST">
        <?= csrf_field() ?><input type="hidden" name="action" value="toggle_live"><input type="hidden" name="id" value="<?= $l['id'] ?>">
        <button type="submit" class="btn btn-secondary btn-sm" style="width:100%"><?= $l['active'] ? 'Hide from Homepage' : 'Show on Homepage' ?></button>
      </form>
      <form method="POST" style="margin-top:.4rem" onsubmit="return confirm('Permanently delete this entry and its photo?')">
        <?= csrf_field() ?><input type="hidden" name="action" value="delete_live"><input type="hidden" name="id" value="<?= $l['id'] ?>">
        <button type="submit" class="btn btn-danger btn-sm" style="width:100%">Delete</button>
      </form>
      <button type="button" class="btn btn-secondary btn-sm" style="width:100%;margin-top:.4rem" onclick="toggleEditForm('edit-live-<?= $l['id'] ?>')">&#9998; Edit</button>
      <form method="POST" enctype="multipart/form-data" id="edit-live-<?= $l['id'] ?>" class="edit-form">
        <?= csrf_field() ?><input type="hidden" name="action" value="edit_live"><input type="hidden" name="id" value="<?= $l['id'] ?>">
        <label>Cadet Name</label>
        <input type="text" name="cadet_name" value="<?= h($l['cadet_name']) ?>" required>
        <label>Job Title</label>
        <input type="text" name="job_title" value="<?= h($l['job_title']) ?>" required>
        <label>YouTube Link</label>
        <input type="url" name="youtube_url" value="<?= !empty($l['youtube_id']) ? 'https://youtube.com/watch?v=' . h($l['youtube_id']) : '' ?>" placeholder="https://youtube.com/watch?v=...">
        <label>Replace Photo <span style="font-weight:400">(optional)</span></label>
        <input type="file" name="photo" accept="image/*">
        <button type="submit" class="btn btn-primary btn-sm" style="width:100%">Save Changes</button>
      </form>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<script>
function toggleEditForm(id) {
  var el = document.getElementById(id);
  el.style.display = el.style.display === 'block' ? 'none' : 'block';
}
</script>

<?php admin_footer(); ?>
