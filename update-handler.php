<?php
/**
 * Update-Your-Information Handler
 * Matches the submitted form to an existing member record and updates it.
 * Never creates a new record — if no match is found, the visitor is told
 * to contact the secretary. Does not touch membership_paid, membership_year,
 * membership_paid_through, or membership_type (dues status is untouched).
 */

header('Content-Type: application/json');
// Must be set on every response, not just the OPTIONS preflight — see
// membership-handler.php for why.
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
if (honeypot_tripped($payload)) {
    echo json_encode(['success' => true, 'message' => 'Your information has been updated.']);
    exit();
}

function sanitize_header($val) {
    return str_replace(["\r", "\n"], '', (string)$val);
}

function s(array $p, string $key): string {
    return trim($p[$key] ?? '');
}

// Blank means "leave the existing value alone" throughout this form's
// COALESCE(NULLIF(...)) update pattern — treat an invalid email address
// the same way (blank) rather than writing bad data into the column.
function e(array $p, string $key): string {
    $v = trim($p[$key] ?? '');
    return ($v !== '' && filter_var($v, FILTER_VALIDATE_EMAIL)) ? $v : '';
}

require_once __DIR__ . '/admin/config.php';

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_EMULATE_PREPARES => true]
    );

    if (rate_limited($pdo, 'update_form')) {
        http_response_code(429);
        echo json_encode(['success' => false, 'error' => 'Too many submissions from your network. Please try again later or email secretary@alabamafalcons.org.']);
        exit();
    }

    $first  = s($payload, 'cadetFirstName');
    $middle = s($payload, 'cadetMiddleName');

    // Date of Birth isn't collected on this form — bind null so the
    // COALESCE-protected UPDATE below leaves the existing value untouched.
    $dob = null;

    // ── Find the existing record — never create a new one. Identity comes
    // from a per-lookup token issued by update-lookup.php upon a real,
    // successful lookup — NOT from resubmitted last name/class year/email
    // hidden fields, which (unlike a server-side token) could be fabricated
    // or replayed directly against this endpoint without ever passing a
    // genuine lookup. Keyed by token rather than one shared session slot so
    // a second "Find My Record" lookup in another tab can't silently
    // overwrite a still-open first tab's verified identity.
    start_verification_session();
    $verify_token = s($payload, 'verifyToken');
    $verified     = $_SESSION['update_verified'][$verify_token] ?? null;
    // Consumed immediately so one verified lookup can't drive repeated
    // updates without going back through "Find My Record" each time.
    if ($verify_token !== '') unset($_SESSION['update_verified'][$verify_token]);

    $existing_id = (int)($verified['member_id'] ?? 0);
    if (!$existing_id || ($verified['expires'] ?? 0) < time()) {
        echo json_encode([
            'success' => false,
            'error'   => 'Your session expired. Please use "Find My Record" again before submitting changes.'
        ]);
        exit();
    }

    $check = $pdo->prepare('SELECT id FROM members WHERE archived = 0 AND id = ?');
    $check->execute([$existing_id]);
    if (!$check->fetch()) {
        echo json_encode([
            'success' => false,
            'error'   => "We couldn't find a matching record. Please email secretary@alabamafalcons.org so we can update your information manually."
        ]);
        exit();
    }

    // Snapshot the record before updating so the secretary notification below
    // can show exactly what changed, not just the record as it now stands.
    $before = $pdo->prepare('SELECT * FROM members WHERE id = ?');
    $before->execute([$existing_id]);
    $old = $before->fetch(PDO::FETCH_ASSOC);

    // A blank submitted value means "didn't change this field" — COALESCE(NULLIF(...))
    // keeps the existing column value instead of overwriting it with an empty string.
    // cadet_birthday is the exception: it's already normalized to PHP null when blank,
    // so a plain COALESCE against the bound (possibly-null) value works directly.
    // Graduation year is a <select> of known values — whitelist it so a
    // tampered/garbage value falls back to COALESCE-preserving the existing
    // year instead of being written as-is.
    $grad_year = s($payload, 'graduationYear');
    if (!in_array($grad_year, ['2026', '2027', '2028', '2029', '2030', 'Prep School'], true)) {
        $grad_year = '';
    }
    // Same whitelist treatment for Gender — a tampered value falls back to
    // COALESCE-preserving whatever is already on file instead of being written as-is.
    $gender = s($payload, 'cadetGender');
    if (!in_array($gender, ['Male', 'Female'], true)) {
        $gender = '';
    }

    $upd = $pdo->prepare("
        UPDATE members SET
            class_year        = COALESCE(NULLIF(:class_year, ''), class_year),
            cadet_first_name  = COALESCE(NULLIF(:cadet_first_name, ''), cadet_first_name),
            cadet_middle_name = COALESCE(NULLIF(:cadet_middle_name, ''), cadet_middle_name),
            cadet_suffix      = COALESCE(NULLIF(:cadet_suffix, ''), cadet_suffix),
            nickname          = COALESCE(NULLIF(:nickname, ''), nickname),
            cadet_gender      = COALESCE(NULLIF(:cadet_gender, ''), cadet_gender),
            cadet_birthday    = COALESCE(:cadet_birthday, cadet_birthday),
            cadet_po_box      = COALESCE(NULLIF(:cadet_po_box, ''), cadet_po_box),
            cadet_email       = COALESCE(NULLIF(:cadet_email, ''), cadet_email),
            cadet_cell        = COALESCE(NULLIF(:cadet_cell, ''), cadet_cell),
            bct_squadron      = COALESCE(NULLIF(:bct_squadron, ''), bct_squadron),
            parent1_last_name  = COALESCE(NULLIF(:parent1_last_name, ''), parent1_last_name),
            parent1_first_name = COALESCE(NULLIF(:parent1_first_name, ''), parent1_first_name),
            parent1_email      = COALESCE(NULLIF(:parent1_email, ''), parent1_email),
            parent1_cell       = COALESCE(NULLIF(:parent1_cell, ''), parent1_cell),
            parent1_street     = COALESCE(NULLIF(:parent1_street, ''), parent1_street),
            parent1_city       = COALESCE(NULLIF(:parent1_city, ''), parent1_city),
            parent1_state      = COALESCE(NULLIF(:parent1_state, ''), parent1_state),
            parent1_zip        = COALESCE(NULLIF(:parent1_zip, ''), parent1_zip),
            parent2_last_name  = COALESCE(NULLIF(:parent2_last_name, ''), parent2_last_name),
            parent2_first_name = COALESCE(NULLIF(:parent2_first_name, ''), parent2_first_name),
            parent2_email      = COALESCE(NULLIF(:parent2_email, ''), parent2_email),
            parent2_cell       = COALESCE(NULLIF(:parent2_cell, ''), parent2_cell),
            parent2_street     = COALESCE(NULLIF(:parent2_street, ''), parent2_street),
            parent2_city       = COALESCE(NULLIF(:parent2_city, ''), parent2_city),
            parent2_state      = COALESCE(NULLIF(:parent2_state, ''), parent2_state),
            parent2_zip        = COALESCE(NULLIF(:parent2_zip, ''), parent2_zip),
            photo_consent      = COALESCE(NULLIF(:photo_consent, ''), photo_consent),
            directory_consent  = COALESCE(NULLIF(:directory_consent, ''), directory_consent)
        WHERE id = :id
    ");
    $upd->execute([
        'class_year'         => $grad_year,
        'cadet_first_name'   => $first,
        'cadet_middle_name'  => $middle,
        'cadet_suffix'       => s($payload, 'cadetSuffix'),
        'nickname'           => s($payload, 'nickname'),
        'cadet_gender'       => $gender,
        'cadet_birthday'     => $dob,
        'cadet_po_box'       => s($payload, 'poBox'),
        'cadet_email'        => e($payload, 'cadetEmail'),
        'cadet_cell'         => s($payload, 'cadetPhone'),
        'bct_squadron'       => s($payload, 'squadron'),
        'parent1_last_name'  => s($payload, 'parent1LastName'),
        'parent1_first_name' => s($payload, 'parent1FirstName'),
        'parent1_email'      => e($payload, 'parent1Email'),
        'parent1_cell'       => s($payload, 'parent1Phone'),
        'parent1_street'     => s($payload, 'streetAddress'),
        'parent1_city'       => s($payload, 'city'),
        'parent1_state'      => s($payload, 'state'),
        'parent1_zip'        => s($payload, 'zipCode'),
        'parent2_last_name'  => s($payload, 'parent2LastName'),
        'parent2_first_name' => s($payload, 'parent2FirstName'),
        'parent2_email'      => e($payload, 'parent2Email'),
        'parent2_cell'       => s($payload, 'parent2Phone'),
        'parent2_street'     => s($payload,'parent2AddressSame')==='Yes' ? s($payload,'streetAddress') : s($payload,'parent2Street'),
        'parent2_city'       => s($payload,'parent2AddressSame')==='Yes' ? s($payload,'city')          : s($payload,'parent2City'),
        'parent2_state'      => s($payload,'parent2AddressSame')==='Yes' ? s($payload,'state')         : s($payload,'parent2State'),
        'parent2_zip'        => s($payload,'parent2AddressSame')==='Yes' ? s($payload,'zipCode')       : s($payload,'parent2Zip'),
        'photo_consent'      => s($payload, 'photoConsent'),
        'directory_consent'  => s($payload, 'directoryConsent'),
        'id'                 => $existing_id,
    ]);

    // Re-fetch the record as it now actually stands — since blank submitted
    // fields were preserved rather than overwritten, the emails below need
    // the real saved values, not just whatever was (or wasn't) typed this time.
    $fresh = $pdo->prepare('SELECT * FROM members WHERE id = ?');
    $fresh->execute([$existing_id]);
    $member = $fresh->fetch(PDO::FETCH_ASSOC);

} catch (PDOException $e) {
    error_log('Update handler: MySQL error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error. Please email secretary@alabamafalcons.org directly.']);
    exit();
}

