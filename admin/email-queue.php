<?php
/**
 * Compose Email sending queue
 * ─────────────────────────────────────────────────────────────────────────────
 * The host caps outgoing mail at about 200 recipients per hour (every BCC
 * counts), and goes over it silently — mail() still reports success. So a
 * Compose Email send is stored as a job plus one row per recipient, and
 * email_queue_process() sends at most EMAIL_QUEUE_HOURLY recipients in any
 * rolling hour, leaving headroom for automated emails and notifications.
 *
 * The Send button processes the queue once right away (small sends go out
 * immediately); admin/email-queue-cron.php finishes the rest every few
 * minutes. Both paths take a MySQL named lock so they never double-send.
 *
 * Tables: admin/migrate_email_queue.sql (email_queue_jobs,
 * email_queue_recipients). Attachments wait in admin/email-queue-files/
 * (denied by .htaccess) and are deleted when their job finishes.
 */

require_once __DIR__ . '/mailer.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

const EMAIL_QUEUE_HOURLY = 150; // recipients per rolling hour (host limit ~200)
const EMAIL_QUEUE_BATCH  = 90;  // BCC recipients per message

function email_queue_dir(int $job_id): string {
    return __DIR__ . '/email-queue-files/' . $job_id;
}

// Store a send as a queued job. $attachments as returned by
// collect_email_attachments(): [['name'=>, 'mime'=>, 'content'=>], ...].
// Throws PDOException if the queue tables don't exist yet.
function email_queue_create(PDO $pdo, string $from_email, string $subject, string $html,
                            array $recipients, array $attachments, ?int $user_id): int {
    $pdo->beginTransaction();
    try {
        $pdo->prepare('INSERT INTO email_queue_jobs (from_email, subject, body, created_by, status) VALUES (?,?,?,?,\'queued\')')
            ->execute([$from_email, $subject, $html, $user_id]);
        $job_id = (int)$pdo->lastInsertId();
        $ins = $pdo->prepare('INSERT INTO email_queue_recipients (job_id, email) VALUES (?,?)');
        foreach ($recipients as $r) $ins->execute([$job_id, $r]);

        $manifest = [];
        if ($attachments) {
            $dir = email_queue_dir($job_id);
            if (!is_dir($dir) && !mkdir($dir, 0750, true)) throw new RuntimeException('Could not create attachment folder.');
            foreach (array_values($attachments) as $i => $att) {
                if (file_put_contents("$dir/$i", $att['content']) === false) throw new RuntimeException('Could not save attachment.');
                $manifest[] = ['name' => $att['name'], 'mime' => $att['mime'], 'file' => (string)$i];
            }
            $pdo->prepare('UPDATE email_queue_jobs SET attachments_json=? WHERE id=?')
                ->execute([json_encode($manifest), $job_id]);
        }
        $pdo->commit();
        return $job_id;
    } catch (Throwable $e) {
        $pdo->rollBack();
        if (isset($job_id)) email_queue_delete_files($job_id);
        throw $e;
    }
}

function email_queue_delete_files(int $job_id): void {
    $dir = email_queue_dir($job_id);
    if (!is_dir($dir)) return;
    foreach (glob("$dir/*") ?: [] as $f) @unlink($f);
    @rmdir($dir);
}

// Recipients sent (or attempted) in the last hour, counted in DB time.
function email_queue_sent_last_hour(PDO $pdo): int {
    return (int)$pdo->query('SELECT COUNT(*) FROM email_queue_recipients WHERE sent_at >= NOW() - INTERVAL 1 HOUR')->fetchColumn();
}

// Mark a job done once nothing is pending, and clear its attachments.
function email_queue_finish_if_done(PDO $pdo, int $job_id): void {
    $st = $pdo->prepare("SELECT COUNT(*) FROM email_queue_recipients WHERE job_id=? AND status='pending'");
    $st->execute([$job_id]);
    if ((int)$st->fetchColumn() > 0) return;
    $pdo->prepare("UPDATE email_queue_jobs SET status='done', finished_at=NOW() WHERE id=? AND status IN ('queued','sending')")
        ->execute([$job_id]);
    email_queue_delete_files($job_id);
}

