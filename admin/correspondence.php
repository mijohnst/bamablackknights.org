<?php
require_once __DIR__ . '/auth.php';
require_login();
if (!can_manage_members() && !is_secretary() && !is_treasurer()) { header('Location: dashboard.php?denied=1'); exit; }
$pdo = get_pdo();

$msg   = '';
$error = '';

// ── CSV Export ─────────────────────────────────────────────────────────────
if (isset($_GET['export'])) {
    $year = (int)($_GET['year'] ?? date('Y'));
    $dir  = $_GET['dir'] ?? '';
    $where  = ['YEAR(log_date) = ?'];
    $params = [$year];
    if (in_array($dir, ['sent','received'])) { $where[] = 'direction = ?'; $params[] = $dir; }
    $stmt = $pdo->prepare("SELECT log_date, direction, contact_name, contact_org, subject, method, notes FROM correspondence_log WHERE " . implode(' AND ', $where) . " ORDER BY log_date DESC");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="correspondence-' . $year . '.csv"');
    $out = fopen('php://output','w');
    fputcsv($out, ['Date','Direction','Contact','Organization','Subject','Method','Notes']);
    foreach ($rows as $r) fputcsv($out, [$r['log_date'],$r['direction'],$r['contact_name'],$r['contact_org'],$r['subject'],$r['method'],$r['notes']]);
    fclose($out); exit;
}

// ── Actions ────────────────────────────────────────────────────────────────
$action = $_POST['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();

    $valid_methods = ['email','letter','phone','in-person','other'];
    $valid_dirs    = ['sent','received'];

    if ($action === 'add' || $action === 'update') {
        $date    = $_POST['log_date'] ?? '';
        $dir     = $_POST['direction'] ?? 'sent';
        $contact = trim($_POST['contact_name'] ?? '');
        $org     = trim($_POST['contact_org'] ?? '');
        $subject = trim($_POST['subject'] ?? '');
        $method  = $_POST['method'] ?? 'email';
        $notes   = trim($_POST['notes'] ?? '');
        if (!in_array($dir, $valid_dirs)) $dir = 'sent';
        if (!in_array($method, $valid_methods)) $method = 'email';

        if ($date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $error = 'Date is required.';
        } elseif ($contact === '') {
            $error = 'Contact name is required.';
        } elseif ($subject === '') {
            $error = 'Subject is required.';
        } elseif ($action === 'add') {
            $s = $pdo->prepare("INSERT INTO correspondence_log (log_date, direction, contact_name, contact_org, subject, method, notes, logged_by) VALUES (?,?,?,?,?,?,?,?)");
            $s->execute([$date, $dir, $contact, $org, $subject, $method, $notes, $_SESSION['user_id']??null]);
            $msg = 'Entry added.';
        } else {
            $id = (int)($_POST['id'] ?? 0);
            if ($id < 1) { $error = 'Invalid entry.'; }
            else {
                $s = $pdo->prepare("UPDATE correspondence_log SET log_date=?, direction=?, contact_name=?, contact_org=?, subject=?, method=?, notes=? WHERE id=?");
                $s->execute([$date, $dir, $contact, $org, $subject, $method, $notes, $id]);
                $msg = 'Entry updated.';
            }
        }
    }

    elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $pdo->prepare("DELETE FROM correspondence_log WHERE id=?")->execute([$id]);
            $msg = 'Entry deleted.';
        }
    }

    if (!$error) {
        $qs = http_build_query(['year'=>$_POST['year_ctx']??date('Y'), 'dir'=>$_POST['dir_ctx']??'', 'msg'=>$msg]);
        header('Location: correspondence.php?' . $qs);
        exit;
    }
}

if (isset($_GET['msg'])) $msg = htmlspecialchars($_GET['msg']);

// ── Filters ────────────────────────────────────────────────────────────────
$year    = (int)($_GET['year'] ?? date('Y'));
$dir_flt = $_GET['dir'] ?? '';
$edit_id = (int)($_GET['edit'] ?? 0);
if (!in_array($dir_flt, ['','sent','received'])) $dir_flt = '';

$years_q = $pdo->query("SELECT DISTINCT YEAR(log_date) y FROM correspondence_log ORDER BY y DESC")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array($year, $years_q)) array_unshift($years_q, $year);

$where  = ['YEAR(log_date) = ?'];
$params = [$year];
if ($dir_flt !== '') { $where[] = 'direction = ?'; $params[] = $dir_flt; }

$stmt = $pdo->prepare("SELECT cl.*, u.name AS logged_by_name FROM correspondence_log cl LEFT JOIN users u ON cl.logged_by = u.id WHERE " . implode(' AND ', $where) . " ORDER BY log_date DESC, cl.id DESC");
$stmt->execute($params);
$entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

