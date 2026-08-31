<?php
require_once __DIR__ . '/auth.php';
start_session();
// Was a plain GET link — a logout endpoint reachable by GET can be forced
// via a third-party page (<img src="…/logout.php">), CSRF-logging a victim
// out. Now requires the same POST + CSRF token as every other mutation.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: dashboard.php'); exit; }
csrf_verify();
$_SESSION = [];
session_destroy();
header('Location: login.php');
exit;
