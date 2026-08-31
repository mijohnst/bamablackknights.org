<?php
require_once __DIR__ . '/auth.php';
require_digest_composer_access();
$pdo = get_pdo();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'save' || $action === 'update') {
        $title = trim($_POST['title'] ?? '');
        $html  = sanitize_rich_html(trim($_POST['html'] ?? ''));
        if ($title === '') $title = 'Untitled Digest';
        if ($html === '') {
            flash('error', 'Digest body can\'t be empty.');
        } elseif ($action === 'save') {
            $pdo->prepare('INSERT INTO digest_emails (title, html_body, created_by) VALUES (?, ?, ?)')
                ->execute([$title, $html, $_SESSION['user_id'] ?? null]);
            flash('success', 'Digest saved to the catalog.');
        } else {
            $id = (int)($_POST['id'] ?? 0);
            $pdo->prepare('UPDATE digest_emails SET title = ?, html_body = ? WHERE id = ?')
                ->execute([$title, $html, $id]);
            flash('success', 'Digest updated.');
        }
        header('Location: digest-catalog.php'); exit;
    } elseif ($action === 'delete') {
        $pdo->prepare('DELETE FROM digest_emails WHERE id = ?')->execute([(int)($_POST['id'] ?? 0)]);
        flash('success', 'Digest deleted.');
        header('Location: digest-catalog.php'); exit;
    } elseif ($action === 'delete_all') {
        $count = (int)$pdo->query('SELECT COUNT(*) FROM digest_emails')->fetchColumn();
        $pdo->exec('DELETE FROM digest_emails');
        flash('success', "Deleted all $count digest(s).");
        header('Location: digest-catalog.php'); exit;
    }
}

$edit = null;
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT * FROM digest_emails WHERE id = ?');
    $stmt->execute([(int)$_GET['edit']]);
    $edit = $stmt->fetch();
}

$digests = $pdo->query(
    "SELECT d.*, u.name AS author_name
     FROM digest_emails d
     LEFT JOIN users u ON u.id = d.created_by
     ORDER BY d.updated_at DESC"
)->fetchAll();

// Pre-derive the plain-text copy for each saved digest so the Copy Plain
// Text button works without another round trip — kept in sync with
// digest-generate.php's own conversion via the shared helper.
$digest_data = [];
foreach ($digests as $d) {
    $digest_data[$d['id']] = ['html' => $d['html_body'], 'text' => digest_html_to_text($d['html_body'])];
}

