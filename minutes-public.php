<?php
// Public minutes viewer for board members without an admin login.
// Requires a valid per-meeting token — not guessable, not browsable.
require_once __DIR__ . '/admin/auth.php';
$pdo = get_pdo();

$id    = (int)($_GET['id'] ?? 0);
$token = $_GET['token'] ?? '';
if ($id < 1 || !preg_match('/^[a-f0-9]{48}$/', $token)) { http_response_code(404); echo 'Not found.'; exit; }

$stmt = $pdo->prepare('SELECT minutes_file, minutes_token, title FROM club_meetings WHERE id=?');
$stmt->execute([$id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row || !$row['minutes_file'] || !$row['minutes_token'] || !hash_equals($row['minutes_token'], $token)) {
    http_response_code(404); echo 'Not found.'; exit;
}

$fname = basename($row['minutes_file']);
if (!preg_match('/^[a-zA-Z0-9._-]+$/', $fname)) { http_response_code(404); echo 'Not found.'; exit; }

$dir  = realpath(__DIR__ . '/admin/minutes-files');
$path = $dir ? realpath($dir . DIRECTORY_SEPARATOR . $fname) : false;
if (!$path || !$dir || strpos($path, $dir . DIRECTORY_SEPARATOR) !== 0 || !is_file($path)) {
    http_response_code(404); echo 'File not found on server.'; exit;
}

$ext = strtolower(pathinfo($fname, PATHINFO_EXTENSION));
$expected_mime = ['pdf' => 'application/pdf', 'doc' => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'][$ext] ?? null;

// Re-validate against the file's actual content rather than trusting the
// extension — matches the hardening every other serve script in this
// codebase does. Anything that doesn't match what this extension should
// be falls back to a generic download type, so a browser can't be tricked
// into rendering (and executing) a mis-tagged upload inline.
$finfo    = finfo_open(FILEINFO_MIME_TYPE);
$detected = finfo_file($finfo, $path) ?: '';
finfo_close($finfo);
// .docx is a zip container — some libmagic builds report it as
// application/zip rather than the Office-specific type, so accept either.
$mime = ($detected === $expected_mime || ($ext === 'docx' && $detected === 'application/zip'))
    ? $expected_mime
    : 'application/octet-stream';

$safe_title = preg_replace('/[^a-zA-Z0-9\-_ ]/', '', $row['title']);
$dl_name = (trim($safe_title) ?: 'minutes');
$dl_name = str_replace(' ', '-', $dl_name) . '.' . $ext;

header('Content-Type: ' . $mime);
header('X-Content-Type-Options: nosniff');
header('Content-Disposition: inline; filename="' . $dl_name . '"');
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, no-store');
readfile($path);
