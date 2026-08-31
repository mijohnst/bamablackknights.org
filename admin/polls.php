<?php
require_once __DIR__ . '/auth.php';
require_login();
$pdo     = get_pdo();
$user_id = (int)($_SESSION['user_id'] ?? 0);

$db_ready = true;
try { $pdo->query('SELECT 1 FROM polls LIMIT 1'); } catch (PDOException $e) { $db_ready = false; }

// Two separate eligibility checks — which one applies depends on each
// poll's audience ('all_paid' vs 'board'), checked per-poll below.
$my_user_stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
$my_user_stmt->execute([$user_id]);
$my_user      = $my_user_stmt->fetch(PDO::FETCH_ASSOC) ?: [];
$my_member    = $my_user ? find_linked_member($pdo, $my_user) : null;
$paid_eligible  = $my_member && !empty($my_member['membership_paid']);
$board_eligible = is_board_role();

if ($db_ready && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    // Same rule as vote.php's officer elections — Login As is for seeing
    // what a role sees, not for an admin to cast a real vote on someone
    // else's behalf.
    if (is_impersonating()) {
        flash('error', 'Voting is disabled while using Login As.');
        header('Location: polls.php'); exit;
    }

    $poll_id   = (int)($_POST['poll_id'] ?? 0);
    $option_id = (int)($_POST['option_id'] ?? 0);

    $p = $pdo->prepare("SELECT * FROM polls WHERE id=? AND status='open'");
    $p->execute([$poll_id]);
    $poll = $p->fetch(PDO::FETCH_ASSOC);

    $poll_eligible = $poll && (($poll['audience'] ?? 'all_paid') === 'board' ? $board_eligible : $paid_eligible);

    if (!$poll_eligible) {
        flash('error', ($poll['audience'] ?? 'all_paid') === 'board' ? 'Only board members can vote on that poll.' : 'Only paid members can vote.');
    } elseif (strtotime($poll['expires_at']) <= time()) {
        flash('error', 'Voting is not currently open for that poll.');
    } else {
        $o = $pdo->prepare('SELECT id FROM poll_options WHERE id=? AND poll_id=?');
        $o->execute([$option_id, $poll_id]);
        if (!$o->fetch()) {
            flash('error', 'Invalid option.');
        } else {
            $is_anonymous = !empty($poll['anonymous']);
            try {
                // Participation (poll_voters) is recorded separately from the
                // choice (poll_votes) — that's what makes an anonymous vote
                // genuinely untraceable rather than just hidden in the UI:
                // for anonymous polls poll_votes.user_id is left NULL, and
                // poll_voters is the only place tying this user to the poll
                // at all, with no link to which option they picked.
                $pdo->prepare('INSERT INTO poll_voters (poll_id, user_id) VALUES (?,?)')
                    ->execute([$poll_id, $user_id]);
                $pdo->prepare('INSERT INTO poll_votes (poll_id, option_id, user_id) VALUES (?,?,?)')
                    ->execute([$poll_id, $option_id, $is_anonymous ? null : $user_id]);
                flash('success', 'Vote recorded — thank you!');
            } catch (PDOException $e) {
                flash('error', "You've already voted on that poll.");
            }
        }
    }
    header('Location: polls.php'); exit;
}

$polls = [];
$my_votes = [];       // poll_id => option_id, only ever populated for non-anonymous polls
$my_voted_polls = []; // poll_id => true, for ANY poll the user has voted on, anonymous or not
if ($db_ready) {
    $polls = $pdo->query(
        "SELECT * FROM polls ORDER BY (status='open') DESC, expires_at DESC"
    )->fetchAll(PDO::FETCH_ASSOC);
    if ($user_id) {
        $mv = $pdo->prepare('SELECT poll_id, option_id FROM poll_votes WHERE user_id=?');
        $mv->execute([$user_id]);
        foreach ($mv->fetchAll(PDO::FETCH_ASSOC) as $r) $my_votes[$r['poll_id']] = $r['option_id'];

        // poll_voters may not exist yet on this install (pre-migration) —
        // fall back to just the poll_votes-based signal above in that case.
        try {
            $mvp = $pdo->prepare('SELECT poll_id FROM poll_voters WHERE user_id=?');
            $mvp->execute([$user_id]);
            foreach ($mvp->fetchAll(PDO::FETCH_COLUMN) as $pid) $my_voted_polls[$pid] = true;
        } catch (PDOException $e) {}
    }
}

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

