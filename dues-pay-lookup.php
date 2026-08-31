<?php
/**
 * Pay Dues Online — Lookup
 * Same 3-factor identity check as job-drop-lookup.php (cadet last name +
 * birthday + a parent email already on file), but unlike Job Drop this
 * isn't restricted to one class_year — any cadet with an unpaid year can
 * pay. Issues its own token in its own session pool (dues_verified) so it
 * doesn't interact with any other verification flow.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: https://alabamafalcons.org');

require_once __DIR__ . '/admin/auth.php';
require_once __DIR__ . '/admin/form-guard.php';

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

if (honeypot_tripped($payload)) {
    echo json_encode(['success' => false, 'error' => "We couldn't find a matching record. Please double-check your information, or contact treasurer@alabamafalcons.org."]);
    exit();
}

$pdo = get_pdo();

if (rate_limited($pdo, 'dues_pay_lookup')) {
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => 'Too many attempts from your network. Please try again later or email treasurer@alabamafalcons.org.']);
    exit();
}

function s(array $p, string $key): string {
    return trim($p[$key] ?? '');
}

$last     = s($payload, 'cadetLastName');
$email    = s($payload, 'email');
$birthday = s($payload, 'cadetBirthday');

if ($last === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthday)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => "Please enter the cadet's last name, birthday, and the email address on file."]);
    exit();
}

$stmt = $pdo->prepare(
    'SELECT * FROM members
     WHERE archived = 0 AND cadet_birthday = :birthday
       AND (parent1_email = :email OR parent2_email = :email)'
);
$stmt->execute(['birthday' => $birthday, 'email' => $email]);
$target_norm = strip_name_suffix(normalize_name($last));
$m = null;
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    if (strip_name_suffix(normalize_name($row['cadet_last_name'])) === $target_norm) { $m = $row; break; }
}

if (!$m) {
    echo json_encode([
        'success' => false,
        'error'   => "We couldn't find a matching record. Please double-check the cadet's last name, birthday, and the email on file, or contact treasurer@alabamafalcons.org."
    ]);
    exit();
}

$cadet_years = cadet_dues_years($m['class_year'] ?? '');
$paid_years  = parse_dues_years($m['membership_paid_years']);
$payable     = array_values(array_diff($cadet_years, $paid_years));

// cadetYears/paidYears let the front end mirror dues_years_price()'s own
// $75/year-with-$275-bundle-discount rule (see admin/lib.php) to compute an
// accurate running total for *any* combination the parent checks — a
// per-checkbox price walked in list order was misleading (it dumped the
// whole 4-year bundle discount onto whichever box happened to be last),
// and wouldn't match what dues-pay-create-order.php actually charges if
// that box were checked alone. This can't leak anything the payable list
// doesn't already imply, since cadetYears - payable = paidYears anyway.
$options = array_map(function ($year) { return ['year' => $year]; }, $payable);

start_verification_session();
$verify_token = bin2hex(random_bytes(24));
if (!isset($_SESSION['dues_verified']) || !is_array($_SESSION['dues_verified'])) {
    $_SESSION['dues_verified'] = [];
}
foreach ($_SESSION['dues_verified'] as $t => $entry) {
    if (($entry['expires'] ?? 0) < time()) unset($_SESSION['dues_verified'][$t]);
}
$_SESSION['dues_verified'][$verify_token] = [
    'member_id'     => (int)$m['id'],
    'expires'       => time() + 1800, // 30 minutes
    'pending_order' => null,
];

echo json_encode([
    'success'     => true,
    'verifyToken' => $verify_token,
    'cadetName'   => trim(cadet_full_name($m)),
    'options'     => $options,
    'cadetYears'  => $cadet_years,
    'paidYears'   => $paid_years,
]);
