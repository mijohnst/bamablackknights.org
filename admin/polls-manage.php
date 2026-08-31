<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/mailer.php';
require_login();

// Any of the 4 board roles (President/VP via 'officer', Secretary,
// Treasurer — see is_board_role()) can create a poll. Admin kept as an
// override, matching every other manage-page in this codebase.
if (!is_board_role() && !is_admin()) { header('Location: dashboard.php?denied=1'); exit; }
$pdo = get_pdo();

$db_ready = true;
try { $pdo->query('SELECT 1 FROM polls LIMIT 1'); } catch (PDOException $e) { $db_ready = false; }

if ($db_ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    // impersonate.php only blocks impersonating another Admin/Tech account,
    // not Officer/Secretary/Treasurer — by design, since Login As is meant
    // to let an admin test what every position sees. But creating/closing/
    // deleting a poll while impersonating one of those would misattribute
    // (created_by) a real, visible action to whoever's being impersonated
    // instead of the admin actually clicking the button — same reasoning as
    // the voting guard in polls.php/vote.php, just for writes here instead
    // of votes.
    if (is_impersonating()) {
        flash('error', 'Managing polls is disabled while using Login As.');
        header('Location: polls-manage.php'); exit;
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $title       = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $poll_type   = ($_POST['poll_type'] ?? 'yesno') === 'multiple' ? 'multiple' : 'yesno';
        $audience    = ($_POST['audience'] ?? 'all_paid') === 'board' ? 'board' : 'all_paid';
        $anonymous   = !empty($_POST['anonymous']) ? 1 : 0;
        $expires_at  = str_replace('T', ' ', trim($_POST['expires_at'] ?? ''));
        $options_raw = trim($_POST['options'] ?? '');

        $errors = [];
        if (!$title) $errors[] = 'Title is required.';
        if (!$expires_at) $errors[] = 'Expiration is required.';
        $custom_options = array_values(array_filter(array_map('trim', explode("\n", $options_raw))));
        if ($poll_type === 'multiple' && count($custom_options) < 2) {
            $errors[] = 'Multiple choice polls need at least 2 options (one per line).';
        }

        if ($errors) {
            flash('error', implode(' ', $errors));
            header('Location: polls-manage.php?new=1'); exit;
        }

        try {
            $pdo->prepare('INSERT INTO polls (title,description,poll_type,audience,anonymous,status,expires_at,created_by) VALUES (?,?,?,?,?,\'open\',?,?)')
                ->execute([$title, $description, $poll_type, $audience, $anonymous, $expires_at, $_SESSION['user_id'] ?? null]);
        } catch (PDOException $e) {
            // audience/anonymous columns not migrated yet on this install
            try {
                $pdo->prepare('INSERT INTO polls (title,description,poll_type,audience,status,expires_at,created_by) VALUES (?,?,?,?,\'open\',?,?)')
                    ->execute([$title, $description, $poll_type, $audience, $expires_at, $_SESSION['user_id'] ?? null]);
            } catch (PDOException $e2) {
                $pdo->prepare('INSERT INTO polls (title,description,poll_type,status,expires_at,created_by) VALUES (?,?,?,\'open\',?,?)')
                    ->execute([$title, $description, $poll_type, $expires_at, $_SESSION['user_id'] ?? null]);
                $audience = 'all_paid';
            }
            $anonymous = 0;
        }
        $poll_id = (int)$pdo->lastInsertId();

        $options = $poll_type === 'multiple' ? $custom_options : ['Yes', 'No'];
        $ins = $pdo->prepare('INSERT INTO poll_options (poll_id, option_text, sort_order) VALUES (?,?,?)');
        foreach ($options as $i => $opt) $ins->execute([$poll_id, $opt, $i]);

        $poll = ['title' => $title, 'description' => $description, 'expires_at' => $expires_at];
        $sent = send_poll_notifications($pdo, $poll, $audience);

        flash('success', "Poll created and $sent notification email(s) sent.");
        header('Location: polls-manage.php'); exit;

    } elseif ($action === 'close') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare("UPDATE polls SET status='closed' WHERE id=?")->execute([$id]);
        flash('success', 'Poll closed.');
        header('Location: polls-manage.php'); exit;

    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        try { $pdo->prepare('DELETE FROM poll_voters WHERE poll_id=?')->execute([$id]); } catch (PDOException $e) {}
        $pdo->prepare('DELETE FROM poll_votes WHERE poll_id=?')->execute([$id]);
        $pdo->prepare('DELETE FROM poll_options WHERE poll_id=?')->execute([$id]);
        $pdo->prepare('DELETE FROM polls WHERE id=?')->execute([$id]);
        flash('success', 'Poll deleted.');
        header('Location: polls-manage.php'); exit;
    }
}

