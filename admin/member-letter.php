<?php
require_once __DIR__ . '/auth.php';
require_login();
if (!can_manage_members() && !is_secretary() && !is_treasurer()) { header('Location: dashboard.php?denied=1'); exit; }
$pdo = get_pdo();

// ── Settings helper ────────────────────────────────────────────────────────
$setting = function(string $key, string $default = '') use ($pdo): string {
    static $cache = [];
    if (!isset($cache[$key])) {
        $s = $pdo->prepare("SELECT setting_value FROM site_settings WHERE setting_key=? LIMIT 1");
        $s->execute([$key]);
        $cache[$key] = $s->fetchColumn() ?: $default;
    }
    return $cache[$key];
};

$club_name = $setting('club_name', 'USAFA Parents Club of Alabama');
$club_website = $setting('website_url', 'alabamafalcons.org');

// ── Member search ──────────────────────────────────────────────────────────
$member_id = (int)($_GET['member_id'] ?? 0);
$q         = trim($_GET['q'] ?? '');
$member    = null;
$results   = [];

if ($member_id > 0) {
    $s = $pdo->prepare("SELECT * FROM members WHERE id=? AND archived=0 LIMIT 1");
    $s->execute([$member_id]);
    $member = $s->fetch(PDO::FETCH_ASSOC);
}

