<?php
header('Content-Type: application/json');
header('Cache-Control: public, max-age=300');
require_once __DIR__ . '/admin/config.php';
try {
    $pdo = new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4', DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES=>true, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $rows = $pdo->query("SELECT * FROM sponsors WHERE active=1 ORDER BY FIELD(level,'presenting','gold','silver','individual','other'), sort_order ASC, name ASC")->fetchAll();
    echo json_encode(['success'=>true,'sponsors'=>$rows]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success'=>false,'sponsors'=>[]]);
    error_log('sponsors-feed: '.$e->getMessage());
}
