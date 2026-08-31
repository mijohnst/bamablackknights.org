<?php
// Public account-setup page for members invited via the "Send Portal
// Invite" bulk action on the member roster. Requires a valid, unexpired
// per-account invite token — not guessable, not browsable.
require_once __DIR__ . '/admin/auth.php';
start_session();
$pdo = get_pdo();

$token = $_GET['token'] ?? $_POST['token'] ?? '';
if (!preg_match('/^[a-f0-9]{48}$/', $token)) { http_response_code(404); echo 'Not found.'; exit; }

$stmt = $pdo->prepare('SELECT * FROM users WHERE invite_token = ? LIMIT 1');
$stmt->execute([$token]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

$expired = $user && strtotime($user['invite_expires']) < time();
$error   = '';

if ($user && !$expired && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $pw  = $_POST['password']  ?? '';
    $pw2 = $_POST['password2'] ?? '';
    if (strlen($pw) < 8)     $error = 'Password must be at least 8 characters.';
    elseif ($pw !== $pw2)    $error = 'Passwords do not match.';
    else {
        $pdo->prepare('UPDATE users SET password_hash=?, invite_token=NULL, invite_expires=NULL WHERE id=?')
            ->execute([password_hash($pw, PASSWORD_BCRYPT), $user['id']]);
        session_regenerate_id(true);
        $_SESSION['logged_in']  = true;
        $_SESSION['role']       = $user['role'];
        $_SESSION['user_id']    = $user['id'];
        $_SESSION['user_name']  = $user['name'];
        $_SESSION['user_email'] = $user['email'];
        header('Location: admin/dashboard.php'); exit;
    }
}
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Set Up Your Portal Account — USAFA Parents Club of Alabama</title>
<link rel="icon" type="image/png" href="logo01.png" />
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:"Segoe UI",Arial,sans-serif;background:#002554;display:flex;justify-content:center;align-items:center;min-height:100vh;padding:1rem}
.box{background:#fff;border-radius:8px;box-shadow:0 4px 24px rgba(0,0,0,.3);padding:2rem;width:100%;max-width:400px}
.logo{text-align:center;margin-bottom:1.5rem}
.logo img{height:48px;border-radius:4px;margin-bottom:.75rem;display:block;margin-left:auto;margin-right:auto}
.logo strong{display:block;font-size:.95rem;color:#002554;letter-spacing:.02em}
.logo small{color:#5a6a7a;font-size:.8rem}
h2{font-size:1rem;color:#002554;margin-bottom:1.25rem;text-align:center}
label{display:block;font-size:.78rem;font-weight:700;color:#5a6a7a;text-transform:uppercase;letter-spacing:.04em;margin-bottom:.3rem}
input{width:100%;padding:.65rem .75rem;border:1px solid #d0d5dd;border-radius:4px;font-size:.95rem;margin-bottom:1rem;font-family:inherit}
input:focus{outline:none;border-color:#003594;box-shadow:0 0 0 2px rgba(0,53,148,.15)}
button{width:100%;padding:.75rem;background:#003594;color:#fff;border:none;border-radius:4px;font-size:.95rem;font-weight:700;cursor:pointer;font-family:inherit}
button:hover{background:#002268}
.alert{padding:.65rem .9rem;border-radius:4px;margin-bottom:1rem;font-size:.875rem;background:#ffebee;color:#c62828;border-left:4px solid #f44336}
.note{background:#e8f5e9;border-left:4px solid #4caf50;padding:.75rem 1rem;border-radius:4px;font-size:.82rem;color:#1b5e20;margin-bottom:1.25rem}
</style></head><body>
<div class="box">
  <div class="logo">
    <img src="logo01.png" alt="USAFA Parents Club">
    <strong>USAFA Parents Club of Alabama</strong>
    <small>Member Portal</small>
  </div>

  <?php if (!$user): ?>
    <div class="alert">This invite link is invalid. It may have already been used — try logging in, or contact a club officer for a new invite.</div>
    <a href="admin/login.php"><button type="button">Go to Login</button></a>
  <?php elseif ($expired): ?>
    <div class="alert">This invite link has expired. Contact a club officer to request a new one.</div>
  <?php else: ?>
    <div class="note">Welcome, <?= h($user['name']) ?>! Set a password to finish creating your portal account.</div>
    <?php if ($error): ?><div class="alert"><?= h($error) ?></div><?php endif; ?>
    <h2>Create Your Password</h2>
    <form method="POST">
      <?= csrf_field() ?>
      <input type="hidden" name="token" value="<?= h($token) ?>">
      <label>Email</label>
      <input type="text" value="<?= h($user['email']) ?>" disabled>
      <label>Password (min 8 characters)</label>
      <input type="password" name="password" required minlength="8" autocomplete="new-password">
      <label>Confirm Password</label>
      <input type="password" name="password2" required autocomplete="new-password">
      <button type="submit">Create Account</button>
    </form>
  <?php endif; ?>
</div>
</body></html>
