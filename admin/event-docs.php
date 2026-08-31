<?php
require_once __DIR__ . '/auth.php';
require_member_admin();
$pdo = get_pdo();

$doc_dir = __DIR__ . '/../event-docs/';
if (!is_dir($doc_dir)) mkdir($doc_dir, 0755, true);

$allowed_mime = [
    'application/pdf'                                                              => 'pdf',
    'application/vnd.ms-powerpoint'                                               => 'ppt',
    'application/vnd.openxmlformats-officedocument.presentationml.presentation'   => 'pptx',
    'application/vnd.ms-excel'                                                    => 'xls',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'           => 'xlsx',
    'application/msword'                                                          => 'doc',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document'     => 'docx',
    'text/csv'                                                                    => 'csv',
    'text/plain'                                                                  => 'txt',
];

// ── Actions ─────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action   = $_POST['action'] ?? '';
    $album_id = (int)($_POST['album_id'] ?? 0);

    if ($action === 'upload') {
        if (!$album_id) { flash('error','Select an album first.'); header('Location: event-docs.php'); exit; }
        $uploaded = 0;
        $skipped  = 0;
        $errors   = [];

        $files = $_FILES['doc'] ?? [];
        if (!empty($files['name'])) {
            if (!is_array($files['name'])) {
                foreach ($files as $k => $v) $files[$k] = [$v];
            }
            $max_sort_row = $pdo->prepare('SELECT COALESCE(MAX(sort_order),0) FROM event_documents WHERE album_id=?');
            $max_sort_row->execute([$album_id]);
            $next_sort = (int)$max_sort_row->fetchColumn() + 10;

            $count = count($files['name']);
            for ($i = 0; $i < $count; $i++) {
                if (empty($files['name'][$i]) || $files['error'][$i] !== UPLOAD_ERR_OK) continue;
                if ($files['size'][$i] > 50 * 1024 * 1024) { $skipped++; continue; } // 50 MB limit

                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime  = finfo_file($finfo, $files['tmp_name'][$i]); finfo_close($finfo);

                if (!isset($allowed_mime[$mime])) { $skipped++; continue; }

                $ext           = $allowed_mime[$mime];
                $original_name = basename($files['name'][$i]);
                $safe_name     = 'doc_' . $album_id . '_' . date('Ymd') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;

                if (!move_uploaded_file($files['tmp_name'][$i], $doc_dir . $safe_name)) { $skipped++; continue; }
                $pdo->prepare('INSERT INTO event_documents (album_id,filename,original_name,type,sort_order) VALUES (?,?,?,\'file\',?)')
                    ->execute([$album_id, $safe_name, $original_name, $next_sort + $i]);
                $uploaded++;
            }
        }

        if ($uploaded)    flash('success', "$uploaded file" . ($uploaded>1?'s':'') . " uploaded." . ($skipped?" $skipped skipped (unsupported type or over 50MB).":""));
        elseif ($skipped) flash('error','No valid files. Supported: PDF, PPT/X, XLS/X, DOC/X, CSV, TXT — max 50MB each.');
        else              flash('error','No files received.');
        header('Location: event-docs.php?album_id=' . $album_id); exit;

    } elseif ($action === 'add_link') {
        if (!$album_id) { flash('error','Select an album first.'); header('Location: event-docs.php'); exit; }
        $label = trim($_POST['link_label'] ?? '');
        $url   = trim($_POST['link_url']   ?? '');
        if (!$label || !$url) { flash('error','Label and URL are both required.'); header('Location: event-docs.php?album_id='.$album_id); exit; }
        if (!is_safe_http_url($url)) { flash('error','URL must start with https:// or http://'); header('Location: event-docs.php?album_id='.$album_id); exit; }
        $max_sort_row = $pdo->prepare('SELECT COALESCE(MAX(sort_order),0) FROM event_documents WHERE album_id=?');
        $max_sort_row->execute([$album_id]);
        $next_sort = (int)$max_sort_row->fetchColumn() + 10;
        $pdo->prepare('INSERT INTO event_documents (album_id,filename,original_name,label,type,url,sort_order) VALUES (?,\'\',\'\',?,\'link\',?,?)')
            ->execute([$album_id, $label, $url, $next_sort]);
        flash('success','Link added.');
        header('Location: event-docs.php?album_id=' . $album_id); exit;

    } elseif ($action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        $row = $pdo->prepare('SELECT type FROM event_documents WHERE id=?');
        $row->execute([$id]); $d = $row->fetch(PDO::FETCH_ASSOC);
        if ($d && $d['type'] === 'link') {
            $url = trim($_POST['url'] ?? '');
            if ($url && !is_safe_http_url($url)) { flash('error','URL must start with https:// or http://'); header('Location: event-docs.php?album_id='.$album_id); exit; }
            $pdo->prepare('UPDATE event_documents SET label=?,url=?,sort_order=? WHERE id=?')
                ->execute([trim($_POST['label']??''), $url ?: null, (int)$_POST['sort_order'], $id]);
        } else {
            $pdo->prepare('UPDATE event_documents SET label=?,sort_order=? WHERE id=?')
                ->execute([trim($_POST['label']??''), (int)$_POST['sort_order'], $id]);
        }
        flash('success','Updated.');
        header('Location: event-docs.php?album_id=' . $album_id); exit;

    } elseif ($action === 'delete') {
        $id  = (int)($_POST['id'] ?? 0);
        $row = $pdo->prepare('SELECT filename, type FROM event_documents WHERE id=? AND album_id=?');
        $row->execute([$id, $album_id]); $d = $row->fetch(PDO::FETCH_ASSOC);
        if ($d) {
            if ($d['type'] === 'file' && preg_match('/^[a-zA-Z0-9._-]+$/', $d['filename'])) {
                @unlink($doc_dir . $d['filename']);
            }
            $pdo->prepare('DELETE FROM event_documents WHERE id=? AND album_id=?')->execute([$id, $album_id]);
        }
        flash('success','Deleted.');
        header('Location: event-docs.php?album_id=' . $album_id); exit;

    } elseif ($action === 'bulk_delete') {
        $ids     = array_filter(array_map('intval', $_POST['ids'] ?? []));
        $deleted = 0;
        if ($ids) {
            // Batched instead of one SELECT+DELETE per id — same
            // album-scoped guard as the single-delete action above, just
            // resolved for the whole selection at once.
            $ph = implode(',', array_fill(0, count($ids), '?'));
            $rows = $pdo->prepare("SELECT id, filename, type FROM event_documents WHERE album_id=? AND id IN ($ph)");
            $rows->execute(array_merge([$album_id], $ids));
            $found = $rows->fetchAll(PDO::FETCH_ASSOC);
            foreach ($found as $d) {
                if ($d['type'] === 'file' && preg_match('/^[a-zA-Z0-9._-]+$/', $d['filename'])) {
                    @unlink($doc_dir . $d['filename']);
                }
            }
            if ($found) {
                $vph = implode(',', array_fill(0, count($found), '?'));
                $pdo->prepare("DELETE FROM event_documents WHERE album_id=? AND id IN ($vph)")
                    ->execute(array_merge([$album_id], array_column($found, 'id')));
                $deleted = count($found);
            }
        }
        flash('success', "$deleted document" . ($deleted!=1?'s':'') . " deleted.");
        header('Location: event-docs.php?album_id=' . $album_id); exit;
    }
}

