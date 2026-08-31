<?php
require_once __DIR__ . '/auth.php';
require_member_admin();
$pdo = get_pdo(); $edit = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify(); $action = $_POST['action'] ?? '';
    if ($action === 'save') {
        $id         = (int)($_POST['id'] ?? 0);
        $message    = strip_tags(trim($_POST['message'] ?? ''));
        $type       = $_POST['type']           ?? 'info';
        $link_text  = trim($_POST['link_text'] ?? '');
        $link_url   = trim($_POST['link_url']  ?? '');
        $expires_at = $_POST['expires_at']     ?: null;
        $active     = isset($_POST['active'])  ? 1 : 0;
        if (!in_array($type,['info','warning','urgent'])) $type='info';
        if ($message) {
            if ($id) {
                $pdo->prepare('UPDATE announcements SET message=?,type=?,link_text=?,link_url=?,expires_at=?,active=? WHERE id=?')
                    ->execute([$message,$type,$link_text,$link_url,$expires_at,$active,$id]);
                flash('success','Announcement updated.');
            } else {
                $pdo->prepare('INSERT INTO announcements (message,type,link_text,link_url,expires_at,active) VALUES (?,?,?,?,?,?)')
                    ->execute([$message,$type,$link_text,$link_url,$expires_at,$active]);
                flash('success','Announcement added.');
            }
        }
        header('Location: announcements.php'); exit;
    } elseif ($action === 'delete') {
        $pdo->prepare('DELETE FROM announcements WHERE id=?')->execute([(int)$_POST['id']]);
        flash('success','Announcement deleted.'); header('Location: announcements.php'); exit;
    }
}

if (isset($_GET['edit'])) { $s=$pdo->prepare('SELECT * FROM announcements WHERE id=?'); $s->execute([(int)$_GET['edit']]); $edit=$s->fetch(); }
$items = $pdo->query('SELECT * FROM announcements ORDER BY id DESC')->fetchAll();
$type_styles = ['info'=>'background:#e3f2fd;border-color:#90caf9;color:#0d47a1','warning'=>'background:#fff8e1;border-color:#ffc107;color:#5f4c00','urgent'=>'background:#ffebee;border-color:#ef9a9a;color:#b71c1c'];

admin_header('Announcements');
echo show_flash();
?>
<div class="page-head"><h1>Site Announcements</h1>
  <div style="display:flex;gap:.5rem">
    <?php if (!$edit): ?><a href="announcements.php?edit=new" class="btn btn-primary">+ Add Announcement</a><?php endif; ?>
    <a href="dashboard.php" class="btn btn-secondary">← Dashboard</a>
  </div>
</div>
<p style="font-size:.82rem;color:#5a6a7a;margin-bottom:1.25rem">Active announcements appear as a dismissible banner at the top of the main website.</p>

<?php if ($edit !== null || isset($_GET['edit'])): ?>
<div class="card" style="max-width:600px;margin-bottom:1.5rem">
  <h2 style="margin-bottom:1.25rem"><?= $edit?'Edit Announcement':'New Announcement' ?></h2>
  <form method="POST">
    <?= csrf_field() ?><input type="hidden" name="action" value="save">
    <?php if ($edit): ?><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>"><?php endif; ?>
    <div class="form-group"><label>Message *</label><textarea name="message" rows="3" required placeholder="e.g. BCT begins July 27 — update your cadet's contact info!"><?= h($edit['message']??'') ?></textarea></div>
    <div class="form-row col-2">
      <div class="form-group"><label>Type</label>
        <select name="type">
          <option value="info"    <?= ($edit['type']??'info')==='info'   ?'selected':''?>>ℹ️ Info (blue)</option>
          <option value="warning" <?= ($edit['type']??'')==='warning'    ?'selected':''?>>⚠️ Warning (yellow)</option>
          <option value="urgent"  <?= ($edit['type']??'')==='urgent'     ?'selected':''?>>🚨 Urgent (red)</option>
        </select>
      </div>
      <div class="form-group"><label>Expires</label><input type="datetime-local" name="expires_at" value="<?= h(isset($edit['expires_at'])&&$edit['expires_at']?str_replace(' ','T',substr($edit['expires_at'],0,16)):'') ?>"></div>
    </div>
    <div class="form-row col-2">
      <div class="form-group"><label>Link Text <span style="font-weight:400;font-size:.72rem;color:#9aa5b4">optional</span></label><input name="link_text" value="<?= h($edit['link_text']??'') ?>" placeholder="e.g. Learn More"></div>
      <div class="form-group"><label>Link URL</label><input name="link_url" value="<?= h($edit['link_url']??'') ?>" placeholder="https://…"></div>
    </div>
    <div class="form-group" style="display:flex;align-items:center;gap:.5rem">
      <input type="checkbox" name="active" id="ann_active" value="1" style="width:auto" <?= ($edit['active']??1)?'checked':'' ?>>
      <label for="ann_active" style="font-weight:400;text-transform:none;cursor:pointer;margin:0;font-size:.9rem">Active (show on site)</label>
    </div>
    <div style="display:flex;gap:.75rem">
      <button type="submit" class="btn btn-primary"><?= $edit?'Save Changes':'Post Announcement' ?></button>
      <a href="announcements.php" class="btn btn-secondary">Cancel</a>
    </div>
  </form>
</div>
<?php endif; ?>

<?php foreach ($items as $a): $ts = $type_styles[$a['type']] ?? $type_styles['info']; ?>
<div style="<?= $ts ?>;border:1px solid;border-radius:6px;padding:.85rem 1.25rem;margin-bottom:.6rem;display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;<?= $a['active']?'':'opacity:.5' ?>">
  <div style="flex:1">
    <div style="font-weight:600;font-size:.9rem"><?= h($a['message']) ?></div>
    <?php if ($a['link_text']): ?><div style="font-size:.78rem;margin-top:.2rem">Link: <?= h($a['link_text']) ?> → <?= h($a['link_url']) ?></div><?php endif; ?>
    <div style="font-size:.72rem;margin-top:.3rem;opacity:.75">
      Type: <?= ucfirst($a['type']) ?> &bull; <?= $a['active']?'Active':'Hidden' ?>
      <?php if ($a['expires_at']): ?> &bull; Expires: <?= date('M j, Y g:ia', strtotime($a['expires_at'])) ?><?php endif; ?>
    </div>
  </div>
  <div style="display:flex;gap:.4rem;flex-shrink:0">
    <a href="announcements.php?edit=<?= $a['id'] ?>" class="btn btn-secondary btn-sm">Edit</a>
    <form method="POST" onsubmit="return confirm('Delete?')" style="margin:0">
      <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $a['id'] ?>">
      <button type="submit" class="btn btn-danger btn-sm">Delete</button>
    </form>
  </div>
</div>
<?php endforeach; ?>
<?php if (empty($items)): ?><p style="color:#9aa5b4">No announcements yet.</p><?php endif; ?>
<?php admin_footer(); ?>