$polls = [];
if ($db_ready) {
    $polls = $pdo->query(
        "SELECT * FROM polls ORDER BY (status='open') DESC, expires_at DESC"
    )->fetchAll(PDO::FETCH_ASSOC);
}

// Options + tallies for every poll on this page
$options_by_poll = []; $tallies = [];
if (!empty($polls)) {
    $ids = array_column($polls, 'id');
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    $orows = $pdo->prepare("SELECT * FROM poll_options WHERE poll_id IN ($ph) ORDER BY sort_order ASC");
    $orows->execute($ids);
    foreach ($orows->fetchAll(PDO::FETCH_ASSOC) as $o) $options_by_poll[$o['poll_id']][] = $o;

    $trows = $pdo->prepare("SELECT option_id, COUNT(*) AS cnt FROM poll_votes WHERE poll_id IN ($ph) GROUP BY option_id");
    $trows->execute($ids);
    foreach ($trows->fetchAll(PDO::FETCH_ASSOC) as $t) $tallies[$t['option_id']] = (int)$t['cnt'];
}

// Eligible-voter counts for the "X of Y voted" gauge — depends on each
// poll's audience (all paid members, or just the 4 board roles).
$eligible_counts = [
    'all_paid' => (int)$pdo->query("SELECT COUNT(*) FROM members WHERE archived=0 AND membership_paid=1")->fetchColumn(),
    'board'    => (int)$pdo->query("SELECT COUNT(*) FROM users WHERE active=1 AND role IN ('officer','secretary','treasurer')")->fetchColumn(),
];