$g = fn(string $k) => (string)($member[$k] ?? '');
$o = fn(string $k) => (string)($old[$k] ?? '');

// ── Build a before/after diff so the secretary can review at a glance ─────
$diff_labels = [
    'class_year'          => 'Graduation Year',
    'cadet_first_name'   => 'Cadet First Name',
    'cadet_middle_name'  => 'Cadet Middle Name',
    'cadet_suffix'        => 'Cadet Suffix',
    'nickname'            => 'Nickname',
    'cadet_gender'        => 'Gender',
    'cadet_po_box'        => 'USAFA Mailbox / PO Box',
    'cadet_email'         => 'Cadet Email',
    'cadet_cell'          => 'Cadet Cell Phone',
    'bct_squadron'        => 'Squadron',
    'parent1_first_name'  => 'Primary Contact First Name',
    'parent1_last_name'   => 'Primary Contact Last Name',
    'parent1_email'       => 'Primary Contact Email',
    'parent1_cell'        => 'Primary Contact Phone',
    'parent1_street'      => 'Primary Contact Street',
    'parent1_city'        => 'Primary Contact City',
    'parent1_state'       => 'Primary Contact State',
    'parent1_zip'         => 'Primary Contact Zip',
    'parent2_first_name'  => 'Secondary Contact First Name',
    'parent2_last_name'   => 'Secondary Contact Last Name',
    'parent2_email'       => 'Secondary Contact Email',
    'parent2_cell'        => 'Secondary Contact Phone',
    'parent2_street'      => 'Secondary Contact Street',
    'parent2_city'        => 'Secondary Contact City',
    'parent2_state'       => 'Secondary Contact State',
    'parent2_zip'         => 'Secondary Contact Zip',
    'photo_consent'       => 'Photo Consent',
    'directory_consent'   => 'Directory Consent',
];
$changes = [];
foreach ($diff_labels as $col => $label) {
    if ($o($col) !== $g($col)) {
        $changes[] = $label . ': ' . ($o($col) !== '' ? $o($col) : '(blank)') . '  →  ' . ($g($col) !== '' ? $g($col) : '(blank)');
    }
}

