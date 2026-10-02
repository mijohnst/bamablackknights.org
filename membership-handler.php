<?php
/**
 * Membership Form Handler
 * Writes to MySQL database
 */

header('Content-Type: application/json');
// Must be set on every response, not just the OPTIONS preflight — browsers
// enforce CORS on the actual POST response too, and without this header
// there the form submission completes server-side but the browser blocks
// the JS from ever seeing success, showing a false failure to the user
// (who may then resubmit).
header('Access-Control-Allow-Origin: https://bamablackknights.org');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit();
}

$input   = file_get_contents('php://input');
$payload = json_decode($input, true);

if (!$payload) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON payload']);
    exit();
}

require_once __DIR__ . '/admin/form-guard.php';
require_once __DIR__ . '/admin/lib.php';
require_once __DIR__ . '/admin/mailer.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

// Honeypot — bots fill this hidden field, real visitors never see it.
// Pretend success so bots don't learn to avoid the field.
if (honeypot_tripped($payload)) {
    http_response_code(200);
    echo json_encode(['success' => true, 'message' => 'Application received! Thank you for joining the West Point Parents Club of Alabama.']);
    exit();
}

function sanitize_header($val) {
    return str_replace(["\r", "\n"], '', (string)$val);
}

function s(array $p, string $key): string {
    return trim($p[$key] ?? '');
}

start_verification_session();
$membership_token = s($payload, 'membershipToken');
$verification = $_SESSION['membership_verified'][$membership_token] ?? null;
if (!$verification || ($verification['expires'] ?? 0) < time()) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Your verification session expired. Please verify your information again.']);
    exit();
}

