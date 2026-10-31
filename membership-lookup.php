<?php
/**
 * Membership verification lookup.
 * Existing records require cadet last name, date of birth, and any email on file.
 * A verified existing record gets the update handler's one-time token; a new
 * applicant gets a token bound to the supplied identity and email field choice.
 *
 * Two steps, like update-lookup.php: every lookup emails a one-time code to
 * the address typed (new applicants too, which confirms their email is
 * real), and the outcome is only revealed once action = "verify" gets the
 * right code — so the form can't be used to learn whether a family is on file.
 */
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate');
require_once __DIR__ . '/admin/auth.php';
require_once __DIR__ . '/admin/form-guard.php';
require_once __DIR__ . '/admin/email-code.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit();
}

$payload = json_decode(file_get_contents('php://input'), true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid request.']);
    exit();
}

if (honeypot_tripped($payload)) {
    echo json_encode(['success' => false, 'error' => "We couldn't verify those details. Please check them or contact the club."]);
    exit();
}

$pdo = get_pdo();

function membership_lookup_value(array $payload, string $key): string {
    return trim((string)($payload[$key] ?? ''));
}

if (membership_lookup_value($payload, 'action') === 'verify') {
    $binding = email_code_handle_verify($pdo, 'membership', $payload, 'secretary@bamablackknights.org');
} else {
    if (rate_limited($pdo, 'membership_lookup')) {
        http_response_code(429);
        echo json_encode(['success' => false, 'error' => 'Too many attempts. Please wait and try again, or contact the club.']);
        exit();
    }

    $last = membership_lookup_value($payload, 'cadetLastName');
    $birthday = membership_lookup_value($payload, 'cadetBirthday');
    $email = strtolower(membership_lookup_value($payload, 'email'));
    $email_owner = membership_lookup_value($payload, 'emailOwner');
    if (
        $last === ''
        || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthday)
        || !filter_var($email, FILTER_VALIDATE_EMAIL)
        || !in_array($email_owner, ['cadet', 'primary', 'secondary'], true)
    ) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Enter the cadet’s last name and birthday, a valid email, and who owns that email address.']);
        exit();
    }

    $stmt = $pdo->prepare(
        'SELECT * FROM members
         WHERE archived = 0 AND cadet_birthday = :birthday'
    );
    $stmt->execute(['birthday' => $birthday]);
    $target_last = strip_name_suffix(normalize_name($last));
    $name_matches = [];
    $email_matches = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (strip_name_suffix(normalize_name($row['cadet_last_name'])) !== $target_last) continue;
        $name_matches[] = $row;
        $record_emails = array_map('strtolower', array_filter([
            $row['cadet_email'] ?? '',
            $row['parent1_email'] ?? '',
            $row['parent2_email'] ?? '',
        ]));
        if (in_array($email, $record_emails, true)) $email_matches[] = $row;
    }

    if (count($email_matches) > 1) {
        $binding = ['error' => 'More than one record matched those details. Please contact the club for help.'];
    } elseif (count($email_matches) === 1) {
        $binding = ['member_id' => (int)$email_matches[0]['id']];
    } elseif ($name_matches) {
        $binding = ['error' => 'A record already exists for that cadet name and birthday, but this email does not match. Try an email address on file or contact the club for help.'];
    } else {
        $binding = [
            'new'         => true,
            'last'        => $last,
            'last_name'   => $target_last,
            'birthday'    => $birthday,
            'email'       => $email,
            'email_owner' => $email_owner,
        ];
    }
    email_code_respond_issued($pdo, 'membership', $binding, $email, 'continue your membership', 'secretary@bamablackknights.org');
}

start_verification_session();

