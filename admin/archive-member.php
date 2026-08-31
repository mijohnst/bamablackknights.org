<?php
require_once __DIR__ . '/auth.php';
require_member_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: index.php'); exit; }
csrf_verify();

$id       = (int)($_POST['id']       ?? 0);
$archived = (int)($_POST['archived'] ?? 0);

if ($id) {
    get_pdo()->prepare('UPDATE members SET archived = ? WHERE id = ?')->execute([$archived, $id]);
    flash('success', $archived ? 'Member archived.' : 'Member restored to active roster.');
}
header('Location: index.php');
exit;
