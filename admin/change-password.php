<?php
require_once __DIR__ . '/auth.php';
require_login();
$pdo    = get_pdo();
$errors = [];
$done   = false;
$avatar_errors = [];
$avatar_done   = false;

$avatar_dir = __DIR__ . '/../avatars/';
if (!is_dir($avatar_dir)) mkdir($avatar_dir, 0755, true);

$post_action = $_POST['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $post_action === 'avatar_upload') {
    csrf_verify();
    $file = $_FILES['avatar'] ?? null;
    if (empty($file['name']) || $file['error'] !== UPLOAD_ERR_OK) {
        $avatar_errors[] = 'Please choose an image to upload.';
    } else {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp'];
        if (!isset($allowed[$mime]) || $file['size'] > 5 * 1024 * 1024) {
            $avatar_errors[] = 'Please use a JPG, PNG, GIF, or WebP image under 5MB.';
        } else {
            $old = $pdo->prepare('SELECT avatar_filename FROM users WHERE id=?');
            $old->execute([$_SESSION['user_id']]);
            $old_file = $old->fetchColumn();

            $filename = 'u' . $_SESSION['user_id'] . '_' . bin2hex(random_bytes(4)) . '.' . $allowed[$mime];
            if (!move_uploaded_file($file['tmp_name'], $avatar_dir . $filename)) {
                $avatar_errors[] = 'Upload failed — please try again.';
            } else {
                $pdo->prepare('UPDATE users SET avatar_filename=? WHERE id=?')->execute([$filename, $_SESSION['user_id']]);
                if ($old_file && is_file($avatar_dir . basename($old_file))) unlink($avatar_dir . basename($old_file));
                $avatar_done = true;
            }
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $post_action === 'avatar_remove') {
    csrf_verify();
    $old = $pdo->prepare('SELECT avatar_filename FROM users WHERE id=?');
    $old->execute([$_SESSION['user_id']]);
    $old_file = $old->fetchColumn();
    if ($old_file && is_file($avatar_dir . basename($old_file))) unlink($avatar_dir . basename($old_file));
    $pdo->prepare('UPDATE users SET avatar_filename=NULL WHERE id=?')->execute([$_SESSION['user_id']]);
    $avatar_done = true;

} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && $post_action === 'update_password') {
    csrf_verify();
    $current  = $_POST['current_password']  ?? '';
    $new_pw   = $_POST['new_password']      ?? '';
    $confirm  = $_POST['confirm_password']  ?? '';

    // Verify current password
    $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($current, $user['password_hash']))
        $errors[] = 'Current password is incorrect.';
    if (strlen($new_pw) < 8)
        $errors[] = 'New password must be at least 8 characters.';
    if ($new_pw !== $confirm)
        $errors[] = 'New passwords do not match.';

    if (empty($errors)) {
        $pdo->prepare('UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ?')
            ->execute([password_hash($new_pw, PASSWORD_BCRYPT), $_SESSION['user_id']]);
        $done = true;
    }
}

$me = $pdo->prepare('SELECT name, avatar_filename FROM users WHERE id = ?');
$me->execute([$_SESSION['user_id']]);
$me = $me->fetch(PDO::FETCH_ASSOC);

