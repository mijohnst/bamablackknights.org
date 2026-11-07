<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/form-guard.php';
require_once __DIR__ . '/email-code.php';
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

// ── Step 2: emailed login code ────────────────────────────────────────────
// Every code-screen action is a plain form POST that redirects back here
// (post/redirect/get), so each render gets a fresh CSRF token and a page
// refresh never resubmits a stale one. Messages ride in login_flash.
function login_flash(string $type, string $msg): void {
    $_SESSION['login_flash'] = ['type' => $type, 'msg' => $msg];
}

// The pending account, re-read so a deactivation or password change made
// after the password step still stops the login.
function pending_login_user(PDO $pdo, array $pending): ?array {
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? AND active = 1');
    $stmt->execute([(int)$pending['user_id']]);
    $u = $stmt->fetch();
    if (!$u || !hash_equals((string)$pending['pw_fingerprint'], hash('sha256', (string)$u['password_hash']))) return null;
    return $u;
}

$pending = $_SESSION['pending_login'] ?? null;
if ($pending && (int)$pending['started'] + LOGIN_PENDING_TTL < time()) {
    unset($_SESSION['pending_login']);
    $pending = null;
    login_flash('error', 'Your login timed out. Please enter your password again.');
}

$code_action = $_POST['code_action'] ?? '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $code_action !== '') {
    csrf_verify();
    $user = $pending ? pending_login_user($pdo, $pending) : null;
    if ($pending && !$user) {
        unset($_SESSION['pending_login']);
        login_flash('error', 'Please log in again.');
    } elseif ($pending && $code_action === 'cancel') {
        unset($_SESSION['pending_login']);
    } elseif ($pending && $code_action === 'resend') {
        $wait = (int)($pending['sent_at'] ?? 0) + LOGIN_CODE_RESEND_WAIT - time();
        if ($wait > 0) {
            login_flash('error', "Please wait $wait second" . ($wait != 1 ? 's' : '') . ' before requesting another code.');
        } else {
            $send_error = login_code_send($pdo, $user);
            login_flash($send_error === '' ? 'success' : 'error', $send_error === '' ? 'A new code is on its way.' : $send_error);
        }
    } elseif ($pending && $code_action === 'verify') {
        // Per-code attempts are capped below; this caps guessing across
        // many codes from one network.
        if (rate_limited($pdo, 'admin_login_code', 15, 15, true)) {
            login_flash('error', 'Too many attempts from your network. Please try again in a few minutes.');
        } elseif (empty($pending['hash']) || (int)$pending['expires'] < time()) {
            login_flash('error', 'That code has expired. Use "Send a new code" below.');
        } else {
            $_SESSION['pending_login']['attempts'] = ++$pending['attempts'];
            $code = email_code_normalize((string)($_POST['code'] ?? ''));
            if (hash_equals((string)$pending['hash'], email_code_hash((string)$pending['salt'], $code))) {
                if (!empty($_POST['trust'])) login_trust_device($user);
                $dest = $pending['next'];
                complete_login($user);
                header('Location: ' . $dest); exit;
            }
            if ($pending['attempts'] >= LOGIN_CODE_MAX_ATTEMPTS) {
                unset($_SESSION['pending_login']['hash']);
                login_flash('error', 'Too many incorrect codes. Use "Send a new code" below.');
            } else {
                login_flash('error', 'That code is incorrect. Please check the email and try again.');
            }
        }
    }
    header('Location: login.php'); exit;
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
            if (!login_2fa_enabled() || login_device_trusted($user)) {
                complete_login($user);
                header('Location: ' . $next); exit;
            }
            if (!filter_var(trim((string)$user['email']), FILTER_VALIDATE_EMAIL)) {
                // No usable address: a code screen would be a dead end.
                $error = "Your account doesn't have a valid email address on file, so we can't send you a login code. "
                       . 'Please contact ' . LOGIN_SUPPORT_EMAIL . '.';
            } else {
                // Password is right — hold the login until the emailed code
                // is entered. Nothing in this state counts as logged in
                // (require_login() only checks logged_in).
                session_regenerate_id(true);
                $_SESSION['pending_login'] = [
                    'user_id'        => (int)$user['id'],
                    'pw_fingerprint' => hash('sha256', (string)$user['password_hash']),
                    'next'           => $next,
                    'started'        => time(),
                    'email_masked'   => mask_email(trim((string)$user['email'])),
                ];
                $send_error = login_code_send($pdo, $user);
                if ($send_error !== '') login_flash('error', $send_error);
                header('Location: login.php'); exit;
            }
        } else {
            if ($status) register_login_failure($pdo, (int)$status['id'], (int)$status['failed_attempts']);
            $error = 'Invalid username or password.';
        }
    }
}
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $bootstrap ? 'First-Time Setup' : 'Admin Login' ?> — West Point Parents Club of Alabama</title>
<link rel="icon" type="image/png" href="../logo01.png" />
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:"Segoe UI",Arial,sans-serif;background:#000000;display:flex;justify-content:center;align-items:center;min-height:100vh;padding:1rem}
.box{background:#fff;border-radius:8px;box-shadow:0 4px 24px rgba(0,0,0,.3);padding:2rem;width:100%;max-width:400px}
.logo{text-align:center;margin-bottom:1.5rem}
.logo img{height:48px;border-radius:4px;margin-bottom:.75rem;display:block;margin-left:auto;margin-right:auto}
.logo strong{display:block;font-size:.95rem;color:#000000;letter-spacing:.02em}
.logo small{color:#5a6a7a;font-size:.8rem}
h2{font-size:1rem;color:#000000;margin-bottom:1.25rem;text-align:center}
label{display:block;font-size:.78rem;font-weight:700;color:#5a6a7a;text-transform:uppercase;letter-spacing:.04em;margin-bottom:.3rem}
input{width:100%;padding:.65rem .75rem;border:1px solid #d0d5dd;border-radius:4px;font-size:.95rem;margin-bottom:1rem;font-family:inherit}
.pw-wrap{position:relative;margin-bottom:1rem}
.pw-wrap input{margin-bottom:0;padding-right:2.75rem}
.pw-toggle{position:absolute;right:.75rem;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:#9aa5b4;font-size:1.1rem;padding:0;width:auto;line-height:1}
input:focus{outline:none;border-color:#000000;box-shadow:0 0 0 2px rgba(0,0,0,.15)}
button{width:100%;padding:.75rem;background:#000000;color:#fff;border:none;border-radius:4px;font-size:.95rem;font-weight:700;cursor:pointer;font-family:inherit}
button:hover{background:#002268}
.alert{padding:.65rem .9rem;border-radius:4px;margin-bottom:1rem;font-size:.875rem;background:#ffebee;color:#c62828;border-left:4px solid #f44336}
.alert.ok{background:#e8f5e9;color:#1b5e20;border-left-color:#4caf50}
.code-note{font-size:.88rem;color:#3a4a5a;margin-bottom:1rem;line-height:1.45;text-align:center}
.code-input{font-size:1.3rem;letter-spacing:.3em;text-align:center;text-transform:uppercase;font-family:Consolas,monospace}
label.trust{display:flex;align-items:center;gap:.5rem;text-transform:none;letter-spacing:0;font-weight:400;font-size:.88rem;color:#3a4a5a;margin-bottom:1rem;cursor:pointer}
label.trust input{width:auto;margin:0}
.code-links{display:flex;justify-content:space-between;margin-top:1rem}
.code-links form{margin:0}
button.link{background:none;color:#000000;padding:0;width:auto;font-weight:600;font-size:.85rem;text-decoration:underline}
button.link:hover{background:none;color:#5a6a7a}
.code-help{font-size:.78rem;color:#5a6a7a;margin-top:1rem;text-align:center;line-height:1.4}
.code-help a{color:#000000}
.setup-note{background:#e8f5e9;border-left:4px solid #4caf50;padding:.75rem 1rem;border-radius:4px;font-size:.82rem;color:#1b5e20;margin-bottom:1.25rem}
</style></head><body>
<div class="box">
  <div class="logo">
    <img src="../logo01.png" alt="West Point Parents Club of Alabama">
    <strong>West Point Parents Club of Alabama</strong>
    <small>Member Administration</small>
  </div>

  <?php $login_flash = $_SESSION['login_flash'] ?? null; unset($_SESSION['login_flash']); ?>
  <?php if ($error): ?>
    <div class="alert"><?= h($error) ?></div>
  <?php elseif ($login_flash): ?>
    <div class="alert<?= $login_flash['type'] === 'success' ? ' ok' : '' ?>"><?= h($login_flash['msg']) ?></div>
  <?php elseif (!empty($_GET['deactivated'])): ?>
    <div class="alert">Your account is no longer active. Contact a site admin if this is unexpected.</div>
  <?php endif; ?>

  <?php if ($pending): ?>
    <h2>Check Your Email</h2>
    <p class="code-note">
      <?php if (!empty($pending['hash'])): ?>
        We sent a <?= LOGIN_CODE_LENGTH ?>-character login code to <strong><?= h($pending['email_masked']) ?></strong>. It expires in 10 minutes.
      <?php else: ?>
        Your login code goes to <strong><?= h($pending['email_masked']) ?></strong>. Use "Send a new code" below to get one.
      <?php endif; ?>
    </p>
    <form method="POST">
      <?= csrf_field() ?>
      <input type="hidden" name="code_action" value="verify">
      <label for="login-code">Login Code</label>
      <input type="text" id="login-code" name="code" required autofocus autocomplete="one-time-code"
             autocapitalize="characters" spellcheck="false" maxlength="12" class="code-input">
      <label class="trust"><input type="checkbox" name="trust" value="1"> Trust this device for <?= LOGIN_TRUST_DAYS ?> days</label>
      <button type="submit">Verify &amp; Log In</button>
    </form>
    <div class="code-links">
      <form method="POST"><?= csrf_field() ?><input type="hidden" name="code_action" value="resend"><button type="submit" class="link">Send a new code</button></form>
      <form method="POST"><?= csrf_field() ?><input type="hidden" name="code_action" value="cancel"><button type="submit" class="link">Start over</button></form>
    </div>
    <p class="code-help">Wrong address, or no email arriving? Check your spam folder, then contact
      <a href="mailto:<?= LOGIN_SUPPORT_EMAIL ?>"><?= LOGIN_SUPPORT_EMAIL ?></a>.</p>
  <?php elseif ($bootstrap): ?>
    <div class="setup-note">No admin accounts exist yet. Create the first admin user below.</div>
    <h2>Create First Admin</h2>
    <form method="POST">
      <?= csrf_field() ?>
      <input type="hidden" name="bootstrap" value="1">
      <label>Full Name</label>
      <input type="text" name="name" required placeholder="e.g. Kari Johnston" autocomplete="name">
      <label>Email</label>
      <input type="email" name="email" required placeholder="president@bamablackknights.org">
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
