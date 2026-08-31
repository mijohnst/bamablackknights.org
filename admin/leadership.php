<?php
require_once __DIR__ . '/auth.php';
require_member_admin();
$pdo = get_pdo();
$errors = []; $edit = null;

function save_officer_photo(string $key): ?string {
    if (empty($_FILES[$key]['name'])) return null;
    $file = $_FILES[$key];
    if ($file['error'] !== UPLOAD_ERR_OK) return null;
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = finfo_file($finfo, $file['tmp_name']); finfo_close($finfo);
    if (!in_array($mime, ['image/jpeg','image/png','image/gif','image/webp'])) return null;
    if ($file['size'] > 10*1024*1024) return null; // 10MB limit
    $ext  = ['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp'][$mime];
    $name = 'officer-' . bin2hex(random_bytes(4)) . '.' . $ext;
    $dir  = __DIR__ . '/../leadership-photos/';
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) return null;
    if (!move_uploaded_file($file['tmp_name'], $dir . $name)) return null;
    return $name;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    if ($action === 'save') {
        $id         = (int)($_POST['id'] ?? 0);
        $name       = trim($_POST['name']       ?? '');
        $role       = trim($_POST['role_title'] ?? '');
        $bio        = trim($_POST['bio']        ?? '');
        $email      = trim($_POST['email']      ?? '');
        $sort_order = (int)($_POST['sort_order']?? 0);
        $active     = isset($_POST['active']) ? 1 : 0;
        $new_photo  = save_officer_photo('photo');
        if (!$name) $errors[] = 'Name is required.';
        if (!$role) $errors[] = 'Role/title is required.';
        if (empty($errors)) {
            if ($id) {
                $cur = $pdo->prepare('SELECT photo_filename FROM leadership WHERE id=?'); $cur->execute([$id]); $existing = $cur->fetchColumn();
                $photo = $new_photo ?? $existing;
                $pdo->prepare('UPDATE leadership SET name=?,role_title=?,bio=?,photo_filename=?,email=?,sort_order=?,active=?,updated_at=NOW() WHERE id=?')
                    ->execute([$name,$role,$bio,$photo,$email,$sort_order,$active,$id]);
                flash('success','Officer updated.');
            } else {
                $pdo->prepare('INSERT INTO leadership (name,role_title,bio,photo_filename,email,sort_order,active) VALUES (?,?,?,?,?,?,?)')
                    ->execute([$name,$role,$bio,$new_photo,$email,$sort_order,$active]);
                $id = (int)$pdo->lastInsertId();
                flash('success','Officer added.');
            }

            // Only one active officer per role title at a time — saving this
            // one as active automatically retires whoever previously held
            // the title, so the public site can't show two people in the
            // same seat.
            if ($active) {
                $pdo->prepare('UPDATE leadership SET active=0 WHERE id<>? AND active=1 AND LOWER(TRIM(role_title))=LOWER(TRIM(?))')
                    ->execute([$id, $role]);
            }

            // Keep the matching login account's display name in sync.
            // leadership and users are separate tables, linked only by the
            // shared role email (e.g. treasurer@alabamafalcons.org) — this is
            // the one place that connects "who the public site shows" to
            // "who's actually logged in," so the nav bar doesn't keep
            // showing whoever previously held the position. Only fires when
            // the name is actually changing (a real handoff to someone new,
            // not just re-saving the same officer) — in that case the old
            // holder's avatar photo and linked cadet family are person-
            // specific and must NOT carry over to whoever takes the account
            // next; clear them so the new holder starts clean rather than
            // silently inheriting the previous person's photo/family.
            if ($email !== '') {
                try {
                    $pdo->prepare('UPDATE users SET name=?, avatar_filename=NULL, member_id=NULL WHERE LOWER(email)=LOWER(?) AND name<>?')
                        ->execute([$name, $email, $name]);
                } catch (PDOException $e) {
                    // users.member_id migration not run yet on this install
                    $pdo->prepare('UPDATE users SET name=?, avatar_filename=NULL WHERE LOWER(email)=LOWER(?) AND name<>?')
                        ->execute([$name, $email, $name]);
                }
            }

            header('Location: leadership.php'); exit;
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) { $pdo->prepare('DELETE FROM leadership WHERE id=?')->execute([$id]); flash('success','Officer removed.'); }
        header('Location: leadership.php'); exit;
    }
}

if (isset($_GET['edit'])) { $s=$pdo->prepare('SELECT * FROM leadership WHERE id=?'); $s->execute([(int)$_GET['edit']]); $edit=$s->fetch(); }
$officers = $pdo->query('SELECT * FROM leadership ORDER BY sort_order ASC')->fetchAll();