// ── Notify secretary of the update ────────────────────────────────────────
$secretary_email = 'secretary@alabamafalcons.org';
$subject = 'Member Info Updated: ' . sanitize_header($g('cadet_first_name')) . ' ' . sanitize_header($g('cadet_last_name'))
         . ($g('cadet_suffix') !== '' ? ' ' . sanitize_header($g('cadet_suffix')) : '');

$email_body  = "A member updated their information via the Update Your Information form.\n\n";
$email_body .= "WHAT CHANGED\n";
$email_body .= empty($changes) ? "(No fields actually changed — everything submitted matched what was already on file.)\n\n" : implode("\n", $changes) . "\n\n";
$email_body .= "Fields left blank on the form kept their existing value — the full record below reflects how it now stands:\n\n";
$email_body .= "CADET INFORMATION\n";
$email_body .= "Name: " . trim(preg_replace('/\s+/', ' ', $g('cadet_first_name') . ' ' . $g('cadet_middle_name') . ' ' . $g('cadet_last_name') . ' ' . $g('cadet_suffix'))) . "\n";
$email_body .= "Nickname: " . $g('nickname') . "\n";
$email_body .= "Email: " . $g('cadet_email') . "\n";
$email_body .= "Phone: " . $g('cadet_cell') . "\n";
$email_body .= "Graduation Year: " . $g('class_year') . "\n";
$email_body .= "Squadron: " . $g('bct_squadron') . "\n";
$email_body .= "USAFA Mailbox: " . $g('cadet_po_box') . "\n\n";
$email_body .= "PARENT/FAMILY INFORMATION\n";
$email_body .= "Primary: " . trim($g('parent1_first_name') . ' ' . $g('parent1_last_name')) . "\n";
$email_body .= "Email: " . $g('parent1_email') . "\n";
$email_body .= "Phone: " . $g('parent1_cell') . "\n";
if ($g('parent2_first_name') !== '') {
    $email_body .= "\nSecondary: " . trim($g('parent2_first_name') . ' ' . $g('parent2_last_name')) . "\n";
    $email_body .= "Email: " . $g('parent2_email') . "\n";
    $email_body .= "Phone: " . $g('parent2_cell') . "\n";
}
$email_body .= "\nADDRESS\n";
$email_body .= $g('parent1_street') . "\n";
$email_body .= $g('parent1_city') . ", " . $g('parent1_state') . " " . $g('parent1_zip') . "\n\n";
$email_body .= "CONSENTS\n";
$email_body .= "Photo: " . $g('photo_consent') . "\n";
$email_body .= "Directory: " . $g('directory_consent') . "\n";

