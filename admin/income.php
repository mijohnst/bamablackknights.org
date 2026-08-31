<?php
require_once __DIR__ . '/auth.php';
require_finance();
if (!is_treasurer() && !is_super_admin()) { header('Location: dashboard.php?denied=1'); exit; }
$pdo = get_pdo();

$can_edit = is_treasurer() || is_super_admin();

// ── Actions ─────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_edit) {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'add' || $action === 'update') {
        $date    = trim($_POST['entry_date']     ?? '');
        $source  = trim($_POST['source']         ?? '');
        $type    = $_POST['source_type']         ?? 'other';
        $amount  = round((float)str_replace(',','', $_POST['amount'] ?? '0'), 2);
        $method  = trim($_POST['payment_method'] ?? '');
        $desc    = trim($_POST['description']    ?? '');
        $notes   = trim($_POST['notes']          ?? '');
        if (!in_array($type, array_keys(INCOME_SOURCE_TYPES))) $type = 'other';

        // On failure, redirected back to the same add/edit form with
        // whatever was typed intact — re-populated below via
        // $_SESSION['income_old_input'] — rather than reloading blank and
        // losing everything the treasurer already entered.
        $redirect_back = 'income.php' . ($action === 'update' && !empty($_POST['id']) ? '?edit=' . (int)$_POST['id'] : '');
        if (!$date || !$source || $amount <= 0 || $amount > 99999.99) {
            $_SESSION['income_old_input'] = $_POST;
            flash('error','Date, source, and a positive amount are required.');
            header('Location: ' . $redirect_back); exit;
        }
        if ($action === 'add') {
            $pdo->prepare('INSERT INTO income_entries (entry_date,source,source_type,description,amount,payment_method,notes,received_by) VALUES (?,?,?,?,?,?,?,?)')
                ->execute([$date, $source, $type, $desc, $amount, $method, $notes, $_SESSION['user_id'] ?? null]);
            flash('success','Income entry added.');
        } else {
            $id = (int)($_POST['id'] ?? 0);
            $pdo->prepare('UPDATE income_entries SET entry_date=?,source=?,source_type=?,description=?,amount=?,payment_method=?,notes=? WHERE id=?')
                ->execute([$date, $source, $type, $desc, $amount, $method, $notes, $id]);
            flash('success','Entry updated.');
        }
        header('Location: income.php?' . http_build_query(['year'=>date('Y',strtotime($date))])); exit;

    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare('DELETE FROM income_entries WHERE id=?')->execute([$id]);
        flash('success','Entry deleted.');
        header('Location: income.php'); exit;
    }
}

// ── Filters ──────────────────────────────────────────────────────────────────
$year     = (int)($_GET['year'] ?? date('Y'));
$type_f   = $_GET['type'] ?? '';
if (!in_array($type_f, array_merge([''], array_keys(INCOME_SOURCE_TYPES)))) $type_f = '';

// Dues payments log to income_entries automatically now (see
// save_dues_years() in auth.php), so they need no separate handling here
// — they're just regular entries with source_type='dues'.
$ie_years    = $pdo->query("SELECT DISTINCT YEAR(entry_date) FROM income_entries")->fetchAll(PDO::FETCH_COLUMN);
$years_avail = array_values(array_unique(array_merge(array_map('intval', $ie_years), [(int)date('Y')])));
rsort($years_avail);

$where = ['YEAR(i.entry_date) = :year'];
$params = [':year' => $year];
if ($type_f !== '') { $where[] = 'i.source_type = :type'; $params[':type'] = $type_f; }

