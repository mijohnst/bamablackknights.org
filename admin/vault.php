<?php
require_once __DIR__ . '/auth.php';
require_login();
if (!can_manage_members() && !is_treasurer()) {
    header('Location: dashboard.php?denied=1'); exit;
}
$pdo    = get_pdo();
$errors = [];

$can_upload = can_manage_members() || is_treasurer();
$vault_dir  = __DIR__ . '/vault/';
if (!is_dir($vault_dir)) mkdir($vault_dir, 0755, true);

const VAULT_CATEGORIES = ['Non-Profit Formation','Tax Filings','Bank Statements','Meeting Minutes','Policies & Bylaws','Correspondence','Events','Insurance','Contracts & Agreements','Other'];
const VAULT_TYPES      = ['application/pdf','application/msword','application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                           'application/vnd.ms-excel','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                           'application/vnd.ms-powerpoint','application/vnd.openxmlformats-officedocument.presentationml.presentation',
                           'image/jpeg','image/png','image/gif','text/plain'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_upload) {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'upload') {
        $title       = trim($_POST['title']       ?? '');
        $category    = trim($_POST['category']    ?? 'Other');
        $description = trim($_POST['description'] ?? '');

        if (!$title) $errors[] = 'Title is required.';
        if (!in_array($category, VAULT_CATEGORIES)) $category = 'Other';

        if (empty($errors) && !empty($_FILES['document']['name'])) {
            $file  = $_FILES['document'];
            if ($file['error'] !== UPLOAD_ERR_OK) { $errors[] = 'Upload error.'; }
            else {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime  = finfo_file($finfo, $file['tmp_name']); finfo_close($finfo);
                if (!in_array($mime, VAULT_TYPES) || $file['size'] > 25*1024*1024) {
                    $errors[] = 'Invalid file type or size exceeds 25MB. Accepted: PDF, Word, Excel, PowerPoint, images, text.';
                } else {
                    $ext_map = ['application/pdf'=>'pdf','application/msword'=>'doc',
                        'application/vnd.openxmlformats-officedocument.wordprocessingml.document'=>'docx',
                        'application/vnd.ms-excel'=>'xls',
                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'=>'xlsx',
                        'application/vnd.ms-powerpoint'=>'ppt',
                        'application/vnd.openxmlformats-officedocument.presentationml.presentation'=>'pptx',
                        'image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','text/plain'=>'txt'];
                    $ext  = $ext_map[$mime] ?? 'bin';
                    $name = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
                    if (!move_uploaded_file($file['tmp_name'], $vault_dir . $name)) {
                        $errors[] = 'Upload failed — please try again.';
                    } else {
                        $pdo->prepare('INSERT INTO vault_documents (title,category,description,filename,file_size,mime_type,uploaded_by) VALUES (?,?,?,?,?,?,?)')
                            ->execute([$title,$category,$description,$name,$file['size'],$mime,$_SESSION['user_id']??null]);
                        flash('success',"\"$title\" uploaded.");
                        header('Location: vault.php'); exit;
                    }
                }
            }
        } elseif (empty($errors)) { $errors[] = 'Please select a file.'; }

    } elseif ($action === 'delete') {
        $id  = (int)($_POST['id'] ?? 0);
        $row = $pdo->prepare('SELECT filename,title FROM vault_documents WHERE id=?'); $row->execute([$id]); $d=$row->fetch();
        if ($d && preg_match('/^[a-zA-Z0-9._-]+$/', $d['filename'])) {
            @unlink($vault_dir . $d['filename']);
            $pdo->prepare('DELETE FROM vault_documents WHERE id=?')->execute([$id]);
            flash('success','"'.$d['title'].'" deleted.');
        }
        header('Location: vault.php'); exit;
    }
}

$filter_cat = $_GET['cat'] ?? '';
$search     = trim($_GET['q'] ?? '');
$where = ['1=1']; $params = [];
if ($filter_cat) { $where[] = 'v.category=:cat'; $params[':cat']=$filter_cat; }
if ($search)     { $se = str_replace(['%','_'],['\%','\_'],$search); $where[] = '(v.title LIKE :q OR v.description LIKE :q)'; $params[':q']="%$se%"; }

$docs = $pdo->prepare('SELECT v.*, u.name as uploader_name FROM vault_documents v LEFT JOIN users u ON v.uploaded_by=u.id WHERE '.implode(' AND ',$where).' ORDER BY v.category, v.created_at DESC');
$docs->execute($params);
$docs = $docs->fetchAll();

$size_fmt = fn($b) => $b>=1048576 ? round($b/1048576,1).'MB' : round($b/1024,0).'KB';
$get_icon  = fn($mime) => match(true) {
    str_contains($mime,'pdf')    => '📄',
    str_contains($mime,'word')   => '📝',
    str_contains($mime,'excel') || str_contains($mime,'spreadsheet') => '📊',
    str_contains($mime,'powerpoint') || str_contains($mime,'presentation') => '📊',
    str_contains($mime,'image')  => '🖼️',
    default => '📁'
};

