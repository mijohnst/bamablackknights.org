<?php
/**
 * Update-Your-Information Lookup
 * Same identity check as job-drop-lookup.php and parent-letters-lookup.php
 * (cadet last name + birthday + a parent email already on file) — kept
 * identical across all three public lookup forms so families see one
 * consistent standard. Verifies before returning that family's current
 * record, so the Update form can be pre-filled. Never creates or modifies
 * anything.
 */

header('Content-Type: application/json');
// Must be set on every response, not just the OPTIONS preflight — see
// membership-handler.php for why.
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

// Honeypot — bots fill this hidden field, real visitors never see it.
// Pretend "not found" either way so bots don't learn anything from the response.
if (honeypot_tripped($payload)) {
    echo json_encode(['success' => false, 'error' => "We couldn't find a matching record. Please double-check your information, or contact secretary@alabamafalcons.org."]);
    exit();
}

$pdo = get_pdo();

if (rate_limited($pdo, 'update_lookup')) {
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => 'Too many attempts from your network. Please try again later or email secretary@alabamafalcons.org.']);
    exit();
}

function s(array $p, string $key): string {
    return trim($p[$key] ?? '');
}

$last     = s($payload, 'cadetLastName');
$birthday = s($payload, 'cadetBirthday');
$email    = s($payload, 'email');

if ($last === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthday)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => "Please enter the cadet's last name, birthday, and the email address on file."]);
    exit();
}

// Matched in PHP against a normalized, suffix-stripped last name rather
// than a strict SQL `=` — a legacy record whose suffix is still crammed
// into cadet_last_name (e.g. "Jimmerson Jr", from before cadet_suffix
// existed) would otherwise never match a lookup for the clean "Jimmerson"
// a parent naturally types now that Suffix is its own field.
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
        'error'   => "We couldn't find a matching record. Please double-check the cadet's last name, birthday, and the email on file, or contact secretary@alabamafalcons.org."
    ]);
    exit();
}

// Bind this specific record to a random per-lookup token, not one shared
// session slot — a single slot meant a second "Find My Record" lookup in
// another tab (e.g. checking a sibling's record) would silently overwrite
// the first, so submitting the still-open first tab could update the wrong
// family. update-handler.php trusts this token to find the record to
// update, instead of resubmitted form fields that could be fabricated.
start_verification_session();
$verify_token = bin2hex(random_bytes(24));
if (!isset($_SESSION['update_verified']) || !is_array($_SESSION['update_verified'])) {
    $_SESSION['update_verified'] = [];
}
// Prune expired entries so this array doesn't grow unbounded across a long-lived session.
foreach ($_SESSION['update_verified'] as $t => $entry) {
    if (($entry['expires'] ?? 0) < time()) unset($_SESSION['update_verified'][$t]);
}
$_SESSION['update_verified'][$verify_token] = [
    'member_id' => (int)$m['id'],
    'expires'   => time() + 1800, // 30 minutes
];

$g = fn(string $k) => (string)($m[$k] ?? '');

echo json_encode([
    'verifyToken' => $verify_token,
    'success' => true,
    'member'  => [
        'cadetFirstName'   => $g('cadet_first_name'),
        'cadetMiddleName'  => $g('cadet_middle_name'),
        'cadetLastName'    => $g('cadet_last_name'),
        'cadetSuffix'      => $g('cadet_suffix'),
        'graduationYear'   => $g('class_year'),
        'nickname'         => $g('nickname'),
        'cadetGender'      => $g('cadet_gender'),
        'poBox'            => $g('cadet_po_box'),
        'cadetEmail'       => $g('cadet_email'),
        'cadetPhone'       => $g('cadet_cell'),
        'squadron'         => $g('bct_squadron'),
        'photoConsent'     => $g('photo_consent'),
        'directoryConsent' => $g('directory_consent'),
        'parent1FirstName' => $g('parent1_first_name'),
        'parent1LastName'  => $g('parent1_last_name'),
        'parent1Phone'     => $g('parent1_cell'),
        'parent1Email'     => $g('parent1_email'),
        'parent2FirstName' => $g('parent2_first_name'),
        'parent2LastName'  => $g('parent2_last_name'),
        'parent2Phone'     => $g('parent2_cell'),
        'parent2Email'     => $g('parent2_email'),
        'streetAddress'    => $g('parent1_street'),
        'city'             => $g('parent1_city'),
        'state'            => $g('parent1_state'),
        'zipCode'          => $g('parent1_zip'),
        'parent2Street'    => $g('parent2_street'),
        'parent2City'      => $g('parent2_city'),
        'parent2State'     => $g('parent2_state'),
        'parent2Zip'       => $g('parent2_zip'),
    ],
]);
