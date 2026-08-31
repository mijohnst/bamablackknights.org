<?php
// Admin-only preview of a member-submitted photo awaiting review. Approved
// submissions get copied into event-photos/ and served by
// event-photo-serve.php from then on — this endpoint is only for the
// review queue itself, never public.
require_once __DIR__ . '/admin/auth.php';
start_session();
if (empty($_SESSION['logged_in']) || !can_manage_members()) {
    http_response_code(404); exit;
}
$pdo = get_pdo();

$id = (int)($_GET['id'] ?? 0);
if (!$id) { http_response_code(404); exit; }

$stmt = $pdo->prepare('SELECT filename FROM photo_submissions WHERE id = ? LIMIT 1');
$stmt->execute([$id]);
$row = $stmt->fetch();
if (!$row) { http_response_code(404); exit; }

$filename = basename($row['filename']);
if ($filename === '' || !preg_match('/^[a-zA-Z0-9._-]+$/', $filename)) {
    http_response_code(404); exit;
}

$dir  = realpath(__DIR__ . '/photo-submissions');
$file = $dir ? realpath($dir . '/' . $filename) : false;
if (!$file || !$dir || strpos($file, $dir . DIRECTORY_SEPARATOR) !== 0 || !is_file($file)) {
    http_response_code(404); exit;
}

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime  = finfo_file($finfo, $file) ?: 'application/octet-stream';
finfo_close($finfo);
if (!in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'])) {
    http_response_code(404); exit;
}

header('Content-Type: ' . $mime);
header('X-Content-Type-Options: nosniff');
header('Content-Length: ' . filesize($file));
header('Cache-Control: private, no-store');
readfile($file);
