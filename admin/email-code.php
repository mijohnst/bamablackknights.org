<?php
/**
 * Emailed one-time verification codes for the public lookup forms.
 * ─────────────────────────────────────────────────────────────────────────────
 * A lookup's identity check (last name, class year, an email on file) uses
 * details that aren't secret, so on its own it proves little. Sending a code
 * to the email the visitor typed proves they actually control that inbox.
 *
 * Each code lives in the verification session under a random challenge id,
 * one per lookup, so two lookups open in different tabs don't clobber each
 * other. A challenge is "bound" to whatever the caller needs once the code
 * checks out (e.g. a member id); a null binding is a decoy for a lookup that
 * matched nothing, which can never verify, so the response looks the same
 * whether or not the record exists.
 *
 * Each caller passes its own $pool name so codes from one form can't be
 * redeemed on another. A binding may instead carry an 'error' message (e.g.
 * "more than one record matched"), which is shown only once the code checks
 * out, so it can't be used to learn who's on file.
 *
 * The lookup endpoints use the request-level helpers at the bottom; the
 * page side is email-code.js.
 * ─────────────────────────────────────────────────────────────────────────────
 */

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/form-guard.php';

// Codes are 3 characters from this alphabet: letters and digits, minus the
// look-alikes 0/O and 1/I/L, so 31^3 ≈ 30,000 possible codes. Matching is
// case-insensitive. Short codes are guessable only because they're short,
// so attempts per code are low and each record can only be sent so many
// codes a day (see email_code_record_request()).
const EMAIL_CODE_ALPHABET     = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
const EMAIL_CODE_LENGTH       = 3;
const EMAIL_CODE_TTL          = 600; // 10 minutes
const EMAIL_CODE_MAX_ATTEMPTS = 3;
const EMAIL_CODE_DAILY_CAP    = 10;  // codes per record (or address) per 24 hours

function email_code_hash(string $challenge_id, string $code): string {
    return hash('sha256', $challenge_id . '|' . $code);
}

function email_code_generate(): string {
    $code = '';
    for ($i = 0; $i < EMAIL_CODE_LENGTH; $i++) {
        $code .= EMAIL_CODE_ALPHABET[random_int(0, strlen(EMAIL_CODE_ALPHABET) - 1)];
    }
    return $code;
}

// Uppercases and drops anything that isn't a letter or digit (spaces, dashes).
function email_code_normalize(string $code): string {
    return preg_replace('/[^A-Z0-9]/', '', strtoupper($code));
}

// Records a code request for $target (a member id or email) and returns
// ['count' => requests in the last 24 hours including this one, 'key' => throttle key],
// or null if the table can't be reached (fail open, like rate_limited()).
// Keyed by recipient rather than IP, so spreading guesses across networks
// doesn't buy more codes — and shared across every form, so neither does
// spreading them across forms. Inserting before counting (rather than
// count-then-insert) means concurrent requests can't all slip under the cap.
function email_code_record_request(PDO $pdo, string $target): ?array {
    $key = hash('sha256', 'email_code|' . strtolower($target));
    try {
        $pdo->prepare("INSERT INTO form_throttle (form_name, ip_hash) VALUES ('email_code', ?)")->execute([$key]);
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM form_throttle WHERE form_name = 'email_code' AND ip_hash = ? AND created_at > NOW() - INTERVAL 1 DAY"
        );
        $stmt->execute([$key]);
        return ['count' => (int)$stmt->fetchColumn(), 'key' => $key];
    } catch (PDOException $e) {
        error_log('email-code: recipient cap check failed — ' . $e->getMessage());
        return null;
    }
}

// Un-counts a request whose code email never went out, so a mail outage
// doesn't use up a family's daily codes.
function email_code_unrecord_request(PDO $pdo, ?array $request): void {
    if (!$request) return;
    try {
        $pdo->prepare("DELETE FROM form_throttle WHERE form_name = 'email_code' AND ip_hash = ? ORDER BY created_at DESC LIMIT 1")
            ->execute([$request['key']]);
    } catch (PDOException $e) {
        error_log('email-code: could not un-count failed send — ' . $e->getMessage());
    }
}

