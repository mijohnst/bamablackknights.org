<?php
require_once __DIR__ . '/admin/auth.php';

header('Content-Type: application/json');
// Must be set on every response, not just an OPTIONS preflight — see
// membership-handler.php for why.
header('Access-Control-Allow-Origin: https://alabamafalcons.org');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Methods: GET, OPTIONS');
    http_response_code(200); exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405); echo json_encode(['error' => 'Method not allowed']); exit();
}

try {
    $pdo = get_pdo();
    $rows = $pdo->query(
        "SELECT id, title, description, event_date, location, spots_needed,
                (SELECT COUNT(*) FROM volunteer_signups WHERE opportunity_id = o.id) AS filled
         FROM volunteer_opportunities o
         WHERE active = 1
         ORDER BY event_date IS NULL, event_date ASC, id DESC"
    )->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    error_log('volunteer-opportunities-public DB error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'A server error occurred.']);
    exit;
}

// Public payload — id (just an internal integer, used to deep-link to a
// specific opportunity) plus title/description/date/location/spot counts.
// No signup rosters or any member/user PII.
$out = array_map(function ($r) {
    return [
        'id'           => (int)$r['id'],
        'title'        => $r['title'],
        'description'  => $r['description'],
        'event_date'   => $r['event_date'],
        'location'     => $r['location'],
        'spots_needed' => (int)$r['spots_needed'],
        'filled'       => (int)$r['filled'],
    ];
}, $rows);

http_response_code(200);
echo json_encode(['success' => true, 'opportunities' => $out]);
