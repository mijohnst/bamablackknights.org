<?php
/**
 * Dues-only edit page — for roles that can mark dues (can_mark_dues(),
 * which includes a plain Treasurer) but can't reach the full Edit Member
 * form (member_form(), gated on can_manage_members(), which a Treasurer
 * alone doesn't pass). The roster's quick-actions only offer "mark
 * current year paid" or "mark all 4 years paid" — this page is where a
 * Treasurer picks exactly which years for a family that, say, paid two
 * specific non-default years. Touches membership_paid_years only; every
 * other member field is untouched and not even shown here.
 */
require_once __DIR__ . '/auth.php';
require_login();
if (!can_mark_dues()) { header('Location: index.php?denied=1'); exit; }
$pdo = get_pdo();

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if (!$id) { header('Location: index.php'); exit; }

$m = $pdo->prepare('SELECT * FROM members WHERE id = ?');
$m->execute([$id]);
$member = $m->fetch(PDO::FETCH_ASSOC);
if (!$member) { flash('error', 'Member not found.'); header('Location: index.php'); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $dues_years = array_intersect((array)($_POST['dues_years'] ?? []), cadet_dues_years($member['class_year']));
    save_dues_years($pdo, $id, $dues_years);
    flash('success', 'Dues years updated.');
    header('Location: index.php'); exit;
}

admin_header('Edit Dues Years');
?>
<div class="page-head">
  <h1>Dues — <?= h(cadet_last_name_suffixed($member)) ?>, <?= h(trim($member['cadet_first_name'] . ' ' . $member['cadet_middle_name'])) ?></h1>
  <a href="index.php" class="btn btn-secondary">← Back</a>
</div>

<div class="card" style="max-width:520px">
  <form method="POST">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= $id ?>">
    <?php dues_years_fieldset($member) ?>
    <div style="margin-top:1rem">
      <button type="submit" class="btn btn-primary">Save Dues</button>
      <a href="index.php" class="btn btn-secondary">Cancel</a>
    </div>
  </form>
</div>

<?php admin_footer(); ?>
