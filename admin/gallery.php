<?php
require_once __DIR__ . '/auth.php';
require_member_admin();
$pdo = get_pdo();

$dir = __DIR__ . '/../site-photos/';
if (!is_dir($dir)) mkdir($dir, 0755, true);

$max_photos = get_gallery_limit($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify(); $action = $_POST['action'] ?? '';

    if ($action === 'set_limit') {
        $new_limit = max(1, min(100, (int)($_POST['gallery_max_photos'] ?? 20)));
        $pdo->prepare("INSERT INTO site_settings (setting_key,setting_label,setting_value,setting_type) VALUES ('gallery_max_photos','Max photos in gallery',?,'number') ON DUPLICATE KEY UPDATE setting_value=?")->execute([$new_limit, $new_limit]);
        $max_photos = $new_limit;
        gallery_cleanup($pdo, $dir, $max_photos);
        flash('success', "Photo limit updated to $max_photos.");
        header('Location: gallery.php'); exit;

    } elseif ($action === 'toggle_visibility') {
        $row = $pdo->query("SELECT setting_value FROM site_settings WHERE setting_key='gallery_section_visible'")->fetch();
        $cur = $row ? (bool)(int)$row['setting_value'] : true;
        $new_val = $cur ? 0 : 1;
        $pdo->prepare("INSERT INTO site_settings (setting_key,setting_label,setting_value,setting_type) VALUES ('gallery_section_visible','Member Photos section visible',?,'number') ON DUPLICATE KEY UPDATE setting_value=?")->execute([$new_val, $new_val]);
        flash('success', $cur ? 'Member Photos section hidden from the homepage.' : 'Member Photos section is back on the homepage.');
        header('Location: gallery.php'); exit;

    } elseif ($action === 'upload') {
        $caption    = trim($_POST['caption']    ?? '');
        $sort       = (int)($_POST['sort_order']?? 0);
        $uploaded   = 0;
        $skipped    = 0;

        // Handle multiple files (photo[] array)
        $files = $_FILES['photo'] ?? [];
        if (!empty($files['name'])) {
            // Normalise single-file to array format
            if (!is_array($files['name'])) {
                foreach ($files as $k => $v) $files[$k] = [$v];
            }
            $count = count($files['name']);
            for ($i = 0; $i < $count; $i++) {
                if (empty($files['name'][$i]) || $files['error'][$i] !== UPLOAD_ERR_OK) continue;
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime  = finfo_file($finfo, $files['tmp_name'][$i]); finfo_close($finfo);
                if (!in_array($mime, ['image/jpeg','image/png','image/gif','image/webp']) || $files['size'][$i] > 10*1024*1024) {
                    $skipped++; continue;
                }
                $ext  = ['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp'][$mime];
                $name = date('Ymd') . '_' . substr(date('His'), 0, 6) . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
                if (!move_uploaded_file($files['tmp_name'][$i], $dir . $name)) { $skipped++; continue; }
                $pdo->prepare('INSERT INTO site_photos (filename,caption,sort_order,active) VALUES (?,?,?,1)')
                    ->execute([$name, $caption, $sort + $i]);
                $uploaded++;
            }
        }

        // Run cleanup after upload
        gallery_cleanup($pdo, $dir, $max_photos);

        if ($uploaded > 0)  flash('success', "$uploaded photo" . ($uploaded>1?'s':'') . " uploaded." . ($skipped>0?" $skipped skipped (invalid).":''));
        elseif ($skipped > 0) flash('error', "No valid photos. Use JPG, PNG, GIF, or WebP under 10MB.");
        header('Location: gallery.php'); exit;

    } elseif ($action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare('UPDATE site_photos SET caption=?,sort_order=?,active=? WHERE id=?')
            ->execute([trim($_POST['caption']??''), (int)$_POST['sort_order'], isset($_POST['active'])?1:0, $id]);
        flash('success','Photo updated.'); header('Location: gallery.php'); exit;

    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $row = $pdo->prepare('SELECT filename FROM site_photos WHERE id=?'); $row->execute([$id]); $p = $row->fetch();
        if ($p && preg_match('/^[a-zA-Z0-9._-]+$/', $p['filename'])) {
            @unlink($dir . $p['filename']);
            $pdo->prepare('DELETE FROM site_photos WHERE id=?')->execute([$id]);
        }
        flash('success','Photo deleted.'); header('Location: gallery.php'); exit;

    } elseif ($action === 'bulk_delete') {
        $ids = array_filter(array_map('intval', $_POST['ids'] ?? []));
        $deleted = 0;
        foreach ($ids as $id) {
            $row = $pdo->prepare('SELECT filename FROM site_photos WHERE id=?'); $row->execute([$id]); $p = $row->fetch();
            if ($p && preg_match('/^[a-zA-Z0-9._-]+$/', $p['filename'])) {
                @unlink($dir . $p['filename']);
                $pdo->prepare('DELETE FROM site_photos WHERE id=?')->execute([$id]);
                $deleted++;
            }
        }
        flash('success', "$deleted photo" . ($deleted!==1?'s':'') . " deleted.");
        header('Location: gallery.php'); exit;

    } elseif ($action === 'bulk_caption') {
        $ids     = array_filter(array_map('intval', $_POST['ids'] ?? []));
        $caption = trim($_POST['bulk_caption'] ?? '');
        if ($ids) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $params = array_merge([$caption], array_values($ids));
            $pdo->prepare("UPDATE site_photos SET caption=? WHERE id IN ($placeholders)")->execute($params);
            flash('success', count($ids) . " photo" . (count($ids)!==1?'s':'') . " updated.");
        }
        header('Location: gallery.php'); exit;
    }
}

