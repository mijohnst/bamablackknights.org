<?php
/**
 * Shared spam/abuse guard for public, unauthenticated form endpoints
 * (contact-form.php, membership-handler.php, sendoff-handler.php).
 */

// True if the honeypot field was filled in — real visitors never see or fill it.
// Accepts mixed (not just array) since callers only loosely check the decoded
// JSON body before passing it here — a malformed/non-object body must not crash.
function honeypot_tripped($data, string $field = 'website'): bool {
    if (!is_array($data)) return false;
    return trim((string)($data[$field] ?? '')) !== '';
}

// True if this IP has hit $form_name more than $max times in the last $window_minutes.
// Fails open (returns false = not limited) if the throttle table can't be reached,
// so a DB hiccup never blocks a legitimate submission.
function rate_limited(PDO $pdo, string $form_name, int $max = 5, int $window_minutes = 15): bool {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $ip_hash = hash('sha256', $ip);
    try {
        $pdo->exec("DELETE FROM form_throttle WHERE created_at < NOW() - INTERVAL 1 DAY");
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM form_throttle WHERE form_name = ? AND ip_hash = ? AND created_at > NOW() - INTERVAL ? MINUTE'
        );
        $stmt->execute([$form_name, $ip_hash, $window_minutes]);
        if ((int)$stmt->fetchColumn() >= $max) return true;
        $pdo->prepare('INSERT INTO form_throttle (form_name, ip_hash) VALUES (?, ?)')->execute([$form_name, $ip_hash]);
        return false;
    } catch (PDOException $e) {
        error_log('form-guard: rate_limited check failed — ' . $e->getMessage());
        return false;
    }
}
