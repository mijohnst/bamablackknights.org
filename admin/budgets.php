<?php
require_once __DIR__ . '/auth.php';
require_finance();
if (!is_treasurer() && !is_super_admin()) { header('Location: dashboard.php?denied=1'); exit; }
$pdo    = get_pdo();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $event  = trim($_POST['event']        ?? '');
        $budget = (float)str_replace(',', '', $_POST['budget'] ?? '0');
        $fyear  = trim($_POST['fiscal_year']  ?? '');
        $notes  = trim($_POST['notes']        ?? '');
        $id     = (int)($_POST['id']          ?? 0);

        if (!$event)      $errors[] = 'Event name is required.';
        if ($budget <= 0) $errors[] = 'Budget must be greater than zero.';

        if (empty($errors)) {
            if ($id) {
                $pdo->prepare('UPDATE event_budgets SET event=?,budget=?,fiscal_year=?,notes=?,last_notified_pct=0,updated_at=NOW() WHERE id=?')
                    ->execute([$event,$budget,$fyear,$notes,$id]);
                flash('success','Budget updated.');
            } else {
                $pdo->prepare('INSERT INTO event_budgets (event,budget,fiscal_year,notes) VALUES (?,?,?,?)')
                    ->execute([$event,$budget,$fyear,$notes]);
                flash('success','Budget added.');
            }
            header('Location: budgets.php'); exit;
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $pdo->prepare('DELETE FROM event_budgets WHERE id=?')->execute([$id]);
            flash('success','Budget deleted.');
        }
        header('Location: budgets.php'); exit;
    }
}

$edit = null;
if (isset($_GET['edit'])) {
    $s = $pdo->prepare('SELECT * FROM event_budgets WHERE id=?');
    $s->execute([(int)$_GET['edit']]);
    $edit = $s->fetch();
}

// Budgets with actual spend
$current_year = date('Y');
$budgets = $pdo->query(
    "SELECT b.*,
     COALESCE((SELECT SUM(p.amount_total) FROM purchases p
               WHERE p.event=b.event AND YEAR(p.purchase_date)=
               CASE WHEN b.fiscal_year='' THEN YEAR(NOW()) ELSE b.fiscal_year END),0) as spent
     FROM event_budgets b ORDER BY b.event"
)->fetchAll();

// Categories with no CURRENT-year budget cap get a synthetic, uncapped row
// (id null marks it as such below) so spend is still visible without
// forcing a treasurer to set a budget just to see where money's going. A
// category whose only budget row is for some other/past fiscal_year still
// counts as uncapped for this year. Real event_budgets rows keep their
// exact behavior/columns above untouched.
$capped_events = array_unique(array_column(array_filter($budgets, function($b) use ($current_year) {
    return $b['fiscal_year'] === '' || $b['fiscal_year'] == $current_year;
}), 'event'));
$spend_stmt = $pdo->prepare('SELECT COALESCE(SUM(amount_total),0) FROM purchases WHERE event = ? AND YEAR(purchase_date) = YEAR(NOW())');
foreach (PURCHASE_EVENTS as $e) {
    if ($e === '' || in_array($e, $capped_events, true)) continue;
    $spend_stmt->execute([$e]);
    $budgets[] = ['id' => null, 'event' => $e, 'fiscal_year' => '', 'budget' => 0, 'notes' => '', 'spent' => (float)$spend_stmt->fetchColumn()];
}
usort($budgets, function($a, $b) { return strcmp($a['event'], $b['event']); });

admin_header('Event Budgets');
echo show_flash();
?>

<div class="page-head">
  <h1>Event Budgets</h1>
  <div style="display:flex;gap:.5rem">
    <?php if (!$edit): ?><a href="budgets.php?edit=new" class="btn btn-primary">+ Add Budget</a><?php endif; ?>
    <a href="purchases.php" class="btn btn-secondary">← Finance</a>
  </div>
</div>
<p style="font-size:.78rem;color:#9aa5b4;margin-top:-.75rem;margin-bottom:1.25rem">Every purchase category is listed with what's been spent this year, even before you set a budget cap for it.</p>

<?php if (!empty($errors)): ?>
  <div class="alert alert-error"><?= implode('<br>', array_map('htmlspecialchars',$errors)) ?></div>
<?php endif; ?>

