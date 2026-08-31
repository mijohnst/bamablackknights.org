<?php
require_once __DIR__ . '/auth.php';
require_login();
$pdo  = get_pdo();
$role = $_SESSION['role'] ?? 'member';
$name = current_user_name();
try {
    $my_user_stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
    $my_user_stmt->execute([$_SESSION['user_id'] ?? 0]);
    $my_user   = $my_user_stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    $my_avatar = $my_user['avatar_filename'] ?? null;
} catch (Exception $e) { $my_avatar = null; $my_user = []; }
try {
    $my_member = $my_user ? find_linked_member($pdo, $my_user) : null;
} catch (Exception $e) { $my_member = null; }

// ── Gather data for alerts and stats ──────────────────────────────────────
$stats = [];

// Member stats — only needed to feed the Members tile's subtitle, which is
// itself only shown to whoever can view member PII (see below) — skip the
// full-table aggregate for everyone else.
if (can_view_member_pii()) {
    $ms = $pdo->query("SELECT COUNT(*) as total,
                              SUM(membership_paid) as paid,
                              SUM(CASE WHEN membership_paid=0 THEN 1 ELSE 0 END) as unpaid,
                              SUM(CASE WHEN created_at>=DATE_FORMAT(NOW(),'%Y-%m-01') THEN 1 ELSE 0 END) as new_month
                       FROM members WHERE archived=0")->fetch();
    $stats['members'] = $ms;
}

// Finance stats
if (can_manage_finances()) {
    $fin = $pdo->query("SELECT
        SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END) as pending_count,
        SUM(CASE WHEN status IN ('approved','submitted') THEN 1 ELSE 0 END) as approved_count,
        SUM(CASE WHEN status='paid' AND YEAR(purchase_date)=YEAR(NOW()) THEN amount_total ELSE 0 END) as ytd
        FROM purchases")->fetch();
    $stats['finance'] = $fin;

    // My own pending submissions — powers the Add Purchase tile's badge for
    // whoever is looking at the dashboard, regardless of role.
    $my_stmt = $pdo->prepare("SELECT COUNT(*) FROM purchases WHERE submitted_by=? AND status='pending'");
    $my_stmt->execute([$_SESSION['user_id']??0]);
    $stats['my_pending'] = (int)$my_stmt->fetchColumn();
}

// Helpdesk stats
$hd = $pdo->query("SELECT
    SUM(CASE WHEN status='open' THEN 1 ELSE 0 END) as open_count,
    SUM(CASE WHEN status='in_progress' THEN 1 ELSE 0 END) as inprog_count,
    SUM(CASE WHEN status='resolved' THEN 1 ELSE 0 END) as resolved_count
    FROM tickets")->fetch();
$stats['tickets'] = $hd;

// Extra treasurer finance breakdown
if (is_treasurer()) {
    $tfin = $pdo->query("SELECT
        SUM(CASE WHEN status='pending'                  THEN amount_total ELSE 0 END) as pending_amt,
        SUM(CASE WHEN status IN ('approved','submitted') THEN amount_total ELSE 0 END) as approved_amt,
        SUM(CASE WHEN status='paid' AND YEAR(purchase_date)=YEAR(NOW()) THEN amount_total ELSE 0 END) as reimbursed_ytd,
        SUM(CASE WHEN status='paid' AND YEAR(purchase_date)=YEAR(NOW()) THEN amount_tax    ELSE 0 END) as tax_ytd,
        SUM(CASE WHEN YEAR(purchase_date)=YEAR(NOW()) THEN amount_total ELSE 0 END) as all_ytd,
        COUNT(CASE WHEN status='paid' AND YEAR(purchase_date)=YEAR(NOW()) THEN 1 END) as reimbursed_count
        FROM purchases")->fetch();
    $stats['tfin'] = $tfin;

    // Dues collected this calendar year — dues payments log to
    // income_entries automatically now (see save_dues_years()), so this is
    // just the same source_type='dues' sum admin/income.php shows.
    $cur_year = membership_year();
    $dues_amt = (float)$pdo->query(
        "SELECT COALESCE(SUM(amount),0) FROM income_entries WHERE source_type='dues' AND YEAR(entry_date)=YEAR(NOW())"
    )->fetchColumn();
    $dues_paid_count = (int)$pdo->query(
        "SELECT COUNT(*) FROM members WHERE archived=0 AND membership_paid=1 AND class_year <> 'Graduate' AND class_year <> ''"
    )->fetchColumn();
    $stats['dues'] = ['grand_total' => $dues_amt, 'paid_count' => $dues_paid_count, 'year' => $cur_year];

    // Budget utilization
    $budgets_row = $pdo->query("SELECT COUNT(*) as cnt,
        SUM(b.budget) as total_budget,
        SUM((SELECT COALESCE(SUM(p.amount_total),0) FROM purchases p WHERE p.event=b.event)) as total_spent
        FROM event_budgets b")->fetch();
    $stats['budgets'] = $budgets_row;
}

// My tickets
$mt_stmt = $pdo->prepare("SELECT COUNT(*) FROM tickets WHERE submitted_by=? AND status!='resolved'");
$mt_stmt->execute([$_SESSION['user_id']??0]);
$stats['my_open_tickets'] = (int)$mt_stmt->fetchColumn();

// Upcoming birthdays (7 days)
$bday_soon = 0;
if (!is_member()) {
    try {
        // Bind PHP's own date rather than MySQL's NOW() — the two can
        // disagree if the DB server's timezone isn't Central, silently
        // shifting "today" by hours.
        $b_stmt = $pdo->prepare("SELECT COUNT(*) FROM members WHERE archived=0
            AND cadet_birthday IS NOT NULL AND cadet_birthday!=''
            AND (DAYOFYEAR(cadet_birthday)-DAYOFYEAR(:today) BETWEEN 0 AND 7
                 OR DAYOFYEAR(cadet_birthday)-DAYOFYEAR(:today)+365 BETWEEN 0 AND 7)");
        $b_stmt->execute(['today' => date('Y-m-d')]);
        $bday_soon = (int)$b_stmt->fetchColumn();
    } catch(Exception $e) {}
}

// Role display label and color
$role_display = ['admin'=>'Admin','tech'=>'Tech Support','officer'=>'Officer',
                 'secretary'=>'Secretary','treasurer'=>'Treasurer','member'=>'Member'];
$role_colors  = ['admin'=>'#002554','tech'=>'#bf360c','officer'=>'#1a237e',
                 'secretary'=>'#5c007a','treasurer'=>'#1b5e20','member'=>'#7b3f00'];
$role_label = $role_display[$role] ?? ucfirst($role);
$role_color = $role_colors[$role] ?? '#5a6a7a';

// ── Quick action definitions by role, grouped into dashboard sections ──────
$sections = [];
// Sponsors is pushed from two independent, currently mutually-exclusive
// blocks below (can_manage_members() and is_treasurer()) since neither
// role alone implies the other — this guard just prevents a double tile
// if that ever stops being true (e.g. a combined officer/treasurer role).
$sponsors_tile_added = false;

// Members tile — full admin roster with un-redacted contact info, so it's
// gated the same as index.php's own guard. Regular members already have
// the consent-filtered "Directory" tile below for the same purpose.
if (can_view_member_pii()) {
    $mem_total = $stats['members']['total'] ?? null;
    $sections['For You'][] = ['icon'=>'👥','label'=>'Members','sub'=>$mem_total!==null?$mem_total.' active':'View roster','href'=>'index.php','color'=>'#002554'];
}

// My Membership — only for accounts linked to (or email-matched to) a
// cadet family record. Staff-only accounts with no family match won't see it.
if ($my_member) {
    $sections['For You'][] = ['icon'=>'🪪','label'=>'My Membership','sub'=>$my_member['membership_paid']?'✓ Paid':'✗ Unpaid — pay now','href'=>'my-membership.php','color'=>$my_member['membership_paid']?'#1b5e20':'#A6192E'];
}

// Officer election — shown only while there's something to act on: an open
// ballot (with days-left / voted state) or a scheduled election to await.
try {
    $now_str = date('Y-m-d H:i:s');
    $today   = date('Y-m-d');
    $election = $pdo->prepare(
        "SELECT * FROM elections
         WHERE (status='open' AND voting_opens_at <= ? AND voting_closes_at >= ?)
            OR (status='draft' AND election_date >= ?)
         ORDER BY (status='open') DESC, election_date ASC LIMIT 1"
    );
    $election->execute([$now_str, $now_str, $today]);
    $election = $election->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) { $election = null; }

if ($election && $election['status'] === 'open') {
    $voted_stmt = $pdo->prepare('SELECT COUNT(*) FROM election_votes WHERE election_id=? AND voter_user_id=?');
    $voted_stmt->execute([$election['id'], $_SESSION['user_id'] ?? 0]);
    $has_voted = (int)$voted_stmt->fetchColumn() > 0;
    $days_left = max(0, (int)ceil((strtotime($election['voting_closes_at']) - time()) / 86400));
    $sections['For You'][] = ['icon'=>'🗳️','label'=>'Election','sub'=>$has_voted?'✓ Voted':"Vote now — closes in {$days_left}d",'href'=>'vote.php','color'=>$has_voted?'#1b5e20':'#A6192E'];
} elseif ($election) {
    $sections['For You'][] = ['icon'=>'🗳️','label'=>'Election','sub'=>'Voting opens '.date('M j', strtotime($election['voting_opens_at'])),'href'=>'vote.php','color'=>'#1565c0'];
}

// Member decision polls — separate from officer elections above. Always
// shown (unlike Election, which only appears when there's something to
// act on) so everyone can find past results too, not just open ballots.
try {
    $poll_paid_ok  = $my_member && !empty($my_member['membership_paid']);
    $poll_board_ok = is_board_role();
    $pv_stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM polls p WHERE p.status='open' AND p.expires_at > NOW()
         AND ((p.audience='board' AND ?) OR (p.audience<>'board' AND ?))
         AND p.id NOT IN (SELECT poll_id FROM poll_votes WHERE user_id = ?)"
    );
    $pv_stmt->execute([$poll_board_ok ? 1 : 0, $poll_paid_ok ? 1 : 0, $_SESSION['user_id'] ?? 0]);
    $open_polls_unvoted = (int)$pv_stmt->fetchColumn();
} catch (Exception $e) { $open_polls_unvoted = 0; }
$sections['For You'][] = [
    'icon'  => '📊',
    'label' => 'Vote',
    'sub'   => $open_polls_unvoted > 0 ? "$open_polls_unvoted vote" . ($open_polls_unvoted > 1 ? 's' : '') . ' waiting' : 'View polls',
    'href'  => 'polls.php',
    'color' => $open_polls_unvoted > 0 ? '#A6192E' : '#1565c0',
    'badge' => $open_polls_unvoted > 0 ? $open_polls_unvoted : 0,
];

// Member self-service tiles — every role, including officers who may want
// to sign up/RSVP/submit themselves too.
try {
    $my_open_slots = (int)$pdo->query(
        "SELECT COUNT(*) FROM volunteer_opportunities o WHERE o.active=1
         AND (SELECT COUNT(*) FROM volunteer_signups WHERE opportunity_id=o.id) < o.spots_needed"
    )->fetchColumn();
} catch (Exception $e) { $my_open_slots = 0; }
$sections['For You'][] = ['icon'=>'🙋','label'=>'Volunteer Sign-Ups','sub'=>$my_open_slots>0?"$my_open_slots need people":'View opportunities','href'=>'volunteer-signup.php','color'=>'#1b5e20'];
$sections['For You'][] = ['icon'=>'📆','label'=>'My RSVPs','sub'=>'Let us know you\'re coming','href'=>'event-rsvp.php','color'=>'#1565c0'];
$my_photo_eligible = is_super_admin() || is_board_role() || ($my_member && !empty($my_member['membership_paid']));
$sections['For You'][] = ['icon'=>'📷','label'=>'Photo Submission','sub'=>$my_photo_eligible?'For the homepage slideshow':'Requires paid membership','href'=>'submit-photo.php','color'=>'#6a1b9a'];
$sections['For You'][] = ['icon'=>'🤝','label'=>'My Committees','sub'=>'Flag where you can help','href'=>'my-committees.php','color'=>'#f57f17'];
$sections['For You'][] = ['icon'=>'📖','label'=>'Directory','sub'=>'Printable roster','href'=>'directory.php','color'=>'#1b5e20'];

if (can_manage_members()) {
    $sections['Member Management'][] = ['icon'=>'➕','label'=>'Add Member','sub'=>'Add new cadet','href'=>'add.php','color'=>'#003594'];
    $sections['Site Management'][] = ['icon'=>'⚙️','label'=>'Site Settings','sub'=>'Hero, dues, letter, links','href'=>'settings.php','color'=>'#37474f'];
    $sections['Site Management'][] = ['icon'=>'🔁','label'=>'Automated Emails','sub'=>'Birthdays, dues, reminders','href'=>'automated-emails.php','color'=>'#00695c'];
    $sections['Site Management'][] = ['icon'=>'📅','label'=>'Events','sub'=>'Manage site events','href'=>'events.php','color'=>'#1565c0'];
    try { $vcount_v = (int)get_pdo()->query('SELECT COUNT(*) FROM volunteers')->fetchColumn(); } catch(Exception $e) { $vcount_v=0; }
    $sections['Member Management'][] = ['icon'=>'🙋','label'=>'Volunteers','sub'=>$vcount_v>0?"$vcount_v submission".($vcount_v>1?'s':''):'View signups','href'=>'volunteers.php','color'=>'#1b5e20','badge'=>$vcount_v>0?$vcount_v:0];
    $sections['Member Management'][] = ['icon'=>'👥','label'=>'Leadership','sub'=>'Update officer profiles','href'=>'leadership.php','color'=>'#002554'];
    // Counts parent slots (not members) still needing a badge, matching the
    // "Needs a badge" filter on badges.php — paid members with no member_badges
    // row, or one with done=0, for that parent slot.
    try {
        $badge_pending = (int)get_pdo()->query("
            SELECT COALESCE(SUM(
                (CASE WHEN TRIM(CONCAT(m.parent1_first_name,' ',m.parent1_last_name)) <> ''
                      AND NOT EXISTS (SELECT 1 FROM member_badges b WHERE b.member_id=m.id AND b.parent_slot=1 AND b.done=1)
                 THEN 1 ELSE 0 END)
                +
                (CASE WHEN TRIM(CONCAT(COALESCE(m.parent2_first_name,''),' ',COALESCE(m.parent2_last_name,''))) <> ''
                      AND NOT EXISTS (SELECT 1 FROM member_badges b WHERE b.member_id=m.id AND b.parent_slot=2 AND b.done=1)
                 THEN 1 ELSE 0 END)
            ), 0)
            FROM members m WHERE m.archived=0 AND m.membership_paid=1
        ")->fetchColumn();
    } catch (Exception $e) { $badge_pending = 0; }
    $sections['Member Management'][] = ['icon'=>'🏅','label'=>'Badges','sub'=>$badge_pending>0?"$badge_pending pending":'Track parent badge orders','href'=>'badges.php','color'=>'#8A8D8F','badge'=>$badge_pending];
    $sections['Site Management'][] = ['icon'=>'📣','label'=>'Announcements','sub'=>'Site banner notices','href'=>'announcements.php','color'=>'#b71c1c'];
    $sections['Site Management'][] = ['icon'=>'🖼️','label'=>'Homepage Gallery','sub'=>'Direct-upload homepage photos','href'=>'gallery.php','color'=>'#1b5e20'];
    $sections['Site Management'][] = ['icon'=>'📸','label'=>'Event Albums','sub'=>'Photos for a specific club event','href'=>'event-albums.php','color'=>'#1565c0'];
    if (!$sponsors_tile_added) {
        $sections['Finance'][] = ['icon'=>'🏆','label'=>'Sponsors','sub'=>'Manage sponsor listings','href'=>'sponsors.php','color'=>'#f57f17'];
        $sponsors_tile_added = true;
    }
    $sections['Member Management'][] = ['icon'=>'📋','label'=>'Lists','sub'=>'Email & contact lists','href'=>'lists.php','color'=>'#1565c0'];
    $sections['Member Management'][] = ['icon'=>'✉️','label'=>'Email Members','sub'=>'Compose blast','href'=>'email.php','color'=>'#6a1b9a'];
    // Secretary tools
    $sections['Secretary Tools'][] = ['icon'=>'📝','label'=>'Minutes','sub'=>'Meeting minutes & files','href'=>'minutes.php','color'=>'#5c007a'];
    $sections['Secretary Tools'][] = ['icon'=>'✅','label'=>'Attendance','sub'=>'Track who attended','href'=>'attendance.php','color'=>'#5c007a'];
    $sections['Secretary Tools'][] = ['icon'=>'📬','label'=>'Correspondence','sub'=>'Log official comms','href'=>'correspondence.php','color'=>'#5c007a'];
    $sections['Secretary Tools'][] = ['icon'=>'🖊️','label'=>'Member Letter','sub'=>'Print status letter','href'=>'member-letter.php','color'=>'#5c007a'];
    try { $pl_count = (int)get_pdo()->query('SELECT COUNT(*) FROM parent_letters')->fetchColumn(); } catch(Exception $e) { $pl_count = 0; }
    $sections['Secretary Tools'][] = ['icon'=>'💌','label'=>'Parent Letters','sub'=>$pl_count>0?"$pl_count saved — print A\u{2013}Z":'None saved yet','href'=>'parent-letters.php','color'=>'#5c007a'];
    try { $pending_noms = (int)get_pdo()->query("SELECT COUNT(*) FROM election_candidates WHERE status='pending'")->fetchColumn(); } catch(Exception $e) { $pending_noms = 0; }
    $sections['Secretary Tools'][] = ['icon'=>'🗳️','label'=>'Elections','sub'=>$pending_noms>0?"$pending_noms nomination".($pending_noms>1?'s':'')." to review":'Set up officer voting','href'=>'elections.php','color'=>'#5c007a','badge'=>$pending_noms>0?$pending_noms:0];
    try { $pending_jd = (int)get_pdo()->query("SELECT COUNT(*) FROM job_drop_submissions WHERE status='pending'")->fetchColumn(); } catch(Exception $e) { $pending_jd = 0; }
    $sections['Secretary Tools'][] = ['icon'=>'🎖️','label'=>'Job Drop Night','sub'=>$pending_jd>0?"$pending_jd to review":'No submissions yet','href'=>'job-drop-submissions.php','color'=>'#5c007a','badge'=>$pending_jd>0?$pending_jd:0];
    // Member support features
    try { $vo_needed = (int)get_pdo()->query(
        "SELECT COUNT(*) FROM volunteer_opportunities o WHERE o.active=1
         AND (SELECT COUNT(*) FROM volunteer_signups WHERE opportunity_id=o.id) < o.spots_needed"
    )->fetchColumn(); } catch(Exception $e) { $vo_needed = 0; }
    $sections['Member Management'][] = ['icon'=>'🧰','label'=>'Volunteer Opportunities','sub'=>$vo_needed>0?"$vo_needed need people":'Manage opportunities','href'=>'volunteer-opportunities.php','color'=>'#1b5e20','badge'=>$vo_needed>0?$vo_needed:0];
    $sections['Member Management'][] = ['icon'=>'👀','label'=>'Event RSVPs','sub'=>'See who\'s coming','href'=>'event-rsvps.php','color'=>'#1565c0'];
    try { $photo_pending = (int)get_pdo()->query("SELECT COUNT(*) FROM photo_submissions WHERE status='pending'")->fetchColumn(); } catch(Exception $e) { $photo_pending = 0; }
    $sections['Member Management'][] = ['icon'=>'🔍','label'=>'Review Photo Submissions','sub'=>$photo_pending>0?"$photo_pending awaiting review":'All caught up','href'=>'photo-submissions.php','color'=>$photo_pending>0?'#A6192E':'#6a1b9a','badge'=>$photo_pending>0?$photo_pending:0];
    $sections['Member Management'][] = ['icon'=>'📇','label'=>'Committee Interest','sub'=>'See who volunteered','href'=>'committee-interest.php','color'=>'#f57f17'];
}

if (can_use_digest_composer()) {
    $sections['Site Management'][] = ['icon'=>'🧵','label'=>'Digest Composer','sub'=>'AI-organize forwarded emails','href'=>'digest-composer.php','color'=>'#6a1b9a'];
    try { $digest_count = (int)get_pdo()->query('SELECT COUNT(*) FROM digest_emails')->fetchColumn(); } catch(Exception $e) { $digest_count = 0; }
    $sections['Site Management'][] = ['icon'=>'📚','label'=>'Digest Catalog','sub'=>$digest_count>0?"$digest_count saved":'Saved digest drafts','href'=>'digest-catalog.php','color'=>'#6a1b9a'];
}

if (can_manage_finances()) {
    $pending  = $stats['finance']['pending_count']  ?? 0;
    $approved = $stats['finance']['approved_count'] ?? 0;
    if (!is_member()) {
        $sections['Finance'][] = ['icon'=>'💰','label'=>'Finance','sub'=>$pending>0?"$pending need approval":'View purchases','href'=>'purchases.php','color'=>$pending>0?'#A6192E':'#1b5e20','badge'=>$pending>0?$pending:0];
    }
    $my_pending = $stats['my_pending'] ?? 0;
    $sections['For You'][] = ['icon'=>'🧾','label'=>'Add Purchase','sub'=>$my_pending>0?"$my_pending pending":'Submit an expense','href'=>'purchase-form.php','color'=>'#003594','badge'=>$my_pending>0?$my_pending:0];
    if (is_treasurer()) {
        $sections['Finance'][] = ['icon'=>'💳','label'=>'Payments','sub'=>$approved>0?"$approved awaiting payment":'Nothing pending','href'=>'pending-reimbursements.php','color'=>$approved>0?'#003594':'#5a6a7a','badge'=>$approved>0?$approved:0];
        $sections['Finance'][] = ['icon'=>'📊','label'=>'Reports','sub'=>'Year-end & budgets','href'=>'report.php','color'=>'#37474f'];
        $sections['Finance'][] = ['icon'=>'🗂️','label'=>'Receipts','sub'=>'Browse by event or vendor','href'=>'receipts-by.php','color'=>'#37474f'];
        $sections['Finance'][] = ['icon'=>'📥','label'=>'Income','sub'=>'Record & review income','href'=>'income.php','color'=>'#1b5e20'];
        $sections['Finance'][] = ['icon'=>'🧾','label'=>'Manual Receipts','sub'=>'Email a payer a receipt','href'=>'manual-receipts.php','color'=>'#00695c'];
        $sections['Finance'][] = ['icon'=>'🏭','label'=>'Vendors','sub'=>'Spend by vendor + 1099','href'=>'vendor-summary.php','color'=>'#1565c0'];
        $sections['Finance'][] = ['icon'=>'📈','label'=>'Year Compare','sub'=>'Multi-year spending','href'=>'year-compare.php','color'=>'#6a1b9a'];
        if (!$sponsors_tile_added) {
            $sections['Finance'][] = ['icon'=>'🏆','label'=>'Sponsors','sub'=>'Manage sponsor listings','href'=>'sponsors.php','color'=>'#f57f17'];
            $sponsors_tile_added = true;
        }
    }
    // Matches paypal-dues-orders.php's own access check exactly (Treasurer/
    // Admin/Tech full access, President/VP read-only) -- was previously
    // stuck inside the is_treasurer()-only block above, so officers/admins
    // never saw a tile to reach the page even after it started allowing them in.
    if (is_treasurer() || is_super_admin() || is_officer()) {
        $sections['Finance'][] = ['icon'=>'🅿️','label'=>'PayPal Activity','sub'=>'Online dues & donations','href'=>'paypal-dues-orders.php','color'=>'#003594'];
    }
}

// Helpdesk — one card for all roles
$open = ($stats['tickets']['open_count'] ?? 0) + ($stats['tickets']['inprog_count'] ?? 0);
if (can_manage_tickets()) {
    $sections['Site Management'][] = ['icon'=>'🎫','label'=>'Support Tickets','sub'=>$open>0?"$open open":'All clear','href'=>'helpdesk.php','color'=>$open>0?'#f57c00':'#1b5e20','badge'=>$open>0?$open:0];
} else {
    $my_open = $stats['my_open_tickets'];
    $sections['For You'][] = ['icon'=>'🎫','label'=>'Support','sub'=>$my_open>0?"$my_open open ticket".($my_open>1?'s':''):'Submit a ticket','href'=>'helpdesk.php','color'=>$my_open>0?'#f57c00':'#5a6a7a','badge'=>$my_open>0?$my_open:0];
}

if (can_manage_members() || is_treasurer()) {
    try { $vcount = (int)get_pdo()->query('SELECT COUNT(*) FROM vault_documents')->fetchColumn(); } catch(Exception $e) { $vcount = 0; }
    $sections['Site Management'][] = ['icon'=>'🔒','label'=>'Document Vault','sub'=>$vcount>0?"$vcount document".($vcount>1?'s':''):'Secure file storage','href'=>'vault.php','color'=>'#37474f'];
}
$sections['For You'][] = ['icon'=>'👤','label'=>'My Profile','sub'=>'Photo & password','href'=>'change-password.php','color'=>'#546e7a'];
$sections['Site Management'][] = ['icon'=>'📚','label'=>'Staff Guide','sub'=>'Portal orientation','href'=>'staff-guide.php','color'=>'#002554'];

if (is_super_admin()) {
    $sections['Site Management'][] = ['icon'=>'👤','label'=>'Users','sub'=>'Manage accounts','href'=>'users.php','color'=>'#37474f'];
}

// Display order — only sections with at least one visible tile are rendered.
$section_order = ['For You', 'Member Management', 'Secretary Tools', 'Finance', 'Site Management'];

admin_header('Dashboard');
?>
<style>
.welcome-banner{background:linear-gradient(135deg,#002554 0%,#003594 60%,#1565c0 100%);border-radius:8px;padding:1.5rem 2rem;color:#fff;margin-bottom:1.5rem;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:1rem}
.welcome-name{font-size:1.6rem;font-weight:700;line-height:1.2}
.welcome-sub{font-size:.85rem;opacity:.7;margin-top:.25rem}
.role-pill{background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.3);border-radius:99px;padding:.3rem .9rem;font-size:.78rem;font-weight:700;letter-spacing:.04em;white-space:nowrap}
.welcome-left{display:flex;align-items:center;gap:1rem}
.welcome-avatar{width:56px;height:56px;border-radius:50%;object-fit:cover;background:rgba(255,255,255,.15);border:2px solid rgba(255,255,255,.4);color:#fff;display:flex;align-items:center;justify-content:center;font-size:1.3rem;font-weight:700;flex-shrink:0}
.action-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:.85rem;margin-bottom:1.5rem}
.action-card{background:#fff;border-radius:8px;box-shadow:0 1px 4px rgba(0,0,0,.08);padding:1.25rem 1rem;text-decoration:none;color:#1a2332;display:flex;flex-direction:column;align-items:center;text-align:center;gap:.4rem;transition:all .2s;border:2px solid transparent;position:relative}
.action-card:hover{border-color:#003594;box-shadow:0 4px 16px rgba(0,0,0,.12);transform:translateY(-2px);text-decoration:none;color:#002554}
.action-icon{font-size:2rem;line-height:1}
.action-label{font-size:.82rem;font-weight:700;letter-spacing:.02em}
.action-sub{font-size:.7rem;color:#5a6a7a;line-height:1.3}
.action-badge{position:absolute;top:-.4rem;right:-.4rem;background:#A6192E;color:#fff;font-size:.62rem;font-weight:700;padding:.15rem .4rem;border-radius:99px;min-width:18px;text-align:center}
.section-label{font-size:.72rem;font-weight:700;color:#5a6a7a;text-transform:uppercase;letter-spacing:.08em;margin:1.5rem 0 .6rem}
.section-label:first-of-type{margin-top:0}
.alert-strip{display:flex;flex-wrap:wrap;gap:.6rem;margin-bottom:1.25rem}
.alert-chip{display:flex;align-items:center;gap:.45rem;padding:.55rem .9rem;border-radius:6px;font-size:.82rem;font-weight:600;text-decoration:none}
.alert-chip:hover{text-decoration:none;filter:brightness(.95)}
.mini-stats{display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:.6rem;margin-bottom:1.5rem}
.mini-stat{background:#fff;border-radius:6px;box-shadow:0 1px 4px rgba(0,0,0,.08);padding:.85rem 1rem;text-align:center}
.mini-stat-val{font-size:1.5rem;font-weight:700}
.mini-stat-lbl{font-size:.65rem;color:#5a6a7a;text-transform:uppercase;letter-spacing:.05em;margin-top:.15rem}
@media(max-width:500px){.action-grid{grid-template-columns:repeat(2,1fr)}.welcome-name{font-size:1.3rem}}
</style>

<!-- Welcome banner -->
<div class="welcome-banner">
  <div class="welcome-left">
    <?php if ($my_avatar): ?>
      <img class="welcome-avatar" src="/avatar-serve.php?id=<?= (int)($_SESSION['user_id'] ?? 0) ?>" alt="">
    <?php else: ?>
      <div class="welcome-avatar"><?= h(mb_strtoupper(mb_substr($name, 0, 1))) ?></div>
    <?php endif; ?>
    <div>
      <div class="welcome-name">Welcome back, <?= h($name) ?>!</div>
      <div class="welcome-sub"><?= date('l, F j, Y') ?></div>
    </div>
  </div>
  <span class="role-pill" style="background:<?= $role_color ?>55;border-color:<?= $role_color ?>88"><?= h($role_label) ?></span>
</div>

<?php
// ── Build alerts ───────────────────────────────────────────────────────────
$alerts = [];
if (can_manage_finances()) {
    $p = $stats['finance']['pending_count'] ?? 0;
    $a = $stats['finance']['approved_count'] ?? 0;
    if ($p) $alerts[] = ['bg'=>'#fff3cd','border'=>'#ffc107','text'=>'#5f4c00','icon'=>'⏳','msg'=>"$p purchase".($p>1?'s':'')." need approval",'href'=>'purchases.php?status=pending'];
    if ($a && is_treasurer()) $alerts[] = ['bg'=>'#e3f2fd','border'=>'#90caf9','text'=>'#0d47a1','icon'=>'💳','msg'=>"$a awaiting payment",'href'=>'pending-reimbursements.php'];
}
if (can_manage_tickets() && $open > 0)
    $alerts[] = ['bg'=>'#fff8e1','border'=>'#ffcc02','text'=>'#5f4c00','icon'=>'🎫','msg'=>"$open support ticket".($open>1?'s':'')." open",'href'=>'helpdesk.php'];
if ($bday_soon > 0)
    $alerts[] = ['bg'=>'#f3e5f5','border'=>'#ce93d8','text'=>'#4a148c','icon'=>'🎂','msg'=>"$bday_soon birthday".($bday_soon>1?'s':'')." in the next 7 days",'href'=>'index.php#bday-panel'];
if ($stats['my_open_tickets'] > 0 && !can_manage_tickets())
    $alerts[] = ['bg'=>'#e8f5e9','border'=>'#a5d6a7','text'=>'#1b5e20','icon'=>'🎫','msg'=>"You have ".$stats['my_open_tickets']." open ticket".($stats['my_open_tickets']>1?'s':''),'href'=>'helpdesk.php?mine=1'];
?>
<?php if (!empty($alerts)): ?>
<div class="alert-strip">
  <?php foreach ($alerts as $al): ?>
  <a href="<?= h($al['href']) ?>" class="alert-chip" style="background:<?= $al['bg'] ?>;border:1px solid <?= $al['border'] ?>;color:<?= $al['text'] ?>">
    <?= $al['icon'] ?> <?= h($al['msg']) ?>
  </a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Quick actions, grouped by section -->
<?php foreach ($section_order as $sec_name): if (empty($sections[$sec_name])) continue; ?>
<p class="section-label"><?= h($sec_name) ?></p>
<div class="action-grid">
  <?php foreach ($sections[$sec_name] as $ac): ?>
  <a href="<?= h($ac['href']) ?>" class="action-card">
    <?php if (!empty($ac['badge'])): ?>
    <span class="action-badge"><?= (int)$ac['badge'] ?></span>
    <?php endif; ?>
    <span class="action-icon"><?= $ac['icon'] ?></span>
    <span class="action-label" style="color:<?= $ac['color'] ?>"><?= h($ac['label']) ?></span>
    <span class="action-sub"><?= h($ac['sub'] ?? '') ?></span>
  </a>
  <?php endforeach; ?>
</div>
<?php if ($sec_name === 'Secretary Tools'):
    try {
        $mtg_count  = (int)$pdo->query("SELECT COUNT(*) FROM club_meetings WHERE YEAR(meeting_date)=YEAR(NOW())")->fetchColumn();
        $corr_count = (int)$pdo->query("SELECT COUNT(*) FROM correspondence_log WHERE YEAR(log_date)=YEAR(NOW())")->fetchColumn();
        $att_pct    = null;
        if ($mtg_count > 0) {
            $total_mem_att = (int)$pdo->query("SELECT COUNT(*) FROM members WHERE archived=0")->fetchColumn();
            $avg_att_row   = $pdo->query("SELECT AVG(cnt) as a FROM (SELECT meeting_id, COUNT(*) as cnt FROM meeting_attendance ma JOIN club_meetings cm ON ma.meeting_id=cm.id WHERE YEAR(cm.meeting_date)=YEAR(NOW()) GROUP BY meeting_id) x")->fetch(PDO::FETCH_ASSOC);
            if ($total_mem_att > 0 && $avg_att_row['a'] !== null) {
                $att_pct = round((float)$avg_att_row['a'] / $total_mem_att * 100);
            }
        }
    } catch(Exception $e) { $mtg_count=0; $corr_count=0; $att_pct=null; }
?>
<div class="mini-stats" style="grid-template-columns:repeat(auto-fill,minmax(140px,1fr));margin-top:.6rem">
  <div class="mini-stat" style="border-left:3px solid #5c007a">
    <div class="mini-stat-val" style="color:#5c007a"><?= $mtg_count ?></div>
    <div class="mini-stat-lbl">Meetings This Year</div>
  </div>
  <?php if ($att_pct !== null): ?>
  <div class="mini-stat" style="border-left:3px solid #5c007a">
    <div class="mini-stat-val" style="color:#5c007a"><?= $att_pct ?>%</div>
    <div class="mini-stat-lbl">Avg Attendance Rate</div>
  </div>
  <?php endif; ?>
  <div class="mini-stat" style="border-left:3px solid #5c007a">
    <div class="mini-stat-val" style="color:#5c007a"><?= $corr_count ?></div>
    <div class="mini-stat-lbl">Correspondence Logged</div>
  </div>
</div>
<?php endif; ?>
<?php endforeach; ?>

<!-- Mini stats -->

<?php if (is_treasurer() && !empty($stats['tfin'])): $tf = $stats['tfin']; ?>
<p style="font-size:.72rem;font-weight:700;color:#5a6a7a;text-transform:uppercase;letter-spacing:.08em;margin-bottom:.6rem">Finance — <?= date('Y') ?> Detail</p>
<div class="mini-stats" style="grid-template-columns:repeat(auto-fill,minmax(140px,1fr))">
  <?php if (!empty($stats['dues'])): $d = $stats['dues']; ?>
  <div class="mini-stat" style="border-left:3px solid #003594">
    <div class="mini-stat-val" style="color:#003594">$<?= number_format($d['grand_total'], 2) ?></div>
    <div class="mini-stat-lbl">Dues Collected <?= h($d['year']) ?></div>
  </div>
  <div class="mini-stat" style="border-left:3px solid #003594">
    <div class="mini-stat-val" style="color:#003594"><?= (int)$d['paid_count'] ?></div>
    <div class="mini-stat-lbl">Members Currently Paid</div>
    <div style="font-size:.65rem;color:#9aa5b4;margin-top:.2rem">for <?= h($d['year']) ?></div>
  </div>
  <?php endif; ?>
  <div class="mini-stat" style="border-left:3px solid #A6192E">
    <div class="mini-stat-val" style="color:#A6192E">$<?= number_format($tf['all_ytd']??0,2) ?></div>
    <div class="mini-stat-lbl">All Purchases YTD</div>
  </div>
  <div class="mini-stat" style="border-left:3px solid #1b5e20">
    <div class="mini-stat-val" style="color:#1b5e20">$<?= number_format($tf['reimbursed_ytd']??0,2) ?></div>
    <div class="mini-stat-lbl">Paid YTD (<?= (int)($tf['reimbursed_count']??0) ?>)</div>
  </div>
  <div class="mini-stat" style="border-left:3px solid #003594">
    <div class="mini-stat-val" style="color:#003594">$<?= number_format($tf['approved_amt']??0,2) ?></div>
    <div class="mini-stat-lbl">Approved/Submitted — Unpaid</div>
  </div>
  <div class="mini-stat" style="border-left:3px solid #f57c00">
    <div class="mini-stat-val" style="color:#f57c00">$<?= number_format($tf['pending_amt']??0,2) ?></div>
    <div class="mini-stat-lbl">Pending Approval</div>
  </div>
  <div class="mini-stat" style="border-left:3px solid #5a6a7a">
    <div class="mini-stat-val" style="color:#5a6a7a">$<?= number_format($tf['tax_ytd']??0,2) ?></div>
    <div class="mini-stat-lbl">Tax Paid YTD</div>
  </div>
  <?php if (!empty($stats['budgets']) && $stats['budgets']['total_budget'] > 0):
    $bu = $stats['budgets'];
    $bpct = round($bu['total_spent']/$bu['total_budget']*100);
    $bcol = $bpct>=100?'#A6192E':($bpct>=75?'#f57c00':'#1b5e20');
  ?>
  <div class="mini-stat" style="border-left:3px solid <?= $bcol ?>">
    <div class="mini-stat-val" style="color:<?= $bcol ?>"><?= $bpct ?>%</div>
    <div class="mini-stat-lbl">Budget Used (<?= (int)$bu['cnt'] ?> events)</div>
  </div>
  <?php endif; ?>
</div>
<div style="display:flex;gap:.6rem;flex-wrap:wrap;margin-top:.5rem">
  <a href="year-end.php" class="btn btn-secondary btn-sm">📋 Year-End Report</a>
  <a href="budgets.php" class="btn btn-secondary btn-sm">🎯 Manage Budgets</a>
  <a href="report.php" class="btn btn-secondary btn-sm">📊 Spending Report</a>
  <a href="income.php" class="btn btn-secondary btn-sm">📥 Income Ledger</a>
  <a href="vendor-summary.php" class="btn btn-secondary btn-sm">🏭 Vendor Summary</a>
  <a href="year-compare.php" class="btn btn-secondary btn-sm">📈 Year Compare</a>
</div>
<?php endif; ?>

<?php admin_footer(); ?>
