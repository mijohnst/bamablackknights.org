<?php
require_once __DIR__ . '/auth.php';
require_member_admin();
$pdo = get_pdo();

$submissions_dir = __DIR__ . '/../photo-submissions/';
$photos_dir       = __DIR__ . '/../site-photos/';
if (!is_dir($photos_dir)) mkdir($photos_dir, 0755, true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    $id     = (int)($_POST['id'] ?? 0);

    // Explicit column list, not SELECT * — this table's status column is
    // stored with different case than the rest (STATUS, not status); naming
    // it explicitly here normalizes the returned array key to lowercase.
    $s = $pdo->prepare('SELECT id, user_id, album_id, filename, caption, status, submitted_at, reviewed_by, reviewed_at FROM photo_submissions WHERE id = ? AND status = \'pending\'');
    $s->execute([$id]);
    $sub = $s->fetch(PDO::FETCH_ASSOC);

    if ($sub) {
        if ($action === 'approve') {
            $src = $submissions_dir . basename($sub['filename']);
            if (is_file($src) && rename($src, $photos_dir . basename($sub['filename']))) {
                $next_sort = (int)$pdo->query('SELECT COALESCE(MAX(sort_order),0)+10 FROM site_photos')->fetchColumn();
                $pdo->prepare('INSERT INTO site_photos (filename,caption,sort_order,active) VALUES (?,?,?,1)')
                    ->execute([$sub['filename'], $sub['caption'], $next_sort]);
                $upd = $pdo->prepare('UPDATE photo_submissions SET status=\'approved\', reviewed_by=?, reviewed_at=NOW() WHERE id=?');
                $upd->execute([$_SESSION['user_id'] ?? null, $id]);
                $max_photos = get_gallery_limit($pdo);
                gallery_cleanup($pdo, $photos_dir, $max_photos);
                if ($upd->rowCount() > 0) {
                    flash('success', 'Photo approved and added to the Member Photos slideshow.');
                } else {
                    flash('error', "Photo was added to the slideshow, but its submission record (id=$id) didn't update to Approved — the submitter may still see it as Pending. Contact tech support with this id.");
                }
            } else {
                flash('error', 'The submitted photo file is missing on the server — could not approve. Contact tech support.');
            }
        } elseif ($action === 'reject') {
            $pdo->prepare('UPDATE photo_submissions SET status=\'rejected\', reviewed_by=?, reviewed_at=NOW() WHERE id=?')
                ->execute([$_SESSION['user_id'] ?? null, $id]);
            flash('success', 'Photo rejected.');
        }
    }
    header('Location: photo-submissions.php'); exit;
}

$pending = $pdo->query(
    "SELECT p.id, p.user_id, p.album_id, p.filename, p.caption, p.status, p.submitted_at, p.reviewed_by, p.reviewed_at, u.name AS submitter_name
     FROM photo_submissions p
     JOIN users u ON p.user_id = u.id WHERE p.status = 'pending' ORDER BY p.submitted_at ASC"
)->fetchAll(PDO::FETCH_ASSOC);

admin_header('Photo Submissions');
echo show_flash();
?>
<style>
.sub-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:1rem;margin-top:1.25rem}
.sub-card{background:#fff;border-radius:6px;box-shadow:0 1px 4px rgba(0,0,0,.1);overflow:hidden}
.sub-card img{width:100%;height:180px;object-fit:cover;display:block}
.sub-body{padding:.85rem}
.sub-meta{font-size:.75rem;color:#5a6a7a;margin-bottom:.5rem}
</style>

<div class="page-head">
  <h1>Photo Submissions</h1>
  <div style="display:flex;gap:.5rem">
    <a href="gallery.php" class="btn btn-secondary">Member Photos Gallery</a>
    <a href="dashboard.php" class="btn btn-secondary">← Dashboard</a>
  </div>
</div>
<p style="font-size:.82rem;color:#5a6a7a;margin-bottom:1.25rem">Member-submitted photos awaiting review. Approving adds the photo to the homepage Member Photos slideshow.</p>

<?php if (empty($pending)): ?>
  <p style="color:#9aa5b4">No photos waiting for review.</p>
<?php else: ?>
<div class="sub-grid">
  <?php foreach ($pending as $s): ?>
  <div class="sub-card">
    <img src="/photo-submission-serve.php?id=<?= $s['id'] ?>" alt="">
    <div class="sub-body">
      <div class="sub-meta">
        By <?= h($s['submitter_name']) ?> &bull; <?= date('M j, Y', strtotime($s['submitted_at'])) ?>
        <?php if ($s['caption']): ?><br><?= h($s['caption']) ?><?php endif; ?>
      </div>
      <form method="POST">
        <?= csrf_field() ?><input type="hidden" name="action" value="approve"><input type="hidden" name="id" value="<?= $s['id'] ?>">
        <button type="submit" class="btn btn-primary btn-sm" style="width:100%">Approve</button>
      </form>
      <form method="POST" style="margin-top:.4rem" onsubmit="return confirm('Reject this photo?')">
        <?= csrf_field() ?><input type="hidden" name="action" value="reject"><input type="hidden" name="id" value="<?= $s['id'] ?>">
        <button type="submit" class="btn btn-danger btn-sm" style="width:100%">Reject</button>
      </form>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php admin_footer(); ?>
