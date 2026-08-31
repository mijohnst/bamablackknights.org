<?php
// Authenticated preview for pending Job Drop Night submissions. The upload
// directory itself is denied by .htaccess so unapproved photos are never
// directly enumerable or public.
require_once __DIR__ . '/admin/auth.php';
start_session();
if (empty($_SESSION['logged_in'])) { http_response_code(404); exit; }

$pdo = get_pdo();
$title_check = $pdo->prepare('SELECT officer_title FROM users WHERE id = ?');
$title_check->execute([$_SESSION['user_id'] ?? 0]);
$my_title = (string)($title_check->fetchColumn() ?: '');
if (!is_super_admin() && !is_secretary() && !in_array($my_title, ['President', 'VP'], true)) {
    http_response_code(404); exit;
}

$id = (int)($_GET['id'] ?? 0);
if ($id < 1) { http_response_code(404); exit; }

$stmt = $pdo->prepare("SELECT filename FROM job_drop_submissions WHERE id=? AND status='pending'");
$stmt->execute([$id]);
$filename = basename((string)($stmt->fetchColumn() ?: ''));
if ($filename === '' || !preg_match('/^[a-zA-Z0-9._-]+$/', $filename)) {
    http_response_code(404); exit;
}

$dir = realpath(__DIR__ . '/job-drop-submissions');
$file = $dir ? realpath($dir . DIRECTORY_SEPARATOR . $filename) : false;
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
header('Cache-Control: private, no-store');
readfile($file);
