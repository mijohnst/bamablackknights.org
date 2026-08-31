<?php
/**
 * PayPal API wrapper — minimal, plain cURL (no vendored SDK). Covers both
 * Payouts (sending reimbursements) and Orders/Checkout (collecting online
 * dues payments). Requires PAYPAL_MODE ('sandbox'|'live'), PAYPAL_CLIENT_ID,
 * PAYPAL_SECRET defined in admin/config.php. Sandbox and live use different
 * API hosts and completely separate credentials/money.
 */

function paypal_api_base(): string {
    return (defined('PAYPAL_MODE') && PAYPAL_MODE === 'live')
        ? 'https://api-m.paypal.com'
        : 'https://api-m.sandbox.paypal.com';
}

// OAuth2 client_credentials grant — short-lived token, fetched fresh for
// every call rather than cached, to keep this wrapper simple; Payouts is a
// low-volume admin action, not a hot path worth optimizing.
// Returns ['token'=>string,'error'=>null] or ['token'=>null,'error'=>string] —
// the specific reason is surfaced all the way to the on-screen flash message
// rather than only to the PHP error log, since that log isn't reliably
// reachable on every hosting setup.
function paypal_get_access_token(): array {
    if (!defined('PAYPAL_CLIENT_ID') || !defined('PAYPAL_SECRET')) {
        return ['token' => null, 'error' => 'PAYPAL_CLIENT_ID/PAYPAL_SECRET are not defined in admin/config.php.'];
    }
    if (!function_exists('curl_init')) {
        return ['token' => null, 'error' => 'The PHP curl extension is not available on this server.'];
    }
    $ch = curl_init(paypal_api_base() . '/v1/oauth2/token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => 'grant_type=client_credentials',
        CURLOPT_USERPWD        => PAYPAL_CLIENT_ID . ':' . PAYPAL_SECRET,
        CURLOPT_HTTPHEADER     => ['Accept: application/json', 'Accept-Language: en_US'],
        CURLOPT_TIMEOUT        => 15,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err = curl_error($ch);
    curl_close($ch);

    if ($resp === false) {
        return ['token' => null, 'error' => 'Connection to PayPal failed: ' . ($curl_err ?: 'unknown cURL error')];
    }
    $data = json_decode($resp, true);
    if ($code !== 200 || empty($data['access_token'])) {
        $reason = $data['error_description'] ?? $data['error'] ?? $resp;
        return ['token' => null, 'error' => "PayPal auth rejected (HTTP $code, mode " . paypal_mode_label() . "): $reason"];
    }
    return ['token' => $data['access_token'], 'error' => null];
}

function paypal_mode_label(): string {
    return (defined('PAYPAL_MODE') && PAYPAL_MODE === 'live') ? 'live' : 'sandbox';
}

// Sends a single payout to one PayPal email address. Returns
// ['success'=>true,'batch_id'=>...,'status'=>...] or ['success'=>false,'error'=>...].
function paypal_send_payout(string $recipientEmail, float $amount, string $note, string $senderItemId): array {
    $auth = paypal_get_access_token();
    if (!$auth['token']) return ['success' => false, 'error' => $auth['error']];
    $token = $auth['token'];

    $payload = [
        'sender_batch_header' => [
            // Deliberately deterministic (purchase id, not timestamped): if
            // a request crashes/times out after PayPal has already accepted
            // it but before our own DB write records the batch id, a retry
            // reuses this exact id — PayPal recognizes it as a duplicate
            // batch and returns the existing one instead of paying out a
            // second time. See the claim/retry logic around this call in
            // admin/purchase-action.php's send_paypal action.
            'sender_batch_id' => $senderItemId,
            'email_subject'   => 'You have a payout from ' . (defined('CLUB_NAME') ? CLUB_NAME : 'USAFA Parents Club of Alabama'),
            'email_message'   => $note,
        ],
        'items' => [[
            'recipient_type' => 'EMAIL',
            'amount'         => ['value' => number_format($amount, 2, '.', ''), 'currency' => 'USD'],
            'receiver'       => $recipientEmail,
            'note'           => $note,
            'sender_item_id' => $senderItemId,
        ]],
    ];

    $ch = curl_init(paypal_api_base() . '/v1/payments/payouts');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $token],
        CURLOPT_TIMEOUT        => 20,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode((string)$resp, true);
    if ($code >= 200 && $code < 300 && !empty($data['batch_header']['payout_batch_id'])) {
        return [
            'success'  => true,
            'batch_id' => $data['batch_header']['payout_batch_id'],
            'status'   => $data['batch_header']['batch_status'] ?? 'PENDING',
        ];
    }
    error_log('paypal_send_payout failed: HTTP ' . $code . ' ' . $resp);
    // PayPal rejects a reused sender_batch_id outright rather than quietly
    // no-op'ing — which, given our batch id is deterministic per purchase,
    // means it has *already* accepted this exact payout once before (most
    // likely from an earlier attempt whose success response we never got to
    // record). Surfaced distinctly so the caller does not treat this the
    // same as an ordinary, safe-to-retry failure.
    $is_duplicate = ($data['name'] ?? '') === 'DUPLICATE_BATCH_ID';
    return ['success' => false, 'duplicate' => $is_duplicate, 'error' => $data['message'] ?? ('PayPal returned HTTP ' . $code)];
}

