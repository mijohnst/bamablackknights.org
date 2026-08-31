<?php
require_once __DIR__ . '/auth.php';
require_login();
if (!can_manage_members() && !is_treasurer()) {
    header('Location: dashboard.php?denied=1'); exit;
}

$pdo = get_pdo();
$id  = (int)($_GET['id'] ?? 0);
$dl  = isset($_GET['download']);

if (!$id) { header('Location: vault.php'); exit; }

$stmt = $pdo->prepare('SELECT * FROM vault_documents WHERE id=?');
$stmt->execute([$id]);
$doc = $stmt->fetch();

if (!$doc || !preg_match('/^[a-zA-Z0-9._-]+$/', $doc['filename'])) {
    header('Location: vault.php'); exit;
}

$vault_dir = realpath(__DIR__ . '/vault');
$file      = realpath(__DIR__ . '/vault/' . $doc['filename']);
if (!$file || !$vault_dir || strpos($file, $vault_dir . DIRECTORY_SEPARATOR) !== 0) {
    header('Location: vault.php'); exit;
}
if (!file_exists($file)) {
    flash('error', 'File not found on server.');
    header('Location: vault.php'); exit;
}

// Re-verify MIME from disk — do not trust the DB-stored value
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime  = finfo_file($finfo, $file) ?: 'application/octet-stream';
finfo_close($finfo);
// Only allow known safe types; force download for anything else
$allowed_inline = ['application/pdf','image/jpeg','image/png','image/gif','image/webp'];
if (!in_array($mime, $allowed_inline)) $mime = 'application/octet-stream';
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($file));

$safe_name = str_replace(["\r","\n",'"'], ['','',''], basename($doc['filename']));
if ($dl) {
    header('Content-Disposition: attachment; filename="' . $safe_name . '"');
} else {
    header('Content-Disposition: inline; filename="' . $safe_name . '"');
}

readfile($file);
exit;