// Creates a challenge and, when $binding is non-null, emails a code to
// $email. Returns the challenge id, or null if the email couldn't be sent.
// A null $binding sends nothing and stores a decoy that never verifies.
// A record over its daily cap gets a decoy too, so the cap isn't visible to
// whoever is requesting — but the first time it's hit, the inbox owner is
// emailed a notice instead of a code, so a real family that's been locked
// out (or targeted) learns why and who to contact.
function email_code_issue(PDO $pdo, string $pool, ?array $binding, string $email, string $purpose): ?string {
    start_verification_session();
    if (!isset($_SESSION['email_codes'][$pool]) || !is_array($_SESSION['email_codes'][$pool])) {
        $_SESSION['email_codes'][$pool] = [];
    }
    // Prune expired challenges so the session doesn't grow unbounded.
    foreach ($_SESSION['email_codes'][$pool] as $id => $c) {
        if (($c['expires'] ?? 0) < time()) unset($_SESSION['email_codes'][$pool][$id]);
    }

    $challenge_id = bin2hex(random_bytes(24));
    $code         = email_code_generate();

    $request = null;
    if ($binding !== null) {
        $target  = isset($binding['member_id']) ? 'member:' . $binding['member_id'] : 'email:' . $email;
        $request = email_code_record_request($pdo, $target);
        if ($request && $request['count'] > EMAIL_CODE_DAILY_CAP) {
            error_log("email-code: daily code cap reached ($pool" . (isset($binding['member_id']) ? ", member {$binding['member_id']}" : '') . ') — no code sent');
            if ($request['count'] === EMAIL_CODE_DAILY_CAP + 1) {
                send_notification($email, 'Verification code limit reached',
                    "We've received more requests for verification codes for your family's record today than we allow, "
                  . "so no more codes will be sent until tomorrow.\n\n"
                  . "If you've been trying to sign in, please try again tomorrow or contact secretary@bamablackknights.org and we'll help. "
                  . "If these requests weren't from you, please let us know at the same address.\n\n"
                  . "West Point Parents Club of Alabama\n"
                  . "bamablackknights.org");
            }
            $binding = null;
        }
    }

    if ($binding !== null) {
        $body = "Your verification code is:\n\n"
              . "    $code\n\n"
              . "(Letters and numbers; capitals don't matter.) Enter it on the bamablackknights.org page where you requested it to $purpose. "
              . "The code expires in 10 minutes.\n\n"
              . "If you didn't request this, you can ignore this email; nothing has been changed. "
              . "Questions? Contact secretary@bamablackknights.org.\n\n"
              . "West Point Parents Club of Alabama\n"
              . "bamablackknights.org";
        if (!send_notification($email, "Your verification code: $code", $body)) {
            email_code_unrecord_request($pdo, $request);
            return null;
        }
    }

    $_SESSION['email_codes'][$pool][$challenge_id] = [
        'hash'     => email_code_hash($challenge_id, $code),
        'binding'  => $binding,
        'attempts' => 0,
        'expires'  => time() + EMAIL_CODE_TTL,
    ];
    return $challenge_id;
}

// Checks $code against the challenge. Returns
//   ['ok' => true,  'binding' => array]  on success (the challenge is consumed),
//   ['ok' => false, 'error'   => string] otherwise.
function email_code_verify(string $pool, string $challenge_id, string $code): array {
    start_verification_session();
    $c = $_SESSION['email_codes'][$pool][$challenge_id] ?? null;
    if ($c === null || ($c['expires'] ?? 0) < time()) {
        unset($_SESSION['email_codes'][$pool][$challenge_id]);
        return ['ok' => false, 'error' => 'That code has expired. Please request a new one.'];
    }

    $code = email_code_normalize($code);
    $_SESSION['email_codes'][$pool][$challenge_id]['attempts'] = ++$c['attempts'];

    if ($c['binding'] !== null && hash_equals($c['hash'], email_code_hash($challenge_id, $code))) {
        unset($_SESSION['email_codes'][$pool][$challenge_id]);
        return ['ok' => true, 'binding' => $c['binding']];
    }

    if ($c['attempts'] >= EMAIL_CODE_MAX_ATTEMPTS) {
        unset($_SESSION['email_codes'][$pool][$challenge_id]);
        return ['ok' => false, 'error' => 'Too many incorrect attempts. Please request a new code.'];
    }
    return ['ok' => false, 'error' => 'That code is incorrect. Please check the email and try again.'];
}

// ── Request-level helpers for the lookup endpoints ────────────────────────
// Each echoes a JSON response and exits on anything but success.

// Step 1: issue a code for $binding (null = no match) and respond with the
// challenge id. Always exits.
function email_code_respond_issued(PDO $pdo, string $pool, ?array $binding, string $email, string $purpose, string $contact): void {
    $challenge_id = email_code_issue($pdo, $pool, $binding, $email, $purpose);
    if ($challenge_id === null) {
        echo json_encode(['success' => false, 'error' => "We couldn't send the verification email right now. Please try again in a few minutes or contact $contact."]);
        exit();
    }
    // codeLength keeps email-code.js in step with EMAIL_CODE_LENGTH.
    echo json_encode(['success' => true, 'challengeId' => $challenge_id, 'codeLength' => EMAIL_CODE_LENGTH]);
    exit();
}

// Step 2: check the posted challengeId + code and return the binding.
function email_code_handle_verify(PDO $pdo, string $pool, array $payload, string $contact): array {
    // Per-challenge attempts are capped in email_code_verify(); this caps
    // guessing across many challenges from one network.
    if (rate_limited($pdo, $pool . '_code', 15, 15)) {
        http_response_code(429);
        echo json_encode(['success' => false, 'error' => "Too many attempts from your network. Please try again later or email $contact."]);
        exit();
    }
    $check = email_code_verify($pool, trim((string)($payload['challengeId'] ?? '')), trim((string)($payload['code'] ?? '')));
    if (!$check['ok']) {
        echo json_encode(['success' => false, 'error' => $check['error']]);
        exit();
    }
    if (isset($check['binding']['error'])) {
        echo json_encode(['success' => false, 'error' => $check['binding']['error']]);
        exit();
    }
    return $check['binding'];
}

// Step 2 for a binding of ['member_id' => …]: returns [member row, binding].
function email_code_verified_member(PDO $pdo, string $pool, array $payload, string $contact): array {
    $binding = email_code_handle_verify($pdo, $pool, $payload, $contact);
    $stmt = $pdo->prepare('SELECT * FROM members WHERE id = ? AND archived = 0');
    $stmt->execute([(int)($binding['member_id'] ?? 0)]);
    $m = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$m) {
        echo json_encode(['success' => false, 'error' => "We couldn't find a matching record. Please contact $contact."]);
        exit();
    }
    return [$m, $binding];
}
