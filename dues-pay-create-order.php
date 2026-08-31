<?php
/**
 * Pay Dues Online — Create Order
 * Locks in the price server-side and creates the PayPal order. member_id
 * is never trusted from the request body — it's re-derived only from the
 * session token issued by dues-pay-lookup.php. years is re-validated
 * against the cadet's real payable window and current paid-years on file,
 * so a tampered client payload can never widen what's charged.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: https://alabamafalcons.org');

require_once __DIR__ . '/admin/auth.php';
require_once __DIR__ . '/admin/form-guard.php';
require_once __DIR__ . '/admin/lib/paypal.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit();
}

$input   = file_get_contents('php://input');
$payload = json_decode($input, true);

if (!$payload) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid request data.']);
    exit();
}

$pdo = get_pdo();

if (rate_limited($pdo, 'dues_pay_create_order')) {
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => 'Too many attempts from your network. Please try again later or email treasurer@alabamafalcons.org.']);
    exit();
}

start_verification_session();
$token = trim($payload['verifyToken'] ?? '');
$entry = $_SESSION['dues_verified'][$token] ?? null;

if (!$entry || ($entry['expires'] ?? 0) < time()) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Your session has expired. Please look up your cadet again.']);
    exit();
}

$member_id = (int)$entry['member_id'];
$stmt = $pdo->prepare('SELECT * FROM members WHERE id = ? AND archived = 0');
$stmt->execute([$member_id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'We could not find that record. Please look up your cadet again.']);
    exit();
}

$cadet_years  = cadet_dues_years($row['class_year'] ?? '');
$paid_years   = parse_dues_years($row['membership_paid_years']);
$requested_in = array_map('strval', (array)($payload['years'] ?? []));
$requested    = array_values(array_diff(array_intersect($requested_in, $cadet_years), $paid_years));

if (!$requested) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Please select at least one unpaid year.']);
    exit();
}

$amount = dues_years_price(array_merge($paid_years, $requested), $cadet_years)
        - dues_years_price($paid_years, $cadet_years);

if ($amount <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Nothing due for the selected year(s).']);
    exit();
}

$reference_id = 'dues-' . $member_id . '-' . substr(hash('sha256', implode(',', $requested)), 0, 12);
$request_id   = 'create-' . hash('sha256', $token . implode(',', $requested));

$order = paypal_create_order((float)$amount, $reference_id, $request_id);
if (!$order['success']) {
    error_log('dues-pay-create-order: ' . $order['error']);
    http_response_code(502);
    echo json_encode(['success' => false, 'error' => 'We could not start the PayPal checkout. Please try again in a moment.']);
    exit();
}

$pdo->prepare(
    'INSERT INTO paypal_dues_orders (member_id, paypal_order_id, years, amount, status)
     VALUES (?, ?, ?, ?, ?)'
)->execute([$member_id, $order['order_id'], implode(',', $requested), $amount, 'created']);

$_SESSION['dues_verified'][$token]['pending_order'] = [
    'order_id' => $order['order_id'],
    'years'    => $requested,
    'amount'   => $amount,
];

echo json_encode(['success' => true, 'orderId' => $order['order_id']]);