// Required fields mirror the `required` attributes on membership.html —
// keep both in sync if the form changes.
$required_fields = [
    'cadetFirstName', 'cadetLastName', 'cadetEmail', 'graduationYear',
    'parent1FirstName', 'parent1LastName', 'parent1Phone', 'parent1Email',
    'streetAddress', 'city', 'state', 'zipCode',
];
foreach ($required_fields as $field) {
    if (s($payload, $field) === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => "Missing required field: $field"]);
        exit();
    }
}
if (!filter_var(s($payload, 'cadetEmail'), FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid cadet email address.']);
    exit();
}
// Graduation year is a <select> of known values on membership.html — reject
// anything else rather than writing a tampered/arbitrary class_year
// (e.g. 'Graduate', which would wrongly exclude a new applicant from
// dues-renewal emails and current-class filters).
if (!in_array(s($payload, 'graduationYear'), ['2026', '2027', '2028', '2029', '2030', 'USMAPS'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid graduation year.']);
    exit();
}
if (!filter_var(s($payload, 'parent1Email'), FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid primary contact email address.']);
    exit();
}
if (
    strip_name_suffix(normalize_name(s($payload, 'cadetLastName'))) !== $verification['last_name']
    || s($payload, 'cadetDOB') !== $verification['birthday']
) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'The verified cadet name or birthday changed. Please verify the details again.']);
    exit();
}
$verified_email_field = [
    'cadet' => 'cadetEmail',
    'primary' => 'parent1Email',
    'secondary' => 'parent2Email',
][$verification['email_owner']] ?? '';
if ($verified_email_field === '' || strtolower(s($payload, $verified_email_field)) !== $verification['email']) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'The verified email was changed. Please verify the details again.']);
    exit();
}
// Gender is optional (blank allowed) but, like graduationYear, is a <select>
// of known values — reject anything else rather than writing a tampered value.
if (s($payload, 'cadetGender') !== '' && !in_array(s($payload, 'cadetGender'), ['Male', 'Female'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid gender.']);
    exit();
}
$valid_relationships = ['', 'Parent', 'Legal guardian', 'Grandparent', 'Sibling', 'Other family member', 'Other'];
foreach (['parent1Relationship', 'parent2Relationship'] as $field) {
    if (!in_array(s($payload, $field), $valid_relationships, true)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid family relationship.']);
        exit();
    }
}
$parent1_email_updates = ($payload['parent1EmailUpdates'] ?? '') === '1' ? 1 : 0;
$parent2_email_updates = ($payload['parent2EmailUpdates'] ?? '') === '1' && s($payload, 'parent2Email') !== '' ? 1 : 0;

// ── 1. Write to MySQL (primary) ────────────────────────────────────────────
require_once __DIR__ . '/admin/config.php';

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_EMULATE_PREPARES => true]
    );

    if (rate_limited($pdo, 'membership_form')) {
        http_response_code(429);
        echo json_encode(['success' => false, 'error' => 'Too many submissions from your network. Please try again later or email secretary@bamablackknights.org.']);
        exit();
    }

    // Map form field names → DB columns
    $first  = s($payload, 'cadetFirstName');
    $middle = s($payload, 'cadetMiddleName');
    $suffix = s($payload, 'cadetSuffix');

    $dob = s($payload, 'cadetDOB');
    if ($dob === '') $dob = null;

    $submitted_emails = array_values(array_unique(array_map('strtolower', array_filter([
        s($payload, 'cadetEmail'),
        s($payload, 'parent1Email'),
        s($payload, 'parent2Email'),
    ], fn($email) => $email !== ''))));
    $email_placeholders = implode(',', array_fill(0, count($submitted_emails), '?'));
    $duplicate_stmt = $pdo->prepare(
        "SELECT id, cadet_last_name FROM members
         WHERE archived = 0 AND class_year = ?
           AND (LOWER(cadet_email) IN ($email_placeholders)
             OR LOWER(parent1_email) IN ($email_placeholders)
             OR LOWER(parent2_email) IN ($email_placeholders))"
    );
    $duplicate_stmt->execute(array_merge(
        [s($payload, 'graduationYear')],
        $submitted_emails,
        $submitted_emails,
        $submitted_emails
    ));
    $target_norm = strip_name_suffix(normalize_name(s($payload, 'cadetLastName')));
    foreach ($duplicate_stmt->fetchAll(PDO::FETCH_ASSOC) as $candidate) {
        if (strip_name_suffix(normalize_name($candidate['cadet_last_name'])) === $target_norm) {
            http_response_code(409);
            echo json_encode([
                'success' => false,
                'error' => 'A member record already uses this cadet name, class year, and email. Please return to verification and update the existing record, or contact the club if you need help.',
            ]);
            exit();
        }
    }

    $stmt = $pdo->prepare("
        INSERT INTO members (
            class_year, cadet_last_name, cadet_suffix, cadet_first_name, cadet_middle_name, nickname,
            cadet_gender,
            cadet_birthday, cadet_po_box, cadet_email, cadet_cell,
            company,
            parent1_last_name, parent1_first_name, parent1_email, parent1_cell, parent1_relationship, parent1_email_updates,
            parent1_street, parent1_city, parent1_state, parent1_zip,
            parent2_last_name, parent2_first_name, parent2_email, parent2_cell, parent2_relationship, parent2_email_updates,
            parent2_street, parent2_city, parent2_state, parent2_zip,
            photo_consent, directory_consent,
            membership_paid, membership_year
        ) VALUES (
            :class_year, :cadet_last_name, :cadet_suffix, :cadet_first_name, :cadet_middle_name, :nickname,
            :cadet_gender,
            :cadet_birthday, :cadet_po_box, :cadet_email, :cadet_cell,
            :company,
            :parent1_last_name, :parent1_first_name, :parent1_email, :parent1_cell, :parent1_relationship, :parent1_email_updates,
            :parent1_street, :parent1_city, :parent1_state, :parent1_zip,
            :parent2_last_name, :parent2_first_name, :parent2_email, :parent2_cell, :parent2_relationship, :parent2_email_updates,
            :parent2_street, :parent2_city, :parent2_state, :parent2_zip,
            :photo_consent, :directory_consent,
            0, ''
        )
    ");

    $stmt->execute([
        'class_year'          => s($payload, 'graduationYear'),
        'cadet_last_name'     => s($payload, 'cadetLastName'),
        'cadet_suffix'        => s($payload, 'cadetSuffix'),
        'cadet_first_name'    => $first,
        'cadet_middle_name'   => $middle,
        'nickname'            => s($payload, 'nickname'),
        'cadet_gender'        => s($payload, 'cadetGender'),
        'cadet_birthday'      => $dob,
        'cadet_po_box'        => s($payload, 'poBox'),
        'cadet_email'         => s($payload, 'cadetEmail'),
        'cadet_cell'          => format_phone(s($payload, 'cadetPhone')),
        'company'             => s($payload, 'company'),
        'parent1_last_name'   => s($payload, 'parent1LastName'),
        'parent1_first_name'  => s($payload, 'parent1FirstName'),
        'parent1_relationship' => s($payload, 'parent1Relationship'),
        'parent1_email_updates' => $parent1_email_updates,
        'parent1_email'       => s($payload, 'parent1Email'),
        'parent1_cell'        => format_phone(s($payload, 'parent1Phone')),
        'parent1_street'      => s($payload, 'streetAddress'),
        'parent1_city'        => s($payload, 'city'),
        'parent1_state'       => s($payload, 'state'),
        'parent1_zip'         => s($payload, 'zipCode'),
        'parent2_last_name'   => s($payload, 'parent2LastName'),
        'parent2_first_name'  => s($payload, 'parent2FirstName'),
        'parent2_relationship' => s($payload, 'parent2Relationship'),
        'parent2_email_updates' => $parent2_email_updates,
        'parent2_email'       => s($payload, 'parent2Email'),
        'parent2_cell'        => format_phone(s($payload, 'parent2Phone')),
        'parent2_street'      => s($payload,'parent2AddressSame')==='Yes' ? s($payload,'streetAddress') : s($payload,'parent2Street'),
        'parent2_city'        => s($payload,'parent2AddressSame')==='Yes' ? s($payload,'city')          : s($payload,'parent2City'),
        'parent2_state'       => s($payload,'parent2AddressSame')==='Yes' ? s($payload,'state')         : s($payload,'parent2State'),
        'parent2_zip'         => s($payload,'parent2AddressSame')==='Yes' ? s($payload,'zipCode')       : s($payload,'parent2Zip'),
        'photo_consent'       => s($payload, 'photoConsent'),
        'directory_consent'   => s($payload, 'directoryConsent'),
    ]);

    $new_member_id = (int)$pdo->lastInsertId();
    $db_success = true;

} catch (PDOException $e) {
    $db_success = false;
    error_log('Membership handler: MySQL insert failed: ' . $e->getMessage());
}