admin_header('Manage Polls');
echo show_flash();
?>
<style>
.poll-card{border-left:3px solid #003594;padding:.85rem 1rem;margin-bottom:.75rem;background:#fff;border-radius:0 4px 4px 0}
.poll-card.closed{border-left-color:#9aa5b4;opacity:.75}
.poll-meta{font-size:.78rem;color:#5a6a7a;margin-top:.15rem}
.poll-bar-row{display:flex;align-items:center;gap:.6rem;margin-top:.35rem;font-size:.82rem}
.poll-bar-track{flex:1;height:10px;background:#f0f2f5;border-radius:99px;overflow:hidden}
.poll-bar-fill{height:100%;background:#003594;border-radius:99px}
</style>

<div class="page-head">
  <h1>Manage Polls</h1>
  <div style="display:flex;gap:.5rem">
    <?php if ($db_ready && !isset($_GET['new'])): ?><a href="polls-manage.php?new=1" class="btn btn-primary">+ New Poll</a><?php endif; ?>
    <a href="dashboard.php" class="btn btn-secondary">← Dashboard</a>
  </div>
</div>

<?php if (!$db_ready): ?>
  <div class="alert alert-error">Polls aren't set up yet — run admin/migrate_polls.sql first.</div>
<?php endif; ?>

<?php if ($db_ready && isset($_GET['new'])): ?>
<div class="card" style="max-width:640px;margin-bottom:1.5rem">
  <h2 style="margin-bottom:1.25rem">New Poll</h2>
  <form method="POST" id="poll-form">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="create">
    <div class="form-group">
      <label>Title *</label>
      <input name="title" required placeholder="e.g. Should we move the Sendoff to Falcon Stadium?">
    </div>
    <div class="form-group">
      <label>Description <span style="font-weight:400;text-transform:none;letter-spacing:0;font-size:.72rem;color:#9aa5b4">optional — context for members</span></label>
      <textarea name="description" rows="3"></textarea>
    </div>
    <div class="form-group">
      <label>Who Can Vote?</label>
      <select name="audience">
        <option value="all_paid">All Paid Members</option>
        <option value="board">Board Members Only (President, VP, Secretary, Treasurer)</option>
      </select>
    </div>
    <div class="form-group" style="display:flex;align-items:center;gap:.5rem">
      <input type="checkbox" name="anonymous" id="poll_anonymous" value="1" style="width:auto">
      <label for="poll_anonymous" style="font-weight:400;text-transform:none;letter-spacing:0;cursor:pointer;margin:0;font-size:.9rem">Anonymous — don't record who voted for what, only the counts</label>
    </div>
    <div class="form-row col-2">
      <div class="form-group">
        <label>Type</label>
        <select name="poll_type" id="poll_type" onchange="document.getElementById('options_group').style.display = this.value === 'multiple' ? 'block' : 'none';">
          <option value="yesno">Yes / No</option>
          <option value="multiple">Multiple Choice</option>
        </select>
      </div>
      <div class="form-group">
        <label>Voting Closes *</label>
        <input type="datetime-local" name="expires_at" required>
      </div>
    </div>
    <div class="form-group" id="options_group" style="display:none">
      <label>Options <span style="font-weight:400;text-transform:none;letter-spacing:0;font-size:.72rem;color:#9aa5b4">one per line, at least 2</span></label>
      <textarea name="options" rows="4" placeholder="Falcon Stadium&#10;Clark Field&#10;Keep current location"></textarea>
    </div>
    <p style="font-size:.78rem;color:#5a6a7a;margin-bottom:1rem">Every portal user gets an email as soon as this is created — there's no separate "publish" step.</p>
    <div style="display:flex;gap:.75rem">
      <button type="submit" class="btn btn-primary">Create &amp; Notify Members</button>
      <a href="polls-manage.php" class="btn btn-secondary">Cancel</a>
    </div>
  </form>
</div>
<?php endif; ?>

<?php if ($db_ready): ?>
<?php if (empty($polls)): ?>
  <p style="color:#9aa5b4">No polls yet.</p>
<?php else: ?>
  <?php foreach ($polls as $p):
    $opts = $options_by_poll[$p['id']] ?? [];
    $total_votes = array_sum(array_map(fn($o) => $tallies[$o['id']] ?? 0, $opts));
    // Some existing databases created this column as uppercase `STATUS`.
    // PDO preserves column-name casing in associative result keys, so accept
    // either spelling until every installation has normalized its schema.
    $poll_status = strtolower(trim((string)($p['status'] ?? $p['STATUS'] ?? '')));
    $is_open = $poll_status === 'open' && strtotime($p['expires_at']) > time();
    $audience = $p['audience'] ?? 'all_paid';
    $eligible_count = $eligible_counts[$audience];
  ?>
  <div class="poll-card <?= $is_open ? '' : 'closed' ?>">
    <div style="display:flex;justify-content:space-between;gap:.75rem;flex-wrap:wrap">
      <div style="flex:1;min-width:0">
        <strong style="color:#002554"><?= h($p['title']) ?></strong>
        <?php if (!$is_open): ?><span style="color:#9aa5b4;font-size:.75rem"> · Closed</span><?php endif; ?>
        <?php if ($audience === 'board'): ?><span style="color:#A6192E;font-size:.75rem"> · Board Only</span><?php endif; ?>
        <?php if (!empty($p['anonymous'])): ?><span style="color:#5a6a7a;font-size:.75rem"> · 🔒 Anonymous</span><?php endif; ?>
        <div class="poll-meta">
          <?= $is_open ? 'Closes' : 'Closed' ?> <?= date('M j, Y g:ia', strtotime($p['expires_at'])) ?>
          &bull; <?= $total_votes ?> of <?= $eligible_count ?> eligible <?= $audience === 'board' ? 'board members' : 'members' ?> voted
        </div>
        <?php if ($p['description']): ?><div class="poll-meta"><?= h($p['description']) ?></div><?php endif; ?>
        <div style="margin-top:.6rem">
          <?php foreach ($opts as $o): $cnt = $tallies[$o['id']] ?? 0; $pct = $total_votes > 0 ? round($cnt / $total_votes * 100) : 0; ?>
          <div class="poll-bar-row">
            <span style="min-width:110px"><?= h($o['option_text']) ?></span>
            <div class="poll-bar-track"><div class="poll-bar-fill" style="width:<?= $pct ?>%"></div></div>
            <span style="color:#5a6a7a;min-width:70px;text-align:right"><?= $cnt ?> (<?= $pct ?>%)</span>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <div style="display:flex;gap:.4rem;flex-shrink:0;align-items:flex-start">
        <?php if ($is_open): ?>
        <form method="POST" style="margin:0" onsubmit="return confirm('Close this poll early? Members will no longer be able to vote.')">
          <?= csrf_field() ?><input type="hidden" name="action" value="close"><input type="hidden" name="id" value="<?= $p['id'] ?>">
          <button type="submit" class="btn btn-secondary btn-sm">Close Early</button>
        </form>
        <?php endif; ?>
        <form method="POST" style="margin:0" onsubmit="return confirm('Delete this poll and all its votes? This cannot be undone.')">
          <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $p['id'] ?>">
          <button type="submit" class="btn btn-danger btn-sm">Delete</button>
        </form>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
<?php endif; ?>
<?php endif; ?>

<?php admin_footer(); ?>
