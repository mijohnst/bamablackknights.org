<?php
/**
 * Update-Your-Information Lookup
 * Two steps:
 *   1. Lookup — graduation year, normalized cadet last name, and any parent
 *      or cadet email already on file. On a match, emails a one-time code to
 *      the address the visitor typed. The response is the same whether or
 *      not anything matched, so the form can't be used to probe who's on file.
 *   2. Verify (action = "verify") — a correct code returns that family's
 *      current record and the token update-handler.php trusts.
 * Never creates or modifies anything.
 */

header('Content-Type: application/json');
// Must be set on every response, not just the OPTIONS preflight — see
// membership-handler.php for why.
header('Access-Control-Allow-Origin: https://bamablackknights.org');

require_once __DIR__ . '/admin/auth.php';
require_once __DIR__ . '/admin/form-guard.php';
require_once __DIR__ . '/admin/email-code.php';

const UPDATE_CODE_POOL = 'update';

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
    echo json_encode(['success' => false, 'error' => "We couldn't find a matching record. Please double-check your information, or contact secretary@bamablackknights.org."]);
    exit();
}

$pdo = get_pdo();

function s(array $p, string $key): string {
    return trim($p[$key] ?? '');
}

// ── Step 2: verify the emailed code ───────────────────────────────────────
if (s($payload, 'action') === 'verify') {
    [$m, $binding] = email_code_verified_member($pdo, UPDATE_CODE_POOL, $payload, 'secretary@bamablackknights.org');

    // Bind this specific record to a random per-lookup token, not one shared
    // session slot — a single slot meant a second "Find My Record" lookup in
    // another tab (e.g. checking a sibling's record) would silently overwrite
    // the first, so submitting the still-open first tab could update the wrong
    // family. update-handler.php trusts this token to find the record to
    // update, instead of resubmitted form fields that could be fabricated.
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
        'email'     => $binding['email'], // which contact looked it up — shown in the Members page change log
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
            'cadetDOB'         => $g('cadet_birthday'),
            'poBox'            => $g('cadet_po_box'),
            'cadetEmail'       => $g('cadet_email'),
            'cadetPhone'       => $g('cadet_cell'),
            'company'          => $g('company'),
            'photoConsent'     => $g('photo_consent'),
            'directoryConsent' => $g('directory_consent'),
            'parent1FirstName' => $g('parent1_first_name'),
            'parent1LastName'  => $g('parent1_last_name'),
            'parent1Relationship' => $g('parent1_relationship'),
            'parent1Phone'     => $g('parent1_cell'),
            'parent1Email'     => $g('parent1_email'),
            'parent2FirstName' => $g('parent2_first_name'),
            'parent2LastName'  => $g('parent2_last_name'),
            'parent2Relationship' => $g('parent2_relationship'),
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
            'parent1EmailUpdates' => (int)($m['parent1_email_updates'] ?? 0),
            'parent2EmailUpdates' => (int)($m['parent2_email_updates'] ?? 0),
        ],
    ]);
    exit();
}

// ── Step 1: look up the record and email a code ───────────────────────────
if (rate_limited($pdo, 'update_lookup')) {
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => 'Too many attempts from your network. Please try again later or email secretary@bamablackknights.org.']);
    exit();
}

$last     = s($payload, 'cadetLastName');
$class_year = s($payload, 'graduationYear');
$email    = s($payload, 'email');

if ($last === '' || !in_array($class_year, CLASS_YEAR_LIST, true) || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => "Please enter the cadet's graduation year and last name, and a valid email address on file."]);
    exit();
}

// Matched in PHP against a normalized, suffix-stripped last name rather
// than a strict SQL `=` — a legacy record whose suffix is still crammed
// into cadet_last_name (e.g. "Jimmerson Jr", from before cadet_suffix
// existed) would otherwise never match a lookup for the clean "Jimmerson"
// a parent naturally types now that Suffix is its own field.
$stmt = $pdo->prepare(
    'SELECT * FROM members
     WHERE archived = 0 AND class_year = :class_year
       AND (LOWER(parent1_email) = :email OR LOWER(parent2_email) = :email OR LOWER(cadet_email) = :email)'
);
$stmt->execute(['class_year' => $class_year, 'email' => strtolower($email)]);
$target_norm = strip_name_suffix(normalize_name($last));
$matches = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    if (strip_name_suffix(normalize_name($row['cadet_last_name'])) === $target_norm) $matches[] = $row;
}

// Email the code only to the address that was typed (it's one of the
// emails on this record, or nothing matched). A lookup that matched more
// than one record still gets a code; the "contact the club" message is
// shown only after the visitor proves they own the inbox, so it can't be
// used to confirm a family is on file. No match gets a decoy challenge so
// the response is identical either way.
$binding = match (true) {
    count($matches) === 1 => ['member_id' => (int)$matches[0]['id'], 'email' => strtolower($email)],
    count($matches) > 1   => ['error' => 'More than one record matched those details. Please contact secretary@bamablackknights.org for help.'],
    default               => null,
};
email_code_respond_issued(UPDATE_CODE_POOL, $binding, $email, 'update your family information', 'secretary@bamablackknights.org');
