<?php
require_once __DIR__ . '/auth.php';
require_login();
$pdo     = get_pdo();
$user_id = $_SESSION['user_id'] ?? 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action   = $_POST['action'] ?? '';
    $event_id = (int)($_POST['event_id'] ?? 0);

    if ($action === 'rsvp' && $event_id) {
        $guests = max(0, (int)($_POST['guest_count'] ?? 0));
        $pdo->prepare(
            'INSERT INTO event_rsvps (event_id, user_id, guest_count, status) VALUES (?,?,?,\'attending\')
             ON DUPLICATE KEY UPDATE guest_count = VALUES(guest_count), status = \'attending\', updated_at = NOW()'
        )->execute([$event_id, $user_id, $guests]);
        flash('success', "You're on the list!");
    } elseif ($action === 'cancel' && $event_id) {
        $pdo->prepare('DELETE FROM event_rsvps WHERE event_id=? AND user_id=?')->execute([$event_id, $user_id]);
        flash('success', 'RSVP removed.');
    }
    header('Location: event-rsvp.php'); exit;
}

$events = $pdo->query(
    "SELECT * FROM events WHERE visible = 1 AND group_label IN ('upcoming','planning')
     ORDER BY event_date IS NULL, event_date ASC"
)->fetchAll(PDO::FETCH_ASSOC);

$my_rsvps = [];
if (!empty($events)) {
    $mine = $pdo->prepare('SELECT event_id, guest_count FROM event_rsvps WHERE user_id=?');
    $mine->execute([$user_id]);
    foreach ($mine->fetchAll(PDO::FETCH_ASSOC) as $r) $my_rsvps[$r['event_id']] = $r['guest_count'];
}

admin_header('Event RSVP');
echo show_flash();
?>
<style>
.rsvp-card{background:#fff;border-radius:6px;box-shadow:0 1px 4px rgba(0,0,0,.1);padding:1rem 1.25rem;margin-bottom:.75rem}
.rsvp-meta{font-size:.8rem;color:#5a6a7a;margin-top:.25rem}
.rsvp-form{display:flex;gap:.5rem;align-items:center;margin-top:.6rem;flex-wrap:wrap}
.rsvp-form input[type=number]{width:70px}
</style>

<div class="page-head">
  <h1>Event RSVP</h1>
  <a href="dashboard.php" class="btn btn-secondary">← Dashboard</a>
</div>
<p style="font-size:.82rem;color:#5a6a7a;margin-bottom:1.25rem">Let us know you're coming so we can plan headcount.</p>

<?php if (empty($events)): ?>
  <p style="color:#9aa5b4">No upcoming events posted right now.</p>
<?php else: ?>
  <?php foreach ($events as $e): $rsvped = array_key_exists($e['id'], $my_rsvps); ?>
  <div class="rsvp-card">
    <strong style="color:#002554"><?= h($e['title']) ?></strong>
    <div class="rsvp-meta">
      <?php if ($e['event_date']): ?><?= date('M j, Y', strtotime($e['event_date'])) ?><?php if ($e['event_date_end']): ?> – <?= date('M j, Y', strtotime($e['event_date_end'])) ?><?php endif; ?><?php endif; ?>
      <?php if ($e['event_time']): ?> &bull; <?= h($e['event_time']) ?><?php endif; ?>
      <?php if ($e['location']): ?> &bull; <?= h($e['location']) ?><?php endif; ?>
    </div>
    <?php if ($e['description']): ?><div class="rsvp-meta"><?= h($e['description']) ?></div><?php endif; ?>

    <?php if ($rsvped): ?>
      <div class="rsvp-form">
        <span style="color:#1b5e20;font-weight:600;font-size:.85rem">✓ You're attending<?= $my_rsvps[$e['id']] > 0 ? ' with ' . (int)$my_rsvps[$e['id']] . ' guest' . ((int)$my_rsvps[$e['id']]!==1?'s':'') : '' ?></span>
        <form method="POST" style="margin:0">
          <?= csrf_field() ?><input type="hidden" name="action" value="cancel"><input type="hidden" name="event_id" value="<?= $e['id'] ?>">
          <button type="submit" class="btn btn-secondary btn-sm">Cancel RSVP</button>
        </form>
      </div>
    <?php else: ?>
      <form method="POST" class="rsvp-form">
        <?= csrf_field() ?><input type="hidden" name="action" value="rsvp"><input type="hidden" name="event_id" value="<?= $e['id'] ?>">
        <label style="font-weight:400;text-transform:none;letter-spacing:0;font-size:.85rem;margin:0">Guests <input type="number" name="guest_count" value="0" min="0" max="20"></label>
        <button type="submit" class="btn btn-primary btn-sm">I'm Coming</button>
      </form>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
<?php endif; ?>

<?php admin_footer(); ?>
