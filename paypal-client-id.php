<?php
/**
 * Exposes the (non-secret) PayPal Client ID to the static front end so
 * payment.html can load the PayPal JS SDK without hardcoding any PayPal
 * value in publicly-committed HTML. admin/config.php stays the only place
 * any PayPal credential lives.
 */

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('Access-Control-Allow-Origin: https://alabamafalcons.org');

require_once __DIR__ . '/admin/config.php';
require_once __DIR__ . '/admin/lib/paypal.php';

if (!defined('PAYPAL_CLIENT_ID') || PAYPAL_CLIENT_ID === '') {
    http_response_code(500);
    echo json_encode(['success' => false]);
    exit();
}

echo json_encode([
    'success'  => true,
    'clientId' => PAYPAL_CLIENT_ID,
    'mode'     => paypal_mode_label(),
]);
