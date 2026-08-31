<?php
// Public photo server — only serves a photo if its album is visible,
// unless the request comes from a logged-in admin session (for previewing
// hidden albums in the admin panel).
require_once __DIR__ . '/admin/auth.php';
start_session();
// Was previously "any logged-in session" — that let a plain member account
// bypass the visible=1 check and view hidden/unpublished albums just by
// knowing a filename. Hidden-album preview should be limited to whoever can
// actually manage albums (event-albums.php's own guard), same as admin.
$is_admin_session = !empty($_SESSION['logged_in']) && can_manage_members();
$pdo = get_pdo();

$filename = basename($_GET['f'] ?? '');
if ($filename === '' || !preg_match('/^[a-zA-Z0-9._-]+$/', $filename)) {
    http_response_code(404); exit;
}

if ($is_admin_session) {
    $stmt = $pdo->prepare('SELECT filename FROM event_photos WHERE filename = ? LIMIT 1');
} else {
    $stmt = $pdo->prepare(
        'SELECT p.filename FROM event_photos p JOIN event_albums a ON a.id = p.album_id WHERE p.filename = ? AND a.visible = 1 LIMIT 1'
    );
}
$stmt->execute([$filename]);
if (!$stmt->fetch()) { http_response_code(404); exit; }

$dir  = realpath(__DIR__ . '/event-photos');
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
header('Cache-Control: ' . ($is_admin_session ? 'private, no-store' : 'public, max-age=86400'));
readfile($file);
