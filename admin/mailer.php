<?php
/**
 * Centralized email sender
 * ─────────────────────────────────────────────────────────────────────────────
 * Sends via Google Workspace's SMTP relay service (smtp-relay.gmail.com),
 * authenticated by the sending server's IP being allowlisted in the Google
 * Admin console — no password/app-password is stored here. Replaced PHP's
 * bare mail(), which broke once alabamafalcons.org's MX moved to Google:
 * cPanel's mail routing no longer considered itself authoritative for the
 * domain, so local mail() delivery silently failed.
 * ─────────────────────────────────────────────────────────────────────────────
 */

// Same reasoning as auth.php — this file is also the entry point for the
// CLI cron job, which never includes auth.php, so the timezone needs to be
// anchored here too, independently. lib.php is required directly (not via
// auth.php) for the same reason — cadet_full_name() etc. must stay
// available in the standalone-cron path.
date_default_timezone_set('America/Chicago');
require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/lib/PHPMailer/Exception.php';
require_once __DIR__ . '/lib/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/lib/PHPMailer/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

define('CLUB_NAME',       'USAFA Parents Club of Alabama');
define('CLUB_FROM_EMAIL', 'info@alabamafalcons.org');
define('CLUB_FROM',       'USAFA Parents Club of Alabama <info@alabamafalcons.org>');
define('ADMIN_URL',       'https://alabamafalcons.org/admin/');
define('SITE_URL',        'https://alabamafalcons.org/');
define('CLUB_TAX_ID',     '61-1791020');

// Shared SMTP relay setup — used here and by email.php's Compose Email tool.
function configure_smtp_relay(PHPMailer $mail): void {
    $mail->isSMTP();
    $mail->Host       = 'smtp-relay.gmail.com';
    $mail->Port       = 587;
    $mail->SMTPAuth   = false; // authenticated by the server's IP being allowlisted in Google Admin
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->CharSet    = 'UTF-8';
}

function send_notification(string $to, string $subject, string $body): bool {
    // Strip all control characters from subject to prevent header injection
    $clean_subject = preg_replace('/[\x00-\x1F\x7F]/', '', $subject);
    $clean_subject = mb_substr($clean_subject, 0, 200); // cap length

    // Shared across every send_notification() call in this request. Several
    // bulk-notify helpers below (nominations/election-open, poll
    // notifications, meeting/volunteer/birthday reminders) call this once
    // per recipient in a loop — without SMTPKeepAlive, each call was
    // opening and TLS-handshaking a brand-new connection to the relay, so a
    // 100-recipient blast meant 100 serial connections. One shared,
    // kept-alive connection is reused instead; register_shutdown_function
    // closes it once, however the script ends, so no socket is left open.
    static $shared_mail = null;
    if ($shared_mail === null) {
        $shared_mail = new PHPMailer(true);
        configure_smtp_relay($shared_mail);
        $shared_mail->SMTPKeepAlive = true;
        register_shutdown_function(function () use ($shared_mail) {
            try { $shared_mail->smtpClose(); } catch (\Throwable $e) { /* already closed */ }
        });
    }

    $mail = $shared_mail;
    $mail->clearAddresses();
    $mail->clearReplyTos();
    try {
        $mail->setFrom(CLUB_FROM_EMAIL, CLUB_NAME);
        $mail->addReplyTo(CLUB_FROM_EMAIL, CLUB_NAME);
        $mail->addAddress($to);
        $mail->isHTML(false);
        $mail->Subject = $clean_subject;
        $mail->Body    = $body;
        return $mail->send();
    } catch (PHPMailerException $e) {
        error_log("send_notification: PHPMailer error for to='$to' — " . $mail->ErrorInfo);
        return false;
    }
}

// ─────────────────────────────────────────────────────────────────────────
// Automated Emails framework — templates + enable/disable live in the
// automated_emails table, managed from admin/automated-emails.php.
// Idempotency ("don't resend the same occasion") is tracked generically in
// automated_email_log, keyed by (email_key, subject_id, period_key).
// ─────────────────────────────────────────────────────────────────────────

