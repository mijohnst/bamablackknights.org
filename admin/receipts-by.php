<?php
require_once __DIR__ . '/auth.php';
require_finance();
if (!is_treasurer() && !is_super_admin() && !is_officer()) { header('Location: dashboard.php?denied=1'); exit; }
$pdo = get_pdo();

$by     = ($_GET['by']     ?? 'event') === 'vendor' ? 'vendor' : 'event';
$year   = (int)($_GET['year']   ?? date('Y'));
$status = $_GET['status'] ?? '';
if (!in_array($status, ['','pending','approved','submitted','paid'])) $status = '';

// Available years
$years = $pdo->query("SELECT DISTINCT YEAR(purchase_date) y FROM purchases ORDER BY y DESC")->fetchAll(PDO::FETCH_COLUMN);
if (empty($years)) $years = [(int)date('Y')];
if ($year < 2020 || $year > 2100) $year = (int)$years[0];

// Fetch purchases
$where  = ['YEAR(p.purchase_date) = :year'];
$params = [':year' => $year];
if ($status !== '') { $where[] = 'p.status = :status'; $params[':status'] = $status; }

$order_col = $by === 'vendor' ? 'COALESCE(NULLIF(p.vendor,\'\'),\'(No Vendor)\')' : 'COALESCE(NULLIF(p.event,\'\'),\'(No Event)\')';