if (isset($binding['member_id'])) {
    $stmt = $pdo->prepare('SELECT * FROM members WHERE id = ? AND archived = 0');
    $stmt->execute([$binding['member_id']]);
    $member = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$member) {
        echo json_encode(['success' => false, 'error' => "We couldn't verify those details. Please check them or contact the club."]);
        exit();
    }
    $token = bin2hex(random_bytes(24));
    if (!isset($_SESSION['update_verified']) || !is_array($_SESSION['update_verified'])) {
        $_SESSION['update_verified'] = [];
    }
    foreach ($_SESSION['update_verified'] as $old_token => $entry) {
        if (($entry['expires'] ?? 0) < time()) unset($_SESSION['update_verified'][$old_token]);
    }
    $_SESSION['update_verified'][$token] = [
        'member_id' => (int)$member['id'],
        'expires' => time() + 1800,
    ];

    $cadet_years = cadet_dues_years((string)($member['class_year'] ?? ''));
    $paid_years = parse_dues_years($member['membership_paid_years'] ?? '');
    $payable_years = array_values(array_diff($cadet_years, $paid_years));
    $g = static fn(string $key): string => (string)($member[$key] ?? '');
    echo json_encode([
        'success' => true,
        'recordType' => 'existing',
        'verifyToken' => $token,
        'cadetYears' => $cadet_years,
        'paidYears' => $paid_years,
        'payableYears' => $payable_years,
        'member' => [
            'cadetFirstName' => $g('cadet_first_name'),
            'cadetMiddleName' => $g('cadet_middle_name'),
            'cadetLastName' => $g('cadet_last_name'),
            'cadetSuffix' => $g('cadet_suffix'),
            'nickname' => $g('nickname'),
            'cadetEmail' => $g('cadet_email'),
            'cadetGender' => $g('cadet_gender'),
            'graduationYear' => $g('class_year'),
            'cadetDOB' => $g('cadet_birthday'),
            'poBox' => $g('cadet_po_box'),
            'company' => $g('company'),
            'parent1FirstName' => $g('parent1_first_name'),
            'parent1LastName' => $g('parent1_last_name'),
            'parent1Relationship' => $g('parent1_relationship'),
            'parent1Phone' => $g('parent1_cell'),
            'parent1Email' => $g('parent1_email'),
            'parent1EmailUpdates' => (int)($member['parent1_email_updates'] ?? 0),
            'parent2FirstName' => $g('parent2_first_name'),
            'parent2LastName' => $g('parent2_last_name'),
            'parent2Relationship' => $g('parent2_relationship'),
            'parent2Phone' => $g('parent2_cell'),
            'parent2Email' => $g('parent2_email'),
            'parent2EmailUpdates' => (int)($member['parent2_email_updates'] ?? 0),
            'streetAddress' => $g('parent1_street'),
            'city' => $g('parent1_city'),
            'state' => $g('parent1_state'),
            'zipCode' => $g('parent1_zip'),
            'parent2Street' => $g('parent2_street'),
            'parent2City' => $g('parent2_city'),
            'parent2State' => $g('parent2_state'),
            'parent2Zip' => $g('parent2_zip'),
            'photoConsent' => $g('photo_consent'),
            'directoryConsent' => $g('directory_consent'),
        ],
    ]);
    exit();
}

$last = $binding['last'];
$target_last = $binding['last_name'];
$birthday = $binding['birthday'];
$email = $binding['email'];
$email_owner = $binding['email_owner'];

$token = bin2hex(random_bytes(24));
if (!isset($_SESSION['membership_verified']) || !is_array($_SESSION['membership_verified'])) {
    $_SESSION['membership_verified'] = [];
}
foreach ($_SESSION['membership_verified'] as $old_token => $entry) {
    if (($entry['expires'] ?? 0) < time()) unset($_SESSION['membership_verified'][$old_token]);
}
$_SESSION['membership_verified'][$token] = [
    'last_name' => $target_last,
    'birthday' => $birthday,
    'email' => $email,
    'email_owner' => $email_owner,
    'expires' => time() + 1800,
];

$prefill = [
    'cadetFirstName' => '',
    'cadetMiddleName' => '',
    'cadetLastName' => $last,
    'cadetSuffix' => '',
    'nickname' => '',
    'cadetEmail' => '',
    'cadetGender' => '',
    'graduationYear' => '',
    'cadetDOB' => $birthday,
    'poBox' => '',
    'company' => '',
    'parent1FirstName' => '',
    'parent1LastName' => '',
    'parent1Relationship' => '',
    'parent1Phone' => '',
    'parent1Email' => '',
    'parent1EmailUpdates' => 0,
    'parent2FirstName' => '',
    'parent2LastName' => '',
    'parent2Relationship' => '',
    'parent2Phone' => '',
    'parent2Email' => '',
    'parent2EmailUpdates' => 0,
    'streetAddress' => '',
    'city' => '',
    'state' => '',
    'zipCode' => '',
    'parent2Street' => '',
    'parent2City' => '',
    'parent2State' => '',
    'parent2Zip' => '',
    'photoConsent' => '',
    'directoryConsent' => '',
];
if ($email_owner === 'cadet') $prefill['cadetEmail'] = $email;
if ($email_owner === 'primary') $prefill['parent1Email'] = $email;
if ($email_owner === 'secondary') $prefill['parent2Email'] = $email;

echo json_encode([
    'success' => true,
    'recordType' => 'new',
    'membershipToken' => $token,
    'member' => $prefill,
]);