function load_automated_email(PDO $pdo, string $email_key): ?array {
    $stmt = $pdo->prepare('SELECT * FROM automated_emails WHERE email_key = ? LIMIT 1');
    $stmt->execute([$email_key]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

// Returns true (and records the send) only the first time this exact
// (email_key, subject_id, period_key) combination is seen.
function mark_automated_sent(PDO $pdo, string $email_key, int $subject_id, string $period_key): bool {
    $stmt = $pdo->prepare('INSERT IGNORE INTO automated_email_log (email_key, subject_id, period_key) VALUES (?, ?, ?)');
    $stmt->execute([$email_key, $subject_id, $period_key]);
    return $stmt->rowCount() > 0;
}

// ── Send happy-birthday emails to today's cadets + their parents ─────────
// Cadet and parent versions can be enabled/disabled independently.
// Returns the number of cadets processed (not the number of individual emails).
function send_birthday_emails(PDO $pdo): int {
    $cadet_cfg  = load_automated_email($pdo, 'birthday_cadet');
    $parent_cfg = load_automated_email($pdo, 'birthday_parent');
    $cadet_on   = $cadet_cfg  && $cadet_cfg['enabled'];
    $parent_on  = $parent_cfg && $parent_cfg['enabled'];
    if (!$cadet_on && !$parent_on) return 0;

    try {
        // Only currently-enrolled cadets (the 4 active class years + Prep
        // School) — graduates keep their birthday on file but shouldn't
        // keep getting club birthday emails after commissioning.
        $eligible_years = array_merge(current_class_years(), ['Prep School']);
        $year_ph = [];
        $params  = ['month' => (int)date('n'), 'day' => (int)date('j')];
        foreach ($eligible_years as $i => $y) {
            $key = ':cy' . $i;
            $year_ph[]     = $key;
            $params[$key]  = $y;
        }

        // Bind PHP's own month/day rather than trusting MySQL's CURDATE() —
        // the two can disagree if the DB server's timezone isn't the same
        // as the one set above, silently shifting "today" by hours.
        $stmt = $pdo->prepare(
            "SELECT id, cadet_first_name, cadet_middle_name, cadet_last_name, cadet_suffix, nickname, cadet_gender, cadet_email, parent1_email, parent2_email
             FROM members
             WHERE archived = 0 AND cadet_birthday IS NOT NULL
               AND MONTH(cadet_birthday) = :month AND DAY(cadet_birthday) = :day
               AND class_year IN (" . implode(',', $year_ph) . ")"
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('mailer: send_birthday_emails query failed — ' . $e->getMessage());
        return 0;
    }
    if (empty($rows)) return 0;

    $year  = (int)date('Y');
    $count = 0;

    foreach ($rows as $r) {
        if (!mark_automated_sent($pdo, 'birthday', (int)$r['id'], (string)$year)) continue; // already wished this year
        $count++;

        $full_name = cadet_full_name($r);
        $nickname  = trim((string)($r['nickname'] ?? ''));
        $nick_or_first = $nickname !== '' ? $nickname : trim((string)($r['cadet_first_name'] ?? ''));
        if ($nick_or_first === '') $nick_or_first = $full_name ?: 'Cadet';
        [$he_she, $him_her, $his_her] = cadet_pronouns((string)($r['cadet_gender'] ?? ''));
        $replace = [
            '{name}' => $nick_or_first, '{cadet_name}' => $full_name ?: $nick_or_first,
            '{he_she}' => $he_she, '{him_her}' => $him_her, '{his_her}' => $his_her,
        ];

        if ($cadet_on && !empty($r['cadet_email']) && filter_var($r['cadet_email'], FILTER_VALIDATE_EMAIL)) {
            send_notification($r['cadet_email'], strtr($cadet_cfg['subject'], $replace), strtr($cadet_cfg['body'], $replace));
        }
        if ($parent_on) {
            $parent_subject = strtr($parent_cfg['subject'], $replace);
            $parent_body    = strtr($parent_cfg['body'], $replace);
            foreach ([$r['parent1_email'] ?? '', $r['parent2_email'] ?? ''] as $pe) {
                if ($pe !== '' && filter_var($pe, FILTER_VALIDATE_EMAIL)) {
                    send_notification($pe, $parent_subject, $parent_body);
                }
            }
        }
    }
    return $count;
}

// ── Dues renewal reminder — parents, once, N days before the current club
// year ends, unless next year is already on file (e.g. a multi-year
// prepay) ──────────────────────────────────────────────────────────────
// Keyed off membership_paid (is the *current* year in this member's
// recorded dues-years set?) and whether *next* year is also already in
// that set — not off membership_paid_through (the furthest year ever
// paid), which a gap can leave pointing at a misleadingly distant date
// even for someone who needs to renew right now. See admin/lib.php's
// membership dues helpers for the underlying data model.
function send_dues_renewal_reminders(PDO $pdo): int {
    $cfg = load_automated_email($pdo, 'dues_renewal');
    if (!$cfg || !$cfg['enabled']) return 0;

    $cur_year = membership_year();
    $parts = explode('-', $cur_year);
    if (count($parts) !== 2) return 0;
    try {
        $year_end = new DateTimeImmutable($parts[1] . '-06-30');
    } catch (Exception $e) {
        return 0;
    }
    $next_year = ((int)$parts[0] + 1) . '-' . ((int)$parts[1] + 1);

    $today = new DateTimeImmutable('today');
    $days_left = (int)$today->diff($year_end)->format('%r%a');
    if ($days_left < 0 || $days_left > (int)$cfg['days_offset']) return 0;

    try {
        $rows = $pdo->query(
            "SELECT id, cadet_first_name, cadet_middle_name, cadet_last_name, cadet_suffix, parent1_first_name, parent1_email, parent2_email, membership_paid_years
             FROM members
             WHERE archived = 0 AND membership_paid = 1 AND class_year <> 'Graduate'"
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('mailer: send_dues_renewal_reminders query failed — ' . $e->getMessage());
        return 0;
    }

    $count = 0;
    foreach ($rows as $r) {
        if (in_array($next_year, parse_dues_years($r['membership_paid_years']), true)) continue; // already covered ahead
        if (!mark_automated_sent($pdo, 'dues_renewal', (int)$r['id'], $cur_year)) continue;
        $count++;

        $full_name = cadet_full_name($r);
        $replace = [
            '{parent_name}' => $r['parent1_first_name'] ?: 'there',
            '{cadet_name}'  => $full_name ?: 'your cadet',
            '{expire_date}' => $year_end->format('F j, Y'),
            '{dues_amount}' => '$75', // cost of renewing one more year; the 4-year bulk option is offered separately on the site
        ];
        $subject = strtr($cfg['subject'], $replace);
        $body    = strtr($cfg['body'], $replace);
        foreach ([$r['parent1_email'] ?? '', $r['parent2_email'] ?? ''] as $pe) {
            if ($pe !== '' && filter_var($pe, FILTER_VALIDATE_EMAIL)) send_notification($pe, $subject, $body);
        }
    }
    return $count;
}

// ── Lapsed member re-engagement — parents, once, N days after the current
// club year opens, for anyone not currently paid ─────────────────────────
// Keyed directly off membership_paid = 0 — the correct, always-accurate
// signal for "not currently covered" — rather than reasoning back from
// membership_paid_through, which a family that paid this year, skipped
// next, then pre-paid the year after would leave pointing at a future
// date despite having a real gap right now.
function send_lapsed_reengagement(PDO $pdo): int {
    $cfg = load_automated_email($pdo, 'lapsed_reengagement');
    if (!$cfg || !$cfg['enabled']) return 0;

    $cur_year = membership_year();
    $parts = explode('-', $cur_year);
    if (count($parts) !== 2) return 0;
    try {
        $year_start = new DateTimeImmutable($parts[0] . '-07-01');
    } catch (Exception $e) {
        return 0;
    }
    $today = new DateTimeImmutable('today');
    $days_open = (int)$year_start->diff($today)->format('%r%a');
    if ($days_open < (int)$cfg['days_offset']) return 0;

    try {
        $rows = $pdo->query(
            "SELECT id, cadet_first_name, cadet_middle_name, cadet_last_name, cadet_suffix, parent1_first_name, parent1_email, parent2_email, membership_paid_through
             FROM members
             WHERE archived = 0 AND membership_paid = 0 AND class_year <> 'Graduate'"
        )->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('mailer: send_lapsed_reengagement query failed — ' . $e->getMessage());
        return 0;
    }

    $count = 0;
    foreach ($rows as $r) {
        if (!mark_automated_sent($pdo, 'lapsed_reengagement', (int)$r['id'], $cur_year)) continue;
        $count++;

        // Best-effort "you were last covered through..." — falls back to
        // something generic for a family that's simply never paid at all.
        $expire_label = 'recently';
        $pt_parts = explode('-', (string)$r['membership_paid_through']);
        if (count($pt_parts) === 2) {
            try { $expire_label = (new DateTimeImmutable($pt_parts[1] . '-06-30'))->format('F j, Y'); } catch (Exception $e) {}
        }

        $full_name = cadet_full_name($r);
        $replace = [
            '{parent_name}' => $r['parent1_first_name'] ?: 'there',
            '{cadet_name}'  => $full_name ?: 'your cadet',
            '{expire_date}' => $expire_label,
        ];
        $subject = strtr($cfg['subject'], $replace);
        $body    = strtr($cfg['body'], $replace);
        foreach ([$r['parent1_email'] ?? '', $r['parent2_email'] ?? ''] as $pe) {
            if ($pe !== '' && filter_var($pe, FILTER_VALIDATE_EMAIL)) send_notification($pe, $subject, $body);
        }
    }
    return $count;
}

// ── New member welcome follow-up — parents, once, N days after joining ───
function send_new_member_welcome(PDO $pdo): int {
    $cfg = load_automated_email($pdo, 'new_member_welcome');
    if (!$cfg || !$cfg['enabled']) return 0;

    $offset = (int)$cfg['days_offset'];
    try {
        $stmt = $pdo->prepare(
            "SELECT id, cadet_first_name, cadet_middle_name, cadet_last_name, cadet_suffix, parent1_first_name, parent1_email, parent2_email
             FROM members
             WHERE archived = 0 AND created_at IS NOT NULL
               AND DATEDIFF(?, created_at) BETWEEN ? AND ?"
        );
        $stmt->execute([date('Y-m-d'), $offset, $offset + 6]); // small window so a missed cron day doesn't skip anyone
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('mailer: send_new_member_welcome query failed — ' . $e->getMessage());
        return 0;
    }

    $count = 0;
    foreach ($rows as $r) {
        if (!mark_automated_sent($pdo, 'new_member_welcome', (int)$r['id'], 'once')) continue;
        $count++;

        $full_name = cadet_full_name($r);
        $replace = [
            '{parent_name}' => $r['parent1_first_name'] ?: 'there',
            '{cadet_name}'  => $full_name ?: 'your cadet',
        ];
        $subject = strtr($cfg['subject'], $replace);
        $body    = strtr($cfg['body'], $replace);
        foreach ([$r['parent1_email'] ?? '', $r['parent2_email'] ?? ''] as $pe) {
            if ($pe !== '' && filter_var($pe, FILTER_VALIDATE_EMAIL)) send_notification($pe, $subject, $body);
        }
    }
    return $count;
}

// ── Meeting reminder — morning-of. Board meetings → board-flagged parents ──
// ── only. General meetings → all active members. Special/Other → no email ──
// ── at all. ─────────────────────────────────────────────────────────────
function send_meeting_reminders(PDO $pdo): int {
    $cfg = load_automated_email($pdo, 'meeting_reminder');
    if (!$cfg || !$cfg['enabled']) return 0;

    try {
        $stmt = $pdo->prepare("SELECT * FROM club_meetings WHERE meeting_date = ?");
        $stmt->execute([date('Y-m-d')]);
        $meetings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('mailer: send_meeting_reminders query failed — ' . $e->getMessage());
        return 0;
    }
    if (empty($meetings)) return 0;

    $count = 0;
    foreach ($meetings as $meeting) {
        // Special/Other meetings never get a reminder
        if (!in_array($meeting['meeting_type'], ['board', 'general'], true)) continue;

        if (!mark_automated_sent($pdo, 'meeting_reminder', (int)$meeting['id'], 'sent')) continue;

        try {
            if ($meeting['meeting_type'] === 'board') {
                $email_rows = $pdo->query(
                    "SELECT parent1_email AS email FROM members WHERE archived=0 AND parent1_is_board_member=1 AND parent1_email <> ''
                     UNION
                     SELECT parent2_email AS email FROM members WHERE archived=0 AND parent2_is_board_member=1 AND parent2_email <> ''"
                )->fetchAll(PDO::FETCH_ASSOC);
            } else { // general
                $pair_rows = $pdo->query("SELECT parent1_email, parent2_email FROM members WHERE archived=0")->fetchAll(PDO::FETCH_ASSOC);
                $email_rows = [];
                foreach ($pair_rows as $pr) {
                    if (!empty($pr['parent1_email'])) $email_rows[] = ['email' => $pr['parent1_email']];
                    if (!empty($pr['parent2_email'])) $email_rows[] = ['email' => $pr['parent2_email']];
                }
            }
        } catch (PDOException $e) {
            $email_rows = [];
        }

        $seen = []; $emails = [];
        foreach ($email_rows as $er) {
            $email = strtolower(trim($er['email'] ?? ''));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || isset($seen[$email])) continue;
            $seen[$email] = true;
            $emails[] = $email;
        }
        if (empty($emails)) continue;
        $count++;

        $replace = [
            '{meeting_title}'    => $meeting['title'],
            '{meeting_date}'     => date('l, F j, Y', strtotime($meeting['meeting_date'])),
            '{meeting_location}' => $meeting['location'] ?: 'No location listed',
            '{meeting_link}'     => $meeting['meeting_link'] ?: 'No virtual link provided',
        ];
        $subject = strtr($cfg['subject'], $replace);
        $body    = strtr($cfg['body'], $replace);
        foreach ($emails as $email) send_notification($email, $subject, $body);
    }
    return $count;
}

// ── Volunteer opportunity reminder — day before the event, to everyone
// signed up (member or guest) plus whoever created the opportunity ────────
// event_date has no time-of-day, and this runs once daily via cron, so
// "24 hours before" in practice means "the morning before the day it's on."
function send_volunteer_opportunity_reminders(PDO $pdo): int {
    $cfg = load_automated_email($pdo, 'volunteer_opportunity_reminder');
    if (!$cfg || !$cfg['enabled']) return 0;

    try {
        // Bind PHP's own "tomorrow" rather than trusting MySQL's CURDATE()+1
        // — same reasoning as send_birthday_emails().
        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        $stmt = $pdo->prepare(
            "SELECT o.id, o.title, o.description, o.event_date, o.location,
                    u.name AS creator_name, u.email AS creator_email
             FROM volunteer_opportunities o
             LEFT JOIN users u ON u.id = o.created_by
             WHERE o.active = 1 AND o.event_date = ?"
        );
        $stmt->execute([$tomorrow]);
        $opportunities = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('mailer: send_volunteer_opportunity_reminders query failed — ' . $e->getMessage());
        return 0;
    }
    if (empty($opportunities)) return 0;

    $count = 0;
    foreach ($opportunities as $opp) {
        // Guard is per-opportunity, not per-recipient — matches how the
        // return count below represents opportunities processed, same as
        // send_birthday_emails() counts cadets, not individual emails.
        if (!mark_automated_sent($pdo, 'volunteer_opportunity_reminder', (int)$opp['id'], (string)$opp['event_date'])) continue;
        $count++;

        $replace = [
            '{opportunity_title}'       => $opp['title'],
            '{event_date}'              => date('l, F j, Y', strtotime($opp['event_date'])),
            '{event_location}'          => $opp['location'] ?: 'No location listed',
            '{opportunity_description}' => $opp['description'] ?: '',
        ];

        // Recipients: everyone signed up (member or guest), plus the
        // creator — deduped by email so the creator doesn't get it twice
        // if they also signed themselves up.
        $recipients = [];
        try {
            $sign = $pdo->prepare(
                "SELECT COALESCE(u.name, s.guest_name) AS name, COALESCE(u.email, s.guest_email) AS email
                 FROM volunteer_signups s LEFT JOIN users u ON u.id = s.user_id
                 WHERE s.opportunity_id = ?"
            );
            $sign->execute([$opp['id']]);
            foreach ($sign->fetchAll(PDO::FETCH_ASSOC) as $r) {
                if (!empty($r['email'])) $recipients[strtolower($r['email'])] = $r['name'] ?: 'there';
            }
        } catch (PDOException $e) {}

        if (!empty($opp['creator_email'])) {
            $recipients[strtolower($opp['creator_email'])] = $opp['creator_name'] ?: 'there';
        }

        foreach ($recipients as $email => $name) {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
            $r = $replace;
            $r['{name}'] = $name;
            send_notification($email, strtr($cfg['subject'], $r), strtr($cfg['body'], $r));
        }
    }
    return $count;
}

// ── Send a preview of any automated email to a test address ──────────────
// Uses sample placeholder data — does not touch automated_email_log or query members.
// $gender ('', 'Male', 'Female') only matters for the two birthday templates,
// letting the admin UI preview how {he_she}/{him_her}/{his_her} render for a
// real cadet without needing to wait for an actual birthday.
function send_automated_test_email(PDO $pdo, string $email_key, string $to, string $gender = ''): bool {
    [$he_she, $him_her, $his_her] = cadet_pronouns($gender);
    $birthday_sample = ['{name}' => 'Jamie', '{cadet_name}' => 'Jamie Example', '{he_she}' => $he_she, '{him_her}' => $him_her, '{his_her}' => $his_her];
    $samples = [
        'birthday_cadet'      => $birthday_sample,
        'birthday_parent'     => $birthday_sample,
        'dues_renewal'        => ['{parent_name}' => 'Alex', '{cadet_name}' => 'Jamie Example', '{expire_date}' => date('F j, Y', strtotime('+30 days')), '{dues_amount}' => '$75'],
        'meeting_reminder'    => ['{meeting_title}' => 'Monthly General Meeting', '{meeting_date}' => date('l, F j, Y'), '{meeting_location}' => 'Zoom', '{meeting_link}' => 'https://zoom.us/j/example'],
        'new_member_welcome'  => ['{parent_name}' => 'Alex', '{cadet_name}' => 'Jamie Example'],
        'lapsed_reengagement' => ['{parent_name}' => 'Alex', '{cadet_name}' => 'Jamie Example', '{expire_date}' => date('F j, Y', strtotime('-60 days'))],
        'volunteer_opportunity_reminder' => ['{name}' => 'Alex', '{opportunity_title}' => 'Cadet Care Package Assembly Night', '{event_date}' => date('l, F j, Y', strtotime('+1 day')), '{event_location}' => 'Brick & Tin, Huntsville', '{opportunity_description}' => 'Join fellow club members as we come together to assemble care packages for our Alabama cadets.'],
    ];
    $cfg = load_automated_email($pdo, $email_key);
    if (!$cfg) return false;
    $replace = $samples[$email_key] ?? [];
    return send_notification($to, '[TEST] ' . strtr($cfg['subject'], $replace), strtr($cfg['body'], $replace));
}

// ── Notify board-flagged parents that meeting minutes have been posted ───
// Returns the number of emails successfully sent.
function notify_board_minutes_posted(PDO $pdo, array $meeting, string $posted_by_name): int {
    try {
        $rows = $pdo->query(
            "SELECT parent1_email AS email FROM members WHERE archived=0 AND parent1_is_board_member=1 AND parent1_email <> ''
             UNION
             SELECT parent2_email AS email FROM members WHERE archived=0 AND parent2_is_board_member=1 AND parent2_email <> ''"
        )->fetchAll();
    } catch (PDOException $e) {
        error_log('mailer: notify_board_minutes_posted query failed — ' . $e->getMessage());
        return 0;
    }
    if (empty($rows)) return 0;

    $seen = [];
    $emails = [];
    foreach ($rows as $r) {
        $email = strtolower(trim($r['email']));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || isset($seen[$email])) continue;
        $seen[$email] = true;
        $emails[] = $email;
    }
    if (empty($emails)) return 0;

    $date = date('F j, Y', strtotime($meeting['meeting_date']));
    $url  = SITE_URL . 'minutes-public.php?id=' . (int)$meeting['id'] . '&token=' . $meeting['minutes_token'];

    $subject = "Meeting Minutes Posted — {$meeting['title']} ($date)";
    $body    = CLUB_NAME . "\n"
             . "Meeting Minutes Posted\n"
             . str_repeat('─', 48) . "\n\n"
             . "Minutes have been posted for the following meeting:\n\n"
             . "  Meeting:  {$meeting['title']}\n"
             . "  Date:     $date\n";
    if (!empty($meeting['location']))     $body .= "  Location: {$meeting['location']}\n";
    if (!empty($meeting['meeting_link'])) $body .= "  Link:     {$meeting['meeting_link']}\n";
    $body .= "\nPosted by: $posted_by_name\n\n"
           . "View / download the minutes:\n$url\n\n"
           . str_repeat('─', 48) . "\n" . CLUB_NAME . "\n" . SITE_URL;

    $sent = 0;
    foreach ($emails as $email) {
        if (send_notification($email, $subject, $body)) $sent++;
    }
    return $sent;
}

// ── Notify all treasurers + admins of a new purchase ─────────────────────
function notify_new_purchase(PDO $pdo, array $purchase, string $submitter_name): void {
    try {
        $recipients = $pdo->query(
            "SELECT name, email FROM users WHERE role IN ('treasurer','admin') AND active = 1"
        )->fetchAll();
    } catch (PDOException $e) {
        error_log('mailer: failed to fetch recipients — ' . $e->getMessage());
        return;
    }
    if (empty($recipients)) return;

    $url  = ADMIN_URL . 'purchase-form.php?id=' . (int)$purchase['id'];
    $amt  = '$' . number_format($purchase['amount_total'], 2);
    $date = date('F j, Y', strtotime($purchase['purchase_date']));

    $subject = 'New Purchase Submitted: ' . $purchase['vendor'] . ' — ' . $amt;
    $body    = CLUB_NAME . "\n"
             . "New Purchase Submitted\n"
             . str_repeat('─', 48) . "\n\n"
             . "Submitted by: $submitter_name\n"
             . "Date:         $date\n"
             . "Vendor:       {$purchase['vendor']}\n";
    if (!empty($purchase['order_number']))
        $body .= "Order #:      {$purchase['order_number']}\n";
    $body   .= "Description:  {$purchase['description']}\n";
    if (!empty($purchase['event']))
        $body .= "Event:        {$purchase['event']}\n";
    if (!empty($purchase['category']))
        $body .= "Category:     {$purchase['category']}\n";
    $body   .= "\nAmounts:\n"
             . "  Pre-Tax:  \${$purchase['amount_pretax']}\n"
             . "  Tax:      \${$purchase['amount_tax']}\n";
    if (!empty($purchase['amount_shipping']) && $purchase['amount_shipping'] > 0)
        $body .= "  Shipping: \${$purchase['amount_shipping']}\n";
    $body   .= "  Total:    $amt\n\n"
             . "View / Approve:  $url\n\n"
             . str_repeat('─', 48) . "\n"
             . CLUB_NAME . "\n" . ADMIN_URL;

    foreach ($recipients as $r) {
        send_notification($r['email'], $subject, $body);
    }
}

// ── Notify all treasurers that a purchase is approved & needs payment submitted ──
function notify_approved(PDO $pdo, array $purchase, string $approved_by): void {
    try {
        $recipients = $pdo->query(
            "SELECT name, email FROM users WHERE role IN ('treasurer','admin') AND active = 1"
        )->fetchAll();
    } catch (PDOException $e) {
        error_log('mailer: failed to fetch treasurer recipients — ' . $e->getMessage());
        return;
    }
    if (empty($recipients)) return;

    $amt  = '$' . number_format($purchase['amount_total'], 2);
    $date = date('F j, Y', strtotime($purchase['purchase_date']));
    $url  = ADMIN_URL . 'purchase-form.php?id=' . (int)$purchase['id'];

    $subject = "Action Required — Submit Payment for Approved Purchase: {$purchase['vendor']} $amt";
    $body    = CLUB_NAME . "\n"
             . "Purchase Approved — Payment Needed\n"
             . str_repeat('─', 48) . "\n\n"
             . "A purchase has been approved and is ready for payment to be submitted.\n\n"
             . "Approved by:  $approved_by\n"
             . "Submitted by: " . ($purchase['submitted_by_name'] ?? 'Unknown') . "\n"
             . "Date:         $date\n"
             . "Vendor:       {$purchase['vendor']}\n";
    if (!empty($purchase['order_number']))
        $body .= "Order #:      {$purchase['order_number']}\n";
    $body   .= "Description:  {$purchase['description']}\n";
    if (!empty($purchase['event']))    $body .= "Event:        {$purchase['event']}\n";
    if (!empty($purchase['category'])) $body .= "Category:     {$purchase['category']}\n";
    $body   .= "\nAmounts:\n"
             . "  Pre-Tax:  \${$purchase['amount_pretax']}\n"
             . "  Tax:      \${$purchase['amount_tax']}\n";
    if (!empty($purchase['amount_shipping']) && $purchase['amount_shipping'] > 0)
        $body .= "  Shipping: \${$purchase['amount_shipping']}\n";
    $body   .= "  Total:    $amt\n\n"
             . "Please submit payment and mark as Submitted:\n$url\n\n"
             . str_repeat('─', 48) . "\n" . CLUB_NAME . "\n" . ADMIN_URL;

    foreach ($recipients as $r) {
        send_notification($r['email'], $subject, $body);
    }
}

// ── Notify submitter their payment has been sent (Submitted step) ────────
function notify_submitted(PDO $pdo, array $purchase, string $processed_by): void {
    // Use email already fetched via JOIN in purchase-action.php if available
    $email = $purchase['submitted_by_email'] ?? '';
    $name  = $purchase['submitted_by_name']  ?? '';

    // Fallback: query users table directly
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        if (empty($purchase['submitted_by'])) {
            error_log('mailer: notify_submitted — no submitted_by on purchase ' . ($purchase['id'] ?? '?'));
            return;
        }
        $submitter = $pdo->prepare('SELECT name, email FROM users WHERE id = ?');
        $submitter->execute([$purchase['submitted_by']]);
        $user = $submitter->fetch();
        if (!$user || !$user['email']) {
            error_log('mailer: notify_submitted — could not find user ' . $purchase['submitted_by']);
            return;
        }
        $email = $user['email'];
        $name  = $user['name'];
    }

    $amt  = '$' . number_format($purchase['amount_total'], 2);
    $date = date('F j, Y', strtotime($purchase['purchase_date']));
    $url  = ADMIN_URL . 'purchase-form.php?id=' . (int)$purchase['id'];
    $method = trim((string)($purchase['payment_method'] ?? ''));

    $subject = "Payment Submitted: {$purchase['vendor']} $amt";
    $body    = CLUB_NAME . "\n"
             . "Your Payment Has Been Submitted\n"
             . str_repeat('─', 48) . "\n\n"
             . "Hi $name,\n\n"
             . "Your payment has been submitted by $processed_by"
             . ($method ? " via $method" : '') . ".\n\n"
             . "Purchase Details:\n"
             . "  Date:        $date\n"
             . "  Vendor:      {$purchase['vendor']}\n"
             . "  Description: {$purchase['description']}\n"
             . "  Amount:      $amt\n\n"
             . "You'll receive a final confirmation once the payment is received.\n"
             . "Please allow time for delivery. If you have questions,\n"
             . "contact your club treasurer.\n\n"
             . "View record:  $url\n\n"
             . str_repeat('─', 48) . "\n" . CLUB_NAME . "\n" . ADMIN_URL;

    $sent = send_notification($email, $subject, $body);
    if (!$sent) {
        error_log("mailer: notify_submitted — mail() returned false for email='$email' purchase_id=" . ($purchase['id'] ?? '?'));
    }
}

// ── Notify submitter their payment is confirmed received (Paid step) ─────
function notify_paid(PDO $pdo, array $purchase, string $confirmed_by): void {
    $email = $purchase['submitted_by_email'] ?? '';
    $name  = $purchase['submitted_by_name']  ?? '';

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        if (empty($purchase['submitted_by'])) {
            error_log('mailer: notify_paid — no submitted_by on purchase ' . ($purchase['id'] ?? '?'));
            return;
        }
        $submitter = $pdo->prepare('SELECT name, email FROM users WHERE id = ?');
        $submitter->execute([$purchase['submitted_by']]);
        $user = $submitter->fetch();
        if (!$user || !$user['email']) {
            error_log('mailer: notify_paid — could not find user ' . $purchase['submitted_by']);
            return;
        }
        $email = $user['email'];
        $name  = $user['name'];
    }

    $amt  = '$' . number_format($purchase['amount_total'], 2);
    $date = date('F j, Y', strtotime($purchase['purchase_date']));
    $url  = ADMIN_URL . 'purchase-form.php?id=' . (int)$purchase['id'];

    $subject = "Payment Confirmed: {$purchase['vendor']} $amt";
    $body    = CLUB_NAME . "\n"
             . "Your Payment Is Confirmed\n"
             . str_repeat('─', 48) . "\n\n"
             . "Hi $name,\n\n"
             . "This confirms your reimbursement has been received, confirmed by $confirmed_by.\n\n"
             . "Purchase Details:\n"
             . "  Date:        $date\n"
             . "  Vendor:      {$purchase['vendor']}\n"
             . "  Description: {$purchase['description']}\n"
             . "  Amount:      $amt\n\n"
             . "Thank you for supporting the club! If you have questions,\n"
             . "contact your club treasurer.\n\n"
             . "View record:  $url\n\n"
             . str_repeat('─', 48) . "\n" . CLUB_NAME . "\n" . ADMIN_URL;

    $sent = send_notification($email, $subject, $body);
    if (!$sent) {
        error_log("mailer: notify_paid — mail() returned false for email='$email' purchase_id=" . ($purchase['id'] ?? '?'));
    }
}

