<?php
/**
 * Public Donation — Capture Order
 * The load-bearing endpoint: this is the only place a donation actually
 * gets applied. Amount is never accepted from the client here — only read
 * back from the paypal_donations tracking row created by
 * donate-create-order.php. Same discipline as dues-pay-capture-order.php:
 * only proceed once PayPal confirms the capture status and the amount
 * matches what was locked in at order creation.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: https://alabamafalcons.org');

require_once __DIR__ . '/admin/auth.php';
require_once __DIR__ . '/admin/form-guard.php';
require_once __DIR__ . '/admin/lib/paypal.php';
require_once __DIR__ . '/admin/mailer.php';

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

if (rate_limited($pdo, 'donate_capture_order', 10, 15)) {
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => 'Too many attempts from your network. Please try again later or email treasurer@alabamafalcons.org.']);
    exit();
}

$order_id = trim($payload['orderId'] ?? '');
if ($order_id === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing order id.']);
    exit();
}

$track_stmt = $pdo->prepare('SELECT * FROM paypal_donations WHERE paypal_order_id = ?');
$track_stmt->execute([$order_id]);
$track = $track_stmt->fetch(PDO::FETCH_ASSOC);

if (!$track) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'We could not find that donation. Please try again.']);
    exit();
}

// Idempotent no-op: this order was already fully captured by an earlier
// call (e.g. a duplicate onApprove firing twice in the browser).
if ($track['status'] === 'captured') {
    echo json_encode(['success' => true, 'amount' => number_format((float)$track['amount'], 2)]);
    exit();
}

function notify_treasurer_donation_issue(string $subject, string $detail): void {
    send_notification('treasurer@alabamafalcons.org', $subject, nl2br(htmlspecialchars($detail)));
}

// Sandbox mode always reports a successful capture (it's fake test money),
// but this code has no other way to know that -- it logs income and emails
// the donor/treasurer exactly like a real live payment. Tagging every
// notes field/subject line here is the only thing that keeps a sandbox
// test from looking identical to a real donation later on.
$capture_note_prefix = paypal_mode_label() === 'live' ? '' : '[SANDBOX TEST] ';

$capture_id = null;
$captured_amount = null;

$result = paypal_capture_order($order_id, 'capture-' . $order_id);
if ($result['success']) {
    $capture_id = $result['capture_id'];
    $captured_amount = $result['captured_amount'];
} elseif (!empty($result['already_captured'])) {
    $recover = paypal_get_order($order_id);
    if (!$recover['success']) {
        error_log('donate-capture-order: recovery failed for order ' . $order_id . ': ' . $recover['error']);
        http_response_code(502);
        echo json_encode(['success' => false, 'error' => 'We could not confirm your donation status. Please contact treasurer@alabamafalcons.org with your PayPal receipt.']);
        exit();
    }
    $capture_id = $recover['capture_id'];
    $captured_amount = $recover['captured_amount'];
} else {
    error_log('donate-capture-order: capture failed for order ' . $order_id . ': ' . $result['error']);
    echo json_encode(['success' => false, 'error' => 'Your donation could not be completed. Please try again.']);
    exit();
}

if (abs((float)$captured_amount - (float)$track['amount']) > 0.001) {
    $pdo->prepare("UPDATE paypal_donations SET paypal_capture_id=?, status='amount_mismatch', captured_at=NOW() WHERE id=?")
        ->execute([$capture_id, $track['id']]);
    notify_treasurer_donation_issue(
        "{$capture_note_prefix}PayPal donation amount mismatch — needs review",
        "Order $order_id / capture $capture_id captured \$$captured_amount but was expected to be \${$track['amount']} from {$track['donor_email']}. Please reconcile manually in the Income Ledger."
    );
    echo json_encode(['success' => true, 'amount' => number_format((float)$captured_amount, 2)]);
    exit();
}

$pdo->prepare("UPDATE paypal_donations SET paypal_capture_id=?, status='captured', captured_at=NOW() WHERE id=?")
    ->execute([$capture_id, $track['id']]);

$donor_name  = (string)($track['donor_name'] ?? '');
$donor_email = (string)$track['donor_email'];

try {
    $pdo->prepare(
        'INSERT INTO income_entries (entry_date, source, source_type, description, amount, payment_method, notes, received_by)
         VALUES (CURDATE(), ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $donor_name ?: $donor_email,
        'donation',
        'Online Donation',
        $track['amount'],
        'PayPal',
        "{$capture_note_prefix}PayPal order $order_id, capture $capture_id",
        null,
    ]);
} catch (\Throwable $e) {
    error_log('donate-capture-order: income_entries insert failed for order ' . $order_id . ': ' . $e->getMessage());
    notify_treasurer_donation_issue(
        "{$capture_note_prefix}ACTION NEEDED: PayPal donation captured but not recorded",
        "Order $order_id / capture $capture_id from $donor_email (\${$track['amount']}) was successfully captured by PayPal, but our system failed to log it to the Income Ledger: {$e->getMessage()}. Please add it manually."
    );
}

try {
    send_donation_receipt($donor_email, $donor_name, (float)$track['amount'], $capture_id, $capture_note_prefix);
} catch (\Throwable $e) {
    error_log('donate-capture-order: donor receipt email failed for order ' . $order_id . ': ' . $e->getMessage());
}
try {
    notify_treasurer_of_donation($donor_name, $donor_email, (float)$track['amount'], $order_id, (string)$capture_id, $capture_note_prefix);
} catch (\Throwable $e) {
    error_log('donate-capture-order: treasurer notification email failed for order ' . $order_id . ': ' . $e->getMessage());
}

echo json_encode(['success' => true, 'amount' => number_format((float)$track['amount'], 2)]);
