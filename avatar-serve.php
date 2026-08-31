<?php
// Portal-only profile picture server. Any logged-in user can view any
// other user's avatar (same low-sensitivity visibility as the Directory) —
// never public.
require_once __DIR__ . '/admin/auth.php';
start_session();
if (empty($_SESSION['logged_in'])) { http_response_code(404); exit; }
$pdo = get_pdo();

$id = (int)($_GET['id'] ?? 0);
if (!$id) { http_response_code(404); exit; }

$stmt = $pdo->prepare('SELECT avatar_filename FROM users WHERE id = ? LIMIT 1');
$stmt->execute([$id]);
$row = $stmt->fetch();
if (!$row || !$row['avatar_filename']) { http_response_code(404); exit; }

$filename = basename($row['avatar_filename']);
if ($filename === '' || !preg_match('/^[a-zA-Z0-9._-]+$/', $filename)) {
    http_response_code(404); exit;
}

$dir  = realpath(__DIR__ . '/avatars');
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
header('Cache-Control: private, max-age=3600');
readfile($file);