// ── Check event budget thresholds after a purchase is saved ─────────────
function check_budget_thresholds(PDO $pdo, string $event): void {
    if (!$event) return;
    try {
        $b_stmt = $pdo->prepare('SELECT * FROM event_budgets WHERE event = ? LIMIT 1');
        $b_stmt->execute([$event]);
        $budget = $b_stmt->fetch();
        if (!$budget || $budget['budget'] <= 0) return;

        // Count all purchases (all statuses) towards budget
        $s_stmt = $pdo->prepare('SELECT COALESCE(SUM(amount_total),0) FROM purchases WHERE event = ?');
        $s_stmt->execute([$event]);
        $spent = (float)$s_stmt->fetchColumn();

        $pct  = (int)round($spent / $budget['budget'] * 100);
        $last = (int)$budget['last_notified_pct'];

        // Determine if a new threshold has been crossed: 75, 90, 100+
        $crossed = null;
        foreach ([75, 90] as $t) {
            if ($pct >= $t && $last < $t) $crossed = $t;
        }
        if ($pct >= 100 && $last < 100) $crossed = $pct; // show exact % when over

        if ($crossed !== null) {
            notify_budget_alert($pdo, $budget, $spent, $pct);
            $pdo->prepare('UPDATE event_budgets SET last_notified_pct = ? WHERE id = ?')
                ->execute([$pct, $budget['id']]);
        }
    } catch (PDOException $e) {
        error_log('mailer: check_budget_thresholds failed — ' . $e->getMessage());
    }
}