$stmt = $pdo->prepare("SELECT i.*, u.name AS received_by_name
    FROM income_entries i
    LEFT JOIN users u ON i.received_by = u.id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY i.entry_date DESC, i.id DESC");
$stmt->execute($params);
$entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

// CSV export
if (isset($_GET['export'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="income-' . $year . '.csv"');
    $out = fopen('php://output','w');
    fputcsv($out, ['Date','Source','Type','Description','Amount','Payment Method','Notes','Received By']);
    foreach ($entries as $e) {
        fputcsv($out, [$e['entry_date'],$e['source'],INCOME_SOURCE_TYPES[$e['source_type']],$e['description'],
                       $e['amount'],$e['payment_method'],$e['notes'],$e['received_by_name']??'']);
    }
    fclose($out); exit;
}

// Totals by type
$by_type = [];
foreach ($entries as $e) {
    $by_type[$e['source_type']] = ($by_type[$e['source_type']] ?? 0) + (float)$e['amount'];
}
$grand_income_total = array_sum($by_type);

// Edit mode
$editing = null;
if (isset($_GET['edit']) && $can_edit) {
    $s = $pdo->prepare('SELECT * FROM income_entries WHERE id=?');
    $s->execute([(int)$_GET['edit']]);
    $editing = $s->fetch(PDO::FETCH_ASSOC);
}

// Whatever was just typed into a submission that failed validation — takes
// priority over $editing's DB values below so a treasurer never has to
// retype a whole entry just because one field was invalid. $editing itself
// is left alone (it still controls Add-vs-Edit mode/heading); only the
// individual field VALUES shown in the form are affected.
$old_input = null;
if (!empty($_SESSION['income_old_input'])) {
    $old_input = $_SESSION['income_old_input'];
    unset($_SESSION['income_old_input']);
}
function income_field(?array $old, ?array $editing, string $key, string $default = ''): string {
    if ($old !== null) return (string)($old[$key] ?? $default);
    return (string)($editing[$key] ?? $default);
}

admin_header('Income Ledger');
echo show_flash();
?>
<style>
.type-pill{display:inline-block;padding:.15rem .5rem;border-radius:99px;font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em}
.income-table td,.income-table th{padding:.55rem .9rem}
.income-table th{font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#5a6a7a;background:#f7f9fc;white-space:nowrap}
.income-table td{border-top:1px solid #f0f2f5;font-size:.84rem;vertical-align:middle}
.income-table tr:hover td{background:#fafbfc}
.summary-chips{display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:1.25rem}
.summary-chip{padding:.4rem .85rem;border-radius:6px;font-size:.78rem;font-weight:700}
</style>

<div class="page-head">
  <h1>Income Ledger</h1>
  <div style="display:flex;gap:.5rem;flex-wrap:wrap">
    <?php $ep = array_merge(array_filter(['year'=>$year,'type'=>$type_f]), ['export'=>1]); ?>
    <a href="income.php?<?= http_build_query($ep) ?>" class="btn btn-secondary">Export CSV</a>
    <?php if ($can_edit): ?><a href="manual-receipts.php" class="btn btn-secondary">Manual Receipts</a><?php endif; ?>
    <a href="purchases.php" class="btn btn-secondary">← Finance</a>
  </div>
</div>

<!-- Year + Type filters -->
<form method="GET" style="display:flex;gap:.6rem;flex-wrap:wrap;align-items:flex-end;margin-bottom:1.25rem">
  <div class="form-group" style="margin:0">
    <label style="font-size:.72rem">Year</label>
    <select name="year" onchange="this.form.submit()">
      <?php foreach ($years_avail as $y): ?>
      <option value="<?= $y ?>" <?= $y==$year?'selected':'' ?>><?= $y ?></option>
      <?php endforeach; ?>
      <?php if (!in_array($year,$years_avail)): ?><option value="<?= $year ?>" selected><?= $year ?></option><?php endif; ?>
    </select>
  </div>
  <div class="form-group" style="margin:0">
    <label style="font-size:.72rem">Type</label>
    <select name="type" onchange="this.form.submit()">
      <option value="">All types</option>
      <?php foreach (INCOME_SOURCE_TYPES as $k => $v): ?>
      <option value="<?= $k ?>" <?= $type_f===$k?'selected':'' ?>><?= $v ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <noscript><button type="submit" class="btn btn-secondary btn-sm">Filter</button></noscript>
</form>

<!-- Summary chips -->
<div class="summary-chips">
  <div class="summary-chip" style="background:#002554;color:#fff">Combined Total: $<?= number_format($grand_income_total,2) ?></div>
  <?php foreach (INCOME_SOURCE_TYPES as $k => $v): if (isset($by_type[$k])): ?>
  <div class="summary-chip" style="background:<?= INCOME_TYPE_COLORS[$k] ?>22;color:<?= INCOME_TYPE_COLORS[$k] ?>"><?= $v ?>: $<?= number_format($by_type[$k],2) ?></div>
  <?php endif; endforeach; ?>
</div>

<script>
// Two-step delete: a plain confirm() is too easy to click through without
// reading, especially for a real financial record. Requires typing DELETE
// as a second, deliberate step before the form actually submits.
function confirmDeleteIncomeEntry(label) {
  if (!confirm('Delete this income entry?\n\n' + label + '\n\nThis cannot be undone.')) return false;
  var typed = prompt('Type DELETE (all caps) to confirm.');
  if (typed !== 'DELETE') { alert('Not confirmed — nothing was deleted.'); return false; }
  return true;
}
</script>

<?php if ($can_edit): ?>
<!-- Add / Edit form -->
<div class="card" style="max-width:920px;margin-bottom:1.75rem">
  <h2 style="margin-bottom:1rem"><?= $editing ? 'Edit Entry' : 'Add Income' ?></h2>
  <form method="POST">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="<?= $editing ? 'update' : 'add' ?>">
    <?php if ($editing): ?><input type="hidden" name="id" value="<?= $editing['id'] ?>"><?php endif; ?>
    <div class="form-row col-3">
      <div class="form-group">
        <label>Date <span style="color:#A6192E">*</span></label>
        <input type="date" name="entry_date" required value="<?= h(income_field($old_input, $editing, 'entry_date', date('Y-m-d'))) ?>">
      </div>
      <div class="form-group">
        <label>Type <span style="color:#A6192E">*</span></label>
        <select name="source_type">
          <?php $cur_type = income_field($old_input, $editing, 'source_type', 'other'); ?>
          <?php foreach (INCOME_SOURCE_TYPES as $k => $v): ?>
          <option value="<?= $k ?>" <?= $cur_type===$k?'selected':'' ?>><?= $v ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label>Amount <span style="color:#A6192E">*</span></label>
        <input name="amount" type="number" step="0.01" min="0.01" required placeholder="0.00" value="<?= h(income_field($old_input, $editing, 'amount')) ?>">
      </div>
    </div>
    <div class="form-row col-2">
      <div class="form-group">
        <label>Source / Payer Name <span style="color:#A6192E">*</span></label>
        <input name="source" required placeholder="e.g. John Smith, Alabama Power Co." value="<?= h(income_field($old_input, $editing, 'source')) ?>">
      </div>
      <div class="form-group">
        <label>Payment Method</label>
        <select name="payment_method">
          <option value="">—</option>
          <?php $cur_method = income_field($old_input, $editing, 'payment_method'); ?>
          <?php foreach (INCOME_PAYMENT_METHODS as $m): ?>
          <option value="<?= $m ?>" <?= $cur_method===$m?'selected':'' ?>><?= $m ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="form-row col-2">
      <div class="form-group">
        <label>Description</label>
        <input name="description" placeholder="Brief description" value="<?= h(income_field($old_input, $editing, 'description')) ?>">
      </div>
      <div class="form-group">
        <label>Notes <span style="font-weight:400;font-size:.72rem;color:#9aa5b4">optional</span></label>
        <input name="notes" placeholder="Additional notes" value="<?= h(income_field($old_input, $editing, 'notes')) ?>">
      </div>
    </div>
    <div style="display:flex;gap:.6rem">
      <button type="submit" class="btn btn-primary"><?= $editing ? 'Save Changes' : 'Add Entry' ?></button>
      <?php if ($editing): ?><a href="income.php?year=<?= $year ?>" class="btn btn-secondary">Cancel</a><?php endif; ?>
    </div>
  </form>
</div>
<?php endif; ?>

<!-- Entries table -->
<?php if (empty($entries)): ?>
  <p style="color:#9aa5b4">No income recorded for <?= $year ?><?= $type_f ? " (" . INCOME_SOURCE_TYPES[$type_f] . ")" : '' ?>.</p>
<?php else: ?>
<div class="card" style="padding:0;overflow-x:auto">
<table class="income-table" style="width:100%;border-collapse:collapse">
  <thead>
    <tr>
      <th>Date</th>
      <th>Source</th>
      <th>Type</th>
      <th>Description</th>
      <th style="text-align:right">Amount</th>
      <th>Method</th>
      <th>Received By</th>
      <?php if ($can_edit): ?><th>Actions</th><?php endif; ?>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($entries as $e): $tc = INCOME_TYPE_COLORS[$e['source_type']] ?? '#5a6a7a'; ?>
  <tr>
    <td style="white-space:nowrap"><?= date('M j, Y', strtotime($e['entry_date'])) ?></td>
    <td style="font-weight:600"><?= h($e['source']) ?></td>
    <td><span class="type-pill" style="background:<?= $tc ?>22;color:<?= $tc ?>"><?= INCOME_SOURCE_TYPES[$e['source_type']] ?></span></td>
    <td style="color:#5a6a7a"><?= h($e['description']) ?><?php if($e['notes']): ?><div style="font-size:.72rem;color:#9aa5b4"><?= h($e['notes']) ?></div><?php endif; ?></td>
    <td style="text-align:right;font-weight:700;color:#1b5e20;white-space:nowrap">$<?= number_format($e['amount'],2) ?></td>
    <td style="font-size:.78rem;color:#5a6a7a"><?= h($e['payment_method']) ?></td>
    <td style="font-size:.78rem;color:#5a6a7a;white-space:nowrap"><?= h($e['received_by_name'] ?? '—') ?></td>
    <?php if ($can_edit): ?>
    <td>
      <div class="btn-group">
        <a href="income.php?edit=<?= $e['id'] ?>&year=<?= $year ?>" class="btn btn-secondary btn-sm">Edit</a>
        <form method="POST" onsubmit="return confirmDeleteIncomeEntry(<?= h(json_encode($e['source'] . ' — $' . number_format($e['amount'],2) . ' (' . date('M j, Y', strtotime($e['entry_date'])) . ')')) ?>)" style="margin:0">
          <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $e['id'] ?>">
          <button type="submit" class="btn btn-danger btn-sm">Delete</button>
        </form>
      </div>
    </td>
    <?php endif; ?>
  </tr>
  <?php endforeach; ?>
  </tbody>
  <tfoot>
    <tr style="background:#f7f9fc;font-weight:700">
      <td colspan="4" style="text-align:right;padding:.6rem .9rem;font-size:.82rem">Total</td>
      <td style="text-align:right;padding:.6rem .9rem;color:#1b5e20">$<?= number_format($grand_income_total,2) ?></td>
      <td colspan="<?= $can_edit ? 3 : 2 ?>"></td>
    </tr>
  </tfoot>
</table>
</div>
<?php endif; ?>

<?php admin_footer(); ?>
