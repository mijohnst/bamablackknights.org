<?php
require_once __DIR__ . '/auth.php';
require_member_admin(); // officers and admins can graduate a class
$pdo = get_pdo();

// Commencement (May) doesn't line up with the club's July 1 fiscal-year
// rollover, so outgoing_class_year() is only a starting guess — officers
// can pick a different year if they're running this before July 1, right
// after an actual graduation ceremony.
$default_year = outgoing_class_year();

$year_counts = $pdo->query(
    "SELECT class_year, COUNT(*) AS cnt FROM members
     WHERE archived = 0 AND class_year REGEXP '^[0-9]{4}$'
     GROUP BY class_year ORDER BY class_year"
)->fetchAll(PDO::FETCH_KEY_PAIR);
if (!isset($year_counts[$default_year])) $year_counts[$default_year] = 0;
ksort($year_counts);

$target_year = $_POST['target_year'] ?? $_GET['target_year'] ?? $default_year;
if (!preg_match('/^\d{4}$/', $target_year)) $target_year = $default_year;
if (!isset($year_counts[$target_year])) $year_counts[$target_year] = 0;

$affected = $year_counts[$target_year];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $upd = $pdo->prepare("UPDATE members SET class_year = 'Graduate', archived = 1 WHERE archived = 0 AND class_year = ?");
    $upd->execute([$target_year]);
    flash('success', "$affected member(s) moved from Class of $target_year to Graduate.");
    header('Location: index.php'); exit;
}

admin_header('Graduate a Class');
?>

<div class="page-head">
  <h1>Graduate a Class</h1>
  <a href="index.php" class="btn btn-secondary">← Back</a>
</div>

<div class="card" style="max-width:600px">
  <p style="margin-bottom:1.25rem;color:#333">
    Run this after commencement each spring — pick the class that just graduated. Their records, dues
    history, and contact info are kept; the class year changes to <strong>Graduate</strong> and the
    members are <strong>archived</strong>, so they stop appearing in class-year lists, filters, Contact
    Lists, and automated emails alongside currently-enrolled classes.
  </p>

  <form method="GET" style="margin-bottom:1.25rem;display:flex;gap:.5rem;align-items:flex-end">
    <div class="form-group" style="margin:0">
      <label>Class to graduate</label>
      <select name="target_year" onchange="this.form.submit()">
        <?php foreach ($year_counts as $yr => $cnt): $cnt = (int)$cnt; ?>
          <option value="<?= h($yr) ?>" <?= $yr === $target_year ? 'selected' : '' ?>>
            Class of <?= h($yr) ?> (<?= $cnt ?> member<?= $cnt !== 1 ? 's' : '' ?>)
          </option>
        <?php endforeach; ?>
      </select>
    </div>
  </form>

  <?php if ($affected === 0): ?>
  <div style="background:#e8f5e9;border:1px solid #a5d6a7;border-radius:4px;padding:1rem 1.25rem;margin-bottom:1.5rem">
    <strong style="color:#1b5e20">No members currently in the Class of <?= h($target_year) ?>.</strong>
    <p style="color:#1b5e20;margin-top:.4rem;font-size:.9rem">Either they've already been graduated, or there's nothing to do yet.</p>
  </div>
  <?php else: ?>
  <div style="background:#fff3cd;border:1px solid #ffc107;border-radius:4px;padding:1rem 1.25rem;margin-bottom:1.5rem">
    <strong style="color:#5f4c00">⚠️ This action cannot be undone automatically.</strong>
    <p style="color:#5f4c00;margin-top:.4rem;font-size:.9rem">
      This will move all <strong><?= $affected ?> member(s)</strong> in the
      <strong>Class of <?= h($target_year) ?></strong> to <strong>Graduate</strong> status and
      <strong>archive</strong> them. A record can always be restored and edited back to a specific
      year manually if needed.
    </p>
  </div>
  <form method="POST">
    <?= csrf_field() ?>
    <input type="hidden" name="target_year" value="<?= h($target_year) ?>">
    <div style="display:flex;gap:.75rem;align-items:center">
      <button type="submit" class="btn btn-danger"
        onclick="return confirm('Move all <?= $affected ?> members from Class of <?= h($target_year) ?> to Graduate and archive them?')">
        Graduate the Class of <?= h($target_year) ?>
      </button>
      <a href="index.php" class="btn btn-secondary">Cancel</a>
    </div>
  </form>
  <?php endif; ?>
</div>

<?php admin_footer(); ?>
