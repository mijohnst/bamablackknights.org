<?php
/**
 * Daily Cadet Birthday Wishes
 * Emails a personalized happy-birthday note to the cadet (if we have their
 * email) and to their parent(s), for any cadet whose birthday is today.
 * Run via cron every day:
 *   0 8 * * * php /home/alabkmgg/public_html/admin/birthday-wishes.php
 */

// Block direct browser access
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('This script runs via cron only.');
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/mailer.php';

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_EMULATE_PREPARES => true]
    );
} catch (PDOException $e) {
    echo "Database error: " . $e->getMessage() . "\n";
    exit(1);
}

$sent = send_birthday_emails($pdo);
echo date('Y-m-d H:i:s') . " — Birthday wishes: $sent cadet(s) processed.\n";
