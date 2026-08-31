<?php
require_once __DIR__ . '/auth.php';
require_login();
$pdo     = get_pdo();
$user_id = $_SESSION['user_id'] ?? 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    $opportunity_id = (int)($_POST['opportunity_id'] ?? 0);

    if ($action === 'signup' && $opportunity_id) {
        $o = $pdo->prepare('SELECT id, spots_needed, active, (SELECT COUNT(*) FROM volunteer_signups WHERE opportunity_id=?) AS filled FROM volunteer_opportunities WHERE id=?');
        $o->execute([$opportunity_id, $opportunity_id]);
        $row = $o->fetch(PDO::FETCH_ASSOC);
        if (!$row || !$row['active']) {
            flash('error', 'This opportunity is no longer open.');
        } elseif ((int)$row['filled'] >= (int)$row['spots_needed']) {
            flash('error', 'That opportunity is already full.');
        } else {
            try {
                $pdo->prepare('INSERT INTO volunteer_signups (opportunity_id, user_id) VALUES (?,?)')->execute([$opportunity_id, $user_id]);
                flash('success', "You're signed up — thank you!");
            } catch (PDOException $e) {
                flash('error', "You're already signed up for that one.");
            }
        }
    } elseif ($action === 'cancel' && $opportunity_id) {
        $pdo->prepare('DELETE FROM volunteer_signups WHERE opportunity_id=? AND user_id=?')->execute([$opportunity_id, $user_id]);
        flash('success', 'Sign-up canceled.');
    }
    header('Location: volunteer-signup.php'); exit;
}

$opportunities = $pdo->query(
    "SELECT o.*, (SELECT COUNT(*) FROM volunteer_signups WHERE opportunity_id=o.id) AS filled
     FROM volunteer_opportunities o WHERE o.active = 1
     ORDER BY o.event_date IS NULL, o.event_date ASC, o.id DESC"
)->fetchAll(PDO::FETCH_ASSOC);

$my_signups = [];
if (!empty($opportunities)) {
    $mine = $pdo->prepare('SELECT opportunity_id FROM volunteer_signups WHERE user_id=?');
    $mine->execute([$user_id]);
    $my_signups = array_flip($mine->fetchAll(PDO::FETCH_COLUMN));
}

admin_header('Volunteer Sign-Ups');
echo show_flash();
?>
<style>
.vs-card{background:#fff;border-radius:6px;box-shadow:0 1px 4px rgba(0,0,0,.1);padding:1rem 1.25rem;margin-bottom:.75rem;display:flex;justify-content:space-between;gap:1rem;flex-wrap:wrap;align-items:flex-start}
.vs-meta{font-size:.8rem;color:#5a6a7a;margin-top:.25rem}
.vs-full{opacity:.6}
</style>

<div class="page-head">
  <h1>Volunteer Sign-Ups</h1>
  <a href="dashboard.php" class="btn btn-secondary">← Dashboard</a>
</div>
<p style="font-size:.82rem;color:#5a6a7a;margin-bottom:1.25rem">Claim a specific need below — no need to wait to be asked.</p>

<?php if (empty($opportunities)): ?>
  <p style="color:#9aa5b4">No open volunteer opportunities right now — check back soon.</p>
<?php else: ?>
  <?php foreach ($opportunities as $o):
    $filled = (int)$o['filled'];
    $needed = (int)$o['spots_needed'];
    $is_full = $filled >= $needed;
    $signed_up = isset($my_signups[$o['id']]);
  ?>
  <div class="vs-card <?= $is_full && !$signed_up ? 'vs-full' : '' ?>">
    <div style="flex:1;min-width:0">
      <strong style="color:#002554"><?= h($o['title']) ?></strong>
      <div class="vs-meta">
        <?php if ($o['event_date']): ?><?= date('M j, Y', strtotime($o['event_date'])) ?><?php endif; ?>
        <?php if ($o['location']): ?><?= $o['event_date'] ? ' &bull; ' : '' ?><?= h($o['location']) ?><?php endif; ?>
        <?php if ($o['event_date'] || $o['location']): ?> &bull; <?php endif; ?>
        <?= $needed - $filled > 0 ? ($needed - $filled) . ' spot' . (($needed-$filled)!==1?'s':'') . ' left' : 'Full' ?>
      </div>
      <?php if ($o['description']): ?><div class="vs-meta"><?= h($o['description']) ?></div><?php endif; ?>
    </div>
    <div style="flex-shrink:0">
      <?php if ($signed_up): ?>
        <form method="POST" style="margin:0">
          <?= csrf_field() ?><input type="hidden" name="action" value="cancel"><input type="hidden" name="opportunity_id" value="<?= $o['id'] ?>">
          <button type="submit" class="btn btn-secondary btn-sm">✓ Signed up — Cancel</button>
        </form>
      <?php elseif ($is_full): ?>
        <button type="button" class="btn btn-secondary btn-sm" disabled>Full</button>
      <?php else: ?>
        <form method="POST" style="margin:0">
          <?= csrf_field() ?><input type="hidden" name="action" value="signup"><input type="hidden" name="opportunity_id" value="<?= $o['id'] ?>">
          <button type="submit" class="btn btn-primary btn-sm">Sign Up</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
<?php endif; ?>

<?php admin_footer(); ?>
