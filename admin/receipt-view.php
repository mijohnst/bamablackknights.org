<?php
require_once __DIR__ . '/auth.php';
require_finance();
$pdo = get_pdo();
$id  = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: purchases.php'); exit; }
$stmt = $pdo->prepare('SELECT receipt_filename, submitted_by FROM purchases WHERE id=?');
$stmt->execute([$id]);
$p = $stmt->fetch();
if (!$p || empty($p['receipt_filename'])) { header('Location: purchases.php'); exit; }
if (!can_view_purchase($p)) { header('Location: purchases.php'); exit; }

$receipts_dir = realpath(__DIR__ . '/receipts');
$file         = realpath(__DIR__ . '/receipts/' . basename($p['receipt_filename']));

// Block path traversal: file must be inside the receipts directory
if (!$file || !$receipts_dir || strpos($file, $receipts_dir . DIRECTORY_SEPARATOR) !== 0) {
    header('Location: purchases.php'); exit;
}
if (!file_exists($file)) { header('Location: purchases.php'); exit; }

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime  = finfo_file($finfo, $file);
finfo_close($finfo);

$allowed_mime = ['image/jpeg','image/png','image/gif','image/webp','image/heic','image/heif','application/pdf'];
if (!in_array($mime, $allowed_mime)) { header('Location: purchases.php'); exit; }

header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="' . basename($file) . '"');
header('Content-Length: ' . filesize($file));
header('Cache-Control: private, no-store');
readfile($file);
exit;
