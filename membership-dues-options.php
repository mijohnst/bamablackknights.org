<?php
/** Returns server-derived membership years for a new applicant's selected class year. */
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate');
require_once __DIR__ . '/admin/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit();
}

$payload = json_decode(file_get_contents('php://input'), true);
$class_year = trim((string)($payload['graduationYear'] ?? ''));
if (!in_array($class_year, ['2027', '2028', '2029', '2030', 'USMAPS'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Select a valid class year.']);
    exit();
}

echo json_encode(['success' => true, 'years' => cadet_dues_years($class_year)]);