// ── Send budget threshold alert to all admins and treasurers ─────────────
function notify_budget_alert(PDO $pdo, array $budget, float $spent, int $pct): void {
    try {
        $recipients = $pdo->query(
            "SELECT name, email FROM users WHERE role IN ('admin','treasurer') AND active = 1"
        )->fetchAll();
    } catch (PDOException $e) {
        error_log('mailer: notify_budget_alert query failed — ' . $e->getMessage());
        return;
    }
    if (empty($recipients)) return;

    $budget_amt = '$' . number_format($budget['budget'], 2);
    $spent_amt  = '$' . number_format($spent, 2);
    $remaining  = $budget['budget'] - $spent;

    if ($pct >= 100) {
        $over    = '$' . number_format(abs($remaining), 2);
        $subject = "⚠️ Budget Exceeded: {$budget['event']} at {$pct}% — $over over budget";
        $level   = "OVER BUDGET ({$pct}%)";
    } elseif ($pct >= 90) {
        $subject = "Budget Alert — 90%: {$budget['event']} ($spent_amt of $budget_amt)";
        $level   = "90% THRESHOLD REACHED";
    } else {
        $subject = "Budget Alert — 75%: {$budget['event']} ($spent_amt of $budget_amt)";
        $level   = "75% THRESHOLD REACHED";
    }

    $body  = CLUB_NAME . "\n"
           . "Event Budget Alert — $level\n"
           . str_repeat('─', 48) . "\n\n"
           . "Event:     {$budget['event']}\n"
           . "Budget:    $budget_amt\n"
           . "Spent:     $spent_amt ($pct%)\n"
           . "Remaining: " . ($remaining >= 0 ? '$' . number_format($remaining,2) : '⚠️ -$' . number_format(abs($remaining),2)) . "\n\n";
    if ($pct >= 100)
        $body .= "This event has exceeded its budget by " . '$' . number_format(abs($remaining),2) . ".\n\n";
    $body .= "Review purchases:  " . ADMIN_URL . "purchases.php?event=" . urlencode($budget['event']) . "\n"
           . "Manage budgets:    " . ADMIN_URL . "budgets.php\n\n"
           . str_repeat('─', 48) . "\n" . CLUB_NAME . "\n" . ADMIN_URL;

    foreach ($recipients as $r) {
        send_notification($r['email'], $subject, $body);
    }
}

