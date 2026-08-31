<?php
require_once __DIR__ . '/auth.php';
require_member_admin();
$pdo = get_pdo();

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if (!$id) { header('Location: index.php'); exit; }

$m = $pdo->prepare('SELECT * FROM members WHERE id = ?');
$m->execute([$id]);
$member = $m->fetch();
if (!$member) { flash('error', 'Member not found.'); header('Location: index.php'); exit; }

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    // Captured before the FIELDS loop below overwrites $member['class_year']
    // with whatever was just submitted — dues years must be validated and
    // priced against the class_year the on-screen checkboxes were actually
    // rendered against, not a class_year this same save might also change.
    $old_class_year = $member['class_year'];
    foreach (FIELDS as $f) $member[$f] = trim($_POST[$f] ?? '');
    $member['parent1_is_board_member'] = isset($_POST['parent1_is_board_member']) ? 1 : 0;
    $member['parent2_is_board_member'] = isset($_POST['parent2_is_board_member']) ? 1 : 0;
    $dues_years = array_intersect((array)($_POST['dues_years'] ?? []), cadet_dues_years($old_class_year));
    $member['membership_paid_years'] = implode(',', $dues_years); // reflected if the form needs to redisplay below
    if ($member['cadet_birthday'] === '') $member['cadet_birthday'] = null;

    if ($member['class_year'] === '') $errors[] = 'Class Year is required.';
    if ($member['cadet_last_name'] === '') $errors[] = 'Cadet Last Name is required.';

    if (empty($errors)) {
        // Two separate writes (dues years, then the rest of the fields) —
        // wrapped in a transaction so a failure partway through can't leave
        // one committed without the other.
        $pdo->beginTransaction();
        // Save dues years first, while the DB's class_year/membership_paid_years
        // still reflect the pre-edit state — save_dues_years() reads that
        // "before" state itself to compute the income delta, and it must
        // match what the checkboxes were actually rendered against, not a
        // class_year this same save is about to change below.
        save_dues_years($pdo, $id, $dues_years);
        $set = implode(', ', array_map(fn($f) => "`$f` = :$f", FIELDS));
        $stmt = $pdo->prepare("UPDATE members SET $set WHERE id = :id");
        $params = [];
        foreach (FIELDS as $f) $params[$f] = $member[$f];
        $params['id'] = $id;
        $stmt->execute($params);
        $pdo->commit();
        flash('success', 'Member updated successfully.');
        header('Location: index.php'); exit;
    }
}

admin_header('Edit Member');
?>
<div class="page-head">
  <h1>Edit — <?= h(cadet_last_name_suffixed($member)) ?>, <?= h(trim($member['cadet_first_name'] . ' ' . $member['cadet_middle_name'])) ?></h1>
  <a href="index.php" class="btn btn-secondary">← Back</a>
</div>

<?php if ($errors): ?>
  <div class="alert alert-error"><?= implode('<br>', array_map('htmlspecialchars', $errors)) ?></div>
<?php endif; ?>

<div class="card">
  <form method="POST">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= $id ?>">
    <?php member_form($member, true) ?>
    <div style="display:flex;gap:.75rem;margin-top:.5rem">
      <button type="submit" class="btn btn-primary">Save Changes</button>
      <a href="index.php" class="btn btn-secondary">Cancel</a>
    </div>
  </form>
</div>

<?php admin_footer(); ?>
