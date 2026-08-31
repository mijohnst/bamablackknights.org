<?php
require_once __DIR__ . '/auth.php';
require_member_admin();
$pdo = get_pdo();

$photo_dir = __DIR__ . '/../event-photos/';
if (!is_dir($photo_dir)) mkdir($photo_dir, 0755, true);

// ── Actions ─────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action   = $_POST['action'] ?? '';
    $album_id = (int)($_POST['album_id'] ?? 0);

    if ($action === 'upload') {
        if (!$album_id) { flash('error','Select an album first.'); header('Location: event-photos.php'); exit; }
        $caption  = trim($_POST['caption'] ?? '');
        $uploaded = 0;
        $skipped  = 0;

        $files = $_FILES['photo'] ?? [];
        if (!empty($files['name'])) {
            if (!is_array($files['name'])) {
                foreach ($files as $k => $v) $files[$k] = [$v];
            }
            // Sort order: start after existing max
            $max_sort_row = $pdo->prepare('SELECT COALESCE(MAX(sort_order),0) FROM event_photos WHERE album_id=?');
            $max_sort_row->execute([$album_id]);
            $next_sort = (int)$max_sort_row->fetchColumn() + 10;

            $count = count($files['name']);
            for ($i = 0; $i < $count; $i++) {
                if (empty($files['name'][$i]) || $files['error'][$i] !== UPLOAD_ERR_OK) continue;
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime  = finfo_file($finfo, $files['tmp_name'][$i]); finfo_close($finfo);
                if (!in_array($mime, ['image/jpeg','image/png','image/gif','image/webp']) || $files['size'][$i] > 10*1024*1024) {
                    $skipped++; continue;
                }
                $ext  = ['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp'][$mime];
                $name = 'ev_' . $album_id . '_' . date('Ymd') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                if (!move_uploaded_file($files['tmp_name'][$i], $photo_dir . $name)) { $skipped++; continue; }
                $pdo->prepare('INSERT INTO event_photos (album_id,filename,caption,sort_order) VALUES (?,?,?,?)')
                    ->execute([$album_id, $name, $caption, $next_sort + $i]);
                $uploaded++;
            }
        }

        if ($uploaded)       flash('success', "$uploaded photo" . ($uploaded>1?'s':'') . " uploaded." . ($skipped?" $skipped skipped (invalid).":""));
        elseif ($skipped)    flash('error','No valid photos. Use JPG, PNG, GIF, or WebP under 10MB.');
        else                 flash('error','No files received.');
        header('Location: event-photos.php?album_id=' . $album_id); exit;

    } elseif ($action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare('UPDATE event_photos SET caption=?,sort_order=? WHERE id=?')
            ->execute([trim($_POST['caption']??''), (int)$_POST['sort_order'], $id]);
        flash('success','Photo updated.');
        header('Location: event-photos.php?album_id=' . $album_id); exit;

    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $row = $pdo->prepare('SELECT filename FROM event_photos WHERE id=? AND album_id=?');
        $row->execute([$id, $album_id]); $p = $row->fetch(PDO::FETCH_ASSOC);
        if ($p && preg_match('/^[a-zA-Z0-9._-]+$/', $p['filename'])) {
            @unlink($photo_dir . $p['filename']);
            $pdo->prepare('DELETE FROM event_photos WHERE id=? AND album_id=?')->execute([$id, $album_id]);
            $pdo->prepare('UPDATE event_albums SET cover_photo_id=NULL WHERE cover_photo_id=?')->execute([$id]);
        }
        flash('success','Photo deleted.');
        header('Location: event-photos.php?album_id=' . $album_id); exit;

    } elseif ($action === 'bulk_delete') {
        $ids = array_filter(array_map('intval', $_POST['ids'] ?? []));
        $deleted = 0;
        if ($ids) {
            // Batched instead of one SELECT+DELETE+UPDATE per id — same
            // album-scoped + filename-pattern guard as the single-delete
            // action above, just resolved for the whole selection at once.
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $rows = $pdo->prepare("SELECT id, filename FROM event_photos WHERE album_id=? AND id IN ($ph)");
            $rows->execute(array_merge([$album_id], $ids));
            $valid_ids = [];
            foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $p) {
                if (preg_match('/^[a-zA-Z0-9._-]+$/', $p['filename'])) {
                    @unlink($photo_dir . $p['filename']);
                    $valid_ids[] = $p['id'];
                }
            }
            if ($valid_ids) {
                $vph = implode(',', array_fill(0, count($valid_ids), '?'));
                $pdo->prepare("DELETE FROM event_photos WHERE album_id=? AND id IN ($vph)")
                    ->execute(array_merge([$album_id], $valid_ids));
                $pdo->prepare("UPDATE event_albums SET cover_photo_id=NULL WHERE cover_photo_id IN ($vph)")
                    ->execute($valid_ids);
                $deleted = count($valid_ids);
            }
        }
        flash('success', "$deleted photo" . ($deleted!=1?'s':'') . " deleted.");
        header('Location: event-photos.php?album_id=' . $album_id); exit;
    }
}

