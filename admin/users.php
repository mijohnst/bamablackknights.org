<?php
require_once __DIR__ . '/auth.php';
require_admin();
$pdo = get_pdo();

$edit_user = null;
$errors    = [];

// ── Handle POST actions ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'add' || $action === 'edit') {
        $id           = (int)($_POST['id'] ?? 0);
        $name         = trim($_POST['name']     ?? '');
        $email        = trim($_POST['email']    ?? '');
        $uname        = trim($_POST['username'] ?? '');
        $role         = $_POST['role'] ?? 'viewer';
        $officer_title = trim($_POST['officer_title'] ?? '');
        $pw           = $_POST['password']  ?? '';
        $pw2          = $_POST['password2'] ?? '';
        $member_id    = (int)($_POST['member_id'] ?? 0) ?: null;
        $send_invite  = $action === 'add' && !empty($_POST['send_invite']);

        if (!$name)  $errors[] = 'Name is required.';
        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Valid email is required.';
        if (!$uname) $errors[] = 'Username is required.';
        if (!in_array($role, ['admin','tech','officer','secretary','treasurer','member'])) $errors[] = 'Invalid role.';
        // Officer Title distinguishes President from VP within the shared
        // 'officer' role — required so purchase approval can enforce
        // President <-> VP cross-approval (see purchase-action.php).
        if ($role === 'officer') {
            if (!in_array($officer_title, ['President', 'VP'], true)) $errors[] = 'Select whether this Officer is the President or VP.';
        } else {
            $officer_title = '';
        }

        // Check username/email uniqueness
        $dup_check = $pdo->prepare('SELECT id FROM users WHERE (username = ? OR email = ?) AND id != ?');
        $dup_check->execute([$uname, $email, $id ?: 0]);
        if ($dup_check->fetch()) $errors[] = 'That username or email is already in use by another account.';
        if ($action === 'add' && !$send_invite && strlen($pw) < 8) $errors[] = 'Password must be at least 8 characters.';
        if ($pw && $pw !== $pw2)                    $errors[] = 'Passwords do not match.';

        if (empty($errors)) {
            // A send-invite account gets a random throwaway password (never
            // shown to anyone) plus an invite token, same as the bulk portal
            // invite on the Members page — they set their own real password
            // via the emailed link instead of an admin typing one in.
            $invite_token = null;
            if ($send_invite) {
                $pw = bin2hex(random_bytes(32));
                $invite_token = bin2hex(random_bytes(24));
            }
            try {
                if ($action === 'add') {
                    if ($send_invite) {
                        $pdo->prepare('INSERT INTO users (name,email,username,password_hash,role,officer_title,active,member_id,invite_token,invite_expires) VALUES (?,?,?,?,?,?,1,?,?,DATE_ADD(NOW(), INTERVAL 14 DAY))')
                            ->execute([$name, $email, $uname, password_hash($pw, PASSWORD_BCRYPT), $role, $officer_title, $member_id, $invite_token]);
                    } else {
                        $pdo->prepare('INSERT INTO users (name,email,username,password_hash,role,officer_title,active,member_id) VALUES (?,?,?,?,?,?,1,?)')
                            ->execute([$name, $email, $uname, password_hash($pw, PASSWORD_BCRYPT), $role, $officer_title, $member_id]);
                    }
                } elseif ($pw) {
                    $pdo->prepare('UPDATE users SET name=?,email=?,username=?,password_hash=?,role=?,officer_title=?,member_id=? WHERE id=?')
                        ->execute([$name, $email, $uname, password_hash($pw, PASSWORD_BCRYPT), $role, $officer_title, $member_id, $id]);
                } else {
                    $pdo->prepare('UPDATE users SET name=?,email=?,username=?,role=?,officer_title=?,member_id=? WHERE id=?')
                        ->execute([$name, $email, $uname, $role, $officer_title, $member_id, $id]);
                }
            } catch (PDOException $e) {
                // users.member_id migration not run yet on this install — save
                // everything except the family link, which needs that column.
                if ($action === 'add') {
                    if ($send_invite) {
                        $pdo->prepare('INSERT INTO users (name,email,username,password_hash,role,active,invite_token,invite_expires) VALUES (?,?,?,?,?,1,?,DATE_ADD(NOW(), INTERVAL 14 DAY))')
                            ->execute([$name, $email, $uname, password_hash($pw, PASSWORD_BCRYPT), $role, $invite_token]);
                    } else {
                        $pdo->prepare('INSERT INTO users (name,email,username,password_hash,role,active) VALUES (?,?,?,?,?,1)')
                            ->execute([$name, $email, $uname, password_hash($pw, PASSWORD_BCRYPT), $role]);
                    }
                } elseif ($pw) {
                    $pdo->prepare('UPDATE users SET name=?,email=?,username=?,password_hash=?,role=? WHERE id=?')
                        ->execute([$name, $email, $uname, password_hash($pw, PASSWORD_BCRYPT), $role, $id]);
                } else {
                    $pdo->prepare('UPDATE users SET name=?,email=?,username=?,role=? WHERE id=?')
                        ->execute([$name, $email, $uname, $role, $id]);
                }
                flash('error', 'Saved, but the family link could not be set — run admin/migrate_user_member_link.sql first.');
                header('Location: users.php'); exit;
            }

            if ($send_invite) {
                require_once __DIR__ . '/mailer.php';
                send_portal_invite($email, $name, $invite_token);
                flash('success', "User '$name' added — invite email sent.");
            } else {
                flash('success', "User '$name' " . ($action === 'add' ? 'added.' : 'updated.'));
            }
            header('Location: users.php'); exit;
        }

    } elseif ($action === 'toggle_active') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id === (int)($_SESSION['user_id'] ?? 0)) {
            flash('error', 'You cannot deactivate your own account.');
        } else {
            $row = $pdo->prepare('SELECT active,name FROM users WHERE id=?');
            $row->execute([$id]);
            $u = $row->fetch();
            if ($u) {
                $pdo->prepare('UPDATE users SET active=? WHERE id=?')->execute([$u['active'] ? 0 : 1, $id]);
                flash('success', $u['name'] . ' ' . ($u['active'] ? 'deactivated.' : 'reactivated.'));
            }
        }
        header('Location: users.php'); exit;

    } elseif ($action === 'resend_invite') {
        require_once __DIR__ . '/mailer.php';
        $id = (int)($_POST['id'] ?? 0);
        $row = $pdo->prepare('SELECT name,email FROM users WHERE id=? AND invite_token IS NOT NULL');
        $row->execute([$id]);
        $u = $row->fetch();
        if ($u) {
            $token = bin2hex(random_bytes(24));
            $pdo->prepare('UPDATE users SET invite_token=?, invite_expires=DATE_ADD(NOW(), INTERVAL 14 DAY) WHERE id=?')->execute([$token, $id]);
            send_portal_invite($u['email'], $u['name'], $token);
            flash('success', 'Invite resent to ' . $u['name'] . '.');
        }
        header('Location: users.php'); exit;

    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id === (int)($_SESSION['user_id'] ?? 0)) {
            flash('error', 'You cannot delete your own account.');
        } else {
            $row = $pdo->prepare('SELECT name FROM users WHERE id=?');
            $row->execute([$id]);
            $u = $row->fetch();
            if ($u) {
                // Best-effort cleanup of self-service records tied to this login
                // (no FK constraints on these tables — orphans would otherwise
                // silently disappear from officer review queues/rosters while
                // still counting against volunteer opportunity slot limits).
                try {
                    $submissions_dir = __DIR__ . '/../photo-submissions/';
                    $pending = $pdo->prepare("SELECT filename FROM photo_submissions WHERE user_id=? AND status='pending'");
                    $pending->execute([$id]);
                    foreach ($pending->fetchAll(PDO::FETCH_COLUMN) as $filename) {
                        $f = $submissions_dir . basename($filename);
                        if (is_file($f)) unlink($f);
                    }
                    $pdo->prepare('DELETE FROM photo_submissions WHERE user_id=?')->execute([$id]);
                    $pdo->prepare('DELETE FROM volunteer_signups WHERE user_id=?')->execute([$id]);
                    $pdo->prepare('DELETE FROM event_rsvps WHERE user_id=?')->execute([$id]);
                    $pdo->prepare('DELETE FROM committee_interest WHERE user_id=?')->execute([$id]);
                } catch (PDOException $e) {
                    // Member-support tables not migrated on this install — nothing to clean up.
                }
                $pdo->prepare('DELETE FROM users WHERE id=?')->execute([$id]);
                flash('success', $u['name'] . ' deleted.');
            }
        }
        header('Location: users.php'); exit;
    }
}