// Send as much of the queue as this hour's budget allows, oldest job first.
// Returns the number of recipients sent.
function email_queue_process(PDO $pdo): int {
    if ((int)$pdo->query("SELECT GET_LOCK('wppc_email_queue', 0)")->fetchColumn() !== 1) return 0;
    $sent_total = 0;
    try {
        $budget = EMAIL_QUEUE_HOURLY - email_queue_sent_last_hour($pdo);
        while ($budget > 0) {
            $job = $pdo->query(
                "SELECT j.* FROM email_queue_jobs j
                 WHERE j.status IN ('queued','sending')
                   AND EXISTS (SELECT 1 FROM email_queue_recipients r WHERE r.job_id = j.id AND r.status = 'pending')
                 ORDER BY j.id LIMIT 1"
            )->fetch(PDO::FETCH_ASSOC);
            if (!$job) break;
            $job_id = (int)$job['id'];

            $take = min($budget, EMAIL_QUEUE_BATCH);
            $st = $pdo->prepare("SELECT id, email FROM email_queue_recipients WHERE job_id=? AND status='pending' ORDER BY id LIMIT $take");
            $st->execute([$job_id]);
            $batch = $st->fetchAll(PDO::FETCH_ASSOC);
            if (!$batch) { email_queue_finish_if_done($pdo, $job_id); continue; }

            $pdo->prepare("UPDATE email_queue_jobs SET status='sending' WHERE id=? AND status='queued'")->execute([$job_id]);

            $ok = email_queue_send_batch($job, array_column($batch, 'email'));
            $ids = implode(',', array_map('intval', array_column($batch, 'id')));
            $pdo->exec("UPDATE email_queue_recipients SET status='" . ($ok ? 'sent' : 'failed') . "', sent_at=NOW() WHERE id IN ($ids)");

            $budget -= count($batch);
            if ($ok) $sent_total += count($batch);
            email_queue_finish_if_done($pdo, $job_id);
        }
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('wppc_email_queue')");
    }
    return $sent_total;
}

function email_queue_send_batch(array $job, array $emails): bool {
    $mail = new PHPMailer(true);
    try {
        configure_smtp_relay($mail);
        $mail->setFrom($job['from_email'], CLUB_NAME);
        $mail->addReplyTo($job['from_email'], CLUB_NAME);
        $mail->addAddress(CLUB_FROM_EMAIL);
        foreach ($emails as $bcc) $mail->addBCC($bcc);
        foreach (json_decode($job['attachments_json'] ?? '', true) ?: [] as $att) {
            $content = @file_get_contents(email_queue_dir((int)$job['id']) . '/' . basename($att['file']));
            if ($content === false) throw new PHPMailerException('Attachment missing: ' . $att['name']);
            $mail->addStringAttachment($content, $att['name'], PHPMailer::ENCODING_BASE64, $att['mime']);
        }
        $mail->isHTML(true);
        $mail->Subject = $job['subject'];
        $mail->Body    = $job['body'];
        $mail->send();
        return true;
    } catch (PHPMailerException $e) {
        error_log('Email queue: job ' . $job['id'] . ' batch of ' . count($emails) . ' failed — ' . ($mail->ErrorInfo ?: $e->getMessage()));
        return false;
    }
}

// Stop a job: unsent recipients are marked cancelled and attachments removed.
function email_queue_cancel(PDO $pdo, int $job_id): void {
    $pdo->prepare("UPDATE email_queue_recipients SET status='cancelled' WHERE job_id=? AND status='pending'")->execute([$job_id]);
    $pdo->prepare("UPDATE email_queue_jobs SET status='cancelled', finished_at=NOW() WHERE id=? AND status IN ('queued','sending')")
        ->execute([$job_id]);
    email_queue_delete_files($job_id);
}

// Recent jobs with per-status counts, for the Sending Queue panel.
function email_queue_recent(PDO $pdo, int $days = 14): array {
    return $pdo->query(
        "SELECT j.id, j.from_email, j.subject, j.status, j.created_at, j.finished_at,
                COUNT(r.id) AS total,
                SUM(r.status='sent') AS sent,
                SUM(r.status='failed') AS failed,
                SUM(r.status='pending') AS pending,
                MAX(r.sent_at) AS last_sent
         FROM email_queue_jobs j LEFT JOIN email_queue_recipients r ON r.job_id = j.id
         WHERE j.created_at >= NOW() - INTERVAL " . (int)$days . " DAY OR j.status IN ('queued','sending')
         GROUP BY j.id ORDER BY j.id DESC LIMIT 25"
    )->fetchAll(PDO::FETCH_ASSOC);
}
