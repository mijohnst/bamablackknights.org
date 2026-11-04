<?php
/**
 * Compose Email queue runner — sends queued recipients within the hourly
 * budget (see admin/email-queue.php). Safe to run as often as you like;
 * it does nothing when the queue is empty or the hour's budget is used.
 *
 * Run via cron every 10 minutes:
 *   *\/10 * * * * /usr/local/bin/php /home/CPANEL_USER/public_html/admin/email-queue-cron.php >/dev/null 2>&1
 * (CPANEL_USER = this site's cPanel username — the home directory shown
 * on the right of the cPanel home page. Drop the backslash in "*\/10";
 * it's only there so this comment doesn't end early.)
 */

// Block direct browser access
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('This script runs via cron only.');
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/email-queue.php';

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_EMULATE_PREPARES => true]
    );
    $sent = email_queue_process($pdo);
    echo date('Y-m-d H:i:s') . " — Email queue: sent $sent\n";
} catch (Throwable $e) {
    error_log('email-queue-cron: ' . $e->getMessage());
    echo "Email queue error: " . $e->getMessage() . "\n";
    exit(1);
}
