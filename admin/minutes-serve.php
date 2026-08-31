<?php
require_once __DIR__ . '/auth.php';
require_login();
if (!can_manage_members() && !is_secretary() && !is_treasurer()) { header('Location: dashboard.php?denied=1'); exit; }
$pdo = get_pdo();

$id = (int)($_GET['id'] ?? 0);
if ($id < 1) { header('Location: minutes.php'); exit; }

$stmt = $pdo->prepare("SELECT minutes_file, title FROM club_meetings WHERE id=?");
$stmt->execute([$id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$row || !$row['minutes_file']) { http_response_code(404); echo 'File not found.'; exit; }

$fname = basename($row['minutes_file']);
$path  = __DIR__ . '/minutes-files/' . $fname;
if (!is_file($path)) { http_response_code(404); echo 'File not found on disk.'; exit; }

$ext = strtolower(pathinfo($fname, PATHINFO_EXTENSION));
$mime_map = ['pdf'=>'application/pdf','doc'=>'application/msword',
    'docx'=>'application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
$mime = $mime_map[$ext] ?? 'application/octet-stream';

$safe_title = preg_replace('/[^a-zA-Z0-9\-_ ]/', '', $row['title']);
$dl_name = trim($safe_title) ?: 'minutes';
$dl_name = str_replace(' ', '-', $dl_name) . '.' . $ext;

header('Content-Type: ' . $mime);
header('Content-Disposition: inline; filename="' . $dl_name . '"');
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, no-cache');
readfile($path);
exit;