<?php if ($edit !== null || isset($_GET['edit'])): ?>
<div class="card" style="max-width:480px;margin-bottom:1.5rem">
  <h2 style="margin-bottom:1.25rem"><?= $edit ? 'Edit Budget' : 'Add Budget' ?></h2>
  <form method="POST">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <?php if ($edit): ?><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>"><?php endif; ?>
    <div class="form-row col-2">
      <div class="form-group">
        <label>Event *</label>
        <select name="event">
          <?php $prefill_event = $edit['event'] ?? ($_GET['event'] ?? ''); ?>
          <?php foreach (PURCHASE_EVENTS as $e): if (!$e) continue; ?>
            <option value="<?= h($e) ?>" <?= $prefill_event===$e?'selected':''?>><?= h($e) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label>Budget Amount *</label>
        <input type="number" name="budget" step="0.01" min="0.01" value="<?= h($edit['budget'] ?? '') ?>" placeholder="0.00" required>
      </div>
    </div>
    <div class="form-group">
      <label>Fiscal Year <span style="font-weight:400;text-transform:none;letter-spacing:0;font-size:.72rem;color:#9aa5b4">leave blank for current year</span></label>
      <input name="fiscal_year" value="<?= h($edit['fiscal_year'] ?? '') ?>" placeholder="e.g. 2026">
    </div>
    <div class="form-group">
      <label>Notes</label>
      <textarea name="notes" rows="2"><?= h($edit['notes'] ?? '') ?></textarea>
    </div>
    <div style="display:flex;gap:.75rem">
      <button type="submit" class="btn btn-primary"><?= $edit ? 'Save Changes' : 'Add Budget' ?></button>
      <a href="budgets.php" class="btn btn-secondary">Cancel</a>
    </div>
  </form>
</div>
<?php endif; ?>

<div class="card" style="padding:0;overflow-x:auto">
<table>
  <thead>
    <tr>
      <th>Event</th>
      <th>Fiscal Year</th>
      <th style="text-align:right">Budget</th>
      <th style="text-align:right">Spent</th>
      <th style="text-align:right">Remaining</th>
      <th>Progress</th>
      <th>Notes</th>
      <th>Actions</th>
    </tr>
  </thead>
  <tbody>
  <?php if (empty($budgets)): ?>
    <tr><td colspan="8" style="text-align:center;padding:2rem;color:#5a6a7a">No budgets set. Add one above.</td></tr>
  <?php endif; ?>
  <?php foreach ($budgets as $b):
    $uncapped = $b['id'] === null;
    $pct  = $b['budget'] > 0 ? min(100, round($b['spent']/$b['budget']*100)) : 0;
    $rem  = $b['budget'] - $b['spent'];
    $over = !$uncapped && $rem < 0;
  ?>
  <tr>
    <td><strong><?= h($b['event']) ?></strong></td>
    <td style="color:#5a6a7a"><?= h($b['fiscal_year'] ?: 'Current') ?></td>
    <td style="text-align:right"><?= $uncapped ? '<span style="color:#9aa5b4">— no cap set</span>' : '$' . number_format($b['budget'],2) ?></td>
    <td style="text-align:right">$<?= number_format($b['spent'],2) ?></td>
    <td style="text-align:right;font-weight:700;color:<?= $over?'#A6192E':'#1b5e20' ?>"><?= $uncapped ? '<span style="color:#9aa5b4;font-weight:400">—</span>' : ($over?'-':'') . '$' . number_format(abs($rem),2) ?></td>
    <td style="min-width:120px">
      <?php if ($uncapped): ?>
        <span style="font-size:.78rem;color:#9aa5b4">No budget set</span>
      <?php else: ?>
        <div style="background:#e1e5eb;border-radius:99px;height:8px;overflow:hidden">
          <div style="height:100%;width:<?= $pct ?>%;background:<?= $pct>=100?'#A6192E':($pct>=75?'#f57c00':'#1b5e20') ?>;border-radius:99px"></div>
        </div>
        <div style="font-size:.7rem;color:#9aa5b4;margin-top:.2rem"><?= $pct ?>%</div>
      <?php endif; ?>
    </td>
    <td style="font-size:.78rem;color:#5a6a7a;max-width:180px"><?= h($b['notes']) ?></td>
    <td class="actions">
      <?php if ($uncapped): ?>
      <a href="budgets.php?edit=new&event=<?= urlencode($b['event']) ?>" class="btn btn-secondary btn-sm">+ Set Budget</a>
      <?php else: ?>
      <div class="btn-group">
        <a href="budgets.php?edit=<?= $b['id'] ?>" class="btn btn-secondary btn-sm">Edit</a>
        <form method="POST" onsubmit="return confirm('Delete this budget?')" style="margin:0">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="id" value="<?= $b['id'] ?>">
          <button type="submit" class="btn btn-danger btn-sm">Delete</button>
        </form>
      </div>
      <?php endif; ?>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<?php admin_footer(); ?>