// ── Notify tech/admin when a submitter adds details to their ticket ──────
function notify_tech_of_comment(PDO $pdo, array $ticket, string $comment, string $from_name): void {
    try {
        $recipients = $pdo->query(
            "SELECT name, email FROM users WHERE role IN ('admin','tech') AND active = 1"
        )->fetchAll();
    } catch (PDOException $e) {
        error_log('mailer: notify_tech_of_comment failed — ' . $e->getMessage());
        return;
    }
    if (empty($recipients)) return;

    $url     = ADMIN_URL . 'ticket-view.php?id=' . (int)$ticket['id'];
    $subject = preg_replace('/[\x00-\x1F\x7F]/', '',
               "Ticket Update — {$ticket['ticket_number']}: {$ticket['subject']}");
    $body    = CLUB_NAME . "\n"
             . "Ticket Update from Submitter\n"
             . str_repeat('─', 48) . "\n\n"
             . "Ticket:   {$ticket['ticket_number']}\n"
             . "Subject:  {$ticket['subject']}\n"
             . "Status:   " . (TICKET_STATUSES[$ticket['status']] ?? $ticket['status']) . "\n"
             . "From:     $from_name\n\n"
             . "Added details:\n$comment\n\n"
             . "Respond: $url\n\n"
             . str_repeat('─', 48) . "\n" . CLUB_NAME . "\n" . ADMIN_URL;

    foreach ($recipients as $r) {
        send_notification($r['email'], $subject, $body);
    }
}

