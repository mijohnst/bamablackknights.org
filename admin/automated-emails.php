<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/mailer.php';
require_member_admin();
$pdo = get_pdo();

// Which items expose a "days offset" field, and what it means for that item.
$days_offset_labels = [
    'dues_renewal'        => 'Days before expiration to send',
    'new_member_welcome'  => 'Days after joining to send',
    'lapsed_reengagement' => 'Days after expiration to send',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_item') {
        $key = $_POST['email_key'] ?? '';
        $cur = $pdo->prepare('SELECT days_offset FROM automated_emails WHERE email_key=?');
        $cur->execute([$key]);
        $existing_offset = (int)$cur->fetchColumn();

        $enabled = isset($_POST['enabled']) ? 1 : 0;
        $subject = trim($_POST['subject'] ?? '');
        $body    = trim($_POST['body'] ?? '');
        $offset  = isset($_POST['days_offset']) ? (int)$_POST['days_offset'] : $existing_offset;

        $pdo->prepare('UPDATE automated_emails SET enabled=?, days_offset=?, subject=?, body=? WHERE email_key=?')
            ->execute([$enabled, $offset, $subject, $body, $key]);
        flash('success', 'Saved.');
        header('Location: automated-emails.php'); exit;
    }

    if ($action === 'send_test') {
        $email_key   = $_POST['email_key'] ?? '';
        $test_email  = trim($_POST['test_email'] ?? '');
        $test_gender = $_POST['test_gender'] ?? '';
        if (!in_array($test_gender, ['Male', 'Female'], true)) $test_gender = '';
        if (!filter_var($test_email, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'Enter a valid email address to send the test to.');
        } else {
            $ok = send_automated_test_email($pdo, $email_key, $test_email, $test_gender);
            flash($ok ? 'success' : 'error', $ok
                ? "Test email sent to $test_email."
                : 'Could not send — check the server\'s mail configuration.');
        }
        header('Location: automated-emails.php'); exit;
    }

    header('Location: automated-emails.php'); exit;
}

