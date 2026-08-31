<?php
require_once __DIR__ . '/auth.php';
require_member_admin();
$pdo = get_pdo();

// Same validate/store/rename pattern as leadership.php's officer photos,
// just a separate directory so this feature's cleanup/lifecycle stays
// independent of leadership and the site_photos gallery.
function save_spotlight_photo(string $field): ?string {
    if (empty($_FILES[$field]['name'])) return null;
    $file = $_FILES[$field];
    if ($file['error'] !== UPLOAD_ERR_OK) return null;
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = finfo_file($finfo, $file['tmp_name']); finfo_close($finfo);
    if (!in_array($mime, ['image/jpeg','image/png','image/gif','image/webp'])) return null;
    if ($file['size'] > 10*1024*1024) return null; // 10MB limit
    $ext  = ['image/jpeg'=>'jpg','image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp'][$mime];
    $name = 'spotlight-' . bin2hex(random_bytes(4)) . '.' . $ext;
    $dir  = __DIR__ . '/../spotlight-photos/';
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) return null;
    if (!move_uploaded_file($file['tmp_name'], $dir . $name)) return null;
    return $name;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $rows = $pdo->query('SELECT setting_key, setting_type, setting_value FROM site_settings')->fetchAll();
    foreach ($rows as $row) {
        $key = $row['setting_key'];
        if ($key === 'spotlight_photo') {
            // Keep the existing photo unless a new one was actually uploaded.
            $val = save_spotlight_photo('spotlight_photo_file') ?? $row['setting_value'];
        } elseif ($key === 'president_letter') {
            // The only setting rendered back as raw HTML (Quill editor here,
            // and unescaped on the public president-letter.html page) — cut
            // down to a safe tag/attribute allow-list rather than escaped.
            $val = sanitize_rich_html($_POST[$key] ?? '');
        } else {
            // Windows browsers submit textarea line breaks as \r\n — normalize to
            // \n so stored values are consistent regardless of the editor's OS,
            // and so the front-end's paragraph-split logic (which looks for a
            // literal blank line, "\n\n") reliably matches.
            $val = trim(str_replace(["\r\n", "\r"], "\n", $_POST[$key] ?? ''));
        }
        $pdo->prepare('UPDATE site_settings SET setting_value=? WHERE setting_key=?')->execute([$val, $key]);
    }
    flash('success', 'Settings saved. Changes will appear on the site within a few minutes.');
    header('Location: settings.php'); exit;
}

$settings = []; $labels = []; $types = [];
$rows = $pdo->query('SELECT * FROM site_settings ORDER BY id')->fetchAll();
foreach ($rows as $r) {
    $settings[$r['setting_key']] = $r['setting_value'];
    $labels[$r['setting_key']]   = $r['setting_label'];
    $types[$r['setting_key']]    = $r['setting_type'];
}

$sections = [
    'Homepage Hero'       => ['hero_subtitle','hero_cta_text','hero_cta_url'],
    'Homepage Stats'      => ['stat_current_cadets','stat_annual_events','stat_years_active'],
    'Parent of the Month' => ['spotlight_name','spotlight_photo','spotlight_description'],
    'Membership'          => ['membership_dues','membership_description'],
    'President\'s Letter' => ['president_letter','president_name','president_title'],
    'Social & Links'      => ['facebook_url'],
    'Footer Resources'    => ['footer_resources'],
    'Email Signatures'    => ['signature_president','signature_vp','signature_secretary','signature_treasurer'],
];