if (!$db_success) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Database error. Please email secretary@bamablackknights.org directly.'
    ]);
    exit();
}

// ── 2. Send secretary notification email ──────────────────────────────────
$secretary_email = 'secretary@bamablackknights.org';
$subject = 'New Membership Application: '
         . sanitize_header(s($payload, 'cadetFirstName')) . ' '
         . sanitize_header(s($payload, 'cadetLastName'))
         . ($suffix !== '' ? ' ' . sanitize_header($suffix) : '');

$email_body  = "New membership application received:\n\n";
$email_body .= "CADET INFORMATION\n";
$email_body .= "Name: " . trim(preg_replace('/\s+/', ' ', s($payload,'cadetFirstName') . " " . s($payload,'cadetMiddleName') . " " . s($payload,'cadetLastName') . " $suffix")) . "\n";
$email_body .= "Nickname: " . s($payload,'nickname') . "\n";
$email_body .= "Email: " . s($payload,'cadetEmail') . "\n";
$email_body .= "Phone: " . format_phone(s($payload,'cadetPhone')) . "\n";
$email_body .= "Graduation Year: " . s($payload,'graduationYear') . "\n";
$email_body .= "Company: " . s($payload,'company') . "\n\n";
$email_body .= "PARENT/FAMILY INFORMATION\n";
$email_body .= "Primary: " . s($payload,'parent1FirstName') . " " . s($payload,'parent1LastName') . "\n";
$email_body .= "Primary relationship: " . s($payload,'parent1Relationship') . "\n";
$email_body .= "Primary contact opted in to routine club emails: " . ($parent1_email_updates ? 'Yes' : 'No') . "\n";
$email_body .= "Email: " . s($payload,'parent1Email') . "\n";
$email_body .= "Phone: " . format_phone(s($payload,'parent1Phone')) . "\n";
if (s($payload,'parent2FirstName') !== '') {
    $email_body .= "\nSecondary: " . s($payload,'parent2FirstName') . " " . s($payload,'parent2LastName') . "\n";
    $email_body .= "Secondary relationship: " . s($payload,'parent2Relationship') . "\n";
    $email_body .= "Secondary contact opted in to routine club emails: " . ($parent2_email_updates ? 'Yes' : 'No') . "\n";
    $email_body .= "Email: " . s($payload,'parent2Email') . "\n";
    $email_body .= "Phone: " . format_phone(s($payload,'parent2Phone')) . "\n";
}
$email_body .= "\nADDRESS\n";
$email_body .= s($payload,'streetAddress') . "\n";
$email_body .= s($payload,'city') . ", " . s($payload,'state') . " " . s($payload,'zipCode') . "\n\n";
$email_body .= "CONSENTS\n";
$email_body .= "Photo: " . s($payload,'photoConsent') . "\n";
$email_body .= "Directory: " . s($payload,'directoryConsent') . "\n";

