<?php
require_once __DIR__ . '/auth.php';
require_member_admin();
$pdo = get_pdo();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id           = (int)($_POST['id'] ?? 0);
        $title        = trim($_POST['title']        ?? '');
        $description  = trim($_POST['description']  ?? '');
        $event_date   = $_POST['event_date'] ?: null;
        $location     = trim($_POST['location']     ?? '');
        $spots_needed = max(1, (int)($_POST['spots_needed'] ?? 1));
        $active       = isset($_POST['active']) ? 1 : 0;

        if ($title === '') {
            flash('error', 'Title is required.');
        } elseif ($id) {
            $pdo->prepare('UPDATE volunteer_opportunities SET title=?,description=?,event_date=?,location=?,spots_needed=?,active=? WHERE id=?')
                ->execute([$title, $description, $event_date, $location, $spots_needed, $active, $id]);
            flash('success', 'Opportunity updated.');
        } else {
            $pdo->prepare('INSERT INTO volunteer_opportunities (title,description,event_date,location,spots_needed,active,created_by) VALUES (?,?,?,?,?,?,?)')
                ->execute([$title, $description, $event_date, $location, $spots_needed, $active, $_SESSION['user_id'] ?? null]);
            flash('success', 'Opportunity added.');
        }
        header('Location: volunteer-opportunities.php'); exit;

    } elseif ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        $cur = $pdo->prepare('SELECT active FROM volunteer_opportunities WHERE id=?'); $cur->execute([$id]); $v = $cur->fetchColumn();
        $pdo->prepare('UPDATE volunteer_opportunities SET active=? WHERE id=?')->execute([$v ? 0 : 1, $id]);
        header('Location: volunteer-opportunities.php'); exit;

    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $pdo->prepare('DELETE FROM volunteer_signups WHERE opportunity_id=?')->execute([$id]);
        $pdo->prepare('DELETE FROM volunteer_opportunities WHERE id=?')->execute([$id]);
        flash('success', 'Opportunity deleted.');
        header('Location: volunteer-opportunities.php'); exit;
    }
}

$edit = null;
if (isset($_GET['edit']) && $_GET['edit'] !== 'new') {
    $s = $pdo->prepare('SELECT * FROM volunteer_opportunities WHERE id=?'); $s->execute([(int)$_GET['edit']]); $edit = $s->fetch(PDO::FETCH_ASSOC);
}

$opportunities = $pdo->query(
    "SELECT o.*, (SELECT COUNT(*) FROM volunteer_signups WHERE opportunity_id=o.id) AS signup_count
     FROM volunteer_opportunities o
     ORDER BY o.active DESC, o.event_date IS NULL, o.event_date ASC, o.id DESC"
)->fetchAll(PDO::FETCH_ASSOC);

// Signup rosters for expanded view
$rosters = [];
if (!empty($opportunities)) {
    $ids = array_column($opportunities, 'id');
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    $rows = $pdo->prepare(
        "SELECT s.opportunity_id, COALESCE(u.name, s.guest_name) AS name,
                COALESCE(u.email, s.guest_email) AS email, s.user_id IS NULL AS is_guest
         FROM volunteer_signups s
         LEFT JOIN users u ON s.user_id = u.id
         WHERE s.opportunity_id IN ($ph) ORDER BY s.signed_up_at ASC"
    );
    $rows->execute($ids);
    foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $rosters[$r['opportunity_id']][] = $r;
    }
}