admin_header('Digest Catalog');
echo show_flash();
?>
<!-- Quill rich text editor (CDN) -->
<link href="https://cdn.quilljs.com/1.3.7/quill.snow.css" rel="stylesheet">
<script src="https://cdn.quilljs.com/1.3.7/quill.min.js"></script>
<style>
.dg-row{background:#fff;border-radius:6px;box-shadow:0 1px 4px rgba(0,0,0,.08);padding:.9rem 1.1rem;margin-bottom:.6rem;display:flex;justify-content:space-between;gap:1rem;flex-wrap:wrap;align-items:center}
.dg-snippet{font-size:.82rem;color:#5a6a7a;margin-top:.25rem;max-width:560px;white-space:pre-wrap;overflow:hidden;text-overflow:ellipsis;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical}
.dg-actions{display:flex;gap:.4rem;flex-shrink:0;flex-wrap:wrap}
.ql-editor{min-height:220px;font-family:"Segoe UI",Arial,sans-serif;font-size:.95rem}
</style>

<div class="page-head">
  <h1>Digest Catalog</h1>
  <div style="display:flex;gap:.5rem;flex-wrap:wrap">
    <a href="digest-composer.php" class="btn btn-primary">+ New Digest</a>
    <?php if (!empty($digests)): ?>
    <form method="POST" style="margin:0" onsubmit="return confirmDeleteAllDigests(<?= count($digests) ?>)">
      <?= csrf_field() ?><input type="hidden" name="action" value="delete_all">
      <button type="submit" class="btn btn-danger">Delete All</button>
    </form>
    <?php endif; ?>
    <a href="dashboard.php" class="btn btn-secondary">← Dashboard</a>
  </div>
</div>
<p style="font-size:.82rem;color:#5a6a7a;margin-bottom:1.25rem">
  Digests you've saved from the Digest Composer. Edit the wording here anytime, or copy an old one back out to paste into Gmail.
</p>

<?php if ($edit): ?>
<div class="card" style="max-width:900px;margin-bottom:1.5rem">
  <h2 style="margin-bottom:1.25rem">Edit Digest</h2>
  <form method="POST" id="edit-form">
    <?= csrf_field() ?><input type="hidden" name="action" value="update">
    <input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
    <div class="form-group"><label>Title</label><input name="title" value="<?= h($edit['title']) ?>" maxlength="200"></div>
    <div class="form-group">
      <label>Digest Body</label>
      <div id="edit-editor"><?= $edit['html_body'] ?></div>
      <input type="hidden" name="html" id="edit-html-input">
    </div>
    <div style="display:flex;gap:.75rem;margin-top:.75rem">
      <button type="submit" class="btn btn-primary">Save Changes</button>
      <a href="digest-catalog.php" class="btn btn-secondary">Cancel</a>
    </div>
  </form>
</div>
<script>
var editQuill = new Quill('#edit-editor', {
  theme: 'snow',
  modules: { toolbar: [['bold','italic','underline'],[{ 'header': [3,false] }],[{ 'list': 'ordered' },{ 'list': 'bullet' }],['link'],['clean']] }
});
document.getElementById('edit-form').addEventListener('submit', function() {
  document.getElementById('edit-html-input').value = editQuill.root.innerHTML;
});
</script>
<?php endif; ?>

<?php if (empty($digests)): ?>
  <p style="color:#9aa5b4">No digests saved yet — generate one on the <a href="digest-composer.php">Digest Composer</a> and save it here.</p>
<?php else: ?>
  <?php foreach ($digests as $d): $saved = date('M j, Y g:ia', strtotime($d['updated_at'])); ?>
  <div class="dg-row">
    <div style="flex:1;min-width:0">
      <strong style="color:#002554"><?= h($d['title']) ?></strong>
      <span style="font-size:.78rem;color:#9aa5b4"> — saved <?= $saved ?><?= $d['author_name'] ? ' by ' . h($d['author_name']) : '' ?></span>
      <div class="dg-snippet"><?= h(digest_html_to_text($d['html_body'])) ?></div>
    </div>
    <div class="dg-actions">
      <button type="button" class="btn btn-secondary btn-sm" onclick="copyDigest(<?= (int)$d['id'] ?>, 'text')">Copy Plain</button>
      <button type="button" class="btn btn-primary btn-sm" onclick="copyDigest(<?= (int)$d['id'] ?>, 'html')">Copy Formatted</button>
      <a href="digest-catalog.php?edit=<?= (int)$d['id'] ?>" class="btn btn-secondary btn-sm">Edit</a>
      <form method="POST" style="margin:0" onsubmit="return confirm('Delete this digest? This cannot be undone.')">
        <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
        <button type="submit" class="btn btn-danger btn-sm">Delete</button>
      </form>
    </div>
  </div>
  <?php endforeach; ?>
<?php endif; ?>

<script>
var digestData = <?= json_encode($digest_data, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

function copyDigest(id, kind) {
  var entry = digestData[id];
  if (!entry) return;
  if (kind === 'html' && navigator.clipboard && window.ClipboardItem) {
    var htmlBlob = new Blob([entry.html], { type: 'text/html' });
    var textBlob = new Blob([entry.text], { type: 'text/plain' });
    navigator.clipboard.write([new ClipboardItem({ 'text/html': htmlBlob, 'text/plain': textBlob })])
      .then(function(){ alert('Copied formatted digest — paste into Gmail.'); })
      .catch(function(){ fallbackCopy(entry.text); });
  } else {
    fallbackCopy(entry.text);
  }
}
function fallbackCopy(text) {
  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(text).then(function(){ alert('Copied.'); });
  } else {
    var ta = document.createElement('textarea');
    ta.value = text; document.body.appendChild(ta); ta.select();
    document.execCommand('copy'); document.body.removeChild(ta);
    alert('Copied.');
  }
}

// Two-step delete for the bulk action — mirrors parent-letters.php's
// confirmDeleteAllLetters() so "Delete All" behaves the same everywhere.
function confirmDeleteAllDigests(count) {
  if (!confirm('Permanently delete all ' + count + ' digest(s)? This cannot be undone.')) return false;
  var typed = prompt('Type DELETE (all caps) to confirm.');
  if (typed !== 'DELETE') { alert('Not confirmed — nothing was deleted.'); return false; }
  return true;
}
</script>

<?php admin_footer(); ?>
