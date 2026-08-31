<?php
/**
 * Parent Letters — Save / Delete
 * Every action is scoped to the member_id bound to the verifyToken issued by
 * parent-letters-lookup.php — never trusts a member_id or letter id from the
 * request alone, so one family can't edit or delete another's letter by
 * guessing an id. A successful action refreshes the token's expiry so a
 * longer writing session doesn't time out mid-letter.
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

// Officer-controlled open/close toggle (admin/parent-letters.php), same
// setting parent-letters.html reads via settings-feed.php to show its
// closed notice. Checked here too -- the real enforcement, blocking saves
// even if someone bypasses the page's UI and calls this directly.
// Defaults open if the setting was never touched.
$open_row = get_pdo()->query("SELECT setting_value FROM site_settings WHERE setting_key='parent_letters_open'")->fetch();
$letters_open = $open_row ? (bool)(int)$open_row['setting_value'] : true;
if (!$letters_open) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Letter submissions are currently closed. Thank you to everyone who wrote a letter this cycle!']);
    exit();
}

$input   = file_get_contents('php://input');
$payload = json_decode($input, true);
if (!$payload) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid request data.']);
    exit();
}

$pdo = get_pdo();

if (rate_limited($pdo, 'parent_letters_save', 30, 15)) {
    http_response_code(429);
    echo json_encode(['success' => false, 'error' => 'Too many requests from your network. Please try again shortly.']);
    exit();
}

start_verification_session();
$token    = trim((string)($payload['verifyToken'] ?? ''));
$verified = $_SESSION['letters_verified'][$token] ?? null;

if (!$verified || ($verified['expires'] ?? 0) < time()) {
    echo json_encode(['success' => false, 'error' => 'Your session expired. Please use "Find My Record" again.']);
    exit();
}
$member_id = (int)$verified['member_id'];

$action = $payload['action'] ?? '';

if ($action === 'save') {
    $letter_id = (int)($payload['letterId'] ?? 0);
    $body      = trim((string)($payload['letterBody'] ?? ''));

    if ($body === '') {
        echo json_encode(['success' => false, 'error' => 'The letter is empty — write something before saving.']);
        exit();
    }
    if (mb_strlen($body) > 20000) {
        echo json_encode(['success' => false, 'error' => 'That letter is too long to save.']);
        exit();
    }

    if ($letter_id) {
        // Scoped to member_id — an id belonging to a different family is
        // simply not matched, not an error that leaks whether it exists.
        $upd = $pdo->prepare('UPDATE parent_letters SET letter_body = ? WHERE id = ? AND member_id = ?');
        $upd->execute([$body, $letter_id, $member_id]);
        if ($upd->rowCount() === 0) {
            echo json_encode(['success' => false, 'error' => 'That letter could not be found.']);
            exit();
        }
    } else {
        $ins = $pdo->prepare('INSERT INTO parent_letters (member_id, letter_body) VALUES (?, ?)');
        $ins->execute([$member_id, $body]);
        $letter_id = (int)$pdo->lastInsertId();
    }

} elseif ($action === 'delete') {
    $letter_id = 0; // deleted — nothing for the client to keep editing
    $del = $pdo->prepare('DELETE FROM parent_letters WHERE id = ? AND member_id = ?');
    $del->execute([(int)($payload['letterId'] ?? 0), $member_id]);

} else {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Unknown action.']);
    exit();
}

// Refresh the session window on every successful action.
$_SESSION['letters_verified'][$token]['expires'] = time() + 1800;

$letters_stmt = $pdo->prepare('SELECT id, letter_body, created_at, updated_at FROM parent_letters WHERE member_id = ? ORDER BY updated_at DESC');
$letters_stmt->execute([$member_id]);

echo json_encode([
    'success'  => true,
    'letterId' => $letter_id,
    'letters'  => $letters_stmt->fetchAll(PDO::FETCH_ASSOC),
]);