// Looks up the current status of a previously-sent payout batch.
function paypal_check_payout_status(string $batchId): array {
    $auth = paypal_get_access_token();
    if (!$auth['token']) return ['success' => false, 'error' => $auth['error']];
    $token = $auth['token'];

    $ch = curl_init(paypal_api_base() . '/v1/payments/payouts/' . urlencode($batchId));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token],
        CURLOPT_TIMEOUT        => 15,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode((string)$resp, true);
    if ($code >= 200 && $code < 300) {
        // Prefer the individual item's status (SUCCESS/FAILED/UNCLAIMED/etc.)
        // over the batch-level status, since a batch of one item is "done"
        // as soon as PayPal has processed it either way.
        $item_status  = $data['items'][0]['transaction_status'] ?? null;
        $batch_status = $data['batch_header']['batch_status'] ?? 'UNKNOWN';
        return ['success' => true, 'status' => $item_status ?: $batch_status];
    }
    error_log('paypal_check_payout_status failed: HTTP ' . $code . ' ' . $resp);
    return ['success' => false, 'error' => $data['message'] ?? ('PayPal returned HTTP ' . $code)];
}

// ── Orders API — collects a payment (the reverse of Payouts, above,
// which sends one) ─────────────────────────────────────────────────────

// Creates a PayPal order for a single USD amount. $requestId is sent as
// the PayPal-Request-Id idempotency header — a retry with the same id
// (e.g. a double-click before the first request finishes) returns the
// same still-open order instead of creating a second one. Returns
// ['success'=>true,'order_id'=>...,'status'=>...] or
// ['success'=>false,'error'=>...].
function paypal_create_order(float $amount, string $referenceId, string $requestId): array {
    $auth = paypal_get_access_token();
    if (!$auth['token']) return ['success' => false, 'error' => $auth['error']];
    $token = $auth['token'];

    $payload = [
        'intent' => 'CAPTURE',
        'purchase_units' => [[
            'reference_id' => $referenceId,
            'amount' => ['currency_code' => 'USD', 'value' => number_format($amount, 2, '.', '')],
        ]],
    ];

    $ch = curl_init(paypal_api_base() . '/v2/checkout/orders');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $token, 'PayPal-Request-Id: ' . $requestId],
        CURLOPT_TIMEOUT        => 20,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode((string)$resp, true);
    if ($code >= 200 && $code < 300 && !empty($data['id'])) {
        return ['success' => true, 'order_id' => $data['id'], 'status' => $data['status'] ?? 'CREATED'];
    }
    error_log('paypal_create_order failed: HTTP ' . $code . ' ' . $resp);
    return ['success' => false, 'error' => $data['message'] ?? ('PayPal returned HTTP ' . $code)];
}

// Captures a previously-created order — the call that actually moves the
// payer's money. $requestId should be deterministic per order (callers
// use 'capture-' . $orderId) so a network retry after a crash/timeout
// reuses it and lands on PayPal's own duplicate-capture rejection rather
// than attempting to charge the payer a second time — the collection-side
// mirror of paypal_send_payout()'s DUPLICATE_BATCH_ID handling above.
// 'already_captured' distinguishes that specific, expected-on-retry
// rejection from an ordinary failure the caller should just report as-is.
function paypal_capture_order(string $orderId, string $requestId): array {
    $auth = paypal_get_access_token();
    if (!$auth['token']) return ['success' => false, 'error' => $auth['error']];
    $token = $auth['token'];

    $ch = curl_init(paypal_api_base() . '/v2/checkout/orders/' . urlencode($orderId) . '/capture');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => '{}',
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $token, 'PayPal-Request-Id: ' . $requestId],
        CURLOPT_TIMEOUT        => 20,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode((string)$resp, true);
    $capture = $data['purchase_units'][0]['payments']['captures'][0] ?? null;
    if ($code >= 200 && $code < 300 && $capture && ($capture['status'] ?? '') === 'COMPLETED') {
        return [
            'success'         => true,
            'status'          => $capture['status'],
            'capture_id'      => $capture['id'],
            'captured_amount' => (float)($capture['amount']['value'] ?? 0),
        ];
    }
    error_log('paypal_capture_order failed: HTTP ' . $code . ' ' . $resp);
    $is_duplicate = $code === 422 && (($data['details'][0]['issue'] ?? '') === 'ORDER_ALREADY_CAPTURED');
    return ['success' => false, 'already_captured' => $is_duplicate, 'error' => $data['message'] ?? ('PayPal returned HTTP ' . $code)];
}

// Recovery path only: looks up an order's current state directly. Used
// when paypal_capture_order() reports 'already_captured' but our own
// tracking row doesn't show it as applied yet (a crash between PayPal
// confirming the capture and our own bookkeeping finishing) — recovers
// the real capture id/amount so the flow can still finish instead of
// stranding an already-paid order in limbo.
function paypal_get_order(string $orderId): array {
    $auth = paypal_get_access_token();
    if (!$auth['token']) return ['success' => false, 'error' => $auth['error']];
    $token = $auth['token'];

    $ch = curl_init(paypal_api_base() . '/v2/checkout/orders/' . urlencode($orderId));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token],
        CURLOPT_TIMEOUT        => 15,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode((string)$resp, true);
    $capture = $data['purchase_units'][0]['payments']['captures'][0] ?? null;
    if ($code >= 200 && $code < 300 && $capture) {
        return [
            'success'         => true,
            'status'          => $capture['status'] ?? 'UNKNOWN',
            'capture_id'      => $capture['id'] ?? null,
            'captured_amount' => (float)($capture['amount']['value'] ?? 0),
        ];
    }
    error_log('paypal_get_order failed: HTTP ' . $code . ' ' . $resp);
    return ['success' => false, 'error' => $data['message'] ?? ('PayPal returned HTTP ' . $code)];
}