admin_header('Vote');
echo show_flash();
?>
<style>
.poll-card{border-left:3px solid #003594;padding:.85rem 1rem;margin-bottom:.85rem;background:#fff;border-radius:0 4px 4px 0}
.poll-card.closed{border-left-color:#9aa5b4}
.poll-meta{font-size:.78rem;color:#5a6a7a;margin-top:.15rem;margin-bottom:.6rem}
.poll-choice{display:flex;align-items:center;gap:.55rem;padding:.4rem 0;font-size:.92rem}
.poll-bar-row{display:flex;align-items:center;gap:.6rem;margin-top:.3rem;font-size:.82rem}
.poll-bar-track{flex:1;height:10px;background:#f0f2f5;border-radius:99px;overflow:hidden}
.poll-bar-fill{height:100%;background:#003594;border-radius:99px}
</style>

<div class="page-head">
  <h1>Vote</h1>
  <div style="display:flex;gap:.5rem">
    <?php if (is_board_role() || is_admin()): ?><a href="polls-manage.php" class="btn btn-primary">Manage Polls</a><?php endif; ?>
    <a href="dashboard.php" class="btn btn-secondary">← Dashboard</a>
  </div>
</div>
<p style="font-size:.82rem;color:#5a6a7a;margin-bottom:1.25rem">Club decisions a board member has opened up for a vote.</p>

<?php if (!$db_ready): ?>
  <p style="color:#9aa5b4">No polls have been set up yet.</p>
<?php elseif (empty($polls)): ?>
  <p style="color:#9aa5b4">No polls right now — check back later.</p>
<?php else: ?>
  <?php foreach ($polls as $p):
    $opts = $options_by_poll[$p['id']] ?? [];
    $total_votes = array_sum(array_map(fn($o) => $tallies[$o['id']] ?? 0, $opts));
    // Some existing databases created this column as uppercase `STATUS`.
    // PDO preserves column-name casing in associative result keys, so accept
    // either spelling until every installation has normalized its schema.
    $poll_status = strtolower(trim((string)($p['status'] ?? $p['STATUS'] ?? '')));
    $is_open   = $poll_status === 'open' && strtotime($p['expires_at']) > time();
    $already   = isset($my_votes[$p['id']]) || isset($my_voted_polls[$p['id']]);
    $audience      = $p['audience'] ?? 'all_paid';
    $poll_eligible = $audience === 'board' ? $board_eligible : $paid_eligible;
    $show_ballot   = $is_open && $poll_eligible && !$already;
  ?>
  <div class="poll-card <?= $is_open ? '' : 'closed' ?>">
    <strong style="color:#002554"><?= h($p['title']) ?></strong>
    <?php if (!$is_open): ?><span style="color:#9aa5b4;font-size:.75rem"> · Closed</span>
    <?php elseif ($already): ?><span style="color:#1b5e20;font-size:.75rem"> · ✓ You voted</span><?php endif; ?>
    <?php if ($audience === 'board'): ?><span style="color:#A6192E;font-size:.75rem"> · Board Only</span><?php endif; ?>
    <?php if (!empty($p['anonymous'])): ?><span style="color:#5a6a7a;font-size:.75rem"> · 🔒 Anonymous</span><?php endif; ?>
    <div class="poll-meta">
      <?= $is_open ? 'Voting closes' : 'Closed' ?> <?= date('M j, Y g:ia', strtotime($p['expires_at'])) ?>
      <?php if ($p['description']): ?><br><?= h($p['description']) ?><?php endif; ?>
    </div>

    <?php if ($is_open && !$poll_eligible && !$already): ?>
      <div class="alert alert-error" style="margin:.5rem 0 0"><?= $audience === 'board' ? 'Only board members (President, VP, Secretary, Treasurer) can vote on this.' : 'Only paid members can vote on this.' ?> You can still see results below.</div>
    <?php endif; ?>

    <?php if ($show_ballot): ?>
      <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="poll_id" value="<?= $p['id'] ?>">
        <?php foreach ($opts as $o): ?>
        <label class="poll-choice">
          <input type="radio" name="option_id" value="<?= $o['id'] ?>" required style="width:auto">
          <?= h($o['option_text']) ?>
        </label>
        <?php endforeach; ?>
        <button type="submit" class="btn btn-primary btn-sm" style="margin-top:.5rem">Cast Vote</button>
      </form>
    <?php else: ?>
      <?php foreach ($opts as $o): $cnt = $tallies[$o['id']] ?? 0; $pct = $total_votes > 0 ? round($cnt / $total_votes * 100) : 0; ?>
      <div class="poll-bar-row">
        <span style="min-width:110px"><?= h($o['option_text']) ?><?= isset($my_votes[$p['id']]) && $my_votes[$p['id']] == $o['id'] ? ' (your vote)' : '' ?></span>
        <div class="poll-bar-track"><div class="poll-bar-fill" style="width:<?= $pct ?>%"></div></div>
        <span style="color:#5a6a7a;min-width:70px;text-align:right"><?= $cnt ?> (<?= $pct ?>%)</span>
      </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
<?php endif; ?>

<?php admin_footer(); ?>
