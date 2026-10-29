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
 * redeemed on another.
 * ─────────────────────────────────────────────────────────────────────────────
 */

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/mailer.php';

const EMAIL_CODE_TTL          = 600; // 10 minutes
const EMAIL_CODE_MAX_ATTEMPTS = 5;

function email_code_hash(string $challenge_id, string $code): string {
    return hash('sha256', $challenge_id . '|' . $code);
}

// Creates a challenge and, when $binding is non-null, emails a 6-digit code
// to $email. Returns the challenge id, or null if the email couldn't be sent.
// A null $binding sends nothing and stores a decoy that never verifies.
function email_code_issue(string $pool, ?array $binding, string $email, string $purpose): ?string {
    start_verification_session();
    if (!isset($_SESSION['email_codes'][$pool]) || !is_array($_SESSION['email_codes'][$pool])) {
        $_SESSION['email_codes'][$pool] = [];
    }
    // Prune expired challenges so the session doesn't grow unbounded.
    foreach ($_SESSION['email_codes'][$pool] as $id => $c) {
        if (($c['expires'] ?? 0) < time()) unset($_SESSION['email_codes'][$pool][$id]);
    }

    $challenge_id = bin2hex(random_bytes(24));
    $code         = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);

    if ($binding !== null) {
        $body = "Your verification code is:\n\n"
              . "    $code\n\n"
              . "Enter it on the bamablackknights.org page where you requested it to $purpose. "
              . "The code expires in 10 minutes.\n\n"
              . "If you didn't request this, you can ignore this email; nothing has been changed. "
              . "Questions? Contact secretary@bamablackknights.org.\n\n"
              . "West Point Parents Club of Alabama\n"
              . "bamablackknights.org";
        if (!send_notification($email, "Your verification code: $code", $body)) return null;
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

    $code = preg_replace('/\D/', '', $code);
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
