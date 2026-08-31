<?php
require_once __DIR__ . '/auth.php';
require_member_admin();

$errors = [];
$m = [];
$duplicates = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    foreach (FIELDS as $f) $m[$f] = trim($_POST[$f] ?? '');
    $m['parent1_is_board_member'] = isset($_POST['parent1_is_board_member']) ? 1 : 0;
    $m['parent2_is_board_member'] = isset($_POST['parent2_is_board_member']) ? 1 : 0;
    $dues_years = array_intersect((array)($_POST['dues_years'] ?? []), cadet_dues_years($m['class_year']));
    $m['membership_paid_years'] = implode(',', $dues_years); // reflected if the form needs to redisplay below

    if ($m['class_year'] === '') $errors[] = 'Class Year is required.';
    if ($m['cadet_last_name'] === '') $errors[] = 'Cadet Last Name is required.';
    if ($m['cadet_birthday'] === '') $m['cadet_birthday'] = null;

    $pdo = get_pdo();

    // Manual entry has no automatic dedup like the public application form
    // (membership-handler.php) does — warn (don't block, since same
    // last name + class year can legitimately be two different cadets)
    // if an active member already looks like this one.
    if (empty($errors)) {
        $cand = $pdo->prepare('SELECT id, cadet_first_name, cadet_last_name, cadet_suffix, al_region, parent1_first_name, parent1_last_name FROM members WHERE archived=0 AND class_year=?');
        $cand->execute([$m['class_year']]);
        $target_norm = normalize_name($m['cadet_last_name']);
        foreach ($cand->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (normalize_name($row['cadet_last_name']) === $target_norm) $duplicates[] = $row;
        }
    }

    if (empty($errors) && (!$duplicates || !empty($_POST['confirm_duplicate']))) {
        // Two separate writes (the new member row, then its dues years) —
        // wrapped in a transaction so a failure partway through can't leave
        // a member row committed with no dues state (or vice versa).
        $pdo->beginTransaction();
        $cols = implode(', ', array_map(fn($f) => "`$f`", FIELDS));
        $placeholders = implode(', ', array_map(fn($f) => ":$f", FIELDS));
        $stmt = $pdo->prepare("INSERT INTO members ($cols) VALUES ($placeholders)");
        $stmt->execute($m);
        save_dues_years($pdo, (int)$pdo->lastInsertId(), $dues_years);
        $pdo->commit();
        flash('success', 'Member added successfully.');
        header('Location: index.php'); exit;
    }
}

admin_header('Add Member');
?>
<div class="page-head">
  <h1>Add Member</h1>
  <a href="index.php" class="btn btn-secondary">← Back</a>
</div>

<?php if ($errors): ?>
  <div class="alert alert-error"><?= implode('<br>', array_map('htmlspecialchars', $errors)) ?></div>
<?php endif; ?>

<?php if ($duplicates): ?>
  <div class="alert alert-error">
    <strong>Possible duplicate<?= count($duplicates) > 1 ? 's' : '' ?>:</strong> an active member with the same last name and class year already exists.
    <ul style="margin:.5rem 0 0 1.25rem">
      <?php foreach ($duplicates as $d): ?>
        <li><a href="edit.php?id=<?= (int)$d['id'] ?>" target="_blank"><?= h(cadet_full_name($d)) ?></a> — Parent: <?= h(trim($d['parent1_first_name'] . ' ' . $d['parent1_last_name'])) ?><?= $d['al_region'] ? ' (' . h($d['al_region']) . ')' : '' ?></li>
      <?php endforeach; ?>
    </ul>
    <p style="margin-top:.5rem">Double-check this isn't the same family before saving. If you're sure this is a different cadet, submit again to save anyway.</p>
  </div>
<?php endif; ?>

<div class="card">
  <form method="POST">
    <?= csrf_field() ?>
    <?php if ($duplicates): ?><input type="hidden" name="confirm_duplicate" value="1"><?php endif; ?>
    <?php member_form($m, false) ?>
    <div style="display:flex;gap:.75rem;margin-top:.5rem">
      <button type="submit" class="btn <?= $duplicates ? 'btn-danger' : 'btn-primary' ?>"><?= $duplicates ? 'Save Anyway' : 'Save Member' ?></button>
      <a href="index.php" class="btn btn-secondary">Cancel</a>
    </div>
  </form>
</div>

<?php admin_footer(); ?>
