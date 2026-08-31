<?php
require_once __DIR__ . '/admin/auth.php';
require_once __DIR__ . '/admin/form-guard.php';
require_once __DIR__ . '/admin/mailer.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

header('Content-Type: application/json');
// Must be set on every response, not just the OPTIONS preflight — see
// membership-handler.php for why.
header('Access-Control-Allow-Origin: https://alabamafalcons.org');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    http_response_code(200); exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); echo json_encode(['error'=>'Method not allowed']); exit();
}

$input = file_get_contents('php://input');
$data  = json_decode($input, true);
if (!$data) { http_response_code(400); echo json_encode(['error'=>'Invalid input']); exit(); }

// Honeypot — bots fill this hidden field, real visitors never see it.
// Pretend success so bots don't learn to avoid the field.
if (honeypot_tripped($data)) {
    echo json_encode(['success' => true, 'message' => "Thank you! We'll be in touch soon."]);
    exit;
}

if (rate_limited(get_pdo(), 'volunteer_form')) {
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => 'Too many submissions from your network. Please try again later or email us directly at info@alabamafalcons.org.']);
    exit;
}

function s(array $d, string $k): string { return trim($d[$k] ?? ''); }
function sanitize_hdr(string $v): string { return str_replace(["\r","\n"], '', $v); }

$name         = s($data, 'name');
$email        = s($data, 'email');
$phone        = s($data, 'phone');
$areas_raw    = $data['areas'] ?? null;
$areas        = is_array($areas_raw)
    ? implode(', ', array_filter(array_map(fn($v) => is_string($v) ? trim($v) : '', $areas_raw)))
    : s($data, 'areas');
$availability = s($data, 'availability');
$cadet_info   = s($data, 'cadetInfo');
$comments     = s($data, 'comments');

if (!$name || !$email) { http_response_code(400); echo json_encode(['error'=>'Name and email are required.']); exit(); }
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { http_response_code(400); echo json_encode(['error'=>'Invalid email address.']); exit(); }

// Save to DB
try {
    $pdo = get_pdo();
    $pdo->prepare('INSERT INTO volunteers (name,email,phone,areas,availability,cadet_info,comments) VALUES (?,?,?,?,?,?,?)')
        ->execute([$name,$email,$phone,$areas,$availability,$cadet_info,$comments]);
} catch (Exception $e) {
    error_log('volunteer-handler DB error: '.$e->getMessage());
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'A server error occurred. Please try again or email us directly.']);
    exit;
}

// Email notification
$subject  = sanitize_hdr('New Volunteer Interest: ' . $name);
$body     = "USAFA Parents Club of Alabama\nNew Volunteer Interest Submission\n" . str_repeat('─',48) . "\n\n"
           . "Name:         $name\n"
           . "Email:        $email\n"
           . ($phone        ? "Phone:        $phone\n"        : '')
           . ($areas        ? "Areas:        $areas\n"        : '')
           . ($availability ? "Availability: $availability\n" : '')
           . ($cadet_info   ? "Cadet Info:   $cadet_info\n"  : '')
           . ($comments     ? "\nComments:\n$comments\n"      : '')
           . "\n" . str_repeat('─',48) . "\nalabamafalcons.org/admin/";

foreach (['secretary@alabamafalcons.org', 'president@alabamafalcons.org'] as $notify_to) {
    $mail = new PHPMailer(true);
    try {
        configure_smtp_relay($mail);
        $mail->setFrom(CLUB_FROM_EMAIL, CLUB_NAME);
        $mail->addReplyTo($email);
        $mail->addAddress($notify_to);
        $mail->isHTML(false);
        $mail->Subject = $subject;
        $mail->Body    = $body;
        $mail->send();
    } catch (PHPMailerException $e) {
        error_log("volunteer-handler: PHPMailer error (notify $notify_to) - " . $mail->ErrorInfo);
    }
}

// Confirmation to volunteer
$conf = "Thank you for your interest in volunteering with the USAFA Parents Club of Alabama!\n\n"
      . "We've received your information and a club officer will be in touch soon.\n\n"
      . "Your submission:\n"
      . "  Areas of interest: " . ($areas ?: 'Not specified') . "\n"
      . "  Availability: " . ($availability ?: 'Not specified') . "\n\n"
      . "Aim High · Fly · Fight · Win\n"
      . "USAFA Parents Club of Alabama\nalabamafalcons.org";
$conf_mail = new PHPMailer(true);
try {
    configure_smtp_relay($conf_mail);
    $conf_mail->setFrom(CLUB_FROM_EMAIL, CLUB_NAME);
    $conf_mail->addAddress($email);
    $conf_mail->isHTML(false);
    $conf_mail->Subject = 'Volunteer Interest Received — USAFA Parents Club of Alabama';
    $conf_mail->Body    = $conf;
    $conf_mail->send();
} catch (PHPMailerException $e) {
    error_log('volunteer-handler: PHPMailer error (confirmation) - ' . $conf_mail->ErrorInfo);
}

http_response_code(200);
echo json_encode(['success'=>true,'message'=>'Thank you! We\'ll be in touch soon.']);
