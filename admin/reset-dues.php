<?php
require_once __DIR__ . '/auth.php';
require_member_admin(); // officers and admins can refresh dues status
$pdo = get_pdo();

$new_year     = membership_year();
$active_years = current_class_years();
$ph           = implode(',', array_fill(0, count($active_years), '?'));

// membership_paid is a derived flag ("is the current club year in this
// member's paid-years set?") — nothing about the underlying
// membership_paid_years set is ever touched here. This just recomputes
// membership_paid/membership_paid_through for the new year, so a member
// who already has this year on file (e.g. paid ahead on a multi-year
// plan) correctly stays marked paid with zero manual bookkeeping, and one
// who doesn't correctly flips to unpaid — safe to run more than once.
$rows_stmt = $pdo->prepare("SELECT id, membership_paid_years FROM members WHERE archived = 0 AND class_year IN ($ph)");
$rows_stmt->execute($active_years);
$rows = $rows_stmt->fetchAll(PDO::FETCH_ASSOC);

$will_stay_paid = 0; $will_be_unpaid = 0;
foreach ($rows as $r) {
    if (in_array($new_year, parse_dues_years($r['membership_paid_years']), true)) $will_stay_paid++;
    else $will_be_unpaid++;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    foreach ($rows as $r) {
        save_dues_years($pdo, (int)$r['id'], parse_dues_years($r['membership_paid_years']), false);
    }
    flash('success', "Dues status refreshed for $new_year: $will_stay_paid member(s) still paid, $will_be_unpaid now unpaid.");
    header('Location: index.php'); exit;
}

admin_header('Start New Membership Year');
?>

<div class="page-head">
  <h1>Start New Membership Year</h1>
  <a href="index.php" class="btn btn-secondary">← Back</a>
</div>

<div class="card" style="max-width:600px">
  <div style="background:#e8f5e9;border:1px solid #a5d6a7;border-radius:4px;padding:1rem 1.25rem;margin-bottom:1.5rem">
    <strong style="color:#1b5e20">This is a safe, non-destructive refresh.</strong>
    <p style="color:#1b5e20;margin-top:.4rem;font-size:.9rem">
      No dues history is ever deleted — this only recalculates who counts as currently paid for
      <strong><?= h($new_year) ?></strong>
      (Class of <?= h($active_years[0]) ?>–<?= h(end($active_years)) ?> only), based on each member's own recorded dues years.
      Prep School and Graduate records are not touched. Safe to run more than once.
    </p>
  </div>
  <p style="margin-bottom:1.5rem;color:#333">
    Use this at the start of each July. <strong><?= $will_be_unpaid ?> member(s)</strong> will flip to Not Paid for
    <?= h($new_year) ?> (nothing on file for this year yet);
    <strong><?= $will_stay_paid ?> member(s)</strong> already have <?= h($new_year) ?> paid (e.g. a multi-year prepay) and will stay marked paid.
  </p>
  <form method="POST">
    <?= csrf_field() ?>
    <div style="display:flex;gap:.75rem;align-items:center">
      <button type="submit" class="btn btn-primary">
        Refresh Dues Status for <?= h($new_year) ?>
      </button>
      <a href="index.php" class="btn btn-secondary">Cancel</a>
    </div>
  </form>
</div>

<?php admin_footer(); ?>