// ── Notify submitter of a generic status change ───────────────────────────
function notify_status_change(PDO $pdo, array $purchase, string $old_status, string $new_status, string $changed_by_name): void {
    if (!$purchase['submitted_by']) return;

    $submitter = $pdo->prepare('SELECT name, email FROM users WHERE id = ?');
    $submitter->execute([$purchase['submitted_by']]);
    $user = $submitter->fetch();
    if (!$user || !$user['email']) return;

    $status_labels = ['pending'=>'Pending','approved'=>'Approved','submitted'=>'Submitted','paid'=>'Paid'];
    $old_label = $status_labels[$old_status] ?? $old_status;
    $new_label = $status_labels[$new_status] ?? $new_status;
    $amt  = '$' . number_format($purchase['amount_total'], 2);
    $url  = ADMIN_URL . 'purchase-form.php?id=' . (int)$purchase['id'];
    $date = date('F j, Y', strtotime($purchase['purchase_date']));

    $subject = "Purchase {$new_label}: {$purchase['vendor']} — $amt";
    $body    = CLUB_NAME . "\n"
             . "Purchase Status Updated\n"
             . str_repeat('─', 48) . "\n\n"
             . "Hi {$user['name']},\n\n"
             . "Your purchase submission has been updated:\n\n"
             . "  Status:      $old_label  →  $new_label\n"
             . "  Updated by:  $changed_by_name\n\n"
             . "Purchase Details:\n"
             . "  Date:        $date\n"
             . "  Vendor:      {$purchase['vendor']}\n"
             . "  Description: {$purchase['description']}\n"
             . "  Total:       $amt\n\n";

    if ($new_status === 'approved')
        $body .= "Your purchase has been approved. Please retain your receipt for records.\n\n";
    elseif ($new_status === 'submitted')
        $body .= "Your payment has been submitted. Please allow time for delivery.\n\n";
    elseif ($new_status === 'paid')
        $body .= "Your payment is confirmed received. Thank you!\n\n";

    $body .= "View purchase:  $url\n\n"
           . str_repeat('─', 48) . "\n"
           . CLUB_NAME . "\n" . ADMIN_URL;

    send_notification($user['email'], $subject, $body);
}