$edit_entry = null;
if ($edit_id > 0) {
    $eq = $pdo->prepare("SELECT * FROM correspondence_log WHERE id=?");
    $eq->execute([$edit_id]);
    $edit_entry = $eq->fetch(PDO::FETCH_ASSOC);
}

$method_labels = ['email'=>'Email','letter'=>'Letter','phone'=>'Phone','in-person'=>'In Person','other'=>'Other'];
$method_colors = ['email'=>'#1565c0','letter'=>'#6a1b9a','phone'=>'#1b5e20','in-person'=>'#37474f','other'=>'#5a6a7a'];

admin_header('Correspondence Log');
echo show_flash();
?>
<style>
.cl-form-box{background:#fff;border-radius:8px;box-shadow:0 1px 4px rgba(0,0,0,.08);padding:1.5rem;margin-bottom:1.5rem}
.cl-form-box h2{font-size:.8rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#5a6a7a;margin-bottom:1rem}
.cl-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:.75rem}
.cl-table{width:100%;border-collapse:collapse;font-size:.84rem}
.cl-table th{padding:.55rem 1rem;font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#5a6a7a;background:#f7f9fc;text-align:left}
.cl-table td{padding:.65rem 1rem;border-top:1px solid #f0f2f5;vertical-align:top}
.cl-table tr:hover td{background:#fafbfc}
.dir-sent{display:inline-block;padding:.12rem .45rem;border-radius:3px;font-size:.68rem;font-weight:700;background:#e3f2fd;color:#0d47a1}
.dir-recv{display:inline-block;padding:.12rem .45rem;border-radius:3px;font-size:.68rem;font-weight:700;background:#f3e5f5;color:#4a148c}
.method-pill{display:inline-block;padding:.1rem .4rem;border-radius:99px;font-size:.68rem;font-weight:600;text-transform:uppercase;letter-spacing:.04em}
</style>

<div class="page-head">
  <h1>Correspondence Log</h1>
  <div style="display:flex;gap:.5rem;flex-wrap:wrap">
    <a href="correspondence.php?<?= http_build_query(['year'=>$year,'dir'=>$dir_flt,'export'=>1]) ?>" class="btn btn-secondary">Export CSV</a>
    <a href="dashboard.php" class="btn btn-secondary">← Dashboard</a>
  </div>
</div>

<?php if ($error): ?><div class="alert alert-danger" style="margin-bottom:1rem"><?= h($error) ?></div><?php endif; ?>
<?php if ($msg):  ?><div class="alert alert-success" style="margin-bottom:1rem"><?= h($msg) ?></div><?php endif; ?>

<!-- Add / Edit form -->
<div class="cl-form-box">
  <h2><?= $edit_entry ? 'Edit Entry' : 'Log Correspondence' ?></h2>
  <form method="POST">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="<?= $edit_entry ? 'update' : 'add' ?>">
    <?php if ($edit_entry): ?><input type="hidden" name="id" value="<?= (int)$edit_entry['id'] ?>"><?php endif; ?>
    <input type="hidden" name="year_ctx" value="<?= $year ?>">
    <input type="hidden" name="dir_ctx"  value="<?= h($dir_flt) ?>">
    <div class="cl-grid">
      <div class="form-group">
        <label>Date <span style="color:#A6192E">*</span></label>
        <input type="date" name="log_date" class="form-control" required
               value="<?= h($edit_entry['log_date'] ?? date('Y-m-d')) ?>">
      </div>
      <div class="form-group">
        <label>Direction</label>
        <select name="direction" class="form-control">
          <option value="sent"     <?= ($edit_entry['direction']??'sent')==='sent'    ?'selected':'' ?>>Sent</option>
          <option value="received" <?= ($edit_entry['direction']??'')==='received'?'selected':'' ?>>Received</option>
        </select>
      </div>
      <div class="form-group">
        <label>Contact Name <span style="color:#A6192E">*</span></label>
        <input type="text" name="contact_name" class="form-control" required maxlength="200"
               value="<?= h($edit_entry['contact_name'] ?? '') ?>" placeholder="Person or org name">
      </div>
      <div class="form-group">
        <label>Organization</label>
        <input type="text" name="contact_org" class="form-control" maxlength="200"
               value="<?= h($edit_entry['contact_org'] ?? '') ?>" placeholder="Optional">
      </div>
      <div class="form-group">
        <label>Method</label>
        <select name="method" class="form-control">
          <?php foreach ($method_labels as $v => $l): ?>
          <option value="<?= $v ?>" <?= ($edit_entry['method']??'email')===$v?'selected':'' ?>><?= $l ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="form-group" style="margin-top:.5rem">
      <label>Subject <span style="color:#A6192E">*</span></label>
      <input type="text" name="subject" class="form-control" required maxlength="500"
             value="<?= h($edit_entry['subject'] ?? '') ?>" placeholder="Brief subject or topic">
    </div>
    <div class="form-group">
      <label>Notes</label>
      <textarea name="notes" class="form-control" rows="2" maxlength="2000"><?= h($edit_entry['notes'] ?? '') ?></textarea>
    </div>
    <div style="display:flex;gap:.5rem;margin-top:1rem">
      <button type="submit" class="btn btn-primary"><?= $edit_entry ? 'Save Changes' : 'Add Entry' ?></button>
      <?php if ($edit_entry): ?><a href="correspondence.php?year=<?= $year ?>&dir=<?= h($dir_flt) ?>" class="btn btn-secondary">Cancel</a><?php endif; ?>
    </div>
  </form>
</div>

<!-- Filters -->
<div style="display:flex;align-items:center;gap:.6rem;flex-wrap:wrap;margin-bottom:1rem">
  <form method="GET" style="display:flex;align-items:center;gap:.5rem">
    <select name="year" onchange="this.form.submit()" style="padding:.35rem .6rem;font-size:.85rem;border:1px solid #d0d5dd;border-radius:4px">
      <?php foreach ($years_q as $y): ?>
      <option value="<?= $y ?>" <?= $y==$year?'selected':'' ?>><?= $y ?></option>
      <?php endforeach; ?>
    </select>
    <select name="dir" onchange="this.form.submit()" style="padding:.35rem .6rem;font-size:.85rem;border:1px solid #d0d5dd;border-radius:4px">
      <option value=""        <?= $dir_flt===''        ?'selected':'' ?>>All</option>
      <option value="sent"    <?= $dir_flt==='sent'    ?'selected':'' ?>>Sent</option>
      <option value="received"<?= $dir_flt==='received'?'selected':'' ?>>Received</option>
    </select>
  </form>
  <span style="font-size:.8rem;color:#9aa5b4"><?= count($entries) ?> entr<?= count($entries)!=1?'ies':'y' ?></span>
</div>

<?php if (empty($entries)): ?>
  <p style="color:#9aa5b4">No correspondence logged for <?= $year ?><?= $dir_flt ? " ($dir_flt)" : '' ?>.</p>
<?php else: ?>
<div class="card" style="padding:0;overflow-x:auto">
<table class="cl-table">
  <thead>
    <tr>
      <th>Date</th>
      <th>Dir.</th>
      <th>Contact</th>
      <th>Subject</th>
      <th>Method</th>
      <th>Notes</th>
      <th>Actions</th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($entries as $e):
    $mc = $method_colors[$e['method']] ?? '#5a6a7a';
  ?>
  <tr>
    <td style="white-space:nowrap"><?= date('M j, Y', strtotime($e['log_date'])) ?></td>
    <td>
      <?php if ($e['direction']==='sent'): ?>
        <span class="dir-sent">↑ Sent</span>
      <?php else: ?>
        <span class="dir-recv">↓ Recv</span>
      <?php endif; ?>
    </td>
    <td>
      <div style="font-weight:600"><?= h($e['contact_name']) ?></div>
      <?php if ($e['contact_org']): ?><div style="font-size:.72rem;color:#9aa5b4"><?= h($e['contact_org']) ?></div><?php endif; ?>
    </td>
    <td><?= h($e['subject']) ?></td>
    <td>
      <span class="method-pill" style="background:<?= $mc ?>22;color:<?= $mc ?>"><?= h($method_labels[$e['method']] ?? $e['method']) ?></span>
    </td>
    <td style="max-width:200px;font-size:.78rem;color:#5a6a7a">
      <?= $e['notes'] ? h(mb_strimwidth($e['notes'],0,100,'…')) : '<span style="color:#c0c8d4">—</span>' ?>
    </td>
    <td style="white-space:nowrap">
      <div style="display:flex;gap:.35rem">
        <a href="correspondence.php?edit=<?= (int)$e['id'] ?>&year=<?= $year ?>&dir=<?= h($dir_flt) ?>" class="btn btn-secondary btn-sm">Edit</a>
        <form method="POST" onsubmit="return confirm('Delete this entry?')">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="id" value="<?= (int)$e['id'] ?>">
          <input type="hidden" name="year_ctx" value="<?= $year ?>">
          <input type="hidden" name="dir_ctx"  value="<?= h($dir_flt) ?>">
          <button type="submit" class="btn btn-sm" style="padding:.25rem .55rem;background:#fff3f3;color:#A6192E;border:1px solid #f5c6cb;border-radius:4px;font-size:.75rem">Del</button>
        </form>
      </div>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>

<?php admin_footer(); ?>