admin_header('Site Settings');
echo show_flash();
?>
<!-- Quill rich text editor (CDN) -->
<link href="https://cdn.quilljs.com/1.3.7/quill.snow.css" rel="stylesheet">
<script src="https://cdn.quilljs.com/1.3.7/quill.min.js"></script>
<style>
.ql-editor{min-height:300px;font-family:"Segoe UI",Arial,sans-serif;font-size:1rem}
.ql-toolbar{border-radius:4px 4px 0 0}
.ql-container{border-radius:0 0 4px 4px;background:#fff}
</style>

<div class="page-head">
  <h1>Site Settings</h1>
  <a href="dashboard.php" class="btn btn-secondary">← Dashboard</a>
</div>
<p style="font-size:.82rem;color:#5a6a7a;margin-bottom:1.5rem">
  Changes here update the main website automatically.
  <strong>Parent of the Month:</strong> shows near the bottom of the homepage once a name is filled in — leave it blank to hide the section.
  <strong>Footer Resources:</strong> one per line as <code>Title|URL</code>.
  Looking for the cadet birthday / dues renewal / meeting reminder emails? Those moved to
  <a href="automated-emails.php">Automated Emails</a>.
  <strong>Email Signatures:</strong> automatically appended to the bottom of messages sent from
  <a href="email.php">Compose Email</a>, based on which "From" address is selected.
</p>

<form method="POST" id="settings-form" enctype="multipart/form-data">
  <?= csrf_field() ?>
  <?php foreach ($sections as $section => $keys): ?>
  <div class="card" style="margin-bottom:1.25rem">
    <h2 style="margin-bottom:1.25rem;font-size:.82rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:#5a6a7a"><?= h($section) ?></h2>
    <?php foreach ($keys as $key): if (!isset($settings[$key])) continue;
      $val   = $settings[$key] ?? '';
      $label = $labels[$key]   ?? $key;
      $type  = $types[$key]    ?? 'text';
    ?>
    <div class="form-group">
      <label><?= h($label) ?></label>
      <?php if ($key === 'president_letter'): ?>
        <!-- Rich text editor for president's letter -->
        <div id="quill-editor" style="margin-bottom:.5rem"><?= $val ?></div>
        <input type="hidden" name="president_letter" id="president_letter_input">
        <p style="font-size:.72rem;color:#9aa5b4;margin-top:.35rem">Use the toolbar above to format text, add links, or insert images. Looks the same as it will on the website.</p>
      <?php elseif ($key === 'spotlight_photo'): ?>
        <?php if ($val && preg_match('/^[a-zA-Z0-9._-]+$/', $val)): ?>
          <img src="/spotlight-photos/<?= h($val) ?>" alt="" style="width:100px;height:100px;border-radius:50%;object-fit:cover;display:block;margin-bottom:.5rem">
        <?php endif; ?>
        <input type="file" name="spotlight_photo_file" accept="image/*" style="padding:.5rem;font-size:.9rem">
        <p style="font-size:.72rem;color:#9aa5b4;margin-top:.35rem">Upload to replace<?= $val ? ' — leave blank to keep the current photo' : '' ?>. Square photos work best.</p>
      <?php elseif ($type === 'textarea'): ?>
        <textarea name="<?= h($key) ?>" rows="<?= $key==='membership_description' ? 6 : 4 ?>"><?= h($val) ?></textarea>
      <?php else: ?>
        <input type="text" name="<?= h($key) ?>" value="<?= h($val) ?>" placeholder="<?= $type==='url'?'e.g. https://... or #section':'' ?>">
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endforeach; ?>
  <button type="submit" class="btn btn-primary" style="min-width:180px">Save All Settings</button>
</form>

<script>
// Initialise Quill rich text editor
var quill = new Quill('#quill-editor', {
  theme: 'snow',
  modules: {
    toolbar: [
      [{ 'header': [1,2,3,false] }],
      ['bold','italic','underline','strike'],
      [{ 'color': [] },{ 'background': [] }],
      [{ 'size': ['small',false,'large','huge'] }],
      [{ 'align': [] }],
      [{ 'list': 'ordered' },{ 'list': 'bullet' }],
      ['link','image'],
      ['clean']
    ]
  }
});

// Populate hidden input initially so a save without editing still works
document.getElementById('president_letter_input').value = quill.root.innerHTML;

// Update hidden input whenever content changes
quill.on('text-change', function() {
  document.getElementById('president_letter_input').value = quill.root.innerHTML;
});

// Also update on submit as a safety net
document.getElementById('settings-form').addEventListener('submit', function() {
  document.getElementById('president_letter_input').value = quill.root.innerHTML;
});
</script>

<?php admin_footer(); ?>
