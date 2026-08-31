<?php
header('Content-Type: application/json');
header('Cache-Control: public, max-age=300');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/admin/config.php';

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_EMULATE_PREPARES => true,
         PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    $rows = $pdo->query(
        "SELECT * FROM events WHERE visible=1 ORDER BY sort_order ASC, event_date ASC"
    )->fetchAll();
    echo json_encode(['success' => true, 'events' => $rows]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false]);
}
