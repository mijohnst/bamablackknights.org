<?php
require_once __DIR__ . '/auth.php';
require_member_admin();
$pdo = get_pdo();

$photo_dir = __DIR__ . '/../event-photos/';
if (!is_dir($photo_dir)) mkdir($photo_dir, 0755, true);

// ── Actions ─────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $name  = trim($_POST['name'] ?? '');
        $desc  = trim($_POST['description'] ?? '');
        $date  = trim($_POST['event_date'] ?? '') ?: null;
        $sort  = (int)($_POST['sort_order'] ?? 0);
        $loc_name = trim($_POST['location_name'] ?? '') ?: null;
        $loc_url  = trim($_POST['location_url'] ?? '') ?: null;
        if ($name === '') { flash('error','Album name is required.'); header('Location: event-albums.php'); exit; }
        if ($loc_url && !is_safe_http_url($loc_url)) { flash('error','Location URL must start with https:// or http://'); header('Location: event-albums.php'); exit; }
        $pdo->prepare('INSERT INTO event_albums (name,event_date,description,sort_order,location_name,location_url) VALUES (?,?,?,?,?,?)')
            ->execute([$name, $date, $desc, $sort, $loc_name, $loc_url]);
        flash('success',"Album '$name' created.");
        header('Location: event-albums.php'); exit;

    } elseif ($action === 'update') {
        $id   = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $desc = trim($_POST['description'] ?? '');
        $date = trim($_POST['event_date'] ?? '') ?: null;
        $sort = (int)($_POST['sort_order'] ?? 0);
        $vis  = isset($_POST['visible']) ? 1 : 0;
        $loc_name = trim($_POST['location_name'] ?? '') ?: null;
        $loc_url  = trim($_POST['location_url'] ?? '') ?: null;
        if ($name === '') { flash('error','Album name is required.'); header('Location: event-albums.php'); exit; }
        if ($loc_url && !is_safe_http_url($loc_url)) { flash('error','Location URL must start with https:// or http://'); header('Location: event-albums.php?edit=' . $id); exit; }
        $pdo->prepare('UPDATE event_albums SET name=?,event_date=?,description=?,sort_order=?,visible=?,location_name=?,location_url=? WHERE id=?')
            ->execute([$name, $date, $desc, $sort, $vis, $loc_name, $loc_url, $id]);
        flash('success','Album updated.');
        header('Location: event-albums.php'); exit;

    } elseif ($action === 'set_cover') {
        $album_id = (int)($_POST['album_id'] ?? 0);
        $photo_id = (int)($_POST['photo_id'] ?? 0);
        $pdo->prepare('UPDATE event_albums SET cover_photo_id=? WHERE id=?')->execute([$photo_id ?: null, $album_id]);
        flash('success','Cover photo set.');
        header('Location: event-albums.php?edit=' . $album_id); exit;

    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        // Delete all photo files in album first
        $photos = $pdo->prepare('SELECT filename FROM event_photos WHERE album_id=?');
        $photos->execute([$id]);
        foreach ($photos->fetchAll(PDO::FETCH_ASSOC) as $p) {
            if (preg_match('/^[a-zA-Z0-9._-]+$/', $p['filename'])) @unlink($photo_dir . $p['filename']);
        }
        $pdo->prepare('DELETE FROM event_albums WHERE id=?')->execute([$id]);
        flash('success','Album and all its photos deleted.');
        header('Location: event-albums.php'); exit;
    }
}

// ── Data ─────────────────────────────────────────────────────────────────────
$edit_id = (int)($_GET['edit'] ?? 0);
$editing = null;
$edit_photos = [];

if ($edit_id) {
    $s = $pdo->prepare('SELECT id, name, event_date, description, cover_photo_id, sort_order, visible, created_at, location_name, location_url FROM event_albums WHERE id=?');
    $s->execute([$edit_id]);
    $editing = $s->fetch(PDO::FETCH_ASSOC);
    if ($editing) {
        $s2 = $pdo->prepare('SELECT id, album_id, filename, caption, sort_order, created_at FROM event_photos WHERE album_id=? ORDER BY sort_order ASC, id ASC');
        $s2->execute([$edit_id]);
        $edit_photos = $s2->fetchAll(PDO::FETCH_ASSOC);
    }
}