$mail = new PHPMailer(true);
try {
    configure_smtp_relay($mail);
    $mail->setFrom(CLUB_FROM_EMAIL, CLUB_NAME);
    $mail->addReplyTo(s($payload,'parent1Email'));
    $mail->addAddress($secretary_email);
    $mail->isHTML(false);
    $mail->Subject = $subject;
    $mail->Body    = $email_body;
    $mail->send();
} catch (PHPMailerException $e) {
    error_log('membership-handler: PHPMailer error (secretary notify) - ' . $mail->ErrorInfo);
}

// ── 3. Confirmation email to parent ──────────────────────────────────────
// Both parents get the confirmation, in one email.
$family = family_recipients(
    s($payload, 'parent1Email'), s($payload, 'parent1FirstName'),
    s($payload, 'parent2Email'), s($payload, 'parent2FirstName')
);
if ($family['emails']) {
    $parent_name  = $family['greeting'];
    $cadet_name   = trim(preg_replace('/\s+/', ' ', "$first $middle " . s($payload, 'cadetLastName') . " $suffix"));
    $conf_subject = 'Membership Application Received — West Point Parents Club of Alabama';
    $conf_body    = "Dear $parent_name,\n\n"
                  . "We have received your membership application for $cadet_name (Class of " . s($payload,'graduationYear') . ").\n\n"
                  . "Your information has been recorded. Continue through the online dues checkout to complete your membership.\n\n"
                  . "If you have any questions, please contact us at secretary@bamablackknights.org.\n\n"
                  . "Duty · Honor · Country\n"
                  . "West Point Parents Club of Alabama\n"
                  . "bamablackknights.org";
    $conf_mail = new PHPMailer(true);
    try {
        configure_smtp_relay($conf_mail);
        $conf_mail->setFrom(CLUB_FROM_EMAIL, CLUB_NAME);
        $conf_mail->addReplyTo(CLUB_FROM_EMAIL, CLUB_NAME);
        foreach ($family['emails'] as $addr) $conf_mail->addAddress($addr);
        $conf_mail->isHTML(false);
        $conf_mail->Subject = $conf_subject;
        $conf_mail->Body    = $conf_body;
        $conf_mail->send();
    } catch (PHPMailerException $e) {
        error_log('membership-handler: PHPMailer error (parent confirmation) - ' . $conf_mail->ErrorInfo);
    }
}

start_verification_session();
$dues_token = bin2hex(random_bytes(24));
if (!isset($_SESSION['dues_verified']) || !is_array($_SESSION['dues_verified'])) {
    $_SESSION['dues_verified'] = [];
}
$_SESSION['dues_verified'][$dues_token] = [
    'member_id' => $new_member_id,
    'expires' => time() + 1800,
    'pending_order' => null,
];
unset($_SESSION['membership_verified'][$membership_token]);
$payable_years = cadet_dues_years(s($payload, 'graduationYear'));

// ── 4. Return success (DB write already succeeded) ────────────────────────
http_response_code(200);
echo json_encode([
    'success' => true,
    'message' => 'Your information has been saved. Continue to checkout to pay your selected membership years.',
    'duesVerifyToken' => $dues_token,
    'cadetYears' => $payable_years,
    'paidYears' => [],
    'payableYears' => $payable_years,
]);
