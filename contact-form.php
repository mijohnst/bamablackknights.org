<?php
/**
 * Contact Form Handler for USAFA Alabama Parents Club
 * Sends via Google SMTP relay (see admin/mailer.php)
 */

require_once __DIR__ . '/admin/auth.php';
require_once __DIR__ . '/admin/form-guard.php';
require_once __DIR__ . '/admin/mailer.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

// Set headers
header('Content-Type: application/json');

// Configuration
$to_email = 'info@alabamafalcons.org'; // Where form submissions go
$subject_prefix = '[USAFA AL Website]'; // Email subject prefix

// Check if this is a POST request
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Get POST data
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

// Fallback: also accept regular POST fields (form submission)
if (empty($data)) {
    $data = $_POST;
}

// Honeypot — bots fill this hidden field, real visitors never see it.
// Pretend success so bots don't learn to avoid the field.
if (honeypot_tripped($data)) {
    echo json_encode(['success' => true, 'message' => 'Thank you! Your message has been sent.']);
    exit;
}

if (rate_limited(get_pdo(), 'contact_form')) {
    http_response_code(429);
    echo json_encode(['success' => false, 'message' => 'Too many submissions from your network. Please try again later or email us directly at info@alabamafalcons.org.']);
    exit;
}

// Validate required fields
$required_fields = ['name', 'email', 'message'];
foreach ($required_fields as $field) {
    if (empty($data[$field])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => "Missing required field: $field"]);
        exit;
    }
}

// Sanitize inputs — strip_tags removes any HTML, str_replace strips CR/LF
// before values enter mail headers. No htmlspecialchars() here: the email
// this feeds is sent as plain text (isHTML(false) below), so HTML-entity
// encoding would just corrupt ordinary characters like & and ' in the
// notification the club receives.
$name = str_replace(["\r", "\n"], '', strip_tags(trim($data['name'])));
$email = str_replace(["\r", "\n"], '', filter_var(trim($data['email']), FILTER_SANITIZE_EMAIL));
$iam = isset($data['iam']) ? strip_tags(trim($data['iam'])) : 'Not specified';
$class_year = isset($data['classYear']) ? strip_tags(trim($data['classYear'])) : 'N/A';
$message = strip_tags(trim($data['message']));

// Validate email
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid email address']);
    exit;
}

// Build email subject
$subject = "$subject_prefix Contact Form Submission from $name";

// Build email body
$email_body = "New contact form submission from alabamafalcons.org\n\n";
$email_body .= "Name: $name\n";
$email_body .= "Email: $email\n";
$email_body .= "I am a: $iam\n";
$email_body .= "Cadet's Class Year: $class_year\n\n";
$email_body .= "Message:\n";
$email_body .= wordwrap($message, 70) . "\n\n";
$email_body .= "---\n";
$email_body .= "Sent from: alabamafalcons.org contact form\n";
$email_body .= "Submitted: " . date('Y-m-d H:i:s T') . "\n";
$email_body .= "IP Address: " . $_SERVER['REMOTE_ADDR'] . "\n";

// Send email
$mail_sent = false;
$mail = new PHPMailer(true);
try {
    configure_smtp_relay($mail);
    $mail->setFrom(CLUB_FROM_EMAIL, CLUB_NAME);
    $mail->addReplyTo($email);
    $mail->addAddress($to_email);
    $mail->isHTML(false);
    $mail->Subject = $subject;
    $mail->Body    = $email_body;
    $mail_sent = $mail->send();
} catch (PHPMailerException $e) {
    error_log('contact-form: PHPMailer error - ' . $mail->ErrorInfo);
}

if ($mail_sent) {
    echo json_encode([
        'success' => true,
        'message' => 'Thank you! Your message has been sent. We\'ll get back to you within 24-48 hours.'
    ]);
} else {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Sorry, there was an error sending your message. Please email us directly at info@alabamafalcons.org'
    ]);
}
?>
