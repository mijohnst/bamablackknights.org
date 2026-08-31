<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/mailer.php';
require_login();
if (!can_manage_members() && !is_treasurer()) { header('Location: dashboard.php?denied=1'); exit; }
$pdo = get_pdo();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_election') {
        $id            = (int)($_POST['id'] ?? 0);
        $title         = trim($_POST['title'] ?? '');
        $election_date = $_POST['election_date'] ?: null;
        $opens         = str_replace('T', ' ', trim($_POST['voting_opens_at'] ?? ''));
        $closes        = str_replace('T', ' ', trim($_POST['voting_closes_at'] ?? ''));

        $errors = [];
        if (!$title) $errors[] = 'Title is required.';
        if (!$election_date) $errors[] = 'Election day is required.';
        if (!$opens || !$closes) $errors[] = 'Voting opens/closes times are required.';
        if ($opens && $closes && $opens >= $closes) $errors[] = 'Voting must close after it opens.';

        if ($errors) {
            flash('error', implode(' ', $errors));
            header('Location: elections.php?edit=' . ($id ?: 'new')); exit;
        }
        if ($id) {
            $pdo->prepare('UPDATE elections SET title=?, election_date=?, voting_opens_at=?, voting_closes_at=? WHERE id=?')
                ->execute([$title, $election_date, $opens, $closes, $id]);
            flash('success', 'Election updated.');
            header('Location: elections.php?manage=' . $id); exit;
        } else {
            $pdo->prepare('INSERT INTO elections (title, election_date, voting_opens_at, voting_closes_at, created_by) VALUES (?,?,?,?,?)')
                ->execute([$title, $election_date, $opens, $closes, $_SESSION['user_id'] ?? null]);
            flash('success', 'Election created — now add candidates.');
            header('Location: elections.php?manage=' . (int)$pdo->lastInsertId()); exit;
        }
    } elseif ($action === 'delete_election') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $pdo->prepare('DELETE FROM election_votes WHERE election_id=?')->execute([$id]);
            $pdo->prepare('DELETE FROM election_candidates WHERE election_id=?')->execute([$id]);
            $pdo->prepare('DELETE FROM elections WHERE id=?')->execute([$id]);
            flash('success', 'Election deleted.');
        }
        header('Location: elections.php'); exit;
    } elseif ($action === 'save_candidate') {
        $cid         = (int)($_POST['candidate_id'] ?? 0);
        $election_id = (int)($_POST['election_id'] ?? 0);
        $position    = $_POST['position'] ?? '';
        $bio         = trim($_POST['bio'] ?? '');

        // The picked value is "<member_id>:<parent_slot>" — resolve the
        // candidate's name server-side from the member record rather than
        // trusting a client-supplied name, so it always matches the roster.
        $member_id = 0; $slot = 0; $name = '';
        if (preg_match('/^(\d+):([12])$/', trim($_POST['member_pick'] ?? ''), $mm)) {
            $ms = $pdo->prepare('SELECT * FROM members WHERE id=? AND archived=0 AND membership_paid=1');
            $ms->execute([(int)$mm[1]]);
            if ($mrow = $ms->fetch(PDO::FETCH_ASSOC)) {
                $member_id = (int)$mm[1];
                $slot      = (int)$mm[2];
                $name      = trim(($mrow["parent{$slot}_first_name"] ?? '') . ' ' . ($mrow["parent{$slot}_last_name"] ?? ''));
            }
        }

        if (in_array($position, ELECTION_POSITIONS, true) && $name && $election_id) {
            if ($cid) {
                $pdo->prepare('UPDATE election_candidates SET member_id=?, parent_slot=?, name=?, bio=? WHERE id=? AND election_id=?')
                    ->execute([$member_id, $slot, $name, $bio, $cid, $election_id]);
            } else {
                $pdo->prepare('INSERT INTO election_candidates (election_id, position, member_id, parent_slot, name, bio) VALUES (?,?,?,?,?,?)')
                    ->execute([$election_id, $position, $member_id, $slot, $name, $bio]);
            }
            flash('success', 'Candidate saved.');
        } else {
            flash('error', 'Choose a member to add as a candidate.');
        }
        header('Location: elections.php?manage=' . $election_id); exit;
    } elseif ($action === 'approve_candidate') {
        $cid         = (int)($_POST['candidate_id'] ?? 0);
        $election_id = (int)($_POST['election_id'] ?? 0);
        if ($cid) {
            // A pending nomination stays approvable up until the election is
            // closed (see comment below near the row template) — but this
            // must be enforced here too, not just by which buttons the UI
            // renders, or a stale tab / replayed request could approve a
            // candidate after voting has closed.
            $pdo->prepare(
                "UPDATE election_candidates ec
                 JOIN elections e ON e.id = ec.election_id
                 SET ec.status='approved'
                 WHERE ec.id=? AND ec.election_id=? AND ec.status='pending' AND e.status <> 'closed'"
            )->execute([$cid, $election_id]);
            flash('success', 'Nomination approved.');
        }
        header('Location: elections.php?manage=' . $election_id); exit;
    } elseif ($action === 'delete_candidate') {
        $cid         = (int)($_POST['candidate_id'] ?? 0);
        $election_id = (int)($_POST['election_id'] ?? 0);
        if ($cid) {
            // Mirror the UI's rule server-side: a pending nomination can be
            // rejected any time before the election closes, but an already-
            // approved candidate can only be removed while still in draft
            // (once voting opens, the ballot roster is locked).
            $chk = $pdo->prepare(
                "SELECT ec.status AS cand_status, e.status AS election_status
                 FROM election_candidates ec JOIN elections e ON e.id = ec.election_id
                 WHERE ec.id=? AND ec.election_id=?"
            );
            $chk->execute([$cid, $election_id]);
            $row = $chk->fetch(PDO::FETCH_ASSOC);
            $allowed = $row && (
                ($row['cand_status'] === 'pending' && $row['election_status'] !== 'closed') ||
                ($row['cand_status'] === 'approved' && $row['election_status'] === 'draft')
            );
            if ($allowed) {
                $pdo->prepare('DELETE FROM election_votes WHERE candidate_id=?')->execute([$cid]);
                $pdo->prepare('DELETE FROM election_candidates WHERE id=?')->execute([$cid]);
                flash('success', 'Candidate removed.');
            } else {
                flash('error', 'That candidate can no longer be removed.');
            }
        }
        header('Location: elections.php?manage=' . $election_id); exit;
    } elseif ($action === 'test_nominations_email') {
        $id         = (int)($_POST['id'] ?? 0);
        $test_email = trim($_POST['test_email'] ?? '');
        if (!filter_var($test_email, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'Enter a valid email address for the test.');
        } else {
            $es = $pdo->prepare('SELECT * FROM elections WHERE id=?');
            $es->execute([$id]);
            $election = $es->fetch(PDO::FETCH_ASSOC);
            $ok = $election && send_nominations_open_test($pdo, $election, $test_email);
            flash($ok ? 'success' : 'error', $ok
                ? "Test email sent to $test_email."
                : 'Nothing to preview — every position already has an approved candidate.');
        }
        header('Location: elections.php?manage=' . $id); exit;
    } elseif ($action === 'announce_nominations') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $es = $pdo->prepare('SELECT * FROM elections WHERE id=?');
            $es->execute([$id]);
            $election = $es->fetch(PDO::FETCH_ASSOC);
            $sent = $election ? notify_nominations_open($pdo, $election) : 0;
            flash('success', $sent > 0
                ? "Notified $sent paid member" . ($sent == 1 ? '' : 's') . " that nominations are open."
                : 'Nothing to announce — either every position already has an approved candidate, or there are no paid members to notify.');
        }
        header('Location: elections.php?manage=' . $id); exit;
    } elseif ($action === 'open_voting') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $pdo->prepare("UPDATE elections SET status='open' WHERE id=? AND status='draft'")->execute([$id]);
            if (!empty($_POST['notify'])) {
                $es = $pdo->prepare('SELECT * FROM elections WHERE id=?');
                $es->execute([$id]);
                $election = $es->fetch(PDO::FETCH_ASSOC);
                $sent = $election ? notify_election_open($pdo, $election) : 0;
                flash('success', "Voting opened — notified $sent member" . ($sent == 1 ? '' : 's') . ".");
            } else {
                flash('success', 'Voting opened.');
            }
        }
        header('Location: elections.php?manage=' . $id); exit;
    } elseif ($action === 'close_voting') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $pdo->prepare("UPDATE elections SET status='closed' WHERE id=? AND status='open'")->execute([$id]);
            flash('success', 'Voting closed.');
        }
        header('Location: elections.php?manage=' . $id); exit;
    }
}