admin_header('Leadership');
echo show_flash();
?>
<div class="page-head">
  <h1>Club Leadership</h1>
  <div style="display:flex;gap:.5rem">
    <?php if (!$edit): ?><a href="leadership.php?edit=new" class="btn btn-primary">+ Add Officer</a><?php endif; ?>
    <a href="dashboard.php" class="btn btn-secondary">← Dashboard</a>
  </div>
</div>
<p style="font-size:.82rem;color:#5a6a7a;margin-bottom:1.25rem">Changes here update the Leadership section on the main site automatically.</p>

<?php if (!empty($errors)): ?><div class="alert alert-error"><?= implode('<br>',array_map('htmlspecialchars',$errors)) ?></div><?php endif; ?>

<?php if ($edit !== null || isset($_GET['edit'])): ?>
<div class="card" style="max-width:620px;margin-bottom:1.5rem">
  <h2 style="margin-bottom:1.25rem"><?= $edit?'Edit Officer':'Add Officer' ?></h2>
  <form method="POST" enctype="multipart/form-data">
    <?= csrf_field() ?><input type="hidden" name="action" value="save">
    <?php if ($edit): ?><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>"><?php endif; ?>
    <div class="form-row col-2">
      <div class="form-group"><label>Full Name *</label><input name="name" value="<?= h($edit['name']??'') ?>" required></div>
      <div class="form-group"><label>Role / Title *</label><input name="role_title" value="<?= h($edit['role_title']??'') ?>" required placeholder="e.g. President"></div>
    </div>
    <div class="form-row col-2">
      <div class="form-group"><label>Email</label><input type="email" name="email" value="<?= h($edit['email']??'') ?>" placeholder="role@alabamafalcons.org"></div>
      <div class="form-group"><label>Sort Order</label><input type="number" name="sort_order" value="<?= h($edit['sort_order']??'0') ?>"></div>
    </div>
    <div class="form-group"><label>Bio</label><textarea name="bio" rows="4" placeholder="Officer background and connection to USAFA…"><?= h($edit['bio']??'') ?></textarea></div>
    <div class="form-group">
      <label>Photo <?= $edit?'(upload to replace)':'' ?></label>
      <input type="file" name="photo" accept="image/*" style="padding:.5rem;font-size:.9rem">
      <?php if (!empty($edit['photo_filename'])): ?>
        <div style="margin-top:.5rem;font-size:.82rem;color:#5a6a7a">Current: <?= h($edit['photo_filename']) ?></div>
      <?php endif; ?>
    </div>
    <div class="form-group" style="display:flex;align-items:center;gap:.5rem">
      <input type="checkbox" name="active" id="off_active" value="1" style="width:auto" <?= ($edit['active']??1)?'checked':'' ?>>
      <label for="off_active" style="font-weight:400;text-transform:none;letter-spacing:0;cursor:pointer;margin:0;font-size:.9rem">Show on website</label>
    </div>
    <div style="display:flex;gap:.75rem">
      <button type="submit" class="btn btn-primary"><?= $edit?'Save Changes':'Add Officer' ?></button>
      <a href="leadership.php" class="btn btn-secondary">Cancel</a>
    </div>
  </form>
</div>
<?php endif; ?>

<div class="card" style="padding:0;overflow-x:auto">
<table><thead><tr><th>Photo</th><th>Name</th><th>Role</th><th>Email</th><th>Order</th><th>Visible</th><th class="actions-head">Actions</th></tr></thead><tbody>
<?php foreach ($officers as $o): ?>
<tr>
  <td><?php if ($o['photo_filename'] && preg_match('/^[a-zA-Z0-9._-]+$/', $o['photo_filename'])):
    $src = file_exists(__DIR__.'/../leadership-photos/'.$o['photo_filename']) ? '/leadership-photos/'.h($o['photo_filename']) : '/'.h($o['photo_filename']); ?>
    <img src="<?= $src ?>" style="width:40px;height:40px;border-radius:50%;object-fit:cover">
  <?php endif; ?></td>
  <td><strong><?= h($o['name']) ?></strong></td>
  <td style="color:#5a6a7a;font-size:.85rem"><?= h($o['role_title']) ?></td>
  <td style="font-size:.78rem;color:#5a6a7a"><?= h($o['email']) ?></td>
  <td style="text-align:center"><?= $o['sort_order'] ?></td>
  <td style="text-align:center"><?= $o['active']?'✅':'—' ?></td>
  <td class="actions"><div class="btn-group">
    <a href="leadership.php?edit=<?= $o['id'] ?>" class="btn btn-secondary btn-sm">Edit</a>
    <form method="POST" onsubmit="return confirm('Remove <?= h(addslashes($o['name'])) ?>?')" style="margin:0">
      <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $o['id'] ?>">
      <button type="submit" class="btn btn-danger btn-sm">Remove</button>
    </form>
  </div></td>
</tr>
<?php endforeach; ?>
</tbody></table>
</div>
<?php admin_footer(); ?>
