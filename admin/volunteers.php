<?php
require_once __DIR__ . '/auth.php';
require_member_admin();
$pdo = get_pdo();

const VOLUNTEER_STATUSES = ['new' => 'New', 'contacted' => 'Contacted', 'assigned' => 'Assigned', 'declined' => 'Not Interested'];

// Handle POST before any output
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare('DELETE FROM volunteers WHERE id=?')->execute([$id]);
        try { $pdo->prepare('DELETE FROM volunteer_committee_tags WHERE volunteer_id=?')->execute([$id]); } catch (PDOException $e) {}
        flash('success', 'Submission deleted.');
        header('Location: volunteers.php'); exit;

    } elseif ($action === 'update') {
        $id     = (int)($_POST['id'] ?? 0);
        $status = $_POST['status'] ?? 'new';
        if (!array_key_exists($status, VOLUNTEER_STATUSES)) $status = 'new';
        $opp_id     = (int)($_POST['assigned_opportunity_id'] ?? 0) ?: null;
        $notes      = trim($_POST['admin_notes'] ?? '');
        $committees = array_intersect((array)($_POST['committees'] ?? []), COMMITTEES);

        try {
            // "Assign to Opportunity" previously only labeled the interest
            // submission — it never actually claimed a spot, so assigned
            // people silently never counted toward the opportunity's filled
            // total or showed up on its roster. Reconcile a real
            // volunteer_signups row (as a guest — these people don't have
            // portal accounts) to match whatever is now selected here.
            $before = $pdo->prepare('SELECT name, email, assigned_opportunity_id FROM volunteers WHERE id=?');
            $before->execute([$id]);
            $vol = $before->fetch(PDO::FETCH_ASSOC);
            $old_opp_id = $vol ? (int)($vol['assigned_opportunity_id'] ?? 0) : 0;

            $pdo->prepare('UPDATE volunteers SET status=?, assigned_opportunity_id=?, admin_notes=? WHERE id=?')
                ->execute([$status, $opp_id, $notes, $id]);
            $pdo->prepare('DELETE FROM volunteer_committee_tags WHERE volunteer_id=?')->execute([$id]);
            $ins = $pdo->prepare('INSERT INTO volunteer_committee_tags (volunteer_id, committee) VALUES (?,?)');
            foreach ($committees as $c) $ins->execute([$id, $c]);

            if ($vol && $vol['email']) {
                // Moved off the old opportunity (or unassigned) — drop the
                // old guest signup so it stops counting there.
                if ($old_opp_id && $old_opp_id !== (int)$opp_id) {
                    $pdo->prepare('DELETE FROM volunteer_signups WHERE opportunity_id=? AND guest_email=?')
                        ->execute([$old_opp_id, $vol['email']]);
                }
                // Newly assigned (or re-saved on the same one) — claim the
                // spot. Duplicate key (already claimed) is expected and fine.
                if ($opp_id) {
                    try {
                        $pdo->prepare('INSERT INTO volunteer_signups (opportunity_id, guest_name, guest_email) VALUES (?,?,?)')
                            ->execute([$opp_id, $vol['name'], $vol['email']]);
                    } catch (PDOException $e) {}
                }
            }

            flash('success', 'Submission updated.');
        } catch (PDOException $e) {
            flash('error', 'Could not save — run admin/migrate_volunteer_assignment.sql first.');
        }
        header('Location: volunteers.php'); exit;
    }
}

$search = trim($_GET['q'] ?? '');
$where  = ['1=1']; $params = [];
if ($search) { $where[] = '(name LIKE :q OR email LIKE :q OR areas LIKE :q)'; $params[':q'] = "%$search%"; }

$stmt = $pdo->prepare('SELECT * FROM volunteers WHERE ' . implode(' AND ', $where) . ' ORDER BY created_at DESC');
$stmt->execute($params);
$vols  = $stmt->fetchAll(PDO::FETCH_ASSOC);
$total = (int)$pdo->query('SELECT COUNT(*) FROM volunteers')->fetchColumn();

// Opportunities for the assignment dropdown
$opportunities = [];
try {
    $opportunities = $pdo->query('SELECT id,title,active FROM volunteer_opportunities ORDER BY active DESC, title ASC')->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}
$opp_titles = [];
foreach ($opportunities as $o) $opp_titles[$o['id']] = $o['title'];

// Committee tags for every submission on this page, keyed by volunteer id
$tags_by_vol = [];
if (!empty($vols)) {
    try {
        $ids = array_column($vols, 'id');
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        $trows = $pdo->prepare("SELECT volunteer_id, committee FROM volunteer_committee_tags WHERE volunteer_id IN ($ph)");
        $trows->execute($ids);
        foreach ($trows->fetchAll(PDO::FETCH_ASSOC) as $t) $tags_by_vol[$t['volunteer_id']][] = $t['committee'];
    } catch (PDOException $e) {}
}

$edit = null; $edit_tags = [];
if (isset($_GET['edit'])) {
    $s = $pdo->prepare('SELECT * FROM volunteers WHERE id=?'); $s->execute([(int)$_GET['edit']]); $edit = $s->fetch(PDO::FETCH_ASSOC);
    if ($edit) $edit_tags = $tags_by_vol[$edit['id']] ?? [];
}

