<?php
/**
 * Job Drop Night — Lookup
 * Same identity check as parent-letters-lookup.php (cadet last name + a
 * parent email already on file), issuing its own token in its own session
 * pool so it doesn't interact with either the Update Your Information or
 * Parent Letters flows. Unlike those, graduation year isn't a field the
 * visitor picks — Job Drop Night is only for the class about to graduate,
 * computed the same way current_class_years() is (no yearly manual
 * upkeep), so a family from any other class simply won't match.
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
    echo json_encode(['success' => false, 'error' => "We couldn't find a matching record. Please double-check your information, or contact secretary@alabamafalcons.org."]);
    exit();
}

$pdo = get_pdo();

if (rate_limited($pdo, 'job_drop_lookup')) {
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => 'Too many attempts from your network. Please try again later or email secretary@alabamafalcons.org.']);
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

// The class Job Drop Night is currently open for — automatic by default,
// but an officer can override it (see job_drop_eligible_year()). Only this
// class_year will ever match this lookup.
$eligible_year = job_drop_eligible_year($pdo);

$stmt = $pdo->prepare(
    'SELECT * FROM members
     WHERE archived = 0 AND class_year = :class_year AND cadet_birthday = :birthday
       AND (parent1_email = :email OR parent2_email = :email)'
);
$stmt->execute(['class_year' => $eligible_year, 'birthday' => $birthday, 'email' => $email]);
$target_norm = strip_name_suffix(normalize_name($last));
$m = null;
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    if (strip_name_suffix(normalize_name($row['cadet_last_name'])) === $target_norm) { $m = $row; break; }
}

if (!$m) {
    echo json_encode([
        'success' => false,
        'error'   => "We couldn't find a matching record. Job Drop Night is only open to the graduating Class of $eligible_year — please double-check the cadet's last name, birthday, and the email on file, or contact secretary@alabamafalcons.org."
    ]);
    exit();
}

// Tell the front end whether this family already has a submission on file,
// so it can offer to edit the existing one instead of blocking a second
// attempt outright (job-drop-submit.php enforces this server-side too —
// this is only used to pre-fill/label the form).
$existing_stmt = $pdo->prepare(
    "SELECT id, status, job_title, youtube_id FROM job_drop_submissions
     WHERE member_id = ? AND status IN ('pending','approved') ORDER BY submitted_at DESC LIMIT 1"
);
$existing_stmt->execute([(int)$m['id']]);
$existing = $existing_stmt->fetch(PDO::FETCH_ASSOC);

start_verification_session();
$verify_token = bin2hex(random_bytes(24));
if (!isset($_SESSION['jobdrop_verified']) || !is_array($_SESSION['jobdrop_verified'])) {
    $_SESSION['jobdrop_verified'] = [];
}
foreach ($_SESSION['jobdrop_verified'] as $t => $entry) {
    if (($entry['expires'] ?? 0) < time()) unset($_SESSION['jobdrop_verified'][$t]);
}
$_SESSION['jobdrop_verified'][$verify_token] = [
    'member_id' => (int)$m['id'],
    'expires'   => time() + 1800, // 30 minutes
];

echo json_encode([
    'success'     => true,
    'verifyToken' => $verify_token,
    'cadetName'   => trim(cadet_full_name($m)),
    'existing'    => $existing ? [
        'status'      => $existing['status'],
        'jobTitle'    => $existing['job_title'],
        'youtubeId'   => $existing['youtube_id'],
        'editable'    => $existing['status'] === 'pending',
    ] : null,
]);