$back = can_manage_finances() ? 'purchases.php' : 'index.php';
admin_header('My Profile');
?>
<style>
.pw-card{background:#fff;border-radius:6px;box-shadow:0 1px 4px rgba(0,0,0,.1);padding:1.5rem;max-width:420px}
.pw-wrap{position:relative;margin-bottom:.9rem}
.pw-wrap input{margin-bottom:0;padding-right:2.75rem}
.pw-toggle{position:absolute;right:.75rem;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:#9aa5b4;font-size:1.1rem;padding:0;line-height:1}
.avatar-circle{width:88px;height:88px;border-radius:50%;object-fit:cover;background:#003594;color:#fff;display:flex;align-items:center;justify-content:center;font-size:1.9rem;font-weight:700;flex-shrink:0}
.avatar-row{display:flex;gap:1.25rem;align-items:center;margin-bottom:1.25rem;flex-wrap:wrap}
</style>

<div class="page-head">
  <h1>My Profile</h1>
  <a href="<?= $back ?>" class="btn btn-secondary">← Back</a>
</div>

<?php if (!empty($avatar_errors)): ?>
  <div class="alert alert-error" style="max-width:420px">
    <?= implode('<br>', array_map('htmlspecialchars', $avatar_errors)) ?>
  </div>
<?php endif; ?>
<?php if ($avatar_done): ?>
  <div class="alert alert-success" style="max-width:420px">✓ Profile picture updated.</div>
<?php endif; ?>

<div class="pw-card">
  <h2 style="font-size:.95rem;color:#002554;margin-bottom:1rem">Profile Picture</h2>
  <div class="avatar-row">
    <?php if ($me['avatar_filename']): ?>
      <img class="avatar-circle" src="/avatar-serve.php?id=<?= (int)$_SESSION['user_id'] ?>&v=<?= time() ?>" alt="">
    <?php else: ?>
      <div class="avatar-circle"><?= h(mb_strtoupper(mb_substr($me['name'], 0, 1))) ?></div>
    <?php endif; ?>
    <div style="flex:1;min-width:200px">
      <form method="POST" enctype="multipart/form-data" style="margin-bottom:.5rem">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="avatar_upload">
        <input type="file" name="avatar" accept="image/*" required style="font-size:.82rem;margin-bottom:.5rem">
        <button type="submit" class="btn btn-primary btn-sm">Upload</button>
      </form>
      <?php if ($me['avatar_filename']): ?>
      <form method="POST" onsubmit="return confirm('Remove your profile picture?')">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="avatar_remove">
        <button type="submit" class="btn btn-secondary btn-sm">Remove Picture</button>
      </form>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php if ($done): ?>
  <div class="alert alert-success" style="max-width:420px">
    ✓ Password updated successfully.
    <a href="<?= $back ?>" style="color:#1b5e20;font-weight:600;margin-left:.5rem">← Back</a>
  </div>
<?php else: ?>

<?php if (!empty($errors)): ?>
  <div class="alert alert-error" style="max-width:420px">
    <?= implode('<br>', array_map('htmlspecialchars', $errors)) ?>
  </div>
<?php endif; ?>

<div class="pw-card">
  <h2 style="font-size:.95rem;color:#002554;margin-bottom:1rem">Change Password</h2>
  <p style="font-size:.85rem;color:#5a6a7a;margin-bottom:1.25rem">
    Changing password for <strong><?= h(current_user_name()) ?></strong>
  </p>
  <form method="POST">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="update_password">
    <div class="form-group">
      <label>Current Password</label>
      <div class="pw-wrap">
        <input type="password" id="pw-cur" name="current_password" required autocomplete="current-password">
        <button type="button" class="pw-toggle" onclick="togglePw('pw-cur',this)">👁</button>
      </div>
    </div>
    <div class="form-group">
      <label>New Password <span style="font-weight:400;text-transform:none;letter-spacing:0;font-size:.72rem;color:#9aa5b4">(min 8 characters)</span></label>
      <div class="pw-wrap">
        <input type="password" id="pw-new" name="new_password" required minlength="8" autocomplete="new-password">
        <button type="button" class="pw-toggle" onclick="togglePw('pw-new',this)">👁</button>
      </div>
    </div>
    <div class="form-group">
      <label>Confirm New Password</label>
      <div class="pw-wrap">
        <input type="password" id="pw-conf" name="confirm_password" required autocomplete="new-password">
        <button type="button" class="pw-toggle" onclick="togglePw('pw-conf',this)">👁</button>
      </div>
    </div>
    <button type="submit" class="btn btn-primary" style="width:100%">Update Password</button>
  </form>
</div>
<?php endif; ?>

<script>
function togglePw(id, btn) {
  var inp  = document.getElementById(id);
  var show = inp.type === 'password';
  inp.type     = show ? 'text' : 'password';
  btn.style.opacity = show ? '1' : '0.4';
}
</script>

<?php admin_footer(); ?>
