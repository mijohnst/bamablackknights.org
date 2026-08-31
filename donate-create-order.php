<?php
/**
 * Public Donation — Create Order
 * Unlike dues-pay-create-order.php, there's no cadet identity to verify
 * here — anyone can donate any amount, so this endpoint trusts the amount
 * the donor typed (after validating it's a sane positive dollar figure)
 * rather than re-deriving it from a member record. donor_name/donor_email
 * are stored purely for the receipt/notification emails sent once the
 * order is captured — they never affect what PayPal actually charges.
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

if (honeypot_tripped($payload, 'website')) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid request.']);
    exit();
}

$pdo = get_pdo();

if (rate_limited($pdo, 'donate_create_order')) {
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => 'Too many attempts from your network. Please try again later or email treasurer@alabamafalcons.org.']);
    exit();
}

$amount      = (float)($payload['amount'] ?? 0);
$donor_name  = trim((string)($payload['donorName'] ?? ''));
$donor_email = trim((string)($payload['donorEmail'] ?? ''));

// $25,000 sanity ceiling — a real donation this large would come through
// the treasurer directly, not this public form; this just catches a typo
// or malformed client payload, not a legitimate high-dollar gift.
if ($amount < 1 || $amount > 25000) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Please enter a donation amount between $1 and $25,000.']);
    exit();
}
if (!filter_var($donor_email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Please enter a valid email address.']);
    exit();
}
$amount = round($amount, 2);
$donor_name = mb_substr($donor_name, 0, 200);

$reference_id = 'donation-' . bin2hex(random_bytes(6));
$request_id   = 'create-' . bin2hex(random_bytes(16));

$order = paypal_create_order($amount, $reference_id, $request_id);
if (!$order['success']) {
    error_log('donate-create-order: ' . $order['error']);
    http_response_code(502);
    echo json_encode(['success' => false, 'error' => 'We could not start the PayPal checkout. Please try again in a moment.']);
    exit();
}

$pdo->prepare(
    'INSERT INTO paypal_donations (donor_name, donor_email, paypal_order_id, amount, status)
     VALUES (?, ?, ?, ?, ?)'
)->execute([$donor_name ?: null, $donor_email, $order['order_id'], $amount, 'created']);

echo json_encode(['success' => true, 'orderId' => $order['order_id']]);