$albums = $pdo->query('SELECT a.id, a.name, a.event_date, a.description, a.cover_photo_id,
    a.sort_order, a.visible, a.created_at, a.location_name, a.location_url,
    (SELECT COUNT(*) FROM event_photos WHERE album_id=a.id) AS photo_count,
    (SELECT filename FROM event_photos WHERE id=a.cover_photo_id LIMIT 1) AS cover_filename
    FROM event_albums a
    ORDER BY a.sort_order ASC, a.id DESC')->fetchAll(PDO::FETCH_ASSOC);

admin_header('Event Albums');
echo show_flash();
?>
<div class="page-head">
  <h1>Event Albums</h1>
  <div style="display:flex;gap:.6rem;align-items:center">
    <a href="event-photos.php" class="btn btn-secondary">📷 Upload Photos</a>
    <a href="dashboard.php" class="btn btn-secondary">← Dashboard</a>
  </div>
</div>
<p style="font-size:.82rem;color:#5a6a7a;margin-bottom:1.5rem">
  Albums group event photos shown on the public <a href="../gallery.html" target="_blank">Club Events page</a>.
  Create an album, then upload photos into it.
</p>

<!-- Create / Edit form -->
<div class="card" style="max-width:600px;margin-bottom:1.75rem">
  <h2 style="margin-bottom:1rem"><?= $editing ? 'Edit Album' : 'New Album' ?></h2>
  <form method="POST">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="<?= $editing ? 'update' : 'create' ?>">
    <?php if ($editing): ?><input type="hidden" name="id" value="<?= $editing['id'] ?>"><?php endif; ?>
    <div class="form-row col-2">
      <div class="form-group" style="flex:2">
        <label>Album Name <span style="color:#A6192E">*</span></label>
        <input name="name" required placeholder="e.g. Appointee Sendoff 2026" value="<?= h($editing['name'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label>Event Date</label>
        <input type="date" name="event_date" value="<?= h($editing['event_date'] ?? '') ?>">
      </div>
    </div>
    <div class="form-group">
      <label>Description <span style="font-weight:400;font-size:.72rem;color:#9aa5b4">optional</span></label>
      <input name="description" placeholder="Short description shown under the album title" value="<?= h($editing['description'] ?? '') ?>">
    </div>
    <div class="form-row col-2">
      <div class="form-group">
        <label>Business/Location Name <span style="font-weight:400;font-size:.72rem;color:#9aa5b4">optional</span></label>
        <input name="location_name" placeholder="e.g. The Venue Downtown" value="<?= h($editing['location_name'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label>Location Website <span style="font-weight:400;font-size:.72rem;color:#9aa5b4">optional</span></label>
        <input type="url" name="location_url" placeholder="https://example.com" value="<?= h($editing['location_url'] ?? '') ?>">
      </div>
    </div>
    <div class="form-row col-2">
      <div class="form-group">
        <label>Sort Order <span style="font-weight:400;font-size:.72rem;color:#9aa5b4">lower = first</span></label>
        <input type="number" name="sort_order" value="<?= $editing ? (int)$editing['sort_order'] : (count($albums)*10+10) ?>">
      </div>
      <?php if ($editing): ?>
      <div class="form-group" style="display:flex;align-items:flex-end;padding-bottom:.25rem">
        <label style="display:flex;align-items:center;gap:.4rem;font-weight:400;text-transform:none;letter-spacing:0;cursor:pointer;margin:0">
          <input type="checkbox" name="visible" value="1" style="width:auto" <?= $editing['visible'] ? 'checked' : '' ?>>
          Visible on public site
        </label>
      </div>
      <?php endif; ?>
    </div>
    <div style="display:flex;gap:.6rem">
      <button type="submit" class="btn btn-primary"><?= $editing ? 'Save Changes' : 'Create Album' ?></button>
      <?php if ($editing): ?><a href="event-albums.php" class="btn btn-secondary">Cancel</a><?php endif; ?>
    </div>
  </form>
</div>

<?php if ($editing && !empty($edit_photos)): ?>
<!-- Cover photo picker inside edit mode -->
<div class="card" style="max-width:600px;margin-bottom:1.75rem">
  <h2 style="margin-bottom:.75rem">Cover Photo</h2>
  <p style="font-size:.82rem;color:#5a6a7a;margin-bottom:1rem">The cover photo is shown as the album thumbnail on the public gallery page.</p>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(100px,1fr));gap:.5rem">
    <?php foreach ($edit_photos as $p): $is_cover = ($editing['cover_photo_id'] == $p['id']); ?>
    <form method="POST" style="margin:0">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="set_cover">
      <input type="hidden" name="album_id" value="<?= $editing['id'] ?>">
      <input type="hidden" name="photo_id" value="<?= $is_cover ? '0' : $p['id'] ?>">
      <button type="submit" style="padding:0;border:3px solid <?= $is_cover ? '#003594' : 'transparent' ?>;border-radius:6px;cursor:pointer;display:block;width:100%;background:none">
        <img src="/event-photo-serve.php?f=<?= rawurlencode($p['filename']) ?>" alt="" style="width:100%;aspect-ratio:1;object-fit:cover;border-radius:3px;display:block">
        <?php if ($is_cover): ?><div style="font-size:.65rem;text-align:center;padding:.15rem;background:#003594;color:white;border-radius:0 0 3px 3px">Cover</div><?php endif; ?>
      </button>
    </form>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<!-- Albums list -->