// ── Data ─────────────────────────────────────────────────────────────────────
$album_id = (int)($_GET['album_id'] ?? 0);
$albums   = $pdo->query('SELECT id, name FROM event_albums ORDER BY sort_order ASC, id DESC')->fetchAll(PDO::FETCH_ASSOC);

$current_album = null;
$docs          = [];
if ($album_id) {
    $s = $pdo->prepare('SELECT id, name, event_date, description, cover_photo_id, sort_order, visible, created_at FROM event_albums WHERE id=?');
    $s->execute([$album_id]);
    $current_album = $s->fetch(PDO::FETCH_ASSOC);
    if ($current_album) {
        $s2 = $pdo->prepare('SELECT id, album_id, filename, original_name, label, type, url, sort_order, created_at FROM event_documents WHERE album_id=? ORDER BY sort_order ASC, id ASC');
        $s2->execute([$album_id]);
        $docs = $s2->fetchAll(PDO::FETCH_ASSOC);
    }
}

function doc_icon(string $filename): string {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    $map = ['pdf'=>'📄','ppt'=>'📊','pptx'=>'📊','xls'=>'📗','xlsx'=>'📗','doc'=>'📝','docx'=>'📝','csv'=>'📋','txt'=>'📋'];
    return $map[$ext] ?? '📎';
}

