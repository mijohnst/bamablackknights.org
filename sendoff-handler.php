<?php
header('Content-Type: application/json');

require_once __DIR__ . '/admin/auth.php';
require_once __DIR__ . '/admin/form-guard.php';
require_once __DIR__ . '/admin/mailer.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid request data.']);
    exit;
}

// Honeypot — bots fill this hidden field, real visitors never see it.
// Pretend success so bots don't learn to avoid the field.
if (honeypot_tripped($input)) {
    echo json_encode(['success' => true, 'message' => 'Registration submitted! We look forward to seeing you at the sendoff.']);
    exit;
}

if (rate_limited(get_pdo(), 'sendoff_form')) {
    http_response_code(429);
    echo json_encode(['success' => false, 'message' => 'Too many submissions from your network. Please try again later or email us directly at secretary@alabamafalcons.org.']);
    exit;
}

// Validate required fields (all except real_id_state, real_id_number, email)
$required = ['last', 'first', 'dob', 'real_id_state', 'real_id_number', 'dod_id_holder', 'us_citizen', 'cadet_name', 'affiliation', 'phone', 'email'];
foreach ($required as $field) {
    if (empty(trim($input[$field] ?? ''))) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Please fill in all required fields.']);
        exit;
    }
}

if (!filter_var(trim($input['email']), FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Please enter a valid email address.']);
    exit;
}

// Paste your deployed Apps Script Web App URL here after following setup instructions
define('APPS_SCRIPT_URL', 'https://script.google.com/macros/s/AKfycbz4ZSWEaO7ttsOJ2Baw9ybPl_eeHbgqXDeCwZHmW7zHqdFInul1b1BEJa82le5v2Ljv/exec');

$dob_raw = trim($input['dob'] ?? '');
$dob     = $dob_raw ? date('m/d/Y', strtotime($dob_raw)) : '';

$payload = json_encode([
    'last'           => trim($input['last']           ?? ''),
    'first'          => trim($input['first']          ?? ''),
    'dob'            => $dob,
    'real_id_state'  => trim($input['real_id_state']  ?? ''),
    'real_id_number' => trim($input['real_id_number'] ?? ''),
    'dod_id_holder'  => trim($input['dod_id_holder']  ?? ''),
    'us_citizen'     => trim($input['us_citizen']     ?? ''),
    'cadet_name'     => trim($input['cadet_name']     ?? ''),
    'affiliation'    => trim($input['affiliation']    ?? ''),
    'phone'          => trim($input['phone']          ?? ''),
    'email'          => trim($input['email']          ?? ''),
]);

$ch = curl_init(APPS_SCRIPT_URL);
curl_setopt($ch, CURLOPT_POST,          true);
curl_setopt($ch, CURLOPT_POSTFIELDS,    $payload);
curl_setopt($ch, CURLOPT_HTTPHEADER,    ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_TIMEOUT,       15);

$response  = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curl_err  = curl_error($ch);
curl_close($ch);

if ($response === false || !empty($curl_err) || $http_code >= 400) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not save your registration. Please try again or email secretary@alabamafalcons.org.']);
    exit;
}

$result = json_decode($response, true);
if (isset($result['success']) && $result['success'] === false) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $result['message'] ?? 'Registration could not be saved.']);
    exit;
}

// Mask all but the last 4 characters of an ID number — the full number is
// already saved to the registration system (Google Sheets); no need for it
// to also sit in plain text in an email inbox indefinitely.
function mask_id_number(string $id): string {
    $id = preg_replace('/\s+/', '', $id);
    $len = strlen($id);
    if ($len === 0) return '';
    if ($len <= 4) return str_repeat('•', $len);
    return str_repeat('•', $len - 4) . substr($id, -4);
}

// Email notification to secretary
$data = json_decode($payload, true);
$to      = 'secretary@alabamafalcons.org';
$subject = str_replace(["\r", "\n"], '', 'New Sendoff Registration – ' . $data['first'] . ' ' . $data['last']);
$body    = "A new registration has been submitted for the Cadet Class of 2030 Sendoff.\n\n"
         . "Last Name:                  " . $data['last']           . "\n"
         . "First Name:                 " . $data['first']          . "\n"
         . "Date of Birth:              " . $data['dob']            . "\n"
         . "REAL ID State:              " . ($data['real_id_state']  ?: '—') . "\n"
         . "REAL ID Number (DL):        " . ($data['real_id_number'] ? mask_id_number($data['real_id_number']) . ' (full number on file in registration system)' : '—') . "\n"
         . "DOD ID Holder:              " . $data['dod_id_holder']  . "\n"
         . "US Citizen:                 " . $data['us_citizen']     . "\n"
         . "Cadet Name:                 " . $data['cadet_name']     . "\n"
         . "Affiliation with Cadet:     " . $data['affiliation']    . "\n"
         . "Phone:                      " . $data['phone']          . "\n"
         . "Email:                      " . ($data['email']          ?: '—') . "\n";

$mail = new PHPMailer(true);
try {
    configure_smtp_relay($mail);
    $mail->setFrom(CLUB_FROM_EMAIL, CLUB_NAME);
    $mail->addReplyTo($data['email']);
    $mail->addAddress($to);
    $mail->isHTML(false);
    $mail->Subject = $subject;
    $mail->Body    = $body;
    $mail->send();
} catch (PHPMailerException $e) {
    error_log('sendoff-handler: PHPMailer error (secretary notify) - ' . $mail->ErrorInfo);
}

// Confirmation email to registrant
if (!empty($data['email'])) {
    $conf_to      = $data['email'];
    $conf_subject = 'Registration Confirmation – USAFA Alabama Cadet Sendoff';
    $conf_body    = "Dear " . $data['first'] . " " . $data['last'] . ",\n\n"
                  . "You have successfully registered for entry into Maxwell Air Force Base to attend the USAFA Alabama Cadet Sendoff.\n\n"
                  . "Please retain this email as confirmation of your registration. Below is a summary of the information you submitted:\n\n"
                  . "Last Name:                  " . $data['last']        . "\n"
                  . "First Name:                 " . $data['first']       . "\n"
                  . "Cadet Name:                 " . $data['cadet_name']  . "\n"
                  . "Affiliation with Cadet:     " . $data['affiliation'] . "\n"
                  . "Phone:                      " . $data['phone']       . "\n"
                  . "Email:                      " . $data['email']       . "\n\n"
                  . "If you have any questions, please contact us at secretary@alabamafalcons.org.\n\n"
                  . "We look forward to celebrating with you!\n\n"
                  . "USAFA Parents Club of Alabama\n"
                  . "alabamafalcons.org";
    $conf_mail = new PHPMailer(true);
    try {
        configure_smtp_relay($conf_mail);
        $conf_mail->setFrom(CLUB_FROM_EMAIL, CLUB_NAME);
        $conf_mail->addReplyTo('secretary@alabamafalcons.org', CLUB_NAME);
        $conf_mail->addAddress($conf_to);
        $conf_mail->isHTML(false);
        $conf_mail->Subject = $conf_subject;
        $conf_mail->Body    = $conf_body;
        $conf_mail->send();
    } catch (PHPMailerException $e) {
        error_log('sendoff-handler: PHPMailer error (registrant confirmation) - ' . $conf_mail->ErrorInfo);
    }
}

echo json_encode(['success' => true, 'message' => 'Registration submitted! We look forward to seeing you at the sendoff.']);