<?php if (empty($albums)): ?>
  <p style="color:#9aa5b4">No albums yet — create one above, then upload photos into it.</p>
<?php else: ?>
<div style="display:grid;gap:1rem">
  <?php foreach ($albums as $a): ?>
  <div class="card" style="display:flex;align-items:center;gap:1rem;padding:1rem 1.25rem;flex-wrap:wrap">
    <?php if (!empty($a['cover_filename'])): ?>
    <img src="/event-photo-serve.php?f=<?= rawurlencode($a['cover_filename']) ?>" alt="" style="width:72px;height:72px;object-fit:cover;border-radius:6px;flex-shrink:0">
    <?php else: ?>
    <div style="width:72px;height:72px;background:var(--light);border-radius:6px;display:flex;align-items:center;justify-content:center;font-size:1.75rem;flex-shrink:0">📷</div>
    <?php endif; ?>
    <div style="flex:1;min-width:0">
      <div style="font-weight:700;font-size:.95rem;color:#002554"><?= h($a['name']) ?></div>
      <div style="font-size:.78rem;color:#5a6a7a;margin-top:.2rem">
        <?= $a['event_date'] ? date('M j, Y', strtotime($a['event_date'])) . ' · ' : '' ?>
        <?= (int)$a['photo_count'] ?> photo<?= $a['photo_count'] != 1 ? 's' : '' ?>
        <?php if (!$a['visible']): ?> · <span style="color:#A6192E;font-weight:700">Hidden</span><?php endif; ?>
      </div>
      <?php if ($a['description']): ?><div style="font-size:.78rem;color:#5a6a7a;margin-top:.15rem"><?= h($a['description']) ?></div><?php endif; ?>
      <?php if ($a['location_name']): ?>
      <div style="font-size:.78rem;color:#5a6a7a;margin-top:.15rem">📍
        <?php if ($a['location_url']): ?><a href="<?= h($a['location_url']) ?>" target="_blank" rel="noopener"><?= h($a['location_name']) ?></a>
        <?php else: ?><?= h($a['location_name']) ?><?php endif; ?>
      </div>
      <?php endif; ?>
    </div>
    <div style="display:flex;gap:.4rem;flex-shrink:0;flex-wrap:wrap">
      <a href="event-photos.php?album_id=<?= $a['id'] ?>" class="btn btn-secondary btn-sm">📷 Photos</a>
      <a href="event-docs.php?album_id=<?= $a['id'] ?>" class="btn btn-secondary btn-sm">📎 Files</a>
      <a href="event-albums.php?edit=<?= $a['id'] ?>" class="btn btn-secondary btn-sm">Edit</a>
      <form method="POST" onsubmit="return confirm('Delete album &quot;<?= h(addslashes($a['name'])) ?>&quot; and all <?= (int)$a['photo_count'] ?> photo(s)? This cannot be undone.')" style="margin:0">
        <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $a['id'] ?>">
        <button type="submit" class="btn btn-danger btn-sm">Delete</button>
      </form>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php admin_footer(); ?>