// ── Data ─────────────────────────────────────────────────────────────────────
$album_id = (int)($_GET['album_id'] ?? 0);

$albums = $pdo->query('SELECT id, name FROM event_albums ORDER BY sort_order ASC, id DESC')->fetchAll(PDO::FETCH_ASSOC);

$current_album = null;
$photos        = [];
if ($album_id) {
    $s = $pdo->prepare('SELECT id, name, event_date, description, cover_photo_id, sort_order, visible, created_at FROM event_albums WHERE id=?');
    $s->execute([$album_id]);
    $current_album = $s->fetch(PDO::FETCH_ASSOC);
    if ($current_album) {
        $s2 = $pdo->prepare('SELECT id, album_id, filename, caption, sort_order, created_at FROM event_photos WHERE album_id=? ORDER BY sort_order ASC, id ASC');
        $s2->execute([$album_id]);
        $photos = $s2->fetchAll(PDO::FETCH_ASSOC);
    }
}

admin_header('Event Photos');
echo show_flash();
?>
<style>
.photo-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:1rem;margin-top:1.25rem}
.photo-card{background:#fff;border-radius:6px;box-shadow:0 1px 4px rgba(0,0,0,.1);overflow:hidden}
.photo-card img{width:100%;height:150px;object-fit:cover;display:block}
.photo-card-body{padding:.75rem}
</style>

<div class="page-head">
  <h1>Event Photos</h1>
  <div style="display:flex;gap:.6rem">
    <a href="event-docs.php<?= $album_id ? '?album_id='.$album_id : '' ?>" class="btn btn-secondary">📎 Files</a>
    <a href="event-albums.php" class="btn btn-secondary">📁 Albums</a>
    <a href="dashboard.php" class="btn btn-secondary">← Dashboard</a>
  </div>
</div>

<!-- Album picker -->
<div class="card" style="max-width:520px;margin-bottom:1.5rem">
  <h2 style="margin-bottom:.75rem">Select Album</h2>
  <form method="GET" style="display:flex;gap:.6rem;align-items:flex-end">
    <div class="form-group" style="flex:1;margin:0">
      <label>Album</label>
      <select name="album_id" onchange="this.form.submit()">
        <option value="">— choose an album —</option>
        <?php foreach ($albums as $al): ?>
        <option value="<?= $al['id'] ?>" <?= $al['id']==$album_id?'selected':'' ?>><?= h($al['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <noscript><button type="submit" class="btn btn-secondary">Go</button></noscript>
  </form>
  <?php if (empty($albums)): ?>
  <p style="font-size:.82rem;color:#9aa5b4;margin-top:.75rem">No albums yet. <a href="event-albums.php">Create one first.</a></p>
  <?php endif; ?>
</div>

<?php if ($current_album): ?>
<!-- Upload form -->
<div class="card" style="max-width:520px;margin-bottom:1.5rem">
  <h2 style="margin-bottom:.25rem">Upload to: <?= h($current_album['name']) ?></h2>
  <?php if (!$current_album['visible']): ?>
  <p style="font-size:.78rem;color:#A6192E;margin-bottom:.75rem">⚠ This album is hidden — photos won't appear on the public site until you make it visible in <a href="event-albums.php?edit=<?= $current_album['id'] ?>">album settings</a>.</p>
  <?php endif; ?>
  <form method="POST" enctype="multipart/form-data" style="margin-top:.75rem">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="upload">
    <input type="hidden" name="album_id" value="<?= $album_id ?>">
    <div class="form-group">
      <label>Photos — JPG, PNG, WebP · max 10MB each · <strong>upload 10 at a time max</strong></label>
      <input type="file" name="photo[]" accept="image/*" multiple required style="padding:.5rem;font-size:.9rem">
    </div>
    <div class="form-group">
      <label>Caption <span style="font-weight:400;font-size:.72rem;color:#9aa5b4">optional — applies to all selected photos</span></label>
      <input name="caption" placeholder="Caption for these photos">
    </div>
    <div id="count-warn" style="display:none;margin-bottom:.75rem;background:#fff3cd;border:1px solid #ffc107;border-radius:4px;padding:.6rem .8rem;font-size:.82rem;color:#5f4c00">
      ⚠ <span id="count-warn-msg"></span> Please select 10 or fewer at a time and upload in batches.
    </div>
    <div id="size-warn" style="display:none;margin-bottom:.75rem;background:#fff3cd;border:1px solid #ffc107;border-radius:4px;padding:.6rem .8rem;font-size:.82rem;color:#5f4c00">
      ⚠ Large selection — consider uploading in batches under 200 MB to avoid server limits.
    </div>
    <button type="submit" class="btn btn-primary" id="upload-btn">Upload Photos</button>
    <div id="upload-notice" style="display:none;margin-top:.75rem;background:#e8f0fb;border:1px solid #b3caf5;border-radius:4px;padding:.6rem .8rem;font-size:.82rem;color:#003594">
      ⏳ Uploading — please wait and do not click again.
    </div>
  </form>
</div>
<script>
var uploadForm = document.getElementById('upload-btn').closest('form');
uploadForm.querySelector('input[type=file]').addEventListener('change', function() {
  var files = Array.from(this.files);
  var total = files.reduce(function(s,f){return s+f.size;}, 0);
  var countWarn = document.getElementById('count-warn');
  var sizeWarn  = document.getElementById('size-warn');
  if (files.length > 10) {
    document.getElementById('count-warn-msg').textContent = 'You selected ' + files.length + ' photos.';
    countWarn.style.display = 'block';
  } else {
    countWarn.style.display = 'none';
  }
  sizeWarn.style.display = total > 200*1024*1024 ? 'block' : 'none';
});
uploadForm.addEventListener('submit', function(e) {
  var input = this.querySelector('input[type=file]');
  var files = Array.from(input.files);
  var total = files.reduce(function(s,f){return s+f.size;}, 0);
  if (files.length > 10) {
    e.preventDefault();
    alert('You selected ' + files.length + ' photos. Please select 10 or fewer at a time and upload in batches.');
    return;
  }
  if (total > 240*1024*1024) {
    e.preventDefault();
    alert('Total selected size (' + Math.round(total/1024/1024) + ' MB) is too large. Please select fewer photos and upload in batches under 200 MB.');
    return;
  }
  document.getElementById('upload-notice').style.display = 'block';
  setTimeout(function() {
    var btn = document.getElementById('upload-btn');
    btn.disabled = true; btn.textContent = 'Uploading…';
  }, 50);
});
</script>

<!-- Bulk forms -->
<form id="bulk-delete-form" method="POST" style="display:none">
  <?= csrf_field() ?><input type="hidden" name="action" value="bulk_delete">
  <input type="hidden" name="album_id" value="<?= $album_id ?>">
  <div id="bulk-delete-ids"></div>
</form>

<?php if (!empty($photos)): ?>
<!-- Bulk bar -->
<div id="bulk-bar" style="display:none;position:sticky;top:0;z-index:100;background:#003594;color:white;padding:.75rem 1rem;border-radius:6px;margin-bottom:1rem;align-items:center;gap:.75rem;flex-wrap:wrap">
  <span id="bulk-count" style="font-size:.85rem;font-weight:600;min-width:80px"></span>
  <button onclick="bulkDelete()" class="btn btn-danger btn-sm">Delete Selected</button>
  <button onclick="clearSelection()" style="background:none;border:none;color:rgba(255,255,255,.7);cursor:pointer;font-size:.85rem;margin-left:auto">✕ Clear</button>
</div>
<div style="display:flex;align-items:center;gap:1rem;margin-bottom:.75rem;font-size:.82rem">
  <label style="display:flex;align-items:center;gap:.4rem;cursor:pointer;font-weight:600;text-transform:none;letter-spacing:0;margin:0">
    <input type="checkbox" id="select-all" style="width:auto" onchange="toggleAll(this.checked)"> Select all
  </label>
  <span style="color:#9aa5b4"><?= count($photos) ?> photo<?= count($photos)!=1?'s':'' ?> in this album</span>
</div>

<!-- Photo grid -->
<div class="photo-grid">
  <?php foreach ($photos as $p): ?>
  <div class="photo-card" data-id="<?= $p['id'] ?>">
    <div style="position:relative">
      <img src="/event-photo-serve.php?f=<?= rawurlencode($p['filename']) ?>" alt="<?= h($p['caption']) ?>">
      <label style="position:absolute;top:.4rem;left:.4rem;background:rgba(0,0,0,.5);border-radius:3px;padding:.2rem .3rem;cursor:pointer;margin:0">
        <input type="checkbox" class="photo-cb" value="<?= $p['id'] ?>" style="width:auto;cursor:pointer" onchange="updateBulkBar()">
      </label>
    </div>
    <div class="photo-card-body">
      <form method="POST">
        <?= csrf_field() ?><input type="hidden" name="action" value="update">
        <input type="hidden" name="album_id" value="<?= $album_id ?>">
        <input type="hidden" name="id" value="<?= $p['id'] ?>">
        <input name="caption" value="<?= h($p['caption']) ?>" placeholder="Caption" style="font-size:.8rem;padding:.35rem .5rem;margin-bottom:.4rem">
        <div style="display:flex;gap:.4rem;align-items:center">
          <input type="number" name="sort_order" value="<?= $p['sort_order'] ?>" style="width:60px;font-size:.8rem;padding:.35rem .4rem">
          <button type="submit" class="btn btn-secondary btn-sm" style="margin-left:auto">Save</button>
        </div>
      </form>
      <form method="POST" onsubmit="return confirm('Delete this photo?')" style="margin-top:.4rem">
        <?= csrf_field() ?><input type="hidden" name="action" value="delete">
        <input type="hidden" name="album_id" value="<?= $album_id ?>">
        <input type="hidden" name="id" value="<?= $p['id'] ?>">
        <button type="submit" class="btn btn-danger btn-sm" style="width:100%">Delete</button>
      </form>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<script>
function getChecked() {
  return Array.from(document.querySelectorAll('.photo-cb:checked')).map(cb => cb.value);
}
function updateBulkBar() {
  var ids = getChecked();
  var bar = document.getElementById('bulk-bar');
  bar.style.display = ids.length ? 'flex' : 'none';
  document.getElementById('bulk-count').textContent = ids.length + ' selected';
  document.getElementById('select-all').checked = ids.length === document.querySelectorAll('.photo-cb').length;
}
function toggleAll(checked) {
  document.querySelectorAll('.photo-cb').forEach(cb => { cb.checked = checked; });
  updateBulkBar();
}
function clearSelection() {
  document.querySelectorAll('.photo-cb').forEach(cb => { cb.checked = false; });
  document.getElementById('select-all').checked = false;
  updateBulkBar();
}
function bulkDelete() {
  var ids = getChecked();
  if (!ids.length) return;
  if (!confirm('Delete ' + ids.length + ' photo' + (ids.length !== 1 ? 's' : '') + '? This cannot be undone.')) return;
  var c = document.getElementById('bulk-delete-ids');
  c.innerHTML = '';
  ids.forEach(function(id) {
    var inp = document.createElement('input');
    inp.type = 'hidden'; inp.name = 'ids[]'; inp.value = id;
    c.appendChild(inp);
  });
  document.getElementById('bulk-delete-form').submit();
}
</script>
<?php else: ?>
  <p style="color:#9aa5b4;margin-top:.5rem">No photos in this album yet. Upload some above.</p>
<?php endif; ?>

<?php elseif (!empty($albums)): ?>
<p style="color:#9aa5b4">Select an album above to upload and manage its photos.</p>
<?php endif; ?>

<?php admin_footer(); ?>