if (!$member && $q !== '') {
    $like = '%' . $q . '%';
    $s = $pdo->prepare("SELECT id, parent1_first_name, parent1_last_name, cadet_first_name, cadet_middle_name, cadet_last_name, cadet_suffix, membership_year, membership_paid
        FROM members WHERE archived=0
        AND (parent1_first_name LIKE ? OR parent1_last_name LIKE ? OR cadet_first_name LIKE ? OR cadet_last_name LIKE ?)
        ORDER BY parent1_last_name ASC, parent1_first_name ASC LIMIT 30");
    $s->execute([$like, $like, $like, $like]);
    $results = $s->fetchAll(PDO::FETCH_ASSOC);
}

// If we have a member, render printable letter
if ($member) {
    $letter_dues_years  = parse_dues_years($member['membership_paid_years'] ?? '');
    $letter_cadet_years = cadet_dues_years($member['class_year'] ?? '');
    $years_label = $letter_dues_years ? implode(', ', $letter_dues_years) : 'None on file';
    $amount     = '$' . dues_years_price($letter_dues_years, $letter_cadet_years);
    $paid_label = $member['membership_paid'] ? 'Paid' : 'Unpaid';
    $paid_color = $member['membership_paid'] ? '#1b5e20' : '#A6192E';
    $cadet_full = cadet_full_name($member);
    $parent_full = trim($member['parent1_first_name'] . ' ' . $member['parent1_last_name']);
    $squadron = $member['squadron_yr2_4'] ?: ($member['fall_squadron'] ?: $member['bct_squadron']);
    $letter_date = date('F j, Y');
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Membership Letter — <?= h($parent_full) ?></title>
<link rel="icon" type="image/png" href="../logo01.png" />
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Times New Roman',Times,serif;font-size:12pt;color:#000;background:#fff;padding:0.75in}
@media print{
  body{padding:0}
  .no-print{display:none!important}
  @page{margin:0.85in}
}
.letterhead{text-align:center;border-bottom:2px solid #003594;padding-bottom:.6rem;margin-bottom:1.4rem}
.club-name{font-size:18pt;font-weight:bold;color:#002554;letter-spacing:.02em}
.club-sub{font-size:10pt;color:#003594;margin-top:.15rem}
.letter-date{text-align:right;margin-bottom:1.4rem;font-size:11pt}
.to-line{margin-bottom:1.4rem;font-size:11pt}
.body-text{line-height:1.7;margin-bottom:1rem;font-size:11.5pt}
.member-box{border:1px solid #003594;border-radius:4px;padding:.75rem 1rem;margin:1.2rem 0;background:#f8faff}
.member-box table{width:100%;border-collapse:collapse;font-size:11pt}
.member-box td{padding:.25rem .5rem;vertical-align:top}
.member-box td:first-child{font-weight:bold;width:45%;color:#003594}
.sig-block{margin-top:2.5rem}
.sig-line{border-top:1px solid #000;margin-top:2rem;padding-top:.25rem;font-size:10.5pt}
.sig-name{font-weight:bold;margin-top:.25rem}
.print-btn{position:fixed;top:1rem;right:1rem;background:#003594;color:#fff;border:none;padding:.6rem 1.2rem;border-radius:5px;font-size:14px;cursor:pointer;font-family:sans-serif}
.print-btn:hover{background:#002554}
.back-link{position:fixed;top:1rem;left:1rem;background:#f0f2f5;color:#002554;border:none;padding:.5rem 1rem;border-radius:5px;font-size:13px;text-decoration:none;font-family:sans-serif}
</style>
</head>
<body>

<a href="member-letter.php" class="back-link no-print">← Back</a>
<button class="print-btn no-print" onclick="window.print()">🖨️ Print Letter</button>

<!-- Letterhead -->
<div class="letterhead">
  <div class="club-name"><?= h($club_name) ?></div>
  <div class="club-sub"><?= h($club_website) ?> &nbsp;·&nbsp; secretary@alabamafalcons.org</div>
</div>

<div class="letter-date"><?= h($letter_date) ?></div>

<div class="to-line">To Whom It May Concern:</div>

<p class="body-text">
  This letter confirms that <strong><?= h($parent_full) ?></strong> is a
  <?= $member['membership_paid'] ? 'current paid' : 'registered' ?>
  member of the <strong><?= h($club_name) ?></strong>.
</p>

<div class="member-box">
  <table>
    <tr>
      <td>Parent / Member Name:</td>
      <td><?= h($parent_full) ?></td>
    </tr>
    <?php if ($cadet_full): ?>
    <tr>
      <td>Cadet Name:</td>
      <td><?= h($cadet_full) ?></td>
    </tr>
    <?php endif; ?>
    <?php if ($squadron): ?>
    <tr>
      <td>Squadron:</td>
      <td><?= h($squadron) ?></td>
    </tr>
    <?php endif; ?>
    <?php if ($member['class_year'] ?? ''): ?>
    <tr>
      <td>Class Year:</td>
      <td><?= h($member['class_year']) ?></td>
    </tr>
    <?php endif; ?>
    <tr>
      <td>Years Paid:</td>
      <td><?= h($years_label) ?> (<?= $amount ?>)</td>
    </tr>
    <tr>
      <td>Payment Status:</td>
      <td style="font-weight:bold;color:<?= $paid_color ?>"><?= $paid_label ?></td>
    </tr>
    <?php if ($member['membership_paid_through'] ?? ''): ?>
    <tr>
      <td>Paid Through:</td>
      <td><?= h($member['membership_paid_through']) ?></td>
    </tr>
    <?php endif; ?>
  </table>
</div>

<p class="body-text">
  The <?= h($club_name) ?> is a volunteer parent support organization for families of cadets attending the United States Air Force Academy. Membership supports club activities, cadet events, and community programs throughout the academic year.
</p>

<p class="body-text">
  If you have any questions regarding this letter, please contact us at
  secretary@alabamafalcons.org.
</p>

<p class="body-text">Sincerely,</p>

<div class="sig-block">
  <div class="sig-line">
    <div class="sig-name">Secretary, <?= h($club_name) ?></div>
  </div>
</div>

</body>
</html>
<?php
    exit;
}

// ── Normal admin view (search + select) ─────────────────────────────────
admin_header('Member Status Letter');
?>
<style>
.letter-search-form{background:#fff;border-radius:8px;box-shadow:0 1px 4px rgba(0,0,0,.08);padding:1.5rem;margin-bottom:1.5rem}
.result-table{width:100%;border-collapse:collapse;font-size:.85rem}
.result-table th{padding:.55rem 1rem;font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#5a6a7a;background:#f7f9fc;text-align:left}
.result-table td{padding:.65rem 1rem;border-top:1px solid #f0f2f5;vertical-align:middle}
.result-table tr:hover td{background:#fafbfc}
.paid-yes{display:inline-block;padding:.12rem .4rem;border-radius:3px;font-size:.7rem;font-weight:700;background:#e8f5e9;color:#1b5e20}
.paid-no{display:inline-block;padding:.12rem .4rem;border-radius:3px;font-size:.7rem;font-weight:700;background:#fff3f3;color:#A6192E}
</style>

<div class="page-head">
  <h1>Member Status Letter</h1>
  <a href="dashboard.php" class="btn btn-secondary">← Dashboard</a>
</div>

<div class="letter-search-form">
  <p style="font-size:.85rem;color:#5a6a7a;margin-bottom:1rem">
    Search for a member and click <strong>Generate Letter</strong> to produce a printable membership confirmation letter.
  </p>
  <form method="GET" style="display:flex;gap:.6rem;align-items:flex-end;flex-wrap:wrap">
    <div class="form-group" style="margin:0;flex:1;min-width:200px">
      <label>Search by parent or cadet name</label>
      <input type="text" name="q" class="form-control" value="<?= h($q) ?>" placeholder="Last name, first name, or cadet name" autofocus>
    </div>
    <button type="submit" class="btn btn-primary">Search</button>
    <?php if ($q): ?><a href="member-letter.php" class="btn btn-secondary">Clear</a><?php endif; ?>
  </form>
</div>

<?php if ($q && empty($results)): ?>
  <p style="color:#9aa5b4">No members found matching "<?= h($q) ?>".</p>
<?php elseif (!empty($results)): ?>
<div class="card" style="padding:0;overflow-x:auto">
<table class="result-table">
  <thead>
    <tr>
      <th>Parent Name</th>
      <th>Cadet Name</th>
      <th>Dues Last Updated</th>
      <th>Status</th>
      <th></th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($results as $r): ?>
  <tr>
    <td style="font-weight:600"><?= h($r['parent1_first_name'] . ' ' . $r['parent1_last_name']) ?></td>
    <td><?= h(cadet_full_name($r)) ?: '<span style="color:#c0c8d4">—</span>' ?></td>
    <td><?= $r['membership_year'] ? h($r['membership_year']) : '<span style="color:#c0c8d4">—</span>' ?></td>
    <td>
      <?php if ($r['membership_paid']): ?>
        <span class="paid-yes">✓ Paid</span>
      <?php else: ?>
        <span class="paid-no">Unpaid</span>
      <?php endif; ?>
    </td>
    <td>
      <a href="member-letter.php?member_id=<?= (int)$r['id'] ?>" class="btn btn-primary btn-sm" target="_blank">
        Generate Letter
      </a>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php elseif (!$q): ?>
<p style="color:#9aa5b4;font-size:.9rem">Enter a name above to search active members.</p>
<?php endif; ?>

<?php admin_footer(); ?>