function format_bytes(int $bytes): string {
    if ($bytes >= 1048576) return round($bytes/1048576,1) . ' MB';
    if ($bytes >= 1024)    return round($bytes/1024)      . ' KB';
    return $bytes . ' B';
}

admin_header('Event Documents');
echo show_flash();
?>
<div class="page-head">
  <h1>Event Documents</h1>
  <div style="display:flex;gap:.6rem">
    <a href="event-photos.php<?= $album_id ? '?album_id='.$album_id : '' ?>" class="btn btn-secondary">📷 Photos</a>
    <a href="event-albums.php" class="btn btn-secondary">📁 Albums</a>
    <a href="dashboard.php" class="btn btn-secondary">← Dashboard</a>
  </div>
</div>
<p style="font-size:.82rem;color:#5a6a7a;margin-bottom:1.5rem">
  Upload presentations, spreadsheets, PDFs, and other files for each event. They appear as downloadable links on the public
  <a href="../gallery.html" target="_blank">Club Events page</a>.
  Supported: PDF, PPT/X, XLS/X, DOC/X, CSV, TXT &middot; max 50 MB each.
</p>

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
  <p style="font-size:.78rem;color:#A6192E;margin-bottom:.75rem">⚠ This album is hidden — files won't appear publicly until you
    <a href="event-albums.php?edit=<?= $current_album['id'] ?>">make it visible</a>.</p>
  <?php endif; ?>
  <form method="POST" enctype="multipart/form-data" style="margin-top:.75rem">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="upload">
    <input type="hidden" name="album_id" value="<?= $album_id ?>">
    <div class="form-group">
      <label>Files — select one or more (PDF, PPT/X, XLS/X, DOC/X, CSV, TXT &middot; 50 MB max each)</label>
      <input type="file" name="doc[]" multiple required
             accept=".pdf,.ppt,.pptx,.xls,.xlsx,.doc,.docx,.csv,.txt,application/pdf,application/msword,application/vnd.ms-powerpoint,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.presentationml.presentation,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.openxmlformats-officedocument.wordprocessingml.document,text/csv,text/plain"
             style="padding:.5rem;font-size:.9rem">
    </div>
    <div id="size-warn" style="display:none;margin-bottom:.75rem;background:#fff3cd;border:1px solid #ffc107;border-radius:4px;padding:.6rem .8rem;font-size:.82rem;color:#5f4c00">
      ⚠ Large selection — consider uploading in batches under 200 MB to avoid server limits.
    </div>
    <button type="submit" class="btn btn-primary" id="upload-btn">Upload Files</button>
    <div id="upload-notice" style="display:none;margin-top:.75rem;background:#e8f0fb;border:1px solid #b3caf5;border-radius:4px;padding:.6rem .8rem;font-size:.82rem;color:#003594">
      ⏳ Uploading — please wait and do not click again.
    </div>
  </form>
</div>

