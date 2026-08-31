<?php
require_once __DIR__ . '/auth.php';
require_member_admin();
$pdo    = get_pdo();
$errors = [];
$edit   = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id          = (int)($_POST['id'] ?? 0);
        $title       = trim($_POST['title']       ?? '');
        $event_date  = $_POST['event_date']  ?: null;
        $event_date_end = $_POST['event_date_end'] ?: null;
        $event_time  = trim($_POST['event_time']  ?? '');
        $location    = trim($_POST['location']    ?? '');
        $description = trim($_POST['description'] ?? '');
        $tag         = trim($_POST['tag']         ?? '');
        $group_label = $_POST['group_label'] ?? 'upcoming';
        $cta_text    = trim($_POST['cta_text']    ?? '');
        $cta_url     = trim($_POST['cta_url']     ?? '');
        $cta_note     = trim($_POST['cta_note']     ?? '');
        $cta_deadline = $_POST['cta_deadline'] ?: null;
        $countdown_type = in_array(($_POST['countdown_type'] ?? 'ends'), ['starts','ends'], true) ? $_POST['countdown_type'] : 'ends';
        $sort_order  = (int)($_POST['sort_order'] ?? 0);
        $visible     = isset($_POST['visible']) ? 1 : 0;

        if (!$title) $errors[] = 'Title is required.';
        if ($cta_url !== '' && !is_safe_http_url($cta_url)) $errors[] = 'Button URL must start with https:// or http://';
        if (!in_array($group_label, ['past','upcoming','planning'])) $group_label = 'upcoming';

        if (empty($errors)) {
            $fields = [$title,$event_date,$event_date_end,$event_time,$location,$description,$tag,$group_label,$cta_text,$cta_url,$cta_note,$sort_order,$visible,$cta_deadline,$countdown_type];
            if ($id) {
                $pdo->prepare('UPDATE events SET title=?,event_date=?,event_date_end=?,event_time=?,location=?,description=?,tag=?,group_label=?,cta_text=?,cta_url=?,cta_note=?,sort_order=?,visible=?,cta_deadline=?,countdown_type=?,updated_at=NOW() WHERE id=?')
                    ->execute(array_merge($fields, [$id]));
                flash('success','Event updated.');
            } else {
                $pdo->prepare('INSERT INTO events (title,event_date,event_date_end,event_time,location,description,tag,group_label,cta_text,cta_url,cta_note,sort_order,visible,cta_deadline,countdown_type) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                    ->execute($fields);
                flash('success','Event added.');
            }
            header('Location: events.php'); exit;
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) { $pdo->prepare('DELETE FROM events WHERE id=?')->execute([$id]); flash('success','Event deleted.'); }
        header('Location: events.php'); exit;
    } elseif ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) {
            $cur = $pdo->prepare('SELECT visible FROM events WHERE id=?'); $cur->execute([$id]); $v = $cur->fetchColumn();
            $pdo->prepare('UPDATE events SET visible=? WHERE id=?')->execute([$v?0:1,$id]);
        }
        header('Location: events.php'); exit;
    }
}

if (isset($_GET['edit'])) {
    $s = $pdo->prepare('SELECT * FROM events WHERE id=?'); $s->execute([(int)$_GET['edit']]); $edit = $s->fetch();
}

$events = $pdo->query('SELECT * FROM events ORDER BY group_label DESC, sort_order ASC, event_date ASC')->fetchAll();
$groups = ['past'=>'Past Events','upcoming'=>'Upcoming','planning'=>'Planning Ahead'];
$tags   = ['Social','Academy','Cadet Support','Community','Other'];