admin_header('Document Vault');
echo show_flash();
?>
<style>
.doc-row{display:flex;align-items:center;gap:1rem;padding:.85rem 1.25rem;border-bottom:1px solid #f0f2f5;flex-wrap:wrap}
.doc-row:last-child{border-bottom:none}
.doc-row:hover{background:#fafbff}
.doc-icon{font-size:1.5rem;flex-shrink:0}
.doc-meta{flex:1;min-width:0}
.doc-title{font-weight:700;color:#002554;font-size:.92rem}
.doc-sub{font-size:.75rem;color:#5a6a7a;margin-top:.15rem}
.cat-badge{display:inline-block;background:#e3f2fd;color:#0d47a1;font-size:.65rem;font-weight:700;padding:.1rem .4rem;border-radius:3px;text-transform:uppercase;letter-spacing:.04em;margin-right:.35rem}
</style>

<div class="page-head">
  <h1>Document Vault</h1>
  <a href="dashboard.php" class="btn btn-secondary">← Dashboard</a>
</div>
<p style="font-size:.82rem;color:#5a6a7a;margin-bottom:1.25rem">Secure storage for club documents. Access restricted to Officers, Secretary, and Treasurer.</p>

<?php if (!empty($errors)): ?><div class="alert alert-error"><?= implode('<br>',array_map('htmlspecialchars',$errors)) ?></div><?php endif; ?>

<?php if ($can_upload): ?>
<!-- Upload -->
<div class="card" style="max-width:600px;margin-bottom:1.5rem">
  <h2 style="margin-bottom:1rem">Upload Document</h2>
  <form method="POST" enctype="multipart/form-data">
    <?= csrf_field() ?><input type="hidden" name="action" value="upload">
    <div class="form-group"><label>Title *</label><input name="title" required placeholder="e.g. 2026 IRS Form 990-N"></div>
    <div class="form-row col-2">
      <div class="form-group">
        <label>Category</label>
        <select name="category">
          <?php foreach (VAULT_CATEGORIES as $c): ?>
            <option value="<?= h($c) ?>"><?= h($c) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group"><label>Description <span style="font-weight:400;font-size:.72rem;color:#9aa5b4">optional</span></label><input name="description" placeholder="Brief description"></div>
    </div>
    <div class="form-group">
      <label>File <span style="font-weight:400;font-size:.72rem;color:#9aa5b4">PDF, Word, Excel, PowerPoint, images · max 25MB</span></label>
      <input type="file" name="document" required accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.jpg,.jpeg,.png,.gif,.txt" style="padding:.5rem;font-size:.9rem">
    </div>
    <button type="submit" class="btn btn-primary">Upload Document</button>
  </form>
</div>
<?php endif; ?>

<!-- Filter -->
<div class="card" style="padding:.85rem 1.25rem;margin-bottom:1rem">
  <form method="GET" style="display:flex;gap:.75rem;flex-wrap:wrap;align-items:flex-end">
    <div class="form-group" style="flex:2;min-width:180px;margin:0">
      <label>Search</label>
      <input name="q" value="<?= h($search) ?>" placeholder="Search by title or description…">
    </div>
    <div class="form-group" style="margin:0">
      <label>Category</label>
      <select name="cat">
        <option value="">All Categories</option>
        <?php foreach (VAULT_CATEGORIES as $c): ?>
          <option value="<?= h($c) ?>" <?= $filter_cat===$c?'selected':''?>><?= h($c) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div style="display:flex;gap:.5rem">
      <button type="submit" class="btn btn-primary">Filter</button>
      <a href="vault.php" class="btn btn-secondary">Clear</a>
    </div>
  </form>
</div>

<!-- Document list -->
<div class="card" style="padding:0">
  <?php if (empty($docs)): ?>
    <p style="text-align:center;padding:2rem;color:#9aa5b4">No documents yet<?= ($search||$filter_cat)?'. Try clearing the filter.':'.'; ?></p>
  <?php endif; ?>
  <?php
  $cur_cat = null;
  foreach ($docs as $d):
    $ext  = pathinfo($d['filename'], PATHINFO_EXTENSION);
    $icon = $get_icon($d['mime_type']);
    if ($d['category'] !== $cur_cat):
      $cur_cat = $d['category'];
  ?>
  <div style="background:#f5f7fa;padding:.5rem 1.25rem;font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:#5a6a7a;border-bottom:1px solid #e1e5eb"><?= h($cur_cat) ?></div>
  <?php endif; ?>
  <div class="doc-row">
    <span class="doc-icon"><?= $icon ?></span>
    <div class="doc-meta">
      <div class="doc-title">
        <?= h($d['title']) ?>
        <?php if (!empty($d['source_meeting_id'])): ?>
        <span title="Auto-synced from Meeting Minutes — edit/replace/delete it there instead" style="font-size:.65rem;font-weight:700;color:#0d47a1;background:#e3f2fd;padding:.05rem .35rem;border-radius:3px;margin-left:.3rem;vertical-align:middle">🔗 SYNCED</span>
        <?php endif; ?>
      </div>
      <div class="doc-sub">
        <?php if ($d['description']): ?><?= h($d['description']) ?> &bull; <?php endif; ?>
        <?= h($size_fmt($d['file_size'])) ?> &bull;
        Uploaded by <?= h($d['uploader_name']??'Unknown') ?> on <?= date('M j, Y', strtotime($d['created_at'])) ?>
      </div>
    </div>
    <div style="display:flex;gap:.4rem;flex-shrink:0;flex-wrap:wrap">
      <a href="vault-serve.php?id=<?= $d['id'] ?>" target="_blank" class="btn btn-primary btn-sm">View</a>
      <a href="vault-serve.php?id=<?= $d['id'] ?>&download=1" class="btn btn-secondary btn-sm">⬇ Download</a>
      <?php if ($can_upload): ?>
      <form method="POST" onsubmit="return confirm('Delete \"<?= h(addslashes($d['title'])) ?>\"? This cannot be undone.')" style="margin:0">
        <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $d['id'] ?>">
        <button type="submit" class="btn btn-danger btn-sm">Delete</button>
      </form>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<?php admin_footer(); ?>