// Deduped, validated email addresses for every active, dues-paid member —
// pulled straight from members.parent1_email/parent2_email rather than the
// users/portal-accounts table, so it reaches every paid family including
// ones who haven't set up a portal login yet. Shared by every election email
// (nominations-open and voting-open alike) so a paid member without a portal
// account gets invited to nominate AND reminded to vote, not just one or the
// other — both previously used different, inconsistent recipient pools.
function paid_member_emails(PDO $pdo): array {
    try {
        $rows = $pdo->query(
            "SELECT parent1_email AS email FROM members WHERE archived=0 AND membership_paid=1 AND parent1_email <> ''
             UNION
             SELECT parent2_email AS email FROM members WHERE archived=0 AND membership_paid=1 AND parent2_email <> ''"
        )->fetchAll();
    } catch (PDOException $e) {
        error_log('mailer: paid_member_emails query failed — ' . $e->getMessage());
        return [];
    }

    $seen = []; $emails = [];
    foreach ($rows as $r) {
        $addr = strtolower(trim($r['email'] ?? ''));
        if ($addr === '' || !filter_var($addr, FILTER_VALIDATE_EMAIL) || isset($seen[$addr])) continue;
        $seen[$addr] = true;
        $emails[] = $addr;
    }
    return $emails;
}

// Builds the subject/body for the nominations-open email — shared by the
// real send (notify_nominations_open) and the Secretary's test-send
// (send_nominations_open_test) so a preview always matches what members
// would actually receive. Returns null if every position already has an
// approved candidate (nothing to announce).
function build_nominations_open_email(PDO $pdo, array $election): ?array {
    $open_positions = ELECTION_POSITIONS;
    try {
        $filled = $pdo->prepare("SELECT DISTINCT position FROM election_candidates WHERE election_id=? AND status='approved'");
        $filled->execute([$election['id']]);
        $open_positions = array_values(array_diff(ELECTION_POSITIONS, $filled->fetchAll(PDO::FETCH_COLUMN)));
    } catch (PDOException $e) {
        error_log('mailer: build_nominations_open_email position query failed — ' . $e->getMessage());
    }
    if (empty($open_positions)) return null;

    $url     = ADMIN_URL . 'vote.php';
    $subject = 'Board Elections — Nominations Now Open';
    $body    = CLUB_NAME . "\n"
             . "Officer Election — Nominations Open\n"
             . str_repeat('─', 48) . "\n\n"
             . "{$election['title']} is coming up, and nominations are now open. The following "
             . "board position" . (count($open_positions) === 1 ? ' is' : 's are') . " still open:\n\n";
    foreach ($open_positions as $p) $body .= "  • $p\n";
    $body .= "\nAs a paid member, you're eligible to nominate yourself for any open position. "
           . "The Secretary reviews and approves nominations before voting opens.\n\n"
           . "Nominate yourself:  $url\n\n"
           . "Don't have a portal login yet? Email info@alabamafalcons.org and we'll get you set up.\n\n"
           . str_repeat('─', 48) . "\n" . CLUB_NAME . "\n" . ADMIN_URL;

    return ['subject' => $subject, 'body' => $body];
}

// ── Notify all paid members that nominations are open for an election ────
function notify_nominations_open(PDO $pdo, array $election): int {
    $email = build_nominations_open_email($pdo, $election);
    if (!$email) return 0; // every seat already has an approved candidate

    $emails = paid_member_emails($pdo);
    $sent = 0;
    foreach ($emails as $addr) {
        if (send_notification($addr, $email['subject'], $email['body'])) $sent++;
    }
    return $sent;
}

// ── Send the Secretary a preview of the nominations-open email ───────────
// Same content real recipients would get, minus the recipient list — sent
// only to the given test address, with the subject flagged [TEST].
function send_nominations_open_test(PDO $pdo, array $election, string $to): bool {
    $email = build_nominations_open_email($pdo, $election);
    if (!$email) return false; // nothing to preview — every seat already filled
    return send_notification($to, '[TEST] ' . $email['subject'], $email['body']);
}

// ── Notify all paid members that voting has opened ────────────────────────
// Uses the same paid_member_emails() pool as notify_nominations_open() —
// previously this queried the users/portal-accounts table instead, so a
// paid member without a portal login would get invited to nominate
// themselves but never hear that voting had opened (and had no way to vote
// regardless, since that also requires a login).
function notify_election_open(PDO $pdo, array $election): int {
    $emails = paid_member_emails($pdo);
    if (empty($emails)) return 0;

    $closes  = date('F j, Y \a\t g:ia', strtotime($election['voting_closes_at']));
    $url     = ADMIN_URL . 'vote.php';
    $subject = 'Voting Is Open: ' . $election['title'];
    $body    = CLUB_NAME . "\n"
             . "Officer Election — Voting Is Open\n"
             . str_repeat('─', 48) . "\n\n"
             . "{$election['title']} is now open for voting. Cast your ballot for "
             . "President, Vice President, Secretary, and Treasurer.\n\n"
             . "Voting closes: $closes\n\n"
             . "Vote now:  $url\n\n"
             . "Don't have a portal login yet? Email info@alabamafalcons.org and we'll get you set up.\n\n"
             . str_repeat('─', 48) . "\n" . CLUB_NAME . "\n" . ADMIN_URL;

    $sent = 0;
    foreach ($emails as $addr) {
        if (send_notification($addr, $subject, $body)) $sent++;
    }
    return $sent;
}