// Run cleanup on page load too (catches old photos even without uploads)
gallery_cleanup($pdo, $dir, $max_photos);

$photos = $pdo->query('SELECT *, DATEDIFF(NOW(),created_at) as days_old FROM site_photos ORDER BY sort_order ASC, id ASC')->fetchAll();
$total  = count($photos);

$vis_row = $pdo->query("SELECT setting_value FROM site_settings WHERE setting_key='gallery_section_visible'")->fetch();
$section_visible = $vis_row ? (bool)(int)$vis_row['setting_value'] : true;

admin_header('Homepage Gallery');
echo show_flash();
?>
<style>
.photo-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:1rem;margin-top:1.25rem}
.photo-card{background:#fff;border-radius:6px;box-shadow:0 1px 4px rgba(0,0,0,.1);overflow:hidden}
.photo-card img{width:100%;height:150px;object-fit:cover;display:block}
.photo-card-body{padding:.75rem}
</style>

<div class="page-head">
  <h1>Homepage Gallery</h1>
  <div style="display:flex;gap:.5rem">
    <form method="POST" style="margin:0">
      <?= csrf_field() ?><input type="hidden" name="action" value="toggle_visibility">
      <button type="submit" class="btn <?= $section_visible ? 'btn-secondary' : 'btn-primary' ?> btn-sm">
        <?= $section_visible ? 'Hide Section from Homepage' : '✓ Show Section on Homepage' ?>
      </button>
    </form>
    <a href="dashboard.php" class="btn btn-secondary">← Dashboard</a>
  </div>
</div>
<?php if (!$section_visible): ?>
<div class="alert alert-error" style="margin-bottom:1.25rem">The Member Photos section is currently hidden from the homepage, regardless of what's uploaded below.</div>
<?php endif; ?>
<p style="font-size:.82rem;color:#5a6a7a;margin-bottom:1.25rem">
  Upload photos directly to the homepage Member Photos slideshow — no review needed (that's what <a href="photo-submissions.php">Photo Submissions</a> is for, when a member submits their own).
  <strong>Auto-cleanup:</strong> photos older than 30 days or beyond the photo limit are removed automatically.
  Currently <strong><?= $total ?>/<?= $max_photos ?></strong> photos.
</p>

<!-- Photo limit setting -->
<div class="card" style="max-width:520px;margin-bottom:1.5rem">
  <h2 style="margin-bottom:1rem">Display Limit</h2>
  <form method="POST" style="display:flex;align-items:flex-end;gap:.75rem">
    <?= csrf_field() ?><input type="hidden" name="action" value="set_limit">
    <div class="form-group" style="margin:0;flex:1">
      <label>Max photos to display <span style="font-weight:400;font-size:.72rem;color:#9aa5b4">(1–100)</span></label>
      <input type="number" name="gallery_max_photos" value="<?= $max_photos ?>" min="1" max="100" required>
    </div>
    <button type="submit" class="btn btn-secondary" style="margin-bottom:0">Save</button>
  </form>
</div>

<!-- Upload form -->
<div class="card" style="max-width:520px;margin-bottom:1.5rem">
  <h2 style="margin-bottom:1rem">Upload Photos</h2>
  <form method="POST" enctype="multipart/form-data">
    <?= csrf_field() ?><input type="hidden" name="action" value="upload">
    <div class="form-group">
      <label>Photos — select multiple at once (JPG, PNG, WebP · max 10MB each)</label>
      <input type="file" name="photo[]" accept="image/*" capture="environment" multiple required style="padding:.5rem;font-size:.9rem">
    </div>
    <div class="form-row col-2">
      <div class="form-group"><label>Caption <span style="font-weight:400;font-size:.72rem;color:#9aa5b4">applies to all selected</span></label><input name="caption" placeholder="Event or description"></div>
      <div class="form-group"><label>Start Sort Order</label><input type="number" name="sort_order" value="<?= ($total)*10+10 ?>"></div>
    </div>
    <?php if ($total >= $max_photos - 2): ?>
    <div style="background:#fff8e1;border:1px solid #ffc107;border-radius:4px;padding:.6rem .8rem;font-size:.82rem;color:#5f4c00;margin-bottom:.75rem">
      ⚠️ <?= $max_photos-$total ?> slot<?= ($max_photos-$total)!==1?'s':'' ?> remaining before the oldest are auto-removed.
    </div>
    <?php endif; ?>
    <button type="submit" class="btn btn-primary" id="upload-btn">Upload Photos</button>
    <div id="upload-notice" style="display:none;margin-top:.75rem;background:#e8f0fb;border:1px solid #b3caf5;border-radius:4px;padding:.6rem .8rem;font-size:.82rem;color:#003594">
      ⏳ Uploading — this may take a moment for large photos. Please wait and do not click again.
    </div>
  </form>
</div>
<script>
document.getElementById('upload-btn').closest('form').addEventListener('submit', function() {
  var btn = document.getElementById('upload-btn');
  var notice = document.getElementById('upload-notice');
  notice.style.display = 'block';
  setTimeout(function() { btn.disabled = true; btn.textContent = 'Uploading…'; }, 50);
});
</script>

<!-- Bulk action bar -->
<?php if (!empty($photos)): ?>
<div id="bulk-bar" style="display:none;position:sticky;top:0;z-index:100;background:#003594;color:white;padding:.75rem 1rem;border-radius:6px;margin-bottom:1rem;display:none;align-items:center;gap:.75rem;flex-wrap:wrap">
  <span id="bulk-count" style="font-size:.85rem;font-weight:600;min-width:80px"></span>
  <input id="bulk-caption-input" type="text" placeholder="Caption to apply…" style="flex:1;min-width:160px;padding:.4rem .6rem;border-radius:4px;border:none;font-size:.85rem;color:#111">
  <button onclick="bulkCaption()" class="btn btn-secondary btn-sm" style="background:white;color:#003594">Apply Caption</button>
  <button onclick="bulkDelete()" class="btn btn-danger btn-sm">Delete Selected</button>
  <button onclick="clearSelection()" style="background:none;border:none;color:rgba(255,255,255,.7);cursor:pointer;font-size:.85rem;margin-left:auto">✕ Clear</button>
</div>
<div style="display:flex;align-items:center;gap:1rem;margin-bottom:.75rem;font-size:.82rem">
  <label style="display:flex;align-items:center;gap:.4rem;cursor:pointer;font-weight:600;text-transform:none;letter-spacing:0;margin:0">
    <input type="checkbox" id="select-all" style="width:auto" onchange="toggleAll(this.checked)"> Select all
  </label>
</div>
<?php endif; ?>

<!-- Bulk forms (hidden, submitted by JS) -->
<form id="bulk-delete-form" method="POST" style="display:none">
  <?= csrf_field() ?><input type="hidden" name="action" value="bulk_delete">
  <div id="bulk-delete-ids"></div>
</form>
<form id="bulk-caption-form" method="POST" style="display:none">
  <?= csrf_field() ?><input type="hidden" name="action" value="bulk_caption">
  <input type="hidden" name="bulk_caption" id="bulk-caption-value">
  <div id="bulk-caption-ids"></div>
</form>

<!-- Photo grid -->
<?php if (empty($photos)): ?>
  <p style="color:#9aa5b4">No photos uploaded yet. The Google Drive slideshow is being used.</p>
<?php else: ?>
<div class="photo-grid">
  <?php foreach ($photos as $p): $age_color = $p['days_old'] >= 25 ? '#A6192E' : ($p['days_old'] >= 20 ? '#f57c00' : '#9aa5b4'); ?>
  <div class="photo-card" style="<?= $p['active']?'':'opacity:.5' ?>" data-id="<?= $p['id'] ?>">
    <div style="position:relative">
      <img src="/site-photos/<?= h($p['filename']) ?>" alt="<?= h($p['caption']) ?>">
      <label style="position:absolute;top:.4rem;left:.4rem;background:rgba(0,0,0,.5);border-radius:3px;padding:.2rem .3rem;cursor:pointer;margin:0">
        <input type="checkbox" class="photo-cb" value="<?= $p['id'] ?>" style="width:auto;cursor:pointer" onchange="updateBulkBar()">
      </label>
    </div>
    <div class="photo-card-body">
      <div style="font-size:.7rem;color:<?= $age_color ?>;margin-bottom:.4rem;font-weight:700">
        <?= $p['days_old'] === '0' ? 'Added today' : $p['days_old'] . 'd old' ?> · expires in <?= max(0,30-(int)$p['days_old']) ?>d
      </div>
      <form method="POST">
        <?= csrf_field() ?><input type="hidden" name="action" value="update"><input type="hidden" name="id" value="<?= $p['id'] ?>">
        <input name="caption" value="<?= h($p['caption']) ?>" placeholder="Caption" style="font-size:.8rem;padding:.35rem .5rem;margin-bottom:.4rem">
        <div style="display:flex;gap:.4rem;align-items:center">
          <input type="number" name="sort_order" value="<?= $p['sort_order'] ?>" style="width:60px;font-size:.8rem;padding:.35rem .4rem">
          <label style="display:flex;align-items:center;gap:.3rem;font-size:.78rem;font-weight:400;text-transform:none;letter-spacing:0;cursor:pointer;margin:0">
            <input type="checkbox" name="active" value="1" style="width:auto" <?= $p['active']?'checked':'' ?>> Show
          </label>
          <button type="submit" class="btn btn-secondary btn-sm" style="margin-left:auto">Save</button>
        </div>
      </form>
      <form method="POST" onsubmit="return confirm('Delete photo?')" style="margin-top:.4rem">
        <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $p['id'] ?>">
        <button type="submit" class="btn btn-danger btn-sm" style="width:100%">Delete</button>
      </form>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
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
function buildIdInputs(containerId, ids) {
  var c = document.getElementById(containerId);
  c.innerHTML = '';
  ids.forEach(function(id) {
    var inp = document.createElement('input');
    inp.type = 'hidden'; inp.name = 'ids[]'; inp.value = id;
    c.appendChild(inp);
  });
}
function bulkDelete() {
  var ids = getChecked();
  if (!ids.length) return;
  if (!confirm('Delete ' + ids.length + ' photo' + (ids.length !== 1 ? 's' : '') + '? This cannot be undone.')) return;
  buildIdInputs('bulk-delete-ids', ids);
  document.getElementById('bulk-delete-form').submit();
}
function bulkCaption() {
  var ids = getChecked();
  var caption = document.getElementById('bulk-caption-input').value.trim();
  if (!ids.length) return;
  if (!confirm('Apply caption "' + (caption || '(blank)') + '" to ' + ids.length + ' photo' + (ids.length !== 1 ? 's' : '') + '?')) return;
  document.getElementById('bulk-caption-value').value = caption;
  buildIdInputs('bulk-caption-ids', ids);
  document.getElementById('bulk-caption-form').submit();
}
</script>
<?php admin_footer(); ?>
