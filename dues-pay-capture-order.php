<?php
/**
 * Pay Dues Online — Capture Order
 * The load-bearing endpoint: this is the only place a payment actually
 * gets applied to a member's record. years/amount are never accepted from
 * the client here — only read back from the session + paypal_dues_orders
 * tracking row. save_dues_years() is only ever called after PayPal has
 * confirmed the capture status is COMPLETED (or recoverable via
 * already_captured) and the amount matches what we locked in at order
 * creation. See the plan's "core invariant" — this mirrors the same
 * discipline already applied to Payouts in admin/purchase-action.php.
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

if (rate_limited($pdo, 'dues_pay_capture_order', 10, 15)) {
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

$pending = $entry['pending_order'] ?? null;
$order_id = trim($payload['orderId'] ?? '');

if (!$pending || $order_id === '' || $order_id !== $pending['order_id']) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'That order does not match your current session. Please look up your cadet again.']);
    exit();
}

$member_id = (int)$entry['member_id'];

$track_stmt = $pdo->prepare('SELECT * FROM paypal_dues_orders WHERE paypal_order_id = ? AND member_id = ?');
$track_stmt->execute([$order_id, $member_id]);
$track = $track_stmt->fetch(PDO::FETCH_ASSOC);

if (!$track) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'We could not find that order. Please try again.']);
    exit();
}

// Idempotent no-op: this order was already fully applied by an earlier
// call (e.g. a duplicate onApprove firing twice in the browser).
if ($track['status'] === 'applied') {
    echo json_encode(['success' => true, 'years' => explode(',', $track['years'])]);
    exit();
}

function notify_treasurer_capture_issue(string $subject, string $detail): void {
    send_notification('treasurer@alabamafalcons.org', $subject, nl2br(htmlspecialchars($detail)));
}

// Sandbox mode always reports a successful capture (it's fake test money),
// but this code has no other way to know that -- it applies the dues years
// and logs income exactly like a real live payment. Tagging the note/subject
// is the only thing that keeps a sandbox test from looking identical to a
// real payment (or a real problem, for the alert emails below) later on.
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
        error_log('dues-pay-capture-order: recovery failed for order ' . $order_id . ': ' . $recover['error']);
        http_response_code(502);
        echo json_encode(['success' => false, 'error' => 'We could not confirm your payment status. Please contact treasurer@alabamafalcons.org with your PayPal receipt.']);
        exit();
    }
    $capture_id = $recover['capture_id'];
    $captured_amount = $recover['captured_amount'];
} else {
    error_log('dues-pay-capture-order: capture failed for order ' . $order_id . ': ' . $result['error']);
    echo json_encode(['success' => false, 'error' => 'Your payment could not be completed. Please try again, or use another payment method.']);
    exit();
}

if (abs((float)$captured_amount - (float)$track['amount']) > 0.001) {
    $pdo->prepare("UPDATE paypal_dues_orders SET paypal_capture_id=?, status='amount_mismatch', captured_at=NOW() WHERE id=?")
        ->execute([$capture_id, $track['id']]);
    notify_treasurer_capture_issue(
        "{$capture_note_prefix}PayPal dues amount mismatch — needs review",
        "Order $order_id / capture $capture_id captured \$$captured_amount but was expected to be \${$track['amount']} for member #$member_id (years: {$track['years']}). Please reconcile manually in the Income Ledger."
    );
    echo json_encode(['success' => true, 'years' => explode(',', $track['years'])]);
    exit();
}

$pdo->prepare("UPDATE paypal_dues_orders SET paypal_capture_id=?, status='captured', captured_at=NOW() WHERE id=?")
    ->execute([$capture_id, $track['id']]);

// Race re-check: someone (a treasurer, another payment) may have marked
// one of these years paid while this checkout was in flight. Never
// silently drop money if that happened — flag it for manual review
// instead of double-applying or discarding the payment.
$member_stmt = $pdo->prepare('SELECT * FROM members WHERE id = ?');
$member_stmt->execute([$member_id]);
$row = $member_stmt->fetch(PDO::FETCH_ASSOC);
$current_paid = parse_dues_years($row['membership_paid_years'] ?? '');
$order_years  = explode(',', $track['years']);
$still_needed = array_values(array_diff($order_years, $current_paid));

if (!$still_needed) {
    $pdo->prepare("UPDATE paypal_dues_orders SET status='needs_manual_review' WHERE id=?")->execute([$track['id']]);
    notify_treasurer_capture_issue(
        "{$capture_note_prefix}PayPal dues payment needs manual review",
        "Order $order_id / capture $capture_id for member #$member_id (\${$track['amount']}) captured successfully, but years {$track['years']} were already marked paid by the time we went to apply it. Please confirm this isn't a double payment and reconcile in the Income Ledger."
    );
    echo json_encode(['success' => true, 'years' => $order_years]);
    exit();
}

try {
    save_dues_years(
        $pdo,
        $member_id,
        array_merge($current_paid, $still_needed),
        true,
        $row,
        'PayPal',
        "{$capture_note_prefix}PayPal order $order_id, capture $capture_id"
    );
    $pdo->prepare("UPDATE paypal_dues_orders SET status='applied', applied_at=NOW() WHERE id=?")->execute([$track['id']]);
} catch (\Throwable $e) {
    error_log('dues-pay-capture-order: save_dues_years failed for order ' . $order_id . ': ' . $e->getMessage());
    // One retry, in case of a transient DB hiccup, before giving up and
    // flagging for the treasurer — the capture already succeeded at
    // PayPal, so this money must never be silently lost track of.
    try {
        save_dues_years(
            $pdo,
            $member_id,
            array_merge($current_paid, $still_needed),
            true,
            $row,
            'PayPal',
            "{$capture_note_prefix}PayPal order $order_id, capture $capture_id"
        );
        $pdo->prepare("UPDATE paypal_dues_orders SET status='applied', applied_at=NOW() WHERE id=?")->execute([$track['id']]);
    } catch (\Throwable $e2) {
        error_log('dues-pay-capture-order: save_dues_years retry failed for order ' . $order_id . ': ' . $e2->getMessage());
        $pdo->prepare("UPDATE paypal_dues_orders SET status='capture_ok_apply_failed', error_note=? WHERE id=?")
            ->execute([$e2->getMessage(), $track['id']]);
        notify_treasurer_capture_issue(
            "{$capture_note_prefix}ACTION NEEDED: PayPal dues payment captured but not recorded",
            "Order $order_id / capture $capture_id for member #$member_id (\${$track['amount']}, years {$track['years']}) was successfully captured by PayPal, but our system failed to record it: {$e2->getMessage()}. Please mark the year(s) paid manually in the member's profile and note the capture id for reference."
        );
    }
}

// Clear the completed pending order but keep the token valid so a parent
// paying for multiple non-contiguous years doesn't need to re-verify.
$_SESSION['dues_verified'][$token]['pending_order'] = null;

echo json_encode(['success' => true, 'years' => $order_years]);
