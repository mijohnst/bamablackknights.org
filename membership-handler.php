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
header('Access-Control-Allow-Origin: https://alabamafalcons.org');

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
    echo json_encode(['success' => true, 'message' => 'Application received! Thank you for joining the Alabama Falcons family.']);
    exit();
}

function sanitize_header($val) {
    return str_replace(["\r", "\n"], '', (string)$val);
}

function s(array $p, string $key): string {
    return trim($p[$key] ?? '');
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
if (!in_array(s($payload, 'graduationYear'), ['2026', '2027', '2028', '2029', '2030', 'Prep School'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid graduation year.']);
    exit();
}
if (!filter_var(s($payload, 'parent1Email'), FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid primary contact email address.']);
    exit();
}
// Gender is optional (blank allowed) but, like graduationYear, is a <select>
// of known values — reject anything else rather than writing a tampered value.
if (s($payload, 'cadetGender') !== '' && !in_array(s($payload, 'cadetGender'), ['Male', 'Female'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid gender.']);
    exit();
}

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
        echo json_encode(['success' => false, 'error' => 'Too many submissions from your network. Please try again later or email us directly at secretary@alabamafalcons.org.']);
        exit();
    }

    // Map form field names → DB columns
    $first  = s($payload, 'cadetFirstName');
    $middle = s($payload, 'cadetMiddleName');
    $suffix = s($payload, 'cadetSuffix');

    $dob = s($payload, 'cadetDOB');
    if ($dob === '') $dob = null;

    // ── Duplicate detection: same last name + class year, AND a matching
    // parent email (either submitted parent against either stored parent
    // column, so a resubmission that lists parents in the opposite order
    // still matches). Matching on name+class-year alone — without an email
    // check — would let two unrelated families who happen to share a last
    // name, class year, and cadet first name silently overwrite each other.
    //
    // Last-name comparison is done in PHP against a normalized form (strip
    // punctuation, collapse whitespace, lowercase) rather than a strict SQL
    // `=` — a name typed as "Jimmerson, Jr" vs "Jimmerson, Jr." on separate
    // submissions is the same family, but a literal `=` treats them as two
    // different rows and silently inserts a duplicate instead of updating.
    // normalize_name() lives in admin/lib.php, shared with the admin panel's
    // own duplicate check, so the two never disagree on what counts as a match.
    // Also stripped of a trailing suffix token via strip_name_suffix() — a
    // legacy record whose suffix is still crammed into cadet_last_name (the
    // exact "Jimmerson, Jr" case this field exists to fix) would otherwise
    // never match the same family's new clean-last-name submission, and get
    // inserted as a duplicate instead of updated.
    $parent1_email = s($payload, 'parent1Email');
    $parent2_email = s($payload, 'parent2Email');
    $submitted_emails = array_map('strtolower', array_filter([$parent1_email, $parent2_email], fn($e) => $e !== ''));
    $cand = $pdo->prepare(
        'SELECT id, cadet_last_name, parent1_email, parent2_email FROM members
         WHERE class_year = :class_year
           AND (
                (:parent1_email <> "" AND (parent1_email = :parent1_email OR parent2_email = :parent1_email))
             OR (:parent2_email <> "" AND (parent1_email = :parent2_email OR parent2_email = :parent2_email))
           )'
    );
    $cand->execute([
        'class_year'    => s($payload, 'graduationYear'),
        'parent1_email' => $parent1_email,
        'parent2_email' => $parent2_email,
    ]);
    $target_norm = strip_name_suffix(normalize_name(s($payload, 'cadetLastName')));
    $existing_id = null;
    foreach ($cand->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (strip_name_suffix(normalize_name($row['cadet_last_name'])) !== $target_norm) continue;
        // Identity check: every email already on file for this family must be
        // present among the submitted emails, not just one of them — matching
        // on just one (the old rule) meant anyone who learned a single parent
        // email on file could silently overwrite the whole record. A family
        // that genuinely only remembers one of two emails on file gets a new
        // row instead (visible to the secretary via the admin duplicate-check
        // tools), which is a far safer failure mode than an unauthorized overwrite.
        $stored_emails = array_map('strtolower', array_filter([$row['parent1_email'], $row['parent2_email']], fn($e) => $e !== ''));
        if (empty(array_diff($stored_emails, $submitted_emails))) { $existing_id = $row['id']; break; }
    }

    if ($existing_id) {
        // Returning member — update their record instead of inserting
        $upd = $pdo->prepare("
            UPDATE members SET
                cadet_suffix=:cadet_suffix,
                cadet_first_name=:cadet_first_name, cadet_middle_name=:cadet_middle_name, nickname=:nickname,
                cadet_gender=:cadet_gender,
                cadet_birthday=:cadet_birthday, cadet_po_box=:cadet_po_box,
                cadet_email=:cadet_email, cadet_cell=:cadet_cell,
                bct_squadron=:bct_squadron,
                parent1_last_name=:parent1_last_name, parent1_first_name=:parent1_first_name,
                parent1_email=:parent1_email, parent1_cell=:parent1_cell,
                parent1_street=:parent1_street, parent1_city=:parent1_city,
                parent1_state=:parent1_state, parent1_zip=:parent1_zip,
                parent2_last_name=:parent2_last_name, parent2_first_name=:parent2_first_name,
                parent2_email=:parent2_email, parent2_cell=:parent2_cell,
                parent2_street=:parent2_street, parent2_city=:parent2_city,
                parent2_state=:parent2_state, parent2_zip=:parent2_zip,
                photo_consent=:photo_consent, directory_consent=:directory_consent
            WHERE id = :id
        ");
        $upd->execute([
            'cadet_suffix'       => $suffix,
            'cadet_first_name'   => $first,
            'cadet_middle_name'  => $middle,
            'nickname'           => s($payload,'nickname'),
            'cadet_gender'       => s($payload,'cadetGender'),
            'cadet_birthday'     => $dob,
            'cadet_po_box'       => s($payload,'poBox'),
            'cadet_email'        => s($payload,'cadetEmail'),
            'cadet_cell'         => s($payload,'cadetPhone'),
            'bct_squadron'       => s($payload,'squadron'),
            'parent1_last_name'  => s($payload,'parent1LastName'),
            'parent1_first_name' => s($payload,'parent1FirstName'),
            'parent1_email'      => s($payload,'parent1Email'),
            'parent1_cell'       => s($payload,'parent1Phone'),
            'parent1_street'     => s($payload,'streetAddress'),
            'parent1_city'       => s($payload,'city'),
            'parent1_state'      => s($payload,'state'),
            'parent1_zip'        => s($payload,'zipCode'),
            'parent2_last_name'  => s($payload,'parent2LastName'),
            'parent2_first_name' => s($payload,'parent2FirstName'),
            'parent2_email'      => s($payload,'parent2Email'),
            'parent2_cell'       => s($payload,'parent2Phone'),
            'parent2_street'     => s($payload,'parent2AddressSame')==='Yes' ? s($payload,'streetAddress') : s($payload,'parent2Street'),
            'parent2_city'       => s($payload,'parent2AddressSame')==='Yes' ? s($payload,'city')          : s($payload,'parent2City'),
            'parent2_state'      => s($payload,'parent2AddressSame')==='Yes' ? s($payload,'state')         : s($payload,'parent2State'),
            'parent2_zip'        => s($payload,'parent2AddressSame')==='Yes' ? s($payload,'zipCode')       : s($payload,'parent2Zip'),
            'photo_consent'      => s($payload,'photoConsent'),
            'directory_consent'  => s($payload,'directoryConsent'),
            'id'                 => $existing_id,
        ]);
        $db_success = true;
    } else {
    $stmt = $pdo->prepare("
        INSERT INTO members (
            class_year, cadet_last_name, cadet_suffix, cadet_first_name, cadet_middle_name, nickname,
            cadet_gender,
            cadet_birthday, cadet_po_box, cadet_email, cadet_cell,
            bct_squadron,
            parent1_last_name, parent1_first_name, parent1_email, parent1_cell,
            parent1_street, parent1_city, parent1_state, parent1_zip,
            parent2_last_name, parent2_first_name, parent2_email, parent2_cell,
            parent2_street, parent2_city, parent2_state, parent2_zip,
            photo_consent, directory_consent,
            membership_paid, membership_year
        ) VALUES (
            :class_year, :cadet_last_name, :cadet_suffix, :cadet_first_name, :cadet_middle_name, :nickname,
            :cadet_gender,
            :cadet_birthday, :cadet_po_box, :cadet_email, :cadet_cell,
            :bct_squadron,
            :parent1_last_name, :parent1_first_name, :parent1_email, :parent1_cell,
            :parent1_street, :parent1_city, :parent1_state, :parent1_zip,
            :parent2_last_name, :parent2_first_name, :parent2_email, :parent2_cell,
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
        'cadet_cell'          => s($payload, 'cadetPhone'),
        'bct_squadron'        => s($payload, 'squadron'),
        'parent1_last_name'   => s($payload, 'parent1LastName'),
        'parent1_first_name'  => s($payload, 'parent1FirstName'),
        'parent1_email'       => s($payload, 'parent1Email'),
        'parent1_cell'        => s($payload, 'parent1Phone'),
        'parent1_street'      => s($payload, 'streetAddress'),
        'parent1_city'        => s($payload, 'city'),
        'parent1_state'       => s($payload, 'state'),
        'parent1_zip'         => s($payload, 'zipCode'),
        'parent2_last_name'   => s($payload, 'parent2LastName'),
        'parent2_first_name'  => s($payload, 'parent2FirstName'),
        'parent2_email'       => s($payload, 'parent2Email'),
        'parent2_cell'        => s($payload, 'parent2Phone'),
        'parent2_street'      => s($payload,'parent2AddressSame')==='Yes' ? s($payload,'streetAddress') : s($payload,'parent2Street'),
        'parent2_city'        => s($payload,'parent2AddressSame')==='Yes' ? s($payload,'city')          : s($payload,'parent2City'),
        'parent2_state'       => s($payload,'parent2AddressSame')==='Yes' ? s($payload,'state')         : s($payload,'parent2State'),
        'parent2_zip'         => s($payload,'parent2AddressSame')==='Yes' ? s($payload,'zipCode')       : s($payload,'parent2Zip'),
        'photo_consent'       => s($payload, 'photoConsent'),
        'directory_consent'   => s($payload, 'directoryConsent'),
    ]);

    $db_success = true;
    } // end else (new member insert)

} catch (PDOException $e) {
    $db_success = false;
    error_log('Membership handler: MySQL insert failed: ' . $e->getMessage());
}

if (!$db_success) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error'   => 'Database error. Please email secretary@alabamafalcons.org directly.'
    ]);
    exit();
}

// ── 2. Send secretary notification email ──────────────────────────────────
$secretary_email = 'secretary@alabamafalcons.org';
$subject = 'New Membership Application: '
         . sanitize_header(s($payload, 'cadetFirstName')) . ' '
         . sanitize_header(s($payload, 'cadetLastName'))
         . ($suffix !== '' ? ' ' . sanitize_header($suffix) : '');

$email_body  = "New membership application received:\n\n";
$email_body .= "CADET INFORMATION\n";
$email_body .= "Name: " . trim(preg_replace('/\s+/', ' ', s($payload,'cadetFirstName') . " " . s($payload,'cadetMiddleName') . " " . s($payload,'cadetLastName') . " $suffix")) . "\n";
$email_body .= "Nickname: " . s($payload,'nickname') . "\n";
$email_body .= "Email: " . s($payload,'cadetEmail') . "\n";
$email_body .= "Phone: " . s($payload,'cadetPhone') . "\n";
$email_body .= "Graduation Year: " . s($payload,'graduationYear') . "\n";
$email_body .= "Squadron: " . s($payload,'squadron') . "\n\n";
$email_body .= "PARENT/FAMILY INFORMATION\n";
$email_body .= "Primary: " . s($payload,'parent1FirstName') . " " . s($payload,'parent1LastName') . "\n";
$email_body .= "Email: " . s($payload,'parent1Email') . "\n";
$email_body .= "Phone: " . s($payload,'parent1Phone') . "\n";
if (s($payload,'parent2FirstName') !== '') {
    $email_body .= "\nSecondary: " . s($payload,'parent2FirstName') . " " . s($payload,'parent2LastName') . "\n";
    $email_body .= "Email: " . s($payload,'parent2Email') . "\n";
    $email_body .= "Phone: " . s($payload,'parent2Phone') . "\n";
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
$parent_email = s($payload, 'parent1Email');
if (filter_var($parent_email, FILTER_VALIDATE_EMAIL)) {
    $parent_name  = s($payload, 'parent1FirstName');
    $cadet_name   = trim(preg_replace('/\s+/', ' ', "$first $middle " . s($payload, 'cadetLastName') . " $suffix"));
    $conf_subject = 'Membership Application Received — USAFA Parents Club of Alabama';
    $conf_body    = "Dear $parent_name,\n\n"
                  . "We have received your membership application for $cadet_name (Class of " . s($payload,'graduationYear') . ").\n\n"
                  . "Your information has been recorded. You will be redirected to our payment page to complete your membership.\n\n"
                  . "If you have any questions, please contact us at info@alabamafalcons.org.\n\n"
                  . "Aim High · Fly · Fight · Win\n"
                  . "USAFA Parents Club of Alabama\n"
                  . "alabamafalcons.org";
    $conf_mail = new PHPMailer(true);
    try {
        configure_smtp_relay($conf_mail);
        $conf_mail->setFrom(CLUB_FROM_EMAIL, CLUB_NAME);
        $conf_mail->addReplyTo(CLUB_FROM_EMAIL, CLUB_NAME);
        $conf_mail->addAddress($parent_email);
        $conf_mail->isHTML(false);
        $conf_mail->Subject = $conf_subject;
        $conf_mail->Body    = $conf_body;
        $conf_mail->send();
    } catch (PHPMailerException $e) {
        error_log('membership-handler: PHPMailer error (parent confirmation) - ' . $conf_mail->ErrorInfo);
    }
}

// ── 4. Return success (DB write already succeeded) ────────────────────────
http_response_code(200);
echo json_encode([
    'success' => true,
    'message' => 'Application received! Thank you for joining the Alabama Falcons family. Redirecting to payment page...'
]);