$status_colors = [
    'draft'  => ['bg' => '#e1e5eb', 'fg' => '#37474f'],
    'open'   => ['bg' => '#e8f5e9', 'fg' => '#1b5e20'],
    'closed' => ['bg' => '#ffebee', 'fg' => '#c62828'],
];

$manage_id = isset($_GET['manage']) ? (int)$_GET['manage'] : 0;
$manage    = null;
if ($manage_id) {
    $s = $pdo->prepare('SELECT * FROM elections WHERE id=?');
    $s->execute([$manage_id]);
    $manage = $s->fetch(PDO::FETCH_ASSOC);
    if (!$manage) $manage_id = 0;
}

admin_header('Elections');
echo show_flash();
?>
<style>
.cand-row{padding:.5rem 0;border-bottom:1px solid #f0f2f5}
.cand-row:last-child{border-bottom:none}
</style>

<?php if ($manage_id && $manage):
    $candidates = $pdo->prepare('SELECT * FROM election_candidates WHERE election_id=? ORDER BY position, name');
    $candidates->execute([$manage_id]);
    $candidates = $candidates->fetchAll(PDO::FETCH_ASSOC);
    $by_position = [];
    foreach (ELECTION_POSITIONS as $p) $by_position[$p] = [];
    foreach ($candidates as $c) $by_position[$c['position']][] = $c;

    $vote_counts = [];
    if ($manage['status'] !== 'draft') {
        $r = $pdo->prepare(
            'SELECT candidate_id, COUNT(*) AS votes FROM election_votes WHERE election_id=? GROUP BY candidate_id'
        );
        $r->execute([$manage_id]);
        foreach ($r->fetchAll(PDO::FETCH_ASSOC) as $row) $vote_counts[$row['candidate_id']] = (int)$row['votes'];
    }

    $edit_candidate_id = isset($_GET['edit_candidate']) ? (int)$_GET['edit_candidate'] : 0;
    $edit_candidate = null;
    foreach ($candidates as $c) if ((int)$c['id'] === $edit_candidate_id) { $edit_candidate = $c; break; }

    // Every parent on an active, dues-paid member record, as a
    // "<member_id>:<slot>" pick list — mirrors how parent1_is_board_member /
    // parent2_is_board_member already tag individual parents on a family
    // record. Only paid members are eligible to run for office. Only needed
    // for the add-candidate form below, which itself only renders while
    // status='draft' — skip the full-roster scan otherwise.
    $parent_options = [];
    if ($manage['status'] === 'draft') {
        $mem_rows = $pdo->query(
            "SELECT id, cadet_first_name, cadet_last_name, cadet_suffix, class_year,
                    parent1_first_name, parent1_last_name, parent2_first_name, parent2_last_name
             FROM members WHERE archived=0 AND membership_paid=1"
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($mem_rows as $m) {
            $cadet = cadet_full_name($m);
            foreach ([1, 2] as $slot) {
                $fn = trim($m["parent{$slot}_first_name"] ?? '');
                $ln = trim($m["parent{$slot}_last_name"] ?? '');
                if ($fn === '' && $ln === '') continue;
                $label = trim("$ln, $fn");
                if ($cadet !== '') $label .= ' — ' . $cadet . (!empty($m['class_year']) ? ' (' . $m['class_year'] . ')' : '');
                $parent_options[] = ['value' => $m['id'] . ':' . $slot, 'label' => $label];
            }
        }
        usort($parent_options, fn($a, $b) => strcasecmp($a['label'], $b['label']));
    }
    $pending_count = count(array_filter($candidates, fn($c) => $c['status'] === 'pending'));
?>
  <div class="page-head">
    <h1><?= h($manage['title']) ?></h1>
    <a href="elections.php" class="btn btn-secondary">← All Elections</a>
  </div>
  <p style="font-size:.82rem;color:#5a6a7a;margin-bottom:1.25rem">
    Election Day: <strong><?= date('F j, Y', strtotime($manage['election_date'])) ?></strong>
    &nbsp;&bull;&nbsp; Voting: <?= date('M j, g:ia', strtotime($manage['voting_opens_at'])) ?> – <?= date('M j, g:ia', strtotime($manage['voting_closes_at'])) ?>
    &nbsp;&bull;&nbsp; <span class="badge" style="background:<?= $status_colors[$manage['status']]['bg'] ?>;color:<?= $status_colors[$manage['status']]['fg'] ?>"><?= ucfirst($manage['status']) ?></span>
  </p>

  <div class="card" style="margin-bottom:1.5rem">
    <div style="display:flex;gap:.75rem;flex-wrap:wrap;align-items:center">
      <a href="elections.php?edit=<?= $manage_id ?>" class="btn btn-secondary btn-sm">Edit Details</a>
      <?php if ($manage['status'] === 'draft'): ?>
        <form method="POST" style="margin:0">
          <?= csrf_field() ?><input type="hidden" name="action" value="announce_nominations"><input type="hidden" name="id" value="<?= $manage_id ?>">
          <button type="submit" class="btn btn-secondary btn-sm" title="Emails every paid member about the open position(s) and how to self-nominate">📧 Announce Nominations</button>
        </form>
        <form method="POST" style="display:flex;gap:.4rem;align-items:center;margin:0">
          <?= csrf_field() ?><input type="hidden" name="action" value="test_nominations_email"><input type="hidden" name="id" value="<?= $manage_id ?>">
          <input type="email" name="test_email" required placeholder="you@example.com" value="<?= h($_SESSION['user_email'] ?? '') ?>" style="width:auto;font-size:.82rem;padding:.35rem .55rem">
          <button type="submit" class="btn btn-secondary btn-sm">Send Test</button>
        </form>
        <?php if ($pending_count > 0): ?>
          <span style="font-size:.78rem;color:#5f4c00;background:#fff3cd;border:1px solid #ffc107;border-radius:4px;padding:.3rem .6rem"><?= $pending_count ?> nomination<?= $pending_count == 1 ? '' : 's' ?> still pending approval — won't appear on the ballot until approved</span>
        <?php endif; ?>
        <form method="POST" style="display:flex;gap:.5rem;align-items:center;margin:0" onsubmit="return confirm('Open voting now? Candidates can’t be changed once voting is open.')">
          <?= csrf_field() ?><input type="hidden" name="action" value="open_voting"><input type="hidden" name="id" value="<?= $manage_id ?>">
          <label style="display:flex;align-items:center;gap:.35rem;font-weight:400;text-transform:none;font-size:.82rem;color:#5a6a7a;cursor:pointer">
            <input type="checkbox" name="notify" value="1" checked style="width:auto"> Email members
          </label>
          <button type="submit" class="btn btn-primary btn-sm">Open Voting</button>
        </form>
      <?php elseif ($manage['status'] === 'open'): ?>
        <form method="POST" style="margin:0" onsubmit="return confirm('Close voting? This can’t be undone.')">
          <?= csrf_field() ?><input type="hidden" name="action" value="close_voting"><input type="hidden" name="id" value="<?= $manage_id ?>">
          <button type="submit" class="btn btn-danger btn-sm">Close Voting</button>
        </form>
      <?php endif; ?>
      <form method="POST" style="margin:0" onsubmit="return confirm('Delete this election and all its candidates/votes? This can’t be undone.')">
        <?= csrf_field() ?><input type="hidden" name="action" value="delete_election"><input type="hidden" name="id" value="<?= $manage_id ?>">
        <button type="submit" class="btn btn-danger btn-sm">Delete Election</button>
      </form>
    </div>
  </div>

  <?php foreach (ELECTION_POSITIONS as $position): ?>
  <div class="card" style="margin-bottom:1.25rem;max-width:640px">
    <h2><?= h($position) ?></h2>
    <?php if (empty($by_position[$position])): ?>
      <p style="font-size:.85rem;color:#9aa5b4;margin-bottom:.75rem">No candidates yet.</p>
    <?php else: foreach ($by_position[$position] as $c): ?>
      <div class="cand-row" style="display:flex;justify-content:space-between;align-items:flex-start;gap:.75rem">
        <div>
          <strong style="color:#1a2332"><?= h($c['name']) ?></strong>
          <?php if ($c['status'] === 'pending'): ?><span class="badge" style="background:#fff3cd;color:#5f4c00;margin-left:.4rem">Pending Approval</span><?php endif; ?>
          <?php if ($c['bio']): ?><div style="font-size:.82rem;color:#5a6a7a;margin-top:.1rem"><?= h($c['bio']) ?></div><?php endif; ?>
        </div>
        <?php if ($c['status'] === 'pending' && $manage['status'] !== 'closed'): ?>
          <!-- A pending nomination stays approvable regardless of whether
               voting has already opened — otherwise a late self-nomination
               that arrives after "Open Voting" is clicked would be stuck
               forever with no way to review it. -->
          <div class="btn-group" style="flex-shrink:0">
            <form method="POST" style="margin:0">
              <?= csrf_field() ?><input type="hidden" name="action" value="approve_candidate">
              <input type="hidden" name="candidate_id" value="<?= $c['id'] ?>"><input type="hidden" name="election_id" value="<?= $manage_id ?>">
              <button type="submit" class="btn btn-primary btn-sm">Approve</button>
            </form>
            <form method="POST" style="margin:0" onsubmit="return confirm('Reject this nomination?')">
              <?= csrf_field() ?><input type="hidden" name="action" value="delete_candidate">
              <input type="hidden" name="candidate_id" value="<?= $c['id'] ?>"><input type="hidden" name="election_id" value="<?= $manage_id ?>">
              <button type="submit" class="btn btn-danger btn-sm">Reject</button>
            </form>
          </div>
        <?php elseif ($manage['status'] === 'draft'): ?>
          <div class="btn-group" style="flex-shrink:0">
            <a href="elections.php?manage=<?= $manage_id ?>&edit_candidate=<?= $c['id'] ?>#candidate-form-<?= h(str_replace(' ', '-', $position)) ?>" class="btn btn-secondary btn-sm">Edit</a>
            <form method="POST" style="margin:0" onsubmit="return confirm('Remove this candidate?')">
              <?= csrf_field() ?><input type="hidden" name="action" value="delete_candidate">
              <input type="hidden" name="candidate_id" value="<?= $c['id'] ?>"><input type="hidden" name="election_id" value="<?= $manage_id ?>">
              <button type="submit" class="btn btn-danger btn-sm">Remove</button>
            </form>
          </div>
        <?php elseif ($c['status'] === 'approved'): ?>
          <span style="font-weight:700;color:#003594;white-space:nowrap"><?= $vote_counts[$c['id']] ?? 0 ?> vote<?= (($vote_counts[$c['id']] ?? 0) == 1 ? '' : 's') ?></span>
        <?php else: ?>
          <span style="font-size:.78rem;color:#9aa5b4">Never approved</span>
        <?php endif; ?>
      </div>
    <?php endforeach; endif; ?>

    <?php if ($manage['status'] === 'draft'):
        $ec = ($edit_candidate && $edit_candidate['position'] === $position) ? $edit_candidate : null;
    ?>
    <form method="POST" id="candidate-form-<?= h(str_replace(' ', '-', $position)) ?>" style="border-top:1px solid #f0f2f5;padding-top:1rem;margin-top:.5rem">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_candidate">
      <input type="hidden" name="election_id" value="<?= $manage_id ?>">
      <input type="hidden" name="position" value="<?= h($position) ?>">
      <?php if ($ec): ?><input type="hidden" name="candidate_id" value="<?= $ec['id'] ?>"><?php endif; ?>
      <?php $ec_pick = $ec ? $ec['member_id'] . ':' . $ec['parent_slot'] : ''; ?>
      <div class="form-row col-3">
        <div class="form-group">
          <label>Member</label>
          <select name="member_pick">
            <option value="">— select a member —</option>
            <?php foreach ($parent_options as $opt): ?>
              <option value="<?= h($opt['value']) ?>" <?= $opt['value'] === $ec_pick ? 'selected' : '' ?>><?= h($opt['label']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group" style="grid-column:span 2"><label>Short Bio <span style="font-weight:400;font-size:.72rem;color:#9aa5b4">optional</span></label><input name="bio" value="<?= h($ec['bio'] ?? '') ?>" placeholder="A sentence or two"></div>
      </div>
      <button type="submit" class="btn btn-secondary btn-sm"><?= $ec ? 'Save Changes' : '+ Add Candidate' ?></button>
      <?php if ($ec): ?><a href="elections.php?manage=<?= $manage_id ?>" class="btn btn-secondary btn-sm" style="margin-left:.4rem">Cancel</a><?php endif; ?>
    </form>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>

<?php else:
    $edit_id = isset($_GET['edit']) ? $_GET['edit'] : null;
    $edit = null;
    if ($edit_id && $edit_id !== 'new') {
        $s = $pdo->prepare('SELECT * FROM elections WHERE id=?');
        $s->execute([(int)$edit_id]);
        $edit = $s->fetch(PDO::FETCH_ASSOC);
    }
    $opens_val = $closes_val = '';
    if ($edit) {
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}/', $edit['voting_opens_at'] ?? ''))
            $opens_val = str_replace(' ', 'T', substr($edit['voting_opens_at'], 0, 16));
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}/', $edit['voting_closes_at'] ?? ''))
            $closes_val = str_replace(' ', 'T', substr($edit['voting_closes_at'], 0, 16));
    }
    $elections = $pdo->query('SELECT * FROM elections ORDER BY election_date DESC')->fetchAll(PDO::FETCH_ASSOC);
    $pending_by_election = [];
    foreach ($pdo->query("SELECT election_id, COUNT(*) AS cnt FROM election_candidates WHERE status='pending' GROUP BY election_id")->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $pending_by_election[$row['election_id']] = (int)$row['cnt'];
    }
?>
  <div class="page-head">
    <h1>Elections</h1>
    <div style="display:flex;gap:.5rem">
      <?php if ($edit_id === null): ?><a href="elections.php?edit=new" class="btn btn-primary">+ New Election</a><?php endif; ?>
      <a href="dashboard.php" class="btn btn-secondary">← Dashboard</a>
    </div>
  </div>

  <?php if ($edit_id !== null): ?>
  <div class="card" style="max-width:680px;margin-bottom:1.5rem">
    <h2 style="margin-bottom:1.25rem"><?= $edit ? 'Edit Election' : 'New Election' ?></h2>
    <form method="POST">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_election">
      <?php if ($edit): ?><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>"><?php endif; ?>
      <div class="form-group">
        <label>Title *</label>
        <input name="title" value="<?= h($edit['title'] ?? '') ?>" required placeholder="e.g. 2026-2027 Officer Election">
      </div>
      <div class="form-row col-3">
        <div class="form-group">
          <label>Election Day *</label>
          <input type="date" name="election_date" value="<?= h($edit['election_date'] ?? '') ?>" required>
        </div>
        <div class="form-group">
          <label>Voting Opens *</label>
          <input type="datetime-local" name="voting_opens_at" value="<?= h($opens_val) ?>" required>
        </div>
        <div class="form-group">
          <label>Voting Closes *</label>
          <input type="datetime-local" name="voting_closes_at" value="<?= h($closes_val) ?>" required>
        </div>
      </div>
      <div style="display:flex;gap:.75rem">
        <button type="submit" class="btn btn-primary"><?= $edit ? 'Save Changes' : 'Create Election' ?></button>
        <a href="elections.php" class="btn btn-secondary">Cancel</a>
      </div>
    </form>
  </div>
  <?php endif; ?>

  <?php if (empty($elections)): ?>
    <p style="color:#9aa5b4">No elections yet.</p>
  <?php else: ?>
  <table>
    <tr><th>Title</th><th>Election Day</th><th>Status</th><th>Pending</th><th class="actions-head">Actions</th></tr>
    <?php foreach ($elections as $e): $pend = $pending_by_election[$e['id']] ?? 0; ?>
    <tr>
      <td style="font-weight:700"><?= h($e['title']) ?></td>
      <td><?= date('M j, Y', strtotime($e['election_date'])) ?></td>
      <td><span class="badge" style="background:<?= $status_colors[$e['status']]['bg'] ?>;color:<?= $status_colors[$e['status']]['fg'] ?>"><?= ucfirst($e['status']) ?></span></td>
      <td><?php if ($pend > 0): ?><span class="badge" style="background:#fff3cd;color:#5f4c00"><?= $pend ?> pending</span><?php endif; ?></td>
      <td class="actions">
        <div class="btn-group">
          <a href="elections.php?manage=<?= $e['id'] ?>" class="btn btn-primary btn-sm">Manage</a>
          <a href="elections.php?edit=<?= $e['id'] ?>" class="btn btn-secondary btn-sm">Edit</a>
        </div>
      </td>
    </tr>
    <?php endforeach; ?>
  </table>
  <?php endif; ?>
<?php endif; ?>

<?php admin_footer(); ?>