<!-- Add external link -->
<div class="card" style="max-width:520px;margin-bottom:1.5rem">
  <h2 style="margin-bottom:.75rem">Add External Link</h2>
  <p style="font-size:.82rem;color:#5a6a7a;margin-bottom:.75rem">Link to a file hosted elsewhere — Google Slides, Drive, Dropbox, etc. Opens in a new tab on the public page.</p>
  <form method="POST">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="add_link">
    <input type="hidden" name="album_id" value="<?= $album_id ?>">
    <div class="form-group">
      <label>Display Label <span style="color:#A6192E">*</span></label>
      <input name="link_label" required placeholder="e.g. Appointee Sendoff 2026 Slideshow">
    </div>
    <div class="form-group">
      <label>URL <span style="color:#A6192E">*</span></label>
      <input type="url" name="link_url" required placeholder="https://docs.google.com/presentation/…" style="font-size:.82rem">
    </div>
    <button type="submit" class="btn btn-primary">Add Link</button>
  </form>
</div>

<script>
var uploadForm = document.getElementById('upload-btn').closest('form');
uploadForm.querySelector('input[type=file]').addEventListener('change', function() {
  var total = Array.from(this.files).reduce(function(s,f){return s+f.size;}, 0);
  document.getElementById('size-warn').style.display = total > 200*1024*1024 ? 'block' : 'none';
});
uploadForm.addEventListener('submit', function(e) {
  var input = this.querySelector('input[type=file]');
  var total = Array.from(input.files).reduce(function(s,f){return s+f.size;}, 0);
  if (total > 240*1024*1024) {
    e.preventDefault();
    alert('Total selected size (' + Math.round(total/1024/1024) + ' MB) is too large. Please upload in batches under 200 MB.');
    return;
  }
  document.getElementById('upload-notice').style.display = 'block';
  setTimeout(function() { var btn = document.getElementById('upload-btn'); btn.disabled = true; btn.textContent = 'Uploading…'; }, 50);
});
</script>

<!-- Bulk form -->
<form id="bulk-delete-form" method="POST" style="display:none">
  <?= csrf_field() ?><input type="hidden" name="action" value="bulk_delete">
  <input type="hidden" name="album_id" value="<?= $album_id ?>">
  <div id="bulk-delete-ids"></div>
</form>

<?php if (!empty($docs)): ?>
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
  <span style="color:#9aa5b4"><?= count($docs) ?> file<?= count($docs)!=1?'s':'' ?></span>
</div>

