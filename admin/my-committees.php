<?php
require_once __DIR__ . '/auth.php';
require_login();
$pdo     = get_pdo();
$user_id = $_SESSION['user_id'] ?? 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $selected = array_intersect((array)($_POST['committees'] ?? []), COMMITTEES);

    $pdo->prepare('DELETE FROM committee_interest WHERE user_id = ?')->execute([$user_id]);
    $ins = $pdo->prepare('INSERT INTO committee_interest (user_id, committee) VALUES (?,?)');
    foreach ($selected as $c) $ins->execute([$user_id, $c]);

    flash('success', empty($selected) ? 'Committee interests cleared.' : "Thanks! We'll reach out if we need a hand with " . implode(', ', $selected) . '.');
    header('Location: my-committees.php'); exit;
}

$mine = $pdo->prepare('SELECT committee FROM committee_interest WHERE user_id = ?');
$mine->execute([$user_id]);
$my_committees = $mine->fetchAll(PDO::FETCH_COLUMN);

admin_header('Committee Interest');
echo show_flash();
?>
<div class="page-head">
  <h1>Committee Interest</h1>
  <a href="dashboard.php" class="btn btn-secondary">← Dashboard</a>
</div>
<p style="font-size:.82rem;color:#5a6a7a;margin-bottom:1.25rem">Flag which areas you'd be willing to help with — officers will reach out when there's a specific need.</p>

<div class="card" style="max-width:480px">
  <form method="POST">
    <?= csrf_field() ?>
    <div class="form-group">
      <?php foreach (COMMITTEES as $c): ?>
      <label style="display:flex;align-items:center;gap:.5rem;font-weight:400;text-transform:none;letter-spacing:0;font-size:.9rem;cursor:pointer;margin-bottom:.5rem">
        <input type="checkbox" name="committees[]" value="<?= h($c) ?>" style="width:auto" <?= in_array($c, $my_committees) ? 'checked' : '' ?>>
        <?= h($c) ?>
      </label>
      <?php endforeach; ?>
    </div>
    <button type="submit" class="btn btn-primary">Save</button>
  </form>
</div>

<?php admin_footer(); ?>