admin_header('Volunteer Submissions');
echo show_flash();
?>
<style>
.status-badge{display:inline-block;padding:.1rem .5rem;border-radius:99px;font-size:.68rem;font-weight:700;white-space:nowrap}
.status-new{background:#e3f2fd;color:#0d47a1}
.status-contacted{background:#fff3cd;color:#5f4c00}
.status-assigned{background:#e8f5e9;color:#1b5e20}
.status-declined{background:#f0f2f5;color:#5a6a7a}
.tag-chip{display:inline-block;background:#f0f2f5;color:#333;font-size:.68rem;padding:.1rem .45rem;border-radius:3px;margin:0 .2rem .2rem 0}
</style>

<div class="page-head">
  <h1>Volunteer Interest <span style="font-size:.85rem;font-weight:400;color:#5a6a7a">(<?= $total ?> total)</span></h1>
  <a href="dashboard.php" class="btn btn-secondary">← Dashboard</a>
</div>

<div class="card" style="padding:.85rem 1.25rem;margin-bottom:1rem">
  <form method="GET" style="display:flex;gap:.75rem;align-items:flex-end">
    <div class="form-group" style="flex:1;margin:0"><label>Search name / email / area</label><input name="q" value="<?= h($search) ?>" placeholder="Type to search…"></div>
    <button type="submit" class="btn btn-primary">Search</button>
    <a href="volunteers.php" class="btn btn-secondary">Clear</a>
  </form>
</div>

<?php if ($edit): ?>
<div class="card" style="max-width:640px;margin-bottom:1.5rem">
  <h2 style="margin-bottom:1.25rem">Assign — <?= h($edit['name']) ?></h2>
  <form method="POST">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="update">
    <input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
    <div class="form-row col-2">
      <div class="form-group">
        <label>Status</label>
        <select name="status">
          <?php foreach (VOLUNTEER_STATUSES as $val => $label): ?>
            <option value="<?= $val ?>" <?= ($edit['status'] ?? 'new') === $val ? 'selected' : '' ?>><?= h($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label>Assign to Opportunity</label>
        <select name="assigned_opportunity_id">
          <option value="">— None —</option>
          <?php foreach ($opportunities as $o): ?>
            <option value="<?= $o['id'] ?>" <?= (int)($edit['assigned_opportunity_id'] ?? 0) === (int)$o['id'] ? 'selected' : '' ?>>
              <?= h($o['title']) ?><?= $o['active'] ? '' : ' (closed)' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="form-group">
      <label>Committee Interest <span style="font-weight:400;text-transform:none;letter-spacing:0;font-size:.72rem;color:#9aa5b4">tag areas they could help with, even without a portal account</span></label>
      <?php foreach (COMMITTEES as $c): ?>
      <label style="display:flex;align-items:center;gap:.5rem;font-weight:400;text-transform:none;letter-spacing:0;font-size:.9rem;cursor:pointer;margin-bottom:.4rem">
        <input type="checkbox" name="committees[]" value="<?= h($c) ?>" style="width:auto" <?= in_array($c, $edit_tags, true) ? 'checked' : '' ?>>
        <?= h($c) ?>
      </label>
      <?php endforeach; ?>
    </div>
    <div class="form-group">
      <label>Notes</label>
      <textarea name="admin_notes" rows="3" placeholder="Any context for other officers…"><?= h($edit['admin_notes'] ?? '') ?></textarea>
    </div>
    <div style="display:flex;gap:.75rem">
      <button type="submit" class="btn btn-primary">Save</button>
      <a href="volunteers.php" class="btn btn-secondary">Cancel</a>
    </div>
  </form>
</div>
<?php endif; ?>

<div class="card" style="padding:0;overflow-x:auto">
<table>
  <thead><tr><th>Name</th><th>Email</th><th>Phone</th><th>Areas</th><th>Availability</th><th>Cadet</th><th>Status</th><th>Assigned To</th><th>Committees</th><th>Date</th><th class="actions-head">Actions</th></tr></thead>
  <tbody>
  <?php if (empty($vols)): ?><tr><td colspan="11" style="text-align:center;padding:2rem;color:#5a6a7a">No volunteer submissions yet.</td></tr><?php endif; ?>
  <?php foreach ($vols as $v):
    $status = $v['status'] ?? 'new';
  ?>
  <tr>
    <td><strong><?= h($v['name']) ?></strong></td>
    <td><a href="mailto:<?= h($v['email']) ?>" style="color:#003594"><?= h($v['email']) ?></a></td>
    <td style="font-size:.82rem"><?= h($v['phone']) ?: '—' ?></td>
    <td style="font-size:.78rem;color:#5a6a7a"><?= h($v['areas']) ?: '—' ?></td>
    <td style="font-size:.78rem;color:#5a6a7a"><?= h($v['availability']) ?: '—' ?></td>
    <td style="font-size:.78rem;color:#5a6a7a"><?= h($v['cadet_info']) ?: '—' ?></td>
    <td><span class="status-badge status-<?= h($status) ?>"><?= h(VOLUNTEER_STATUSES[$status] ?? 'New') ?></span></td>
    <td style="font-size:.78rem;color:#5a6a7a"><?= !empty($v['assigned_opportunity_id']) ? h($opp_titles[$v['assigned_opportunity_id']] ?? '—') : '—' ?></td>
    <td style="font-size:.78rem">
      <?php if (!empty($tags_by_vol[$v['id']])): ?>
        <?php foreach ($tags_by_vol[$v['id']] as $tag): ?><span class="tag-chip"><?= h($tag) ?></span><?php endforeach; ?>
      <?php else: ?>—<?php endif; ?>
    </td>
    <td style="font-size:.78rem;white-space:nowrap;color:#5a6a7a"><?= date('M j, Y', strtotime($v['created_at'])) ?></td>
    <td class="actions"><div class="btn-group">
      <a href="volunteers.php?edit=<?= $v['id'] ?>" class="btn btn-secondary btn-sm">Assign</a>
      <form method="POST" onsubmit="return confirm('Delete this submission?')" style="margin:0">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="id" value="<?= $v['id'] ?>">
        <button type="submit" class="btn btn-danger btn-sm">Delete</button>
      </form>
    </div></td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<?php admin_footer(); ?>