$items = $pdo->query('SELECT * FROM automated_emails ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);

$last_run = null;
try {
    $last_run = $pdo->query('SELECT * FROM automated_email_runs WHERE id=1')->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (PDOException $e) {
    // migrate_automated_email_last_run.sql hasn't been run yet — just skip the panel
}

admin_header('Automated Emails');
echo show_flash();
?>
<style>
.ae-card{background:#fff;border-radius:8px;box-shadow:0 1px 4px rgba(0,0,0,.08);padding:1.5rem;margin-bottom:1.25rem}
.ae-head{display:flex;justify-content:space-between;align-items:flex-start;gap:1rem;margin-bottom:.25rem;flex-wrap:wrap}
.ae-title{font-size:1rem;font-weight:700;color:#002554}
.ae-desc{font-size:.8rem;color:#5a6a7a;margin-top:.15rem;max-width:640px}
.ae-toggle{display:flex;align-items:center;gap:.5rem;flex-shrink:0;cursor:pointer}
.ae-toggle input{width:auto}
.ae-fields{margin-top:1rem;display:grid;gap:.75rem}
.ae-save-row{display:flex;gap:.6rem;margin-top:.75rem}
.ae-test{margin-top:1rem;padding-top:1rem;border-top:1px solid #f0f2f5;display:flex;gap:.6rem;align-items:flex-end;flex-wrap:wrap}
</style>

<div class="page-head">
  <h1>Automated Emails</h1>
  <a href="dashboard.php" class="btn btn-secondary">← Dashboard</a>
</div>
<p style="font-size:.82rem;color:#5a6a7a;margin-bottom:1.5rem">
  Each of these runs automatically once a day via a scheduled cron job. Turning one off here takes effect immediately —
  no cron changes needed. Placeholders you can use (only the relevant ones work per email — see its description):
  <code>{name}</code>, <code>{cadet_name}</code>, <code>{parent_name}</code>, <code>{expire_date}</code>,
  <code>{dues_amount}</code>, <code>{meeting_title}</code>, <code>{meeting_date}</code>,
  <code>{meeting_location}</code>, <code>{meeting_link}</code>, <code>{opportunity_title}</code>,
  <code>{event_date}</code>, <code>{event_location}</code>, <code>{opportunity_description}</code>.
  The birthday emails also support
  <code>{he_she}</code>, <code>{him_her}</code>, <code>{his_her}</code> (drawn from the cadet's Gender field,
  falling back to "they/them/their" if it's blank).
</p>

<?php
$is_stale = $last_run && (strtotime($last_run['ran_at']) < strtotime('-26 hours'));
$lr_bg    = !$last_run ? '#fff3cd' : ($is_stale ? '#ffebee' : '#e8f5e9');
$lr_text  = !$last_run ? '#5f4c00' : ($is_stale ? '#c62828' : '#1b5e20');
?>
<div style="background:<?= $lr_bg ?>;color:<?= $lr_text ?>;border-radius:6px;padding:.75rem 1rem;font-size:.82rem;margin-bottom:1.5rem">
  <?php if (!$last_run): ?>
    ⚠️ No cron run has been recorded yet — either the daily cron job hasn't run since this tracker was added, or <code>migrate_automated_email_last_run.sql</code> hasn't been run in phpMyAdmin.
  <?php else: ?>
    <?= $is_stale ? '⚠️' : '✓' ?> Last cron run: <strong><?= date('M j, Y g:i A', strtotime($last_run['ran_at'])) ?></strong><?= $is_stale ? ' — more than a day ago, check that the cron job is still running' : '' ?>
    &mdash; Birthdays: <?= (int)$last_run['birthdays'] ?>,
    Dues renewals: <?= (int)$last_run['dues_renewals'] ?>,
    Meeting reminders: <?= (int)$last_run['meeting_reminders'] ?>,
    New member welcomes: <?= (int)$last_run['new_member_welcomes'] ?>,
    Lapsed re-engagements: <?= (int)$last_run['lapsed_reengagements'] ?>,
    Volunteer opportunity reminders: <?= (int)($last_run['volunteer_opp_reminders'] ?? 0) ?>
  <?php endif; ?>
</div>

<?php foreach ($items as $item): $key = $item['email_key']; ?>
<div class="ae-card">
  <div class="ae-head">
    <div>
      <div class="ae-title"><?= h($item['label']) ?></div>
      <div class="ae-desc"><?= h($item['description']) ?></div>
    </div>
  </div>
  <form method="POST">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_item">
    <input type="hidden" name="email_key" value="<?= h($key) ?>">
    <div class="ae-head" style="margin-top:.75rem;margin-bottom:0">
      <label class="ae-toggle">
        <input type="checkbox" name="enabled" value="1" <?= $item['enabled'] ? 'checked' : '' ?>>
        <span style="font-size:.82rem;font-weight:700;color:<?= $item['enabled'] ? '#1b5e20' : '#9aa5b4' ?>">
          <?= $item['enabled'] ? 'Enabled' : 'Disabled' ?>
        </span>
      </label>
    </div>
    <div class="ae-fields">
      <?php if (isset($days_offset_labels[$key])): ?>
      <div class="form-group" style="max-width:280px;margin:0">
        <label><?= h($days_offset_labels[$key]) ?></label>
        <input type="number" min="0" name="days_offset" value="<?= (int)$item['days_offset'] ?>">
      </div>
      <?php endif; ?>
      <div class="form-group" style="margin:0">
        <label>Subject</label>
        <input type="text" name="subject" value="<?= h($item['subject']) ?>">
      </div>
      <div class="form-group" style="margin:0">
        <label>Body</label>
        <textarea name="body" rows="6"><?= h($item['body']) ?></textarea>
      </div>
    </div>
    <div class="ae-save-row">
      <button type="submit" class="btn btn-primary btn-sm">Save</button>
    </div>
  </form>

  <div class="ae-test">
    <form method="POST" style="display:flex;gap:.6rem;align-items:flex-end;flex-wrap:wrap">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="send_test">
      <input type="hidden" name="email_key" value="<?= h($key) ?>">
      <div class="form-group" style="margin:0;min-width:220px">
        <label>Send test to</label>
        <input type="email" name="test_email" required placeholder="you@example.com" value="<?= h($_SESSION['user_email'] ?? '') ?>">
      </div>
      <?php if (in_array($key, ['birthday_cadet', 'birthday_parent'], true)): ?>
      <div class="form-group" style="margin:0;min-width:160px">
        <label>Preview as</label>
        <select name="test_gender">
          <option value="">Neutral (they/them)</option>
          <option value="Male">Male (he/him)</option>
          <option value="Female">Female (she/her)</option>
        </select>
      </div>
      <?php endif; ?>
      <button type="submit" class="btn btn-secondary btn-sm">Send Test</button>
    </form>
  </div>
</div>
<?php endforeach; ?>

<?php admin_footer(); ?>
