<?php
header('Content-Type: application/json');
header('Cache-Control: public, max-age=60');
require_once __DIR__ . '/admin/config.php';
try {
    $pdo = new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4', DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES=>true, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $rows = $pdo->query("SELECT * FROM announcements WHERE active=1 AND (expires_at IS NULL OR expires_at > NOW()) ORDER BY id DESC LIMIT 3")->fetchAll();
    echo json_encode(['success'=>true,'announcements'=>$rows]);
} catch (Exception $e) { http_response_code(500); echo json_encode(['success'=>false,'announcements'=>[]]); }
