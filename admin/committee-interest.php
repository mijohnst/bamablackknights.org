<?php
require_once __DIR__ . '/auth.php';
require_member_admin();
$pdo = get_pdo();

$rows = $pdo->query(
    "SELECT ci.committee, u.name, u.email FROM committee_interest ci
     JOIN users u ON ci.user_id = u.id ORDER BY ci.committee, u.name"
)->fetchAll(PDO::FETCH_ASSOC);

$by_committee = [];
foreach ($rows as $r) $by_committee[$r['committee']][] = $r;

admin_header('Committee Interest');
?>
<style>
.ci-group{margin-bottom:1.25rem}
.ci-head{font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:#003594;border-bottom:2px solid #003594;padding-bottom:.25rem;margin-bottom:.6rem}
.ci-member{display:flex;justify-content:space-between;padding:.4rem .1rem;font-size:.85rem;border-bottom:1px solid #f0f2f5}
</style>

<div class="page-head">
  <h1>Committee Interest</h1>
  <a href="dashboard.php" class="btn btn-secondary">← Dashboard</a>
</div>
<p style="font-size:.82rem;color:#5a6a7a;margin-bottom:1.25rem">Members who've flagged interest in helping with each area, from their own dashboard.</p>

<?php if (empty($by_committee)): ?>
  <p style="color:#9aa5b4">No one has flagged committee interest yet.</p>
<?php else: ?>
  <?php foreach ($by_committee as $committee => $members): ?>
  <div class="ci-group">
    <div class="ci-head"><?= h($committee) ?> — <?= count($members) ?></div>
    <?php foreach ($members as $m): ?>
    <div class="ci-member">
      <span><?= h($m['name']) ?></span>
      <a href="mailto:<?= h($m['email']) ?>" style="color:#5a6a7a"><?= h($m['email']) ?></a>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endforeach; ?>
<?php endif; ?>

<?php admin_footer(); ?>
