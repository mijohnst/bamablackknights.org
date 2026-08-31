<?php
require_once __DIR__ . '/auth.php';
require_member_admin();
$pdo = get_pdo();

$events = $pdo->query(
    "SELECT e.*,
        (SELECT COUNT(*) FROM event_rsvps WHERE event_id=e.id) AS rsvp_count,
        (SELECT COALESCE(SUM(guest_count),0) FROM event_rsvps WHERE event_id=e.id) AS guest_total
     FROM events e
     ORDER BY e.group_label DESC, e.event_date IS NULL, e.event_date ASC"
)->fetchAll(PDO::FETCH_ASSOC);

$rosters = [];
$rows = $pdo->query(
    "SELECT r.event_id, u.name, u.email, r.guest_count FROM event_rsvps r
     JOIN users u ON r.user_id = u.id ORDER BY r.created_at ASC"
)->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $r) $rosters[$r['event_id']][] = $r;

admin_header('Event RSVPs');
?>
<style>
.er-row{border-left:3px solid #1565c0;padding:.75rem .9rem;margin-bottom:.6rem;background:#fff;border-radius:0 4px 4px 0}
.er-meta{font-size:.78rem;color:#5a6a7a;margin-top:.2rem}
.er-roster{font-size:.78rem;color:#5a6a7a;margin-top:.5rem;border-top:1px solid #f0f2f5;padding-top:.5rem}
</style>

<div class="page-head">
  <h1>Event RSVPs</h1>
  <div style="display:flex;gap:.5rem">
    <a href="events.php" class="btn btn-secondary">Manage Events</a>
    <a href="dashboard.php" class="btn btn-secondary">← Dashboard</a>
  </div>
</div>
<p style="font-size:.82rem;color:#5a6a7a;margin-bottom:1.25rem">Headcounts from members RSVPing on their own dashboard.</p>

<?php if (empty($events)): ?>
  <p style="color:#9aa5b4">No events yet.</p>
<?php else: ?>
  <?php foreach ($events as $e):
    $count = (int)$e['rsvp_count'];
    $guests = (int)$e['guest_total'];
    if ($count === 0) continue;
  ?>
  <div class="er-row">
    <strong style="color:#002554"><?= h($e['title']) ?></strong>
    <span style="font-size:.78rem;color:#1565c0;font-weight:700"> — <?= $count ?> RSVP<?= $count!==1?'s':'' ?><?= $guests>0 ? " + $guests guest".($guests!==1?'s':'') : '' ?> (<?= $count + $guests ?> total)</span>
    <div class="er-meta"><?php if ($e['event_date']): ?><?= date('M j, Y', strtotime($e['event_date'])) ?><?php endif; ?></div>
    <?php if (!empty($rosters[$e['id']])): ?>
    <div class="er-roster">
      <?= implode(', ', array_map(fn($r) => h($r['name']) . ($r['guest_count'] > 0 ? ' (+' . (int)$r['guest_count'] . ')' : ''), $rosters[$e['id']])) ?>
    </div>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
  <?php if (!array_filter($events, fn($e) => (int)$e['rsvp_count'] > 0)): ?>
    <p style="color:#9aa5b4">No RSVPs yet.</p>
  <?php endif; ?>
<?php endif; ?>

<?php admin_footer(); ?>