// ── Notify portal users that a new poll is open for voting ───────────────
// Sent once, when a board member opens a poll (see admin/polls-manage.php).
// Links to the login page rather than the poll directly, matching how the
// portal already works — no per-poll magic link, just a reminder. A
// 'board' audience only emails the 4 board roles, since everyone else
// can't vote on it anyway.
function send_poll_notifications(PDO $pdo, array $poll, string $audience = 'all_paid'): int {
    $url     = SITE_URL . 'admin/login.php';
    $subject = 'New Vote Open: ' . $poll['title'];
    $expires = date('F j, Y g:ia', strtotime($poll['expires_at']));
    $body    = CLUB_NAME . "\n"
             . "A New Vote Is Open\n"
             . str_repeat('─', 48) . "\n\n"
             . '"' . $poll['title'] . "\"\n\n"
             . ($poll['description'] !== '' ? $poll['description'] . "\n\n" : '')
             . "Log in to the member portal to cast your vote:\n$url\n\n"
             . "Voting closes: $expires\n\n"
             . str_repeat('─', 48) . "\n" . CLUB_NAME . "\n" . SITE_URL;

    if ($audience === 'board') {
        $emails = $pdo->query("SELECT email FROM users WHERE active = 1 AND email <> '' AND role IN ('officer','secretary','treasurer')")->fetchAll(PDO::FETCH_COLUMN);
    } else {
        $emails = $pdo->query("SELECT email FROM users WHERE active = 1 AND email <> ''")->fetchAll(PDO::FETCH_COLUMN);
    }
    $sent = 0;
    foreach ($emails as $email) {
        if (send_notification($email, $subject, $body)) $sent++;
    }
    return $sent;
}

// ── Invite a paid member to set up their own portal login ────────────────
function send_portal_invite(string $to, string $name, string $token): bool {
    $url     = SITE_URL . 'portal-signup.php?token=' . $token;
    $subject = 'Set Up Your ' . CLUB_NAME . ' Portal Account';
    $body    = CLUB_NAME . "\n"
             . "You're Invited to the Member Portal\n"
             . str_repeat('─', 48) . "\n\n"
             . "Hi $name,\n\n"
             . "As a paid member, you now have access to the Parents Club portal — "
             . "sign up for volunteer opportunities, RSVP to events, share event photos, "
             . "and flag which committees you'd like to help with.\n\n"
             . "Set up your account (link expires in 14 days):\n$url\n\n"
             . str_repeat('─', 48) . "\n" . CLUB_NAME . "\n" . SITE_URL;
    return send_notification($to, $subject, $body);
}

// ── Donation receipt to the donor, sent right after PayPal capture ───────
// $subjectPrefix lets the caller flag a sandbox-mode test capture (donate-
// capture-order.php) so a donor never mistakes a fake test transaction for
// a real tax-deductible receipt.
function send_donation_receipt(string $donorEmail, string $donorName, float $amount, string $captureId, string $subjectPrefix = ''): bool {
    $amt  = '$' . number_format($amount, 2);
    $date = date('F j, Y');
    $name = $donorName !== '' ? $donorName : 'there';

    $subject = "{$subjectPrefix}Thank You for Your Donation — $amt";
    $body    = CLUB_NAME . "\n"
             . "Donation Receipt\n"
             . str_repeat('─', 48) . "\n\n"
             . "Hi $name,\n\n"
             . "Thank you for your generous donation of $amt to " . CLUB_NAME . "! "
             . "Your support directly helps our cadets and families throughout their Academy journey.\n\n"
             . "Donation Details:\n"
             . "  Date:          $date\n"
             . "  Amount:        $amt\n"
             . "  PayPal Ref:    $captureId\n\n"
             . "If you have any questions, please contact our treasurer at treasurer@alabamafalcons.org.\n\n"
             . str_repeat('─', 48) . "\n" . CLUB_NAME . "\n" . SITE_URL;
    return send_notification($donorEmail, $subject, $body);
}

// ── Receipt for a payment the treasurer recorded manually (check, cash,
// Zelle, etc. — anything that didn't go through the online PayPal flow) ──
// Mirrors the club's paper donation-receipt letter content in plain text:
// no-goods-or-services statement + 501(c)(3)/tax-ID language, so it holds
// up the same way for tax purposes regardless of payment channel.
function send_manual_payment_receipt(string $payerEmail, string $payerName, float $amount, string $date, string $description): bool {
    $amt      = '$' . number_format($amount, 2);
    $dateStr  = date('F j, Y', strtotime($date) ?: time());
    $name     = $payerName !== '' ? $payerName : 'there';
    $descLine = $description !== '' ? $description : 'Monetary gift';

    $subject = 'Your Receipt from ' . CLUB_NAME . " — $amt";
    $body    = CLUB_NAME . "\n"
             . "Payment Receipt\n"
             . str_repeat('─', 48) . "\n\n"
             . "Dear $name,\n\n"
             . "Thank you for your payment to the " . CLUB_NAME . ". Your generous contribution "
             . "helps sustain cadet morale and hometown outreach via care packages, special events, "
             . "and other cadet and family support activities. Your gift makes a real difference in "
             . "the lives of the Alabama cadets who volunteered to serve our great country while "
             . "separated from their families throughout their Academy academic and leadership "
             . "journey. These fine men and women are the future of our armed forces and deserve "
             . "our unwavering support.\n\n"
             . "Date:        $dateStr\n"
             . "Amount:      $amt\n"
             . "Description: $descLine\n\n"
             . "No goods or services were provided in return for this contribution.\n\n"
             . "The " . CLUB_NAME . " is a 501(c)(3) tax-exempt organization (Tax ID: " . CLUB_TAX_ID . "). "
             . "This contribution is tax-deductible to the extent allowable by law. Please consult a "
             . "qualified tax professional for further guidance regarding the deductibility of your "
             . "contribution for your personal tax situation.\n\n"
             . "Sincerely,\n" . CLUB_NAME . "\n\n"
             . str_repeat('─', 48) . "\n" . SITE_URL;
    return send_notification($payerEmail, $subject, $body);
}

// ── Notify the treasurer of a completed online donation ──────────────────
function notify_treasurer_of_donation(string $donorName, string $donorEmail, float $amount, string $orderId, string $captureId, string $subjectPrefix = ''): bool {
    $amt  = '$' . number_format($amount, 2);
    $date = date('F j, Y g:ia');

    $subject = "{$subjectPrefix}New Online Donation Received — $amt";
    $body    = CLUB_NAME . "\n"
             . "New Online Donation\n"
             . str_repeat('─', 48) . "\n\n"
             . "A donation was just completed via PayPal on payment.html.\n\n"
             . "Donor Name:    " . ($donorName !== '' ? $donorName : '(not provided)') . "\n"
             . "Donor Email:   $donorEmail\n"
             . "Amount:        $amt\n"
             . "Date:          $date\n"
             . "PayPal Order:   $orderId\n"
             . "PayPal Capture: $captureId\n\n"
             . "This has been logged in the Income Ledger automatically (source: Online Donation).\n\n"
             . str_repeat('─', 48) . "\n" . CLUB_NAME . "\n" . ADMIN_URL;
    return send_notification('treasurer@alabamafalcons.org', $subject, $body);
}
