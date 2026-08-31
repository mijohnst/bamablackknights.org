<?php
require_once __DIR__ . '/admin/auth.php';
require_once __DIR__ . '/admin/form-guard.php';
require_once __DIR__ . '/admin/mailer.php';

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
    http_response_code(405); echo json_encode(['success' => false, 'error' => 'Method not allowed']); exit();
}

$input = file_get_contents('php://input');
$data  = json_decode($input, true);
if (!$data) { http_response_code(400); echo json_encode(['success' => false, 'error' => 'Invalid input']); exit(); }

// Honeypot — bots fill this hidden field, real visitors never see it.
// Pretend success so bots don't learn to avoid the field.
if (honeypot_tripped($data)) {
    echo json_encode(['success' => true, 'message' => "You're signed up — thank you!"]);
    exit;
}

$pdo = get_pdo();

if (rate_limited($pdo, 'volunteer_claim')) {
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => 'Too many submissions from your network. Please try again later or email us directly at info@alabamafalcons.org.']);
    exit;
}

$opportunity_id = (int)($data['opportunity_id'] ?? 0);
$name  = trim((string)($data['name']  ?? ''));
$email = trim((string)($data['email'] ?? ''));

if (!$opportunity_id || !$name || !$email) {
    http_response_code(400); echo json_encode(['success' => false, 'error' => 'Name and email are required.']); exit();
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400); echo json_encode(['success' => false, 'error' => 'Invalid email address.']); exit();
}

try {
    $pdo->beginTransaction();

    // FOR UPDATE locks the opportunity row for the rest of this
    // transaction, so a second concurrent claim has to wait until this one
    // commits (and sees the up-to-date fill count) rather than both reading
    // "not yet full" at the same time and overbooking past spots_needed.
    $stmt = $pdo->prepare('SELECT id, title, spots_needed, active FROM volunteer_opportunities WHERE id = ? FOR UPDATE');
    $stmt->execute([$opportunity_id]);
    $opp = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$opp || !$opp['active']) {
        $pdo->rollBack();
        http_response_code(410); echo json_encode(['success' => false, 'error' => 'This opportunity is no longer open.']); exit();
    }

    $filled_stmt = $pdo->prepare('SELECT COUNT(*) FROM volunteer_signups WHERE opportunity_id = ?');
    $filled_stmt->execute([$opportunity_id]);
    $filled = (int)$filled_stmt->fetchColumn();

    if ($filled >= (int)$opp['spots_needed']) {
        $pdo->rollBack();
        http_response_code(409); echo json_encode(['success' => false, 'error' => 'That opportunity is already full.']); exit();
    }

    $pdo->prepare('INSERT INTO volunteer_signups (opportunity_id, guest_name, guest_email) VALUES (?, ?, ?)')
        ->execute([$opportunity_id, $name, strtolower($email)]);

    $pdo->commit();
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($e->getCode() === '23000') {
        // Unique key on (opportunity_id, guest_email) — this email already
        // claimed this one. Any other error code is a real failure (lost
        // connection, schema mismatch, etc.) and must not be reported to
        // the visitor as if they'd succeeded.
        echo json_encode(['success' => true, 'message' => "You're already signed up for that one — thank you!"]);
        exit;
    }
    error_log('volunteer-opportunity-claim: signup failed — ' . $e->getMessage());
    http_response_code(500); echo json_encode(['success' => false, 'error' => 'A server error occurred. Please try again or email us directly at info@alabamafalcons.org.']); exit();
}

$title = $opp['title'];

send_notification(
    $email,
    'You\'re Signed Up — ' . $title,
    "Thanks for volunteering with the USAFA Parents Club of Alabama!\n\n"
    . "You're signed up for: $title\n\n"
    . "A club officer may follow up with details beforehand. If your plans change, just reply to this email and let us know.\n\n"
    . "Aim High \xC2\xB7 Fly \xC2\xB7 Fight \xC2\xB7 Win\nUSAFA Parents Club of Alabama\nalabamafalcons.org"
);

foreach (['secretary@alabamafalcons.org', 'president@alabamafalcons.org'] as $notify_to) {
    send_notification(
        $notify_to,
        'New Volunteer Sign-Up: ' . $title,
        "$name <$email> just claimed a spot for \"$title\" from the website (no portal account).\n\n"
        . ADMIN_URL . 'volunteer-opportunities.php'
    );
}

http_response_code(200);
echo json_encode(['success' => true, 'message' => "You're signed up — thank you!"]);
