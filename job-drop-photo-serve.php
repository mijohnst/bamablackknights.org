<?php
// Serves approved Job Drop Night photos without exposing the storage
// directory. Public visitors may only retrieve active entries for the
// currently eligible class while the homepage section is enabled.
require_once __DIR__ . '/admin/auth.php';
start_session();
$pdo = get_pdo();

$can_preview = false;
if (!empty($_SESSION['logged_in'])) {
    $title_check = $pdo->prepare('SELECT officer_title FROM users WHERE id = ?');
    $title_check->execute([$_SESSION['user_id'] ?? 0]);
    $my_title = (string)($title_check->fetchColumn() ?: '');
    $can_preview = is_super_admin() || is_secretary() || in_array($my_title, ['President', 'VP'], true);
}

$id = (int)($_GET['id'] ?? 0);
$filename = basename((string)($_GET['f'] ?? ''));
if ($id < 1 && ($filename === '' || !preg_match('/^[a-zA-Z0-9._-]+$/', $filename))) {
    http_response_code(404); exit;
}

if ($can_preview && $id > 0) {
    $stmt = $pdo->prepare('SELECT filename FROM job_drop_photos WHERE id=?');
    $stmt->execute([$id]);
} else {
    if ($filename === '') { http_response_code(404); exit; }
    $section_visible = true;
    try {
        $value = $pdo->query('SELECT section_visible FROM job_drop_settings WHERE id=1')->fetchColumn();
        if ($value !== false) $section_visible = (bool)$value;
    } catch (PDOException $e) { /* migration not installed yet */ }
    if (!$section_visible) { http_response_code(404); exit; }

    $stmt = $pdo->prepare('SELECT filename FROM job_drop_photos WHERE filename=? AND active=1 AND class_year=?');
    $stmt->execute([$filename, job_drop_eligible_year($pdo)]);
}

$stored = basename((string)($stmt->fetchColumn() ?: ''));
if ($stored === '' || !preg_match('/^[a-zA-Z0-9._-]+$/', $stored)) {
    http_response_code(404); exit;
}

$dir = realpath(__DIR__ . '/job-drop-photos');
$file = $dir ? realpath($dir . DIRECTORY_SEPARATOR . $stored) : false;
if (!$file || !$dir || strpos($file, $dir . DIRECTORY_SEPARATOR) !== 0 || !is_file($file)) {
    http_response_code(404); exit;
}

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime = finfo_file($finfo, $file) ?: 'application/octet-stream';
finfo_close($finfo);
if (!in_array($mime, ['image/jpeg','image/png','image/gif','image/webp'], true)) {
    http_response_code(404); exit;
}

header('Content-Type: ' . $mime);
header('X-Content-Type-Options: nosniff');
header('Content-Length: ' . filesize($file));
header('Cache-Control: ' . ($can_preview ? 'private, no-store' : 'public, max-age=300'));
readfile($file);
