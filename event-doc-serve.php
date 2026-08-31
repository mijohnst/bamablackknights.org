<?php
// Public document server — only serves a file if its album is visible,
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
    $stmt = $pdo->prepare("SELECT filename, original_name FROM event_documents WHERE filename = ? AND type = 'file' LIMIT 1");
} else {
    $stmt = $pdo->prepare(
        "SELECT d.filename, d.original_name FROM event_documents d JOIN event_albums a ON a.id = d.album_id WHERE d.filename = ? AND d.type = 'file' AND a.visible = 1 LIMIT 1"
    );
}
$stmt->execute([$filename]);
$doc = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$doc) { http_response_code(404); exit; }

$dir  = realpath(__DIR__ . '/event-docs');
$file = $dir ? realpath($dir . '/' . $filename) : false;
if (!$file || !$dir || strpos($file, $dir . DIRECTORY_SEPARATOR) !== 0 || !is_file($file)) {
    http_response_code(404); exit;
}

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime  = finfo_file($finfo, $file) ?: 'application/octet-stream';
finfo_close($finfo);

header('Content-Type: ' . $mime);
header('X-Content-Type-Options: nosniff');
header('Content-Length: ' . filesize($file));
$safe_name = str_replace(["\r", "\n", '"'], '', $doc['original_name'] ?: basename($file));
header('Content-Disposition: inline; filename="' . $safe_name . '"');
header('Cache-Control: ' . ($is_admin_session ? 'private, no-store' : 'public, max-age=86400'));
readfile($file);
