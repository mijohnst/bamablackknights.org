<?php
require_once __DIR__ . '/auth.php';
require_member_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: index.php'); exit; }
csrf_verify();

$id = (int)($_POST['id'] ?? 0);
if ($id) {
    $pdo = get_pdo();
    $pdo->prepare('DELETE FROM members WHERE id = ?')->execute([$id]);
    flash('success', 'Member deleted.');
}
header('Location: index.php');
exit;