admin_header('Events');
echo show_flash();
?>
<style>
.ev-row{border-left:3px solid #e1e5eb;padding:.6rem .85rem;margin-bottom:.4rem;background:#fff;border-radius:0 4px 4px 0;display:flex;justify-content:space-between;align-items:center;gap:.75rem;flex-wrap:wrap}
.ev-row.hidden{opacity:.45}
.ev-meta{font-size:.75rem;color:#5a6a7a;margin-top:.15rem}
.group-head{font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:#5a6a7a;margin:.75rem 0 .3rem;padding-left:.85rem;border-left:3px solid #003594}
</style>

<div class="page-head">
  <h1>Site Events</h1>
  <div style="display:flex;gap:.5rem">
    <?php if (!$edit): ?><a href="events.php?edit=new" class="btn btn-primary">+ Add Event</a><?php endif; ?>
    <a href="dashboard.php" class="btn btn-secondary">← Dashboard</a>
  </div>
</div>
<p style="font-size:.82rem;color:#5a6a7a;margin-bottom:1.25rem">Changes here update the main website's Events section automatically.</p>

<?php if (!empty($errors)): ?>
  <div class="alert alert-error"><?= implode('<br>', array_map('htmlspecialchars',$errors)) ?></div>
<?php endif; ?>

<?php if ($edit !== null || isset($_GET['edit'])): ?>
<div class="card" style="max-width:680px;margin-bottom:1.5rem">
  <h2 style="margin-bottom:1.25rem"><?= $edit?'Edit Event':'Add New Event' ?></h2>
  <form method="POST">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <?php if ($edit): ?><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>"><?php endif; ?>
    <div class="form-group">
      <label>Title *</label>
      <input name="title" value="<?= h($edit['title']??'') ?>" required placeholder="Event name">
    </div>
    <div class="form-row col-3">
      <div class="form-group">
        <label>Group</label>
        <select name="group_label">
          <?php foreach ($groups as $k=>$v): ?>
            <option value="<?= $k ?>" <?= ($edit['group_label']??'upcoming')===$k?'selected':''?>><?= $v ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label>Tag</label>
        <select name="tag">
          <option value="">— none —</option>
          <?php foreach ($tags as $t): ?>
            <option value="<?= h($t) ?>" <?= ($edit['tag']??'')===$t?'selected':''?>><?= h($t) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label>Sort Order</label>
        <input type="number" name="sort_order" value="<?= h($edit['sort_order']??'0') ?>" placeholder="10">
      </div>
    </div>
    <div class="form-row col-2">
      <div class="form-group">
        <label>Start Date <span style="font-weight:400;font-size:.72rem;color:#9aa5b4">optional</span></label>
        <input type="date" name="event_date" value="<?= h($edit['event_date']??'') ?>">
      </div>
      <div class="form-group">
        <label>End Date <span style="font-weight:400;font-size:.72rem;color:#9aa5b4">for ranges</span></label>
        <input type="date" name="event_date_end" value="<?= h($edit['event_date_end']??'') ?>">
      </div>
    </div>
    <div class="form-row col-2">
      <div class="form-group">
        <label>Time</label>
        <input name="event_time" value="<?= h($edit['event_time']??'') ?>" placeholder="e.g. 9:00 am – noon">
      </div>
      <div class="form-group">
        <label>Location</label>
        <input name="location" value="<?= h($edit['location']??'') ?>" placeholder="Venue, City, State">
      </div>
    </div>
    <div class="form-group">
      <label>Description</label>
      <textarea name="description" rows="3" placeholder="Event details…"><?= h($edit['description']??'') ?></textarea>
    </div>
    <div class="form-row col-3">
      <div class="form-group">
        <label>Button Text</label>
        <input name="cta_text" value="<?= h($edit['cta_text']??'') ?>" placeholder="e.g. Buy Tickets">
      </div>
      <div class="form-group" style="grid-column:span 2">
        <label>Button URL</label>
        <input name="cta_url" value="<?= h($edit['cta_url']??'') ?>" placeholder="https://…">
      </div>
    </div>
    <div class="form-group">
      <label>Button Note <span style="font-weight:400;font-size:.72rem;color:#9aa5b4">small text below button</span></label>
      <input name="cta_note" value="<?= h($edit['cta_note']??'') ?>" placeholder="e.g. Questions? email@example.com">
    </div>
    <div class="form-row col-2">
      <div class="form-group">
        <label>Countdown Target <span style="font-weight:400;font-size:.72rem;color:#9aa5b4">date/time the timer counts to</span></label>
        <?php
        $dl_val = '';
        if (!empty($edit['cta_deadline']) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}/', $edit['cta_deadline']))
            $dl_val = str_replace(' ','T', substr($edit['cta_deadline'], 0, 16));
        ?>
        <input type="datetime-local" name="cta_deadline" value="<?= h($dl_val) ?>">
      </div>
      <div class="form-group">
        <label>Countdown Label <span style="font-weight:400;font-size:.72rem;color:#9aa5b4">what the timer says</span></label>
        <?php $cd_type = $edit['countdown_type'] ?? 'ends'; ?>
        <select name="countdown_type">
          <option value="ends"   <?= $cd_type==='ends'  ?'selected':'' ?>>Ends in… (deadline)</option>
          <option value="starts" <?= $cd_type==='starts'?'selected':'' ?>>Starts in… (event begins)</option>
        </select>
      </div>
    </div>
    <div class="form-group" style="display:flex;align-items:center;gap:.5rem">
      <input type="checkbox" name="visible" id="ev_visible" value="1" style="width:auto" <?= ($edit['visible']??1)?'checked':'' ?>>
      <label for="ev_visible" style="font-weight:400;text-transform:none;letter-spacing:0;font-size:.9rem;cursor:pointer;margin:0">Show on website</label>
    </div>
    <div style="display:flex;gap:.75rem">
      <button type="submit" class="btn btn-primary"><?= $edit?'Save Changes':'Add Event' ?></button>
      <a href="events.php" class="btn btn-secondary">Cancel</a>
    </div>
  </form>
</div>
<?php endif; ?>

<!-- Event list by group -->
<?php foreach ($groups as $grp => $grp_label):
  $grp_events = array_filter($events, fn($e) => $e['group_label'] === $grp);
  if (empty($grp_events)) continue;
?>
  <div class="group-head"><?= $grp_label ?></div>
  <?php foreach ($grp_events as $e): ?>
  <div class="ev-row <?= $e['visible']?'':'hidden' ?>">
    <div style="flex:1;min-width:0">
      <strong style="color:#002554"><?= h($e['title']) ?></strong>
      <div class="ev-meta">
        <?php if ($e['event_date']): echo date('M j, Y', strtotime($e['event_date'])); if ($e['event_date_end']) echo ' – ' . date('M j, Y', strtotime($e['event_date_end'])); endif; ?>
        <?php if ($e['event_time']): ?> &bull; <?= h($e['event_time']) ?><?php endif; ?>
        <?php if ($e['tag']): ?> &bull; <span style="color:#003594"><?= h($e['tag']) ?></span><?php endif; ?>
        <?php if (!$e['visible']): ?> &bull; <span style="color:#9aa5b4">Hidden</span><?php endif; ?>
      </div>
    </div>
    <div style="display:flex;gap:.4rem;flex-shrink:0">
      <a href="events.php?edit=<?= $e['id'] ?>" class="btn btn-secondary btn-sm">Edit</a>
      <form method="POST" style="margin:0">
        <?= csrf_field() ?><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= $e['id'] ?>">
        <button type="submit" class="btn btn-secondary btn-sm"><?= $e['visible']?'Hide':'Show' ?></button>
      </form>
      <form method="POST" style="margin:0" onsubmit="return confirm('Delete this event?')">
        <?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= $e['id'] ?>">
        <button type="submit" class="btn btn-danger btn-sm">Delete</button>
      </form>
    </div>
  </div>
  <?php endforeach; ?>
<?php endforeach; ?>

<?php admin_footer(); ?>