// ── Load user for editing ──────────────────────────────────────────────────
if (isset($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id=?');
    $stmt->execute([(int)$_GET['edit']]);
    $edit_user = $stmt->fetch();
}

$users = $pdo->query('SELECT * FROM users ORDER BY role, name')->fetchAll();

$linkable_members = $pdo->query(
    "SELECT id, cadet_last_name, cadet_suffix, cadet_first_name, cadet_middle_name, class_year FROM members WHERE archived = 0 ORDER BY cadet_last_name, cadet_first_name"
)->fetchAll(PDO::FETCH_ASSOC);
$member_names = [];
foreach ($linkable_members as $lm) {
    $member_names[$lm['id']] = trim(cadet_last_name_suffixed($lm) . ', ' . trim($lm['cadet_first_name'] . ' ' . $lm['cadet_middle_name'])) . ' (' . $lm['class_year'] . ')';
}

$role_labels = ['admin'=>'Admin','tech'=>'Tech Support','officer'=>'Officer','secretary'=>'Secretary','treasurer'=>'Treasurer','member'=>'Member'];
$role_colors = ['admin'=>'#002554','tech'=>'#bf360c','officer'=>'#1a237e','secretary'=>'#5c007a','treasurer'=>'#1b5e20','member'=>'#7b3f00'];

admin_header('Users');
echo show_flash();
?>
<style>
.user-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:1rem;margin-bottom:1.5rem}
.user-card{background:#fff;border-radius:6px;box-shadow:0 1px 4px rgba(0,0,0,.1);padding:1.1rem 1.25rem}
.user-card.inactive{opacity:.55}
.user-role{display:inline-block;font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;padding:.15rem .5rem;border-radius:3px;background:#f0f2f5;margin-bottom:.5rem}
.user-top{display:flex;gap:.75rem;align-items:flex-start}
.user-avatar{width:44px;height:44px;border-radius:50%;object-fit:cover;background:#003594;color:#fff;display:flex;align-items:center;justify-content:center;font-size:1.05rem;font-weight:700;flex-shrink:0}
.user-name{font-size:1rem;font-weight:700;color:#002554;margin-bottom:.15rem}
.user-meta{font-size:.78rem;color:#5a6a7a;margin-bottom:.75rem}
.user-actions{display:flex;gap:.4rem;flex-wrap:wrap}
.form-card{background:#fff;border-radius:6px;box-shadow:0 1px 4px rgba(0,0,0,.1);padding:1.5rem;max-width:520px;margin-bottom:1.5rem}
.form-card h2{font-size:1rem;color:#002554;margin-bottom:1.25rem}
@media(max-width:500px){.user-grid{grid-template-columns:1fr}}
</style>

<div class="page-head">
  <h1>Users</h1>
  <?php if (!$edit_user): ?>
  <a href="users.php?edit=new" class="btn btn-primary">+ Add User</a>
  <?php endif; ?>
</div>

<?php if (!empty($errors)): ?>
  <div class="alert alert-error"><?= implode('<br>', array_map('htmlspecialchars', $errors)) ?></div>
<?php endif; ?>

<?php if ($edit_user || isset($_GET['edit'])): ?>
<!-- Add / Edit form -->
<div class="form-card">
  <h2><?= $edit_user ? 'Edit User — ' . h($edit_user['name']) : 'Add New User' ?></h2>
  <form method="POST">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="<?= $edit_user ? 'edit' : 'add' ?>">
    <?php if ($edit_user): ?><input type="hidden" name="id" value="<?= (int)$edit_user['id'] ?>"><?php endif; ?>

    <div class="form-row col-2">
      <div class="form-group">
        <label>Full Name *</label>
        <input name="name" value="<?= h($edit_user['name'] ?? '') ?>" required autocomplete="off">
      </div>
      <div class="form-group">
        <label>Username *</label>
        <input name="username" value="<?= h($edit_user['username'] ?? '') ?>" required autocomplete="off">
      </div>
    </div>
    <div class="form-group">
      <label>Email *</label>
      <input type="email" name="email" value="<?= h($edit_user['email'] ?? '') ?>" required autocomplete="off">
    </div>
    <div class="form-group">
      <label>Role *</label>
      <select name="role" id="role_select" onchange="document.getElementById('officer_title_group').style.display = this.value === 'officer' ? '' : 'none';">
        <?php foreach ($role_labels as $r => $label): ?>
          <option value="<?= $r ?>" <?= ($edit_user['role'] ?? 'viewer')===$r?'selected':''?>><?= $label ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group" id="officer_title_group" style="display:<?= ($edit_user['role'] ?? '')==='officer'?'':'none' ?>">
      <label>Officer Title * <span style="font-weight:400;text-transform:none;letter-spacing:0;font-size:.72rem;color:#9aa5b4">President and VP must approve each other's purchases</span></label>
      <select name="officer_title">
        <option value="">— Select —</option>
        <option value="President" <?= ($edit_user['officer_title'] ?? '')==='President'?'selected':''?>>President</option>
        <option value="VP" <?= ($edit_user['officer_title'] ?? '')==='VP'?'selected':''?>>VP</option>
      </select>
    </div>
    <div class="form-group">
      <label>Linked Cadet Family <span style="font-weight:400;text-transform:none;letter-spacing:0;font-size:.72rem;color:#9aa5b4">optional — powers the My Membership dashboard tile</span></label>
      <select name="member_id">
        <option value="">— Not linked —</option>
        <?php foreach ($member_names as $mid => $mlabel): ?>
          <option value="<?= $mid ?>" <?= (int)($edit_user['member_id'] ?? 0) === $mid ? 'selected' : '' ?>><?= h($mlabel) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php if (!$edit_user): ?>
    <div class="form-group" style="display:flex;align-items:center;gap:.5rem">
      <input type="checkbox" name="send_invite" id="send_invite" value="1" style="width:auto"
             onchange="document.getElementById('pw_section').style.display = this.checked ? 'none' : '';
                       document.getElementById('pw_field').required = !this.checked;">
      <label for="send_invite" style="font-weight:400;text-transform:none;letter-spacing:0;cursor:pointer;margin:0;font-size:.9rem">Email an invite to let them set their own password, instead of setting one now</label>
    </div>
    <?php endif; ?>
    <div class="form-row col-2" id="pw_section">
      <div class="form-group">
        <label><?= $edit_user ? 'New Password' : 'Password *' ?> <span style="font-weight:400;text-transform:none;letter-spacing:0;font-size:.72rem">(min 8 chars)</span></label>
        <input type="password" name="password" id="pw_field" <?= $edit_user?'':'required' ?> minlength="8" autocomplete="new-password">
      </div>
      <div class="form-group">
        <label>Confirm Password</label>
        <input type="password" name="password2" autocomplete="new-password">
      </div>
    </div>
    <?php if ($edit_user): ?><p style="font-size:.78rem;color:#9aa5b4;margin-top:-.5rem;margin-bottom:1rem">Leave password blank to keep current password.</p><?php endif; ?>
    <div style="display:flex;gap:.75rem;flex-wrap:wrap">
      <button type="submit" class="btn btn-primary"><?= $edit_user ? 'Save Changes' : 'Add User' ?></button>
      <a href="users.php" class="btn btn-secondary">Cancel</a>
    </div>
  </form>
</div>
<?php endif; ?>

<!-- User cards -->
<div class="user-grid">
<?php foreach ($users as $u): ?>
  <div class="user-card <?= $u['active'] ? '' : 'inactive' ?>">
    <div class="user-top">
      <?php if (!empty($u['avatar_filename'])): ?>
        <img class="user-avatar" src="/avatar-serve.php?id=<?= (int)$u['id'] ?>" alt="">
      <?php else: ?>
        <div class="user-avatar"><?= h(mb_strtoupper(mb_substr($u['name'], 0, 1))) ?></div>
      <?php endif; ?>
      <div style="min-width:0">
        <div class="user-role" style="color:<?= $role_colors[$u['role']] ?>"><?= $role_labels[$u['role']] ?><?= !empty($u['officer_title']) ? ' — ' . h($u['officer_title']) : '' ?></div>
        <div class="user-name"><?= h($u['name']) ?> <?= !$u['active'] ? '<span style="font-size:.7rem;color:#c62828">(Inactive)</span>' : '' ?></div>
        <div class="user-meta">
          @<?= h($u['username']) ?><br>
          <?= h($u['email']) ?>
          <?php if (!empty($u['member_id']) && isset($member_names[$u['member_id']])): ?>
            <br><span style="color:#9aa5b4">Linked: <?= h($member_names[$u['member_id']]) ?></span>
          <?php endif; ?>
          <?php if (!empty($u['invite_token'])): ?>
            <br><span style="display:inline-block;margin-top:.3rem;font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;padding:.15rem .5rem;border-radius:99px;background:<?= strtotime($u['invite_expires']) < time() ? '#ffebee' : '#fff3cd' ?>;color:<?= strtotime($u['invite_expires']) < time() ? '#c62828' : '#5f4c00' ?>"><?= strtotime($u['invite_expires']) < time() ? 'Invite Expired' : 'Invite Pending' ?></span>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <div class="user-actions">
      <a href="users.php?edit=<?= $u['id'] ?>" class="btn btn-secondary btn-sm">Edit</a>
      <?php if (is_super_admin() && !in_array($u['role'], ['admin', 'tech'], true) && $u['active'] && $u['id'] !== (int)($_SESSION['user_id'] ?? 0)): ?>
      <form method="POST" action="impersonate.php" style="margin:0">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="start">
        <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
        <button type="submit" class="btn btn-secondary btn-sm" title="View the portal as this user">🔀 Login As</button>
      </form>
      <?php endif; ?>
      <?php if (!empty($u['invite_token'])): ?>
      <form method="POST" style="margin:0">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="resend_invite">
        <input type="hidden" name="id" value="<?= $u['id'] ?>">
        <button type="submit" class="btn btn-secondary btn-sm">Resend Invite</button>
      </form>
      <?php endif; ?>
      <?php if ($u['id'] !== (int)($_SESSION['user_id'] ?? 0)): ?>
      <form method="POST" style="margin:0">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="toggle_active">
        <input type="hidden" name="id" value="<?= $u['id'] ?>">
        <button type="submit" class="btn btn-secondary btn-sm"><?= $u['active'] ? 'Deactivate' : 'Reactivate' ?></button>
      </form>
      <form method="POST" style="margin:0" onsubmit="return confirm('Delete <?= h(addslashes($u['name'])) ?>? This cannot be undone.')">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="id" value="<?= $u['id'] ?>">
        <button type="submit" class="btn btn-danger btn-sm">Delete</button>
      </form>
      <?php else: ?>
      <span style="font-size:.75rem;color:#9aa5b4;padding:.28rem 0">(your account)</span>
      <?php endif; ?>
    </div>
  </div>
<?php endforeach; ?>
</div>

<?php admin_footer(); ?>