$mail = new PHPMailer(true);
try {
    configure_smtp_relay($mail);
    $mail->setFrom(CLUB_FROM_EMAIL, CLUB_NAME);
    // parent1_email can be blank here (an unrelated field may have been the
    // one actually updated) — only set Reply-To when there's a real address,
    // since PHPMailer rejects an empty one and would abort the whole send.
    if ($g('parent1_email') !== '') $mail->addReplyTo($g('parent1_email'));
    $mail->addAddress($secretary_email);
    $mail->isHTML(false);
    $mail->Subject = $subject;
    $mail->Body    = $email_body;
    $mail->send();
} catch (PHPMailerException $e) {
    error_log('update-handler: PHPMailer error (secretary notify) - ' . $mail->ErrorInfo);
}

// ── Confirmation email to parent ──────────────────────────────────────────
$parent_email = $g('parent1_email');
if (filter_var($parent_email, FILTER_VALIDATE_EMAIL)) {
    $parent_name  = $g('parent1_first_name') ?: 'there';
    $cadet_name   = trim(preg_replace('/\s+/', ' ', $g('cadet_first_name') . ' ' . $g('cadet_middle_name') . ' ' . $g('cadet_last_name') . ' ' . $g('cadet_suffix'))) ?: 'your cadet';
    $conf_subject = 'Your Information Has Been Updated — USAFA Parents Club of Alabama';
    $conf_body    = "Dear $parent_name,\n\n"
                  . "Your family's information for $cadet_name has been updated in our records.\n\n"
                  . "If any of this wasn't intentional, or you have questions, please contact us at secretary@alabamafalcons.org.\n\n"
                  . "Aim High · Fly · Fight · Win\n"
                  . "USAFA Parents Club of Alabama\n"
                  . "alabamafalcons.org";
    $conf_mail = new PHPMailer(true);
    try {
        configure_smtp_relay($conf_mail);
        $conf_mail->setFrom('secretary@alabamafalcons.org', CLUB_NAME);
        $conf_mail->addReplyTo('secretary@alabamafalcons.org', CLUB_NAME);
        $conf_mail->addAddress($parent_email);
        $conf_mail->isHTML(false);
        $conf_mail->Subject = $conf_subject;
        $conf_mail->Body    = $conf_body;
        $conf_mail->send();
    } catch (PHPMailerException $e) {
        error_log('update-handler: PHPMailer error (parent confirmation) - ' . $conf_mail->ErrorInfo);
    }
}

http_response_code(200);
echo json_encode([
    'success' => true,
    'message' => 'Thank you! Your information has been updated.'
]);