<div style="display:grid;gap:.6rem">
  <?php foreach ($docs as $d):
    $is_link      = ($d['type'] === 'link');
    $icon         = $is_link ? '🔗' : doc_icon($d['filename']);
    $display_name = $d['label'] !== '' ? $d['label'] : $d['original_name'];
    $view_href    = $is_link ? h($d['url']) : '/event-doc-serve.php?f=' . rawurlencode($d['filename']);
    $meta         = $is_link
        ? 'External link · added ' . date('M j, Y', strtotime($d['created_at']))
        : h($d['original_name']) . ' · ' . (file_exists($doc_dir.$d['filename']) ? format_bytes(filesize($doc_dir.$d['filename'])) : '?') . ' · uploaded ' . date('M j, Y', strtotime($d['created_at']));
  ?>
  <div class="card" style="display:flex;align-items:center;gap:.75rem;padding:.85rem 1rem;flex-wrap:wrap" data-id="<?= $d['id'] ?>">
    <label style="flex-shrink:0;cursor:pointer;margin:0;display:flex;align-items:center;gap:.4rem">
      <input type="checkbox" class="doc-cb" value="<?= $d['id'] ?>" style="width:auto" onchange="updateBulkBar()">
    </label>
    <div style="font-size:1.5rem;flex-shrink:0"><?= $icon ?></div>
    <div style="flex:1;min-width:180px">
      <div style="display:flex;align-items:center;gap:.4rem;flex-wrap:wrap">
        <span style="font-weight:600;font-size:.9rem;color:#002554"><?= h($display_name) ?></span>
        <span style="font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;padding:.1rem .35rem;border-radius:3px;background:<?= $is_link?'#e3f2fd':'#f1f3f5' ?>;color:<?= $is_link?'#1565c0':'#5a6a7a' ?>"><?= $is_link?'LINK':'FILE' ?></span>
      </div>
      <div style="font-size:.72rem;color:#9aa5b4;margin-top:.1rem"><?= $meta ?></div>
      <?php if ($is_link): ?><div style="font-size:.7rem;color:#9aa5b4;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:320px"><?= h($d['url']) ?></div><?php endif; ?>
    </div>
    <form method="POST" style="display:flex;gap:.4rem;align-items:center;flex-shrink:0;flex-wrap:wrap">
      <?= csrf_field() ?><input type="hidden" name="action" value="update">
      <input type="hidden" name="album_id" value="<?= $album_id ?>">
      <input type="hidden" name="id" value="<?= $d['id'] ?>">
      <input name="label" value="<?= h($d['label']) ?>" placeholder="<?= $is_link ? 'Label (required)' : 'Display label (optional)' ?>" style="width:<?= $is_link?'160px':'200px' ?>;font-size:.8rem;padding:.3rem .45rem">
      <?php if ($is_link): ?>
      <input type="url" name="url" value="<?= h($d['url']) ?>" placeholder="https://…" style="width:200px;font-size:.8rem;padding:.3rem .45rem">
      <?php endif; ?>
      <input type="number" name="sort_order" value="<?= $d['sort_order'] ?>" style="width:55px;font-size:.8rem;padding:.3rem .4rem" title="Sort order">
      <button type="submit" class="btn btn-secondary btn-sm">Save</button>
    </form>
    <a href="<?= $view_href ?>" target="_blank" rel="noopener" class="btn btn-secondary btn-sm" style="flex-shrink:0">View</a>
    <form method="POST" onsubmit="return confirm('Delete this <?= $is_link?'link':'file' ?>?')" style="margin:0;flex-shrink:0">
      <?= csrf_field() ?><input type="hidden" name="action" value="delete">
      <input type="hidden" name="album_id" value="<?= $album_id ?>">
      <input type="hidden" name="id" value="<?= $d['id'] ?>">
      <button type="submit" class="btn btn-danger btn-sm">Delete</button>
    </form>
  </div>
  <?php endforeach; ?>
</div>

<script>
function getChecked() {
  return Array.from(document.querySelectorAll('.doc-cb:checked')).map(cb => cb.value);
}
function updateBulkBar() {
  var ids = getChecked();
  var bar = document.getElementById('bulk-bar');
  bar.style.display = ids.length ? 'flex' : 'none';
  document.getElementById('bulk-count').textContent = ids.length + ' selected';
  document.getElementById('select-all').checked = ids.length === document.querySelectorAll('.doc-cb').length;
}
function toggleAll(checked) {
  document.querySelectorAll('.doc-cb').forEach(cb => { cb.checked = checked; });
  updateBulkBar();
}
function clearSelection() {
  document.querySelectorAll('.doc-cb').forEach(cb => { cb.checked = false; });
  document.getElementById('select-all').checked = false;
  updateBulkBar();
}
function bulkDelete() {
  var ids = getChecked();
  if (!ids.length) return;
  if (!confirm('Delete ' + ids.length + ' file' + (ids.length !== 1 ? 's' : '') + '? This cannot be undone.')) return;
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
  <p style="color:#9aa5b4;margin-top:.5rem">No files uploaded for this album yet.</p>
<?php endif; ?>

<?php elseif (!empty($albums)): ?>
<p style="color:#9aa5b4">Select an album above to upload and manage its files.</p>
<?php endif; ?>

<?php admin_footer(); ?>
