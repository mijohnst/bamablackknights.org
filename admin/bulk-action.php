<?php
require_once __DIR__ . '/auth.php';
require_login();
if (!can_mark_dues()) { header('Location: index.php?denied=1'); exit; }

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: index.php'); exit; }
csrf_verify();

$ids    = array_filter(array_map('intval', $_POST['member_ids'] ?? []));
$action = $_POST['action'] ?? '';

$dues_actions   = ['mark_paid_current','mark_paid_4year','mark_unpaid_current'];
$member_actions = ['archive','restore','delete','portal_invite'];
$all_actions    = array_merge($dues_actions, $member_actions);

if (empty($ids) || !in_array($action, $all_actions)) {
    flash('error', 'No members selected.');
    header('Location: index.php'); exit;
}

// Archive/restore/delete require member-management permission
if (in_array($action, $member_actions) && !can_manage_members()) {
    header('Location: index.php?denied=1'); exit;
}

$pdo = get_pdo();
$ph  = implode(',', array_fill(0, count($ids), '?'));

if (in_array($action, $dues_actions, true)) {
    // Each member's own dues years are derived from their class_year
    // (cadet_dues_years()), not a single global plan — so this loops
    // per-member rather than a single bulk UPDATE. Fine at this club's
    // roster size. Includes the cadet name columns and passes each row
    // straight into save_dues_years() as $before, so it doesn't re-fetch
    // the same row per member that's already in hand here.
    $rows_stmt = $pdo->prepare(
        "SELECT id, class_year, membership_paid_years, cadet_first_name, cadet_middle_name, cadet_last_name, cadet_suffix
         FROM members WHERE id IN ($ph)"
    );
    $rows_stmt->execute($ids);
    $cur_year = membership_year();
    $count = 0;
    foreach ($rows_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $cadet_years = cadet_dues_years($row['class_year']);
        if (!$cadet_years) continue; // Graduate/blank class year — nothing to mark
        $years = parse_dues_years($row['membership_paid_years']);
        if ($action === 'mark_paid_current') {
            if (!in_array($cur_year, $cadet_years, true)) continue;
            $years[] = $cur_year;
        } elseif ($action === 'mark_paid_4year') {
            $years = array_merge($years, array_slice($cadet_years, -4));
        } else { // mark_unpaid_current
            $years = array_diff($years, [$cur_year]);
        }
        save_dues_years($pdo, (int)$row['id'], $years, true, $row);
        $count++;
    }
    $labels = [
        'mark_paid_current'   => "marked paid for $cur_year",
        'mark_paid_4year'     => 'marked paid for all 4 undergrad years ($275 rate)',
        'mark_unpaid_current' => "marked not paid for $cur_year",
    ];
    flash('success', "$count member(s) " . $labels[$action] . '.');

} elseif ($action === 'archive') {
    $pdo->prepare("UPDATE members SET archived = 1 WHERE id IN ($ph)")->execute($ids);
    flash('success', count($ids) . ' member(s) archived.');

} elseif ($action === 'restore') {
    $pdo->prepare("UPDATE members SET archived = 0 WHERE id IN ($ph)")->execute($ids);
    flash('success', count($ids) . ' member(s) restored to active roster.');

} elseif ($action === 'delete') {
    $pdo->prepare("DELETE FROM members WHERE id IN ($ph)")->execute($ids);
    flash('success', count($ids) . ' member(s) permanently deleted.');

} elseif ($action === 'portal_invite') {
    require_once __DIR__ . '/mailer.php';
    $stmt = $pdo->prepare("SELECT id,parent1_first_name,parent1_last_name,parent1_email,parent2_first_name,parent2_last_name,parent2_email FROM members WHERE id IN ($ph)");
    $stmt->execute($ids);
    $members = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // One query for every existing email/username instead of one lookup per
    // parent slot (up to 2x the selected member count) — this action can be
    // run against a large batch of members at once.
    $existing = [];
    foreach ($pdo->query('SELECT LOWER(email) AS e, LOWER(username) AS u FROM users')->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $existing[$row['e']] = true;
        $existing[$row['u']] = true;
    }

    $insert = $pdo->prepare(
        "INSERT INTO users (name,email,username,password_hash,role,active,invite_token,invite_expires,member_id)
         VALUES (?,?,?,?,'member',1,?,DATE_ADD(NOW(), INTERVAL 14 DAY),?)"
    );
    $invited = 0; $skipped = 0;
    foreach ($members as $m) {
        foreach ([1, 2] as $slot) {
            $email = strtolower(trim($m["parent{$slot}_email"] ?? ''));
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
            $name = trim($m["parent{$slot}_first_name"] . ' ' . $m["parent{$slot}_last_name"]) ?: $email;

            if (isset($existing[$email])) { $skipped++; continue; }

            $token = bin2hex(random_bytes(24));
            try {
                $insert->execute([$name, $email, $email, password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT), $token, $m['id']]);
            } catch (PDOException $e) {
                $skipped++; continue;
            }
            // Covers the same email appearing on more than one selected
            // member (or as both parents on one) within this same batch.
            $existing[$email] = true;
            send_portal_invite($email, $name, $token);
            $invited++;
        }
    }
    $msg = "$invited portal invite" . ($invited !== 1 ? 's' : '') . ' sent.';
    if ($skipped) $msg .= " $skipped already had a portal account.";
    flash('success', $msg);
}

header('Location: index.php' . ($action === 'restore' ? '?archived=1' : ''));
exit;
