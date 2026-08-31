<?php
require_once __DIR__ . '/auth.php';
require_login();
$pdo  = get_pdo();

$user = $pdo->prepare('SELECT * FROM users WHERE id = ?');
$user->execute([$_SESSION['user_id'] ?? 0]);
$user = $user->fetch(PDO::FETCH_ASSOC);

$member = $user ? find_linked_member($pdo, $user) : null;

admin_header('My Membership');
?>
<style>
.mm-card{background:#fff;border-radius:6px;box-shadow:0 1px 4px rgba(0,0,0,.1);padding:1.5rem;max-width:480px}
.mm-row{display:flex;justify-content:space-between;padding:.5rem 0;border-bottom:1px solid #f0f2f5;font-size:.9rem}
.mm-row:last-child{border-bottom:none}
.mm-label{color:#5a6a7a}
.mm-value{font-weight:700;color:#1a2332}
</style>

<div class="page-head">
  <h1>My Membership</h1>
  <a href="dashboard.php" class="btn btn-secondary">← Dashboard</a>
</div>

<?php if (!$member): ?>
  <div class="alert alert-error" style="max-width:480px">
    We couldn't find a membership record linked to your account. If you believe this is an error, contact
    <a href="mailto:info@alabamafalcons.org">info@alabamafalcons.org</a>.
  </div>
<?php else: ?>
  <div class="mm-card">
    <div class="mm-row">
      <span class="mm-label">Cadet</span>
      <span class="mm-value"><?= h(cadet_full_name($member)) ?></span>
    </div>
    <div class="mm-row">
      <span class="mm-label">Class Year</span>
      <span class="mm-value"><?= h($member['class_year']) ?></span>
    </div>
    <div class="mm-row">
      <span class="mm-label">Dues Status</span>
      <span class="mm-value" style="color:<?= $member['membership_paid'] ? '#1b5e20' : '#c62828' ?>">
        <?= $member['membership_paid'] ? '✓ Paid' : '✗ Unpaid' ?>
      </span>
    </div>
    <?php $mm_years = parse_dues_years($member['membership_paid_years'] ?? ''); ?>
    <?php if ($mm_years): ?>
    <div class="mm-row">
      <span class="mm-label">Years Paid</span>
      <span class="mm-value"><?= h(implode(', ', $mm_years)) ?></span>
    </div>
    <div class="mm-row">
      <span class="mm-label">Covered Through</span>
      <span class="mm-value"><?= h($member['membership_paid_through']) ?></span>
    </div>
    <?php endif; ?>
  </div>

  <?php if (!$member['membership_paid']): ?>
  <div class="hbox mt2" style="max-width:480px">
    Your dues aren't marked paid yet. <a href="/payment.html" style="font-weight:700">Pay dues →</a>
  </div>
  <?php endif; ?>
  <p style="font-size:.8rem;color:#9aa5b4;margin-top:1rem;max-width:480px">
    Need to update your family's contact info? Use the <a href="/update.html">member update form</a>.
  </p>
<?php endif; ?>

<?php admin_footer(); ?>