$stmt = $pdo->prepare("SELECT p.*, u.name AS submitted_by_name
    FROM purchases p
    LEFT JOIN users u ON p.submitted_by = u.id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY $order_col ASC, p.purchase_date DESC, p.id DESC");
$stmt->execute($params);
$all = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Group in PHP
$groups = [];
foreach ($all as $p) {
    $key = $by === 'vendor'
        ? (trim($p['vendor']) !== '' ? $p['vendor'] : '(No Vendor)')
        : (trim($p['event'])  !== '' ? $p['event']  : '(No Event)');
    $groups[$key][] = $p;
}
ksort($groups);

$status_colors = ['pending'=>'#f57c00','approved'=>'#1b5e20','submitted'=>'#6a1b9a','paid'=>'#003594'];

admin_header('Receipts by ' . ucfirst($by));
?>
<style>
.rb-tabs{display:flex;gap:.4rem;margin-bottom:1.5rem;flex-wrap:wrap}
.rb-tab{padding:.45rem 1.1rem;border-radius:5px;font-size:.82rem;font-weight:700;letter-spacing:.03em;text-decoration:none;border:2px solid transparent;transition:all .15s}
.rb-tab.active{background:#002554;color:#fff;border-color:#002554}
.rb-tab:not(.active){background:#fff;color:#002554;border-color:#d0d8e4}
.rb-tab:not(.active):hover{border-color:#002554;text-decoration:none;color:#002554}
.group-card{background:#fff;border-radius:8px;box-shadow:0 1px 4px rgba(0,0,0,.08);margin-bottom:.85rem;overflow:hidden}
.group-header{display:flex;align-items:center;justify-content:space-between;padding:.9rem 1.25rem;cursor:pointer;user-select:none;gap:1rem;flex-wrap:wrap}
.group-header:hover{background:#f7f9fc}
.group-header.open{border-bottom:2px solid #e1e8f0}
.group-name{font-family:var(--font-display,Georgia,serif);font-size:.95rem;font-weight:700;color:#002554;letter-spacing:.02em}
.group-meta{display:flex;align-items:center;gap:.75rem;flex-wrap:wrap}
.group-total{font-size:.9rem;font-weight:700;color:#002554}
.group-count{font-size:.75rem;color:#9aa5b4}
.group-chevron{font-size:1rem;color:#9aa5b4;transition:transform .2s;flex-shrink:0}
.group-header.open .group-chevron{transform:rotate(180deg)}
.group-body{display:none;overflow-x:auto}
.group-body.open{display:block}
table.rb-table{width:100%;border-collapse:collapse;font-size:.82rem}
.rb-table th{padding:.55rem 1rem;text-align:left;font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#5a6a7a;background:#f7f9fc;white-space:nowrap}
.rb-table td{padding:.6rem 1rem;border-top:1px solid #f0f2f5;vertical-align:middle}
.rb-table tr:hover td{background:#fafbfc}
.status-pill{display:inline-block;padding:.15rem .5rem;border-radius:99px;font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em}
.no-receipt{font-size:.72rem;color:#9aa5b4;font-style:italic}
.receipt-link{font-size:.78rem;color:#003594;font-weight:600;text-decoration:none}
.receipt-link:hover{text-decoration:underline}
.group-footer td{background:#f7f9fc;font-weight:700;font-size:.82rem;padding:.55rem 1rem;border-top:2px solid #e1e8f0}
.no-receipt-badge{display:inline-block;background:#fff3cd;color:#856404;border:1px solid #ffc107;border-radius:3px;font-size:.65rem;padding:.1rem .35rem;font-weight:700;letter-spacing:.03em}
</style>

<div class="page-head">
  <h1>Receipts by <?= ucfirst($by) ?></h1>
  <a href="dashboard.php" class="btn btn-secondary">← Dashboard</a>
</div>

<!-- Mode tabs -->
<div class="rb-tabs">
  <?php
  $qs_event  = http_build_query(['by'=>'event',  'year'=>$year, 'status'=>$status]);
  $qs_vendor = http_build_query(['by'=>'vendor', 'year'=>$year, 'status'=>$status]);
  ?>
  <a href="receipts-by.php?<?= $qs_event  ?>" class="rb-tab <?= $by==='event' ?'active':'' ?>">By Event</a>
  <a href="receipts-by.php?<?= $qs_vendor ?>" class="rb-tab <?= $by==='vendor'?'active':'' ?>">By Vendor</a>
</div>

<!-- Filters -->
<form method="GET" style="display:flex;gap:.6rem;flex-wrap:wrap;align-items:flex-end;margin-bottom:1.5rem">
  <input type="hidden" name="by" value="<?= h($by) ?>">
  <div class="form-group" style="margin:0">
    <label style="font-size:.72rem">Year</label>
    <select name="year" onchange="this.form.submit()" style="padding:.35rem .55rem;font-size:.85rem">
      <?php foreach ($years as $y): ?>
      <option value="<?= $y ?>" <?= $y==$year?'selected':'' ?>><?= $y ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="form-group" style="margin:0">
    <label style="font-size:.72rem">Status</label>
    <select name="status" onchange="this.form.submit()" style="padding:.35rem .55rem;font-size:.85rem">
      <option value=""      <?= $status===''           ?'selected':'' ?>>All statuses</option>
      <option value="pending"   <?= $status==='pending'  ?'selected':'' ?>>Pending</option>
      <option value="approved"  <?= $status==='approved' ?'selected':'' ?>>Approved</option>
      <option value="submitted" <?= $status==='submitted'?'selected':'' ?>>Submitted</option>
      <option value="paid"      <?= $status==='paid'     ?'selected':'' ?>>Paid</option>
    </select>
  </div>
  <noscript><button type="submit" class="btn btn-secondary btn-sm">Filter</button></noscript>
</form>

<?php if (empty($groups)): ?>
  <p style="color:#9aa5b4">No purchases found for <?= $year ?><?= $status ? " ($status)" : '' ?>.</p>
<?php else: ?>

<!-- Summary strip -->
<?php
$grand_total = array_sum(array_map(fn($g) => array_sum(array_column($g,'amount_total')), $groups));
$no_receipt_count = count(array_filter($all, fn($p) => empty($p['receipt_filename'])));
?>
<div style="display:flex;gap:.75rem;flex-wrap:wrap;margin-bottom:1.25rem;font-size:.82rem;color:#5a6a7a">
  <span><?= count($groups) ?> <?= $by === 'vendor' ? 'vendor' : 'event' ?><?= count($groups)!==1?'s':'' ?></span>
  <span>·</span>
  <span><?= count($all) ?> purchase<?= count($all)!==1?'s':'' ?></span>
  <span>·</span>
  <span style="font-weight:700;color:#002554">$<?= number_format($grand_total,2) ?> total</span>
  <?php if ($no_receipt_count > 0): ?>
  <span>·</span>
  <span style="color:#856404">⚠ <?= $no_receipt_count ?> missing receipt<?= $no_receipt_count!==1?'s':'' ?></span>
  <?php endif; ?>
</div>

<!-- Groups -->
<?php foreach ($groups as $group_name => $rows):
  $group_total   = array_sum(array_column($rows, 'amount_total'));
  $group_missing = count(array_filter($rows, fn($p) => empty($p['receipt_filename'])));
  $safe_id       = 'g-' . preg_replace('/[^a-z0-9]/i', '-', $group_name);
?>
<div class="group-card">
  <div class="group-header" onclick="toggleGroup('<?= $safe_id ?>')" id="hdr-<?= $safe_id ?>">
    <div class="group-name"><?= h($group_name) ?></div>
    <div class="group-meta">
      <?php if ($group_missing > 0): ?>
      <span class="no-receipt-badge">⚠ <?= $group_missing ?> no receipt</span>
      <?php endif; ?>
      <span class="group-count"><?= count($rows) ?> purchase<?= count($rows)!==1?'s':'' ?></span>
      <span class="group-total">$<?= number_format($group_total,2) ?></span>
      <span class="group-chevron">&#9660;</span>
    </div>
  </div>
  <div class="group-body" id="<?= $safe_id ?>">
    <table class="rb-table">
      <thead>
        <tr>
          <th>Date</th>
          <?= $by === 'event' ? '<th>Vendor</th>' : '<th>Event</th>' ?>
          <th>Description</th>
          <th style="text-align:right">Amount</th>
          <th>Status</th>
          <th>Submitted By</th>
          <th>Receipt</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $p):
          $sc = $status_colors[$p['status']] ?? '#5a6a7a';
        ?>
        <tr>
          <td style="white-space:nowrap"><?= date('M j, Y', strtotime($p['purchase_date'])) ?></td>
          <td><?= h($by === 'event' ? $p['vendor'] : $p['event']) ?></td>
          <td>
            <?= h($p['description']) ?>
            <?php if ($p['order_number']): ?><span style="font-size:.72rem;color:#9aa5b4"> #<?= h($p['order_number']) ?></span><?php endif; ?>
            <?php if ($p['notes']): ?><div style="font-size:.72rem;color:#9aa5b4;margin-top:.1rem"><?= h($p['notes']) ?></div><?php endif; ?>
          </td>
          <td style="text-align:right;white-space:nowrap;font-weight:600">$<?= number_format($p['amount_total'],2) ?></td>
          <td><span class="status-pill" style="background:<?= $sc ?>22;color:<?= $sc ?>"><?= ucfirst($p['status']) ?></span></td>
          <td style="white-space:nowrap"><?= h($p['submitted_by_name'] ?? '—') ?></td>
          <td>
            <?php if (!empty($p['receipt_filename'])): ?>
              <a href="receipt-view.php?id=<?= (int)$p['id'] ?>" target="_blank" class="receipt-link">View Receipt</a>
            <?php else: ?>
              <span class="no-receipt">None</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr class="group-footer">
          <td colspan="3" style="text-align:right">Group total</td>
          <td style="text-align:right">$<?= number_format($group_total,2) ?></td>
          <td colspan="3"></td>
        </tr>
      </tfoot>
    </table>
  </div>
</div>
<?php endforeach; ?>

<!-- Grand total -->
<div style="text-align:right;padding:.75rem 1.25rem;font-size:.9rem;font-weight:700;color:#002554">
  Grand total &nbsp; $<?= number_format($grand_total,2) ?>
</div>

<?php endif; ?>

<script>
function toggleGroup(id) {
  var body = document.getElementById(id);
  var hdr  = document.getElementById('hdr-' + id);
  var open = body.classList.toggle('open');
  hdr.classList.toggle('open', open);
}
// Open first group by default
(function() {
  var first = document.querySelector('.group-body');
  var hdr   = document.querySelector('.group-header');
  if (first) { first.classList.add('open'); hdr.classList.add('open'); }
})();
</script>

<?php admin_footer(); ?>
