<?php
require_once __DIR__ . '/auth.php';
start_session();

// Only a fixed allowlist of destinations can be requested via ?next= — never
// trust an arbitrary redirect target from the query string or POST body.
$next_allowlist = ['volunteer-signup.php'];
$next = $_POST['next'] ?? $_GET['next'] ?? '';
$next = in_array($next, $next_allowlist, true) ? $next : 'dashboard.php';

if (!empty($_SESSION['logged_in'])) { header('Location: ' . $next); exit; }

$pdo   = get_pdo();
$error = '';
$bootstrap = users_table_empty($pdo);

// ── Bootstrap: create first admin user ────────────────────────────────────
if ($bootstrap && $_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['bootstrap'])) {
    csrf_verify();
    $name  = trim($_POST['name']  ?? '');
    $email = trim($_POST['email'] ?? '');
    $uname = trim($_POST['username'] ?? '');
    $pw    = $_POST['password']  ?? '';
    $pw2   = $_POST['password2'] ?? '';
    if (!$name || !$email || !$uname)      $error = 'All fields are required.';
    elseif (strlen($pw) < 8)               $error = 'Password must be at least 8 characters.';
    elseif ($pw !== $pw2)                  $error = 'Passwords do not match.';
    else {
        $pdo->prepare('INSERT INTO users (name,email,username,password_hash,role,active) VALUES (?,?,?,?,?,1)')
            ->execute([$name, $email, $uname, password_hash($pw, PASSWORD_BCRYPT), 'admin']);
        $bootstrap = false;
    }
}

// ── Normal login ───────────────────────────────────────────────────────────
if (!$bootstrap && $_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST['bootstrap'])) {
    csrf_verify();
    $username_input = trim($_POST['username'] ?? '');
    $status = login_attempt_status($pdo, $username_input);

    if ($status && !empty($status['locked_until']) && strtotime($status['locked_until']) > time()) {
        $wait = (int)ceil((strtotime($status['locked_until']) - time()) / 60);
        $error = "Too many failed attempts. Try again in $wait minute" . ($wait != 1 ? 's' : '') . '.';
    } else {
        $user = verify_login($pdo, $username_input, $_POST['password'] ?? '');
        if ($user) {
            if ((int)$user['failed_attempts'] > 0 || $user['locked_until']) {
                register_login_success($pdo, (int)$user['id']);
            }
            session_regenerate_id(true);
            $_SESSION['logged_in']  = true;
            $_SESSION['role']          = $user['role'];
            $_SESSION['officer_title'] = $user['officer_title'] ?? '';
            $_SESSION['user_id']    = $user['id'];
            $_SESSION['user_name']  = $user['name'];
            $_SESSION['user_email'] = $user['email'];
            header('Location: ' . $next); exit;
        }
        if ($status) register_login_failure($pdo, (int)$status['id'], (int)$status['failed_attempts']);
        $error = 'Invalid username or password.';
    }
}
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $bootstrap ? 'First-Time Setup' : 'Admin Login' ?> — USAFA Parents Club of Alabama</title>
<link rel="icon" type="image/png" href="../logo01.png" />
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
.pw-wrap{position:relative;margin-bottom:1rem}
.pw-wrap input{margin-bottom:0;padding-right:2.75rem}
.pw-toggle{position:absolute;right:.75rem;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:#9aa5b4;font-size:1.1rem;padding:0;width:auto;line-height:1}
input:focus{outline:none;border-color:#003594;box-shadow:0 0 0 2px rgba(0,53,148,.15)}
button{width:100%;padding:.75rem;background:#003594;color:#fff;border:none;border-radius:4px;font-size:.95rem;font-weight:700;cursor:pointer;font-family:inherit}
button:hover{background:#002268}
.alert{padding:.65rem .9rem;border-radius:4px;margin-bottom:1rem;font-size:.875rem;background:#ffebee;color:#c62828;border-left:4px solid #f44336}
.setup-note{background:#e8f5e9;border-left:4px solid #4caf50;padding:.75rem 1rem;border-radius:4px;font-size:.82rem;color:#1b5e20;margin-bottom:1.25rem}
</style></head><body>
<div class="box">
  <div class="logo">
    <img src="../logo01.png" alt="USAFA Parents Club">
    <strong>USAFA Parents Club of Alabama</strong>
    <small>Member Administration</small>
  </div>

  <?php if ($error): ?>
    <div class="alert"><?= h($error) ?></div>
  <?php elseif (!empty($_GET['deactivated'])): ?>
    <div class="alert">Your account is no longer active. Contact a site admin if this is unexpected.</div>
  <?php endif; ?>

  <?php if ($bootstrap): ?>
    <div class="setup-note">No admin accounts exist yet. Create the first admin user below.</div>
    <h2>Create First Admin</h2>
    <form method="POST">
      <?= csrf_field() ?>
      <input type="hidden" name="bootstrap" value="1">
      <label>Full Name</label>
      <input type="text" name="name" required placeholder="e.g. Kari Johnston" autocomplete="name">
      <label>Email</label>
      <input type="email" name="email" required placeholder="president@alabamafalcons.org">
      <label>Username</label>
      <input type="text" name="username" required placeholder="e.g. kjohnston" autocomplete="username">
      <label>Password (min 8 characters)</label>
      <input type="password" name="password" required minlength="8" autocomplete="new-password">
      <label>Confirm Password</label>
      <input type="password" name="password2" required autocomplete="new-password">
      <button type="submit">Create Admin Account</button>
    </form>
  <?php else: ?>
    <form method="POST">
      <?= csrf_field() ?>
      <input type="hidden" name="next" value="<?= h($next) ?>">
      <label>Username or Email</label>
      <input type="text" name="username" required autocomplete="username">
      <label>Password</label>
      <div class="pw-wrap">
        <input type="password" id="pw-login" name="password" required autocomplete="current-password">
        <button type="button" class="pw-toggle" onclick="togglePw('pw-login',this)" title="Show/hide password">👁</button>
      </div>
      <button type="submit">Log In</button>
    </form>
  <?php endif; ?>
</div>
<script>
function togglePw(id, btn) {
  var inp = document.getElementById(id);
  var show = inp.type === 'password';
  inp.type = show ? 'text' : 'password';
  btn.style.opacity = show ? '1' : '0.4';
}
</script>
</body></html>
