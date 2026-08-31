<?php
require_once __DIR__ . '/auth.php';
require_login();
if (!can_manage_members() && !is_secretary() && !is_treasurer()) { header('Location: dashboard.php?denied=1'); exit; }
$pdo = get_pdo();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';
    if ($action === 'delete') {
        $pdo->prepare('DELETE FROM parent_letters WHERE id = ?')->execute([(int)($_POST['id'] ?? 0)]);
        flash('success', 'Letter deleted.');
    } elseif ($action === 'delete_all') {
        $count = (int)$pdo->query('SELECT COUNT(*) FROM parent_letters')->fetchColumn();
        $pdo->exec('DELETE FROM parent_letters');
        flash('success', "Deleted all $count letter(s).");
    } elseif ($action === 'toggle_open') {
        // Same setting parent-letters-lookup.php/-save.php enforce server-side
        // and parent-letters.html reads via settings-feed.php for its closed
        // notice -- this button is the only place that value gets changed.
        $row = $pdo->query("SELECT setting_value FROM site_settings WHERE setting_key='parent_letters_open'")->fetch();
        $cur = $row ? (bool)(int)$row['setting_value'] : true;
        $new_val = $cur ? 0 : 1;
        $pdo->prepare("INSERT INTO site_settings (setting_key,setting_label,setting_value,setting_type) VALUES ('parent_letters_open','Parent Letters submissions open',?,'number') ON DUPLICATE KEY UPDATE setting_value=?")->execute([$new_val, $new_val]);
        flash('success', $cur ? 'Letter submissions closed on the public page.' : 'Letter submissions reopened on the public page.');
    }
    header('Location: parent-letters.php'); exit;
}

// Alphabetical by cadet last name so a printed batch lines up with
// envelopes sorted the same way.
$letters = $pdo->query(
    "SELECT pl.id, pl.letter_body, pl.created_at, pl.updated_at,
            m.cadet_first_name, m.cadet_middle_name, m.cadet_last_name, m.cadet_suffix,
            m.parent1_first_name, m.parent1_last_name
     FROM parent_letters pl
     JOIN members m ON m.id = pl.member_id
     ORDER BY m.cadet_last_name ASC, m.cadet_first_name ASC, pl.created_at ASC"
)->fetchAll(PDO::FETCH_ASSOC);

$open_row = $pdo->query("SELECT setting_value FROM site_settings WHERE setting_key='parent_letters_open'")->fetch();
$letters_open = $open_row ? (bool)(int)$open_row['setting_value'] : true;

admin_header('Parent Letters');
echo show_flash();
?>
<style>
.pl-row{background:#fff;border-radius:6px;box-shadow:0 1px 4px rgba(0,0,0,.08);padding:.9rem 1.1rem;margin-bottom:.6rem;display:flex;justify-content:space-between;gap:1rem;flex-wrap:wrap;align-items:center}
.pl-snippet{font-size:.82rem;color:#5a6a7a;margin-top:.25rem;max-width:520px;white-space:pre-wrap;overflow:hidden;text-overflow:ellipsis;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical}
</style>

<div class="page-head">
  <h1>Parent Letters</h1>
  <div style="display:flex;gap:.5rem;flex-wrap:wrap">
    <?php if (!empty($letters)): ?>
    <a href="parent-letters-pdf-all.php" class="btn btn-primary">⬇️ Download All as PDF (A–Z by Cadet Last Name)</a>
    <?php endif; ?>
    <form method="POST" style="margin:0">
      <?= csrf_field() ?><input type="hidden" name="action" value="toggle_open">
      <button type="submit" class="btn <?= $letters_open ? 'btn-secondary' : 'btn-primary' ?>">
        <?= $letters_open ? 'Close Submissions' : '✓ Reopen Submissions' ?>
      </button>
    </form>
    <?php if (!empty($letters)): ?>
    <form method="POST" style="margin:0" onsubmit="return confirmDeleteAllLetters(<?= count($letters) ?>)">
      <?= csrf_field() ?><input type="hidden" name="action" value="delete_all">
      <button type="submit" class="btn btn-danger">Delete All Letters</button>
    </form>
    <?php endif; ?>
    <a href="dashboard.php" class="btn btn-secondary">← Dashboard</a>
  </div>
</div>

<script>
// Two-step delete for the bulk action -- a plain confirm() is too easy to
// click through, and this permanently deletes every family's letter.
function confirmDeleteAllLetters(count) {
  if (!confirm('Permanently delete all ' + count + ' letter(s)? This cannot be undone.')) return false;
  var typed = prompt('Type DELETE (all caps) to confirm.');
  if (typed !== 'DELETE') { alert('Not confirmed — nothing was deleted.'); return false; }
  return true;
}
</script>
<?php if (!$letters_open): ?>
<div class="alert alert-error" style="margin-bottom:1.25rem">Letter submissions are currently closed on the public page — parents see a closed notice instead of the lookup/write form.</div>
<?php endif; ?>
<p style="font-size:.82rem;color:#5a6a7a;margin-bottom:1.25rem">
  Letters parents wrote to their cadets via the public Parent Letters page. Sorted alphabetically by cadet last name — use "Print All" to get every letter as one PDF in that order, ready to match against alphabetized envelopes.
</p>

<?php if (empty($letters)): ?>
  <p style="color:#9aa5b4">No letters saved yet.</p>
<?php else: ?>
  <?php foreach ($letters as $l):
    $cadet_name  = trim($l['cadet_first_name'] . ' ' . $l['cadet_middle_name'] . ' ' . $l['cadet_last_name'] . ' ' . $l['cadet_suffix']);
    $cadet_name  = preg_replace('/\s+/', ' ', $cadet_name);
    $parent_name = trim($l['parent1_first_name'] . ' ' . $l['parent1_last_name']);
    $saved       = date('M j, Y', strtotime($l['updated_at']));
  ?>
  <div class="pl-row">
    <div style="flex:1;min-width:0">
      <strong style="color:#002554"><?= h($cadet_name) ?></strong>
      <span style="font-size:.78rem;color:#9aa5b4"> — from <?= h($parent_name) ?> · saved <?= $saved ?></span>
      <div class="pl-snippet"><?= h($l['letter_body']) ?></div>
    </div>
    <form method="POST" style="margin:0" onsubmit="return confirm('Delete this letter? This cannot be undone.')">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="delete">
      <input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
      <button type="submit" class="btn btn-danger btn-sm">Delete</button>
    </form>
  </div>
  <?php endforeach; ?>
<?php endif; ?>

<?php admin_footer(); ?>