admin_header('Volunteer Opportunities');
echo show_flash();
?>
<style>
.vo-row{border-left:3px solid #1b5e20;padding:.75rem .9rem;margin-bottom:.6rem;background:#fff;border-radius:0 4px 4px 0}
.vo-row.inactive{opacity:.5;border-left-color:#9aa5b4}
.vo-meta{font-size:.78rem;color:#5a6a7a;margin-top:.2rem}
.vo-roster{font-size:.78rem;color:#5a6a7a;margin-top:.5rem;border-top:1px solid #f0f2f5;padding-top:.5rem}
.spots-badge{display:inline-block;padding:.1rem .5rem;border-radius:99px;font-size:.7rem;font-weight:700}
</style>

<div class="page-head">
  <h1>Volunteer Opportunities</h1>
  <div style="display:flex;gap:.5rem">
    <?php if (!$edit): ?><a href="volunteer-opportunities.php?edit=new" class="btn btn-primary">+ Add Opportunity</a><?php endif; ?>
    <a href="dashboard.php" class="btn btn-secondary">← Dashboard</a>
  </div>
</div>
<p style="font-size:.82rem;color:#5a6a7a;margin-bottom:1.25rem">
  Post specific volunteer needs here — members claim them from their own dashboard instead of you manually matching up general interest submissions.
</p>

<?php if ($edit !== null || isset($_GET['edit'])): ?>
<div class="card" style="max-width:640px;margin-bottom:1.5rem">
  <h2 style="margin-bottom:1.25rem"><?= $edit ? 'Edit Opportunity' : 'Add Opportunity' ?></h2>
  <form method="POST">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <?php if ($edit): ?><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>"><?php endif; ?>
    <div class="form-group">
      <label>Title *</label>
      <input name="title" required value="<?= h($edit['title'] ?? '') ?>" placeholder="e.g. Care Package Packing">
    </div>
    <div class="form-group">
      <label>Description</label>
      <textarea name="description" rows="3" placeholder="What's involved…"><?= h($edit['description'] ?? '') ?></textarea>
    </div>
    <div class="form-row col-3">
      <div class="form-group">
        <label>Date <span style="font-weight:400;font-size:.72rem;color:#9aa5b4">optional</span></label>
        <input type="date" name="event_date" value="<?= h($edit['event_date'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label>Location</label>
        <input name="location" value="<?= h($edit['location'] ?? '') ?>" placeholder="Where">
      </div>
      <div class="form-group">
        <label>Spots Needed</label>
        <input type="number" name="spots_needed" min="1" value="<?= h((string)($edit['spots_needed'] ?? 1)) ?>">
      </div>
    </div>
    <div class="form-group" style="display:flex;align-items:center;gap:.5rem">
      <input type="checkbox" name="active" id="vo_active" value="1" style="width:auto" <?= ($edit['active'] ?? 1) ? 'checked' : '' ?>>
      <label for="vo_active" style="font-weight:400;text-transform:none;letter-spacing:0;font-size:.9rem;cursor:pointer;margin:0">Open for sign-ups</label>
    </div>
    <div style="display:flex;gap:.75rem">
      <button type="submit" class="btn btn-primary"><?= $edit ? 'Save Changes' : 'Add Opportunity' ?></button>
      <a href="volunteer-opportunities.php" class="btn btn-secondary">Cancel</a>
    </div>
  </form>
</div>
<?php endif; ?>

<?php if (empty($opportunities)): ?>
  <p style="color:#9aa5b4">No volunteer opportunities posted yet.</p>
<?php else: ?>
  <?php foreach ($opportunities as $o):
    $filled = (int)$o['signup_count'];
    $needed = (int)$o['spots_needed'];
    $badge_color = $filled >= $needed ? '#1b5e20' : ($filled > 0 ? '#f57c00' : '#A6192E');
  ?>
  <div class="vo-row <?= $o['active'] ? '' : 'inactive' ?>">
    <div style="display:flex;justify-content:space-between;gap:.75rem;flex-wrap:wrap">
      <div style="flex:1;min-width:0">
        <strong style="color:#002554"><?= h($o['title']) ?></strong>
        <span class="spots-badge" style="background:<?= $badge_color ?>22;color:<?= $badge_color ?>"><?= $filled ?>/<?= $needed ?> filled</span>
        <?php if (!$o['active']): ?><span style="color:#9aa5b4;font-size:.75rem"> · Closed</span><?php endif; ?>
        <div class="vo-meta">
          <?php if ($o['event_date']): ?><?= date('M j, Y', strtotime($o['event_date'])) ?><?php endif; ?>
          <?php if ($o['location']): ?><?= $o['event_date'] ? ' &bull; ' : '' ?><?= h($o['location']) ?><?php endif; ?>
        </div>
        <?php if ($o['description']): ?><div class="vo-meta"><?= h($o['description']) ?></div><?php endif; ?>
        <?php if (!empty($rosters[$o['id']])): ?>
        <div class="vo-roster">
          <strong>Signed up:</strong>
          <?= implode(', ', array_map(fn($r) => h($r['name']) . ($r['is_guest'] ? ' (Guest)' : ''), $rosters[$o['id']])) ?>
        </div>
        <?php endif; ?>
      </div>
      <div style="display:flex;gap:.4rem;flex-shrink:0;align-items:flex-start">
        <?php if (!empty($rosters[$o['id']])): ?>
        <form method="POST" action="email.php" style="margin:0">
          <input type="hidden" name="recipients" value="<?= h(implode("\n", array_map(fn($r) => $r['name'] . ' <' . $r['email'] . '>', $rosters[$o['id']]))) ?>">
          <button type="submit" class="btn btn-secondary btn-sm">✉️ Email Everyone Signed Up</button>
        </form>
        <?php endif; ?>
        <?php if ($o['active']): ?>
        <button type="button" class="btn btn-secondary btn-sm" onclick="copyOppLink(this, <?= $o['id'] ?>)">Copy Link</button>
        <?php endif; ?>
        <a href="volunteer-opportunities.php?edit=<?= $o['id'] ?>" class="btn btn-secondary btn-sm">Edit</a>
        <form method="POST" style="margin:0">
          <?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= $o['id'] ?>">
          <button type="submit" class="btn btn-secondary btn-sm"><?= $o['active'] ? 'Close' : 'Reopen' ?></button>
        </form>
        <form method="POST" style="margin:0" onsubmit="return confirm('Delete this opportunity and all its sign-ups?')">
          <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $o['id'] ?>">
          <button type="submit" class="btn btn-danger btn-sm">Delete</button>
        </form>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
<?php endif; ?>

<script>
function copyOppLink(btn, id) {
  var url = 'https://alabamafalcons.org/#opp-' + id;
  navigator.clipboard.writeText(url).then(function() {
    var orig = btn.textContent;
    btn.textContent = 'Copied!';
    setTimeout(function() { btn.textContent = orig; }, 2000);
  });
}
</script>

<?php admin_footer(); ?>
