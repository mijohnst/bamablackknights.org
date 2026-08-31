<?php
// Shared utilities — never served directly (blocked by .htaccess)

require_once __DIR__ . '/lib.php';

// The club operates in Alabama (Central) — anchor PHP's clock here
// explicitly rather than trusting the server's default (often UTC on
// shared hosting), which otherwise rolls "today" over hours before it
// actually is locally and throws off every date-based feature (upcoming
// birthdays, dues deadlines, etc).
date_default_timezone_set('America/Chicago');

// Never leak raw PHP warnings/notices/deprecations onto the page for
// visitors to see (exposes server file paths and looks broken) — still
// log everything server-side so issues remain diagnosable.
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

function start_session(): void {
    if (session_status() === PHP_SESSION_NONE) {
        // The host's default session.gc_maxlifetime is short enough (~10 min
        // observed) that an admin idling on a form — composing an email,
        // filling out a long member edit — can have their session file
        // garbage-collected out from under them, so their next submit fails
        // CSRF verification with no obvious cause. Extend it to 4 hours.
        // The session cookie itself stays browser-close-scoped (lifetime 0,
        // unchanged) — this only affects how long an open tab can idle, not
        // whether a session survives closing the browser.
        ini_set('session.gc_maxlifetime', '14400');
        session_name('usafa_admin');
        $is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict', 'secure' => $is_https]);
        session_start();
    }
}

function require_login(): void {
    start_session();
    if (empty($_SESSION['logged_in'])) {
        header('Location: login.php');
        exit;
    }
    revalidate_session_periodically();
}

// Session cookies are deliberately kept alive for hours (see
// start_session()'s comment) so an idling admin doesn't lose in-progress
// work to CSRF failures — but $_SESSION['role'] is otherwise only set once,
// at login, so that same long lifetime could let a deactivated or demoted
// account keep acting on its old privileges for the rest of that window.
// Re-checks the users table at most once every 5 minutes (this runs on
// every require_login() call, i.e. every admin page load) and forces a
// fresh login the moment a deactivated account is caught, or silently
// refreshes a merely-changed role/title so the next permission check sees
// it. During impersonation this re-validates the impersonated target
// (whose id/role $_SESSION currently holds), not the real admin — which is
// the intended check either way.
function revalidate_session_periodically(): void {
    $now = time();
    if ($now - (int)($_SESSION['role_checked_at'] ?? 0) < 300) return;

    $stmt = get_pdo()->prepare('SELECT role, officer_title, active FROM users WHERE id = ?');
    $stmt->execute([$_SESSION['user_id'] ?? 0]);
    $user = $stmt->fetch();

    if (!$user || !$user['active']) {
        session_unset();
        session_destroy();
        header('Location: login.php?deactivated=1');
        exit;
    }
    $_SESSION['role']          = $user['role'];
    $_SESSION['officer_title'] = $user['officer_title'] ?? '';
    $_SESSION['role_checked_at'] = $now;
}

function is_admin(): bool {
    return ($_SESSION['role'] ?? '') === 'admin';
}

// True while an admin is using "Login As" (impersonate.php) to view the
// portal as another user — see admin_header()'s banner and impersonate.php.
// Deliberately checks impersonator_id rather than the active role/is_admin(),
// since impersonating flips $_SESSION['role'] to the target user's role for
// the whole point of the feature: seeing exactly what that role sees.
function is_impersonating(): bool {
    return !empty($_SESSION['impersonator_id']);
}

function is_treasurer(): bool {
    return ($_SESSION['role'] ?? '') === 'treasurer';
}

function is_viewer(): bool {
    // "viewer" for member editing purposes: cannot edit members
    return !can_manage_members();
}

function require_member_admin(): void {
    require_login();
    if (!can_manage_members()) {
        header('Location: index.php?denied=1');
        exit;
    }
}

function is_member(): bool {
    return ($_SESSION['role'] ?? '') === 'member';
}

function is_secretary(): bool {
    return ($_SESSION['role'] ?? '') === 'secretary';
}

function is_tech(): bool {
    return ($_SESSION['role'] ?? '') === 'tech';
}

function is_officer(): bool {
    return ($_SESSION['role'] ?? '') === 'officer';
}

// The 4 board positions (President, VP, Secretary, Treasurer) — President
// and VP share the generic 'officer' role, Secretary and Treasurer have
// their own. Used to gate "board members only" polls (see polls.php/
// polls-manage.php). Deliberately excludes admin/tech — holding one of
// those login roles doesn't by itself make someone a board member.
function is_board_role(): bool {
    return in_array($_SESSION['role'] ?? '', ['officer', 'secretary', 'treasurer'], true);
}

// Admin or Tech have full super-admin access (users mgmt + helpdesk mgmt)
function is_super_admin(): bool {
    return is_admin() || is_tech();
}

// Board (officer/secretary/treasurer) + Admin/Tech — who may use the Digest
// Composer (digest-composer.php). Deliberately its own check rather than
// can_manage_members() (excludes Treasurer) or can_manage_finances()
// (includes plain Member) — this tool is for whoever sends club-wide
// communications, which is the full board plus super-admins. Composed from
// is_board_role()/is_super_admin() rather than its own role list so it can't
// silently drift out of sync if either of those changes later.
function can_use_digest_composer(): bool {
    return is_board_role() || is_super_admin();
}

function require_digest_composer_access(): void {
    require_login();
    if (!can_use_digest_composer()) {
        header('Location: index.php?denied=1');
        exit;
    }
}

// Admin, Tech, Officer — full club officer-level access
function is_club_officer(): bool {
    return is_super_admin() || is_officer();
}

// Admin, Tech, Officer, or Secretary can fully manage cadet/member records
function can_manage_members(): bool {
    return is_club_officer() || is_secretary();
}

// Admin and Tech only can manage helpdesk tickets
function can_manage_tickets(): bool {
    return is_super_admin();
}

// Treasurer (and anyone who can manage members) can toggle dues paid/unpaid
function can_mark_dues(): bool {
    return can_manage_members() || is_treasurer();
}

// Who may see a member's un-redacted PII (home address, birthday, both
// parents' contact info) — index.php and view.php's guard. Today this is
// the same population as can_mark_dues() (Treasurer's only current
// interface for marking dues happens to be that same page), but it's a
// distinct concern from "may toggle a dues flag": if a narrower dues-only
// surface is ever built for Treasurer, this can be redefined without
// touching every PII-gated page's guard.
function can_view_member_pii(): bool {
    return can_mark_dues();
}

function can_manage_finances(): bool {
    return in_array($_SESSION['role'] ?? '', ['admin', 'tech', 'officer', 'treasurer', 'member', 'secretary']);
}

// Admin/Treasurer can edit any purchase; Member/Secretary can only edit
// their own. Officer (President/VP specifically, not Tech) can edit only a
// still-Pending purchase — just enough that one redirected here to attach a
// missing receipt before approving isn't stuck read-only, without handing
// every officer full edit rights (vendor, amounts, receipt swap) over
// purchases that have already moved past their approval step.
function can_edit_purchase(array $purchase): bool {
    if (is_admin() || is_treasurer()) return true;
    if (is_officer()) return ($purchase['status'] ?? '') === 'pending';
    if (is_member() || is_secretary()) return (int)($purchase['submitted_by'] ?? -1) === (int)($_SESSION['user_id'] ?? 0);
    return false;
}

// Leadership (admin/tech/officer/treasurer/secretary) can view any purchase;
// a plain member can only view their own.
function can_view_purchase(array $purchase): bool {
    if (!is_member()) return true;
    return (int)($purchase['submitted_by'] ?? -1) === (int)($_SESSION['user_id'] ?? 0);
}

function current_user_name(): string {
    return $_SESSION['user_name'] ?? 'Unknown';
}

function require_admin(): void {
    require_login();
    if (!is_super_admin()) {
        header('Location: index.php?denied=1');
        exit;
    }
}

function require_finance(): void {
    require_login();
    if (!can_manage_finances()) {
        header('Location: index.php?denied=1');
        exit;
    }
}

// Find the cadet family record a portal login belongs to, if any. Uses the
// stored member_id link when present, otherwise falls back to matching the
// user's email against the family's parent emails (covers accounts created
// before the link existed).
function find_linked_member(PDO $pdo, array $user): ?array {
    if (!empty($user['member_id'])) {
        $stmt = $pdo->prepare('SELECT * FROM members WHERE id = ? AND archived = 0');
        $stmt->execute([$user['member_id']]);
        $m = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($m) return $m;
    }
    $email = strtolower(trim($user['email'] ?? ''));
    if ($email === '') return null;
    $stmt = $pdo->prepare(
        'SELECT * FROM members WHERE archived = 0 AND (LOWER(parent1_email) = ? OR LOWER(parent2_email) = ?) LIMIT 1'
    );
    $stmt->execute([$email, $email]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

// Verify credentials against the users table
function verify_login(PDO $pdo, string $username, string $password): ?array {
    $stmt = $pdo->prepare(
        'SELECT * FROM users WHERE (username = :u OR email = :u) AND active = 1 LIMIT 1'
    );
    $stmt->execute([':u' => $username]);
    $user = $stmt->fetch();
    if ($user && $user['password_hash'] && password_verify($password, $user['password_hash'])) {
        return $user;
    }
    return null;
}

const LOGIN_MAX_ATTEMPTS   = 5;
const LOGIN_LOCKOUT_MINUTES = 15;

// Look up an account's current lockout state by username or email
function login_attempt_status(PDO $pdo, string $username): ?array {
    $stmt = $pdo->prepare(
        'SELECT id, failed_attempts, locked_until FROM users WHERE (username = :u OR email = :u) AND active = 1 LIMIT 1'
    );
    $stmt->execute([':u' => $username]);
    return $stmt->fetch() ?: null;
}

function register_login_failure(PDO $pdo, int $user_id, int $failed_attempts): void {
    $failed_attempts++;
    $locked_until = $failed_attempts >= LOGIN_MAX_ATTEMPTS
        ? date('Y-m-d H:i:s', time() + LOGIN_LOCKOUT_MINUTES * 60)
        : null;
    $pdo->prepare('UPDATE users SET failed_attempts = ?, locked_until = ? WHERE id = ?')
        ->execute([$failed_attempts, $locked_until, $user_id]);
}

function register_login_success(PDO $pdo, int $user_id): void {
    $pdo->prepare('UPDATE users SET failed_attempts = 0, locked_until = NULL WHERE id = ?')->execute([$user_id]);
}

// Check if the users table exists and has at least one user
function users_table_empty(PDO $pdo): bool {
    try {
        return (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0;
    } catch (Exception $e) {
        return true;
    }
}

function get_pdo(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    require_once __DIR__ . '/config.php';
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
         PDO::ATTR_EMULATE_PREPARES => true]
    );
    return $pdo;
}

function h(?string $s): string {
    // Every current write path sanitizes with trim($x ?? '') before storage,
    // so no app-driven flow actually stores NULL in a nullable column — but
    // a direct DB edit/import could, and that shouldn't fatal a whole page.
    return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// Whether a URL is safe to save/link out to (http/https only) — used
// everywhere an admin-entered URL ends up in a public <a href>, since
// HTML-escaping alone doesn't stop a javascript: URL from being a valid
// (and dangerous) href value.
function is_safe_http_url(string $url): bool {
    return (bool)preg_match('#^https?://#i', $url);
}

function csrf_field(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return '<input type="hidden" name="csrf" value="' . $_SESSION['csrf'] . '">';
}

function csrf_verify(): void {
    if (empty($_POST['csrf']) || !hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf'])) {
        http_response_code(403); die('Invalid request token.');
    }
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

function flash(string $type, string $msg): void {
    $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
}

function show_flash(): string {
    if (empty($_SESSION['flash'])) return '';
    $f = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return '<div class="alert alert-' . h($f['type']) . '">' . h($f['msg']) . '</div>';
}

function admin_css(): string { return '
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:"Segoe UI",Arial,sans-serif;background:#f0f2f5;color:#1a2332;font-size:15px}
a{color:#003594;text-decoration:none}
a:hover{text-decoration:underline}
.topbar{background:#002554;color:#fff;padding:.75rem 1.5rem;display:flex;justify-content:space-between;align-items:center;gap:1rem}
.topbar-title{font-weight:700;font-size:1rem;letter-spacing:.03em}
.topbar-title small{font-weight:400;opacity:.65;margin-left:.5rem;font-size:.8rem}
.topbar nav{display:flex;gap:1.25rem;align-items:center}
.topbar nav a{color:rgba(255,255,255,.8);font-size:.85rem}
.topbar nav a:hover{color:#fff;text-decoration:none}
.main{max-width:1300px;margin:2rem auto;padding:0 1rem}
.page-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:1.25rem;flex-wrap:wrap;gap:.75rem}
h1{font-size:1.4rem;color:#002554}
h2{font-size:1rem;color:#002554;margin-bottom:.75rem;font-weight:700}
.card{background:#fff;border-radius:6px;box-shadow:0 1px 4px rgba(0,0,0,.1);padding:1.5rem;margin-bottom:1.5rem}
table{width:100%;border-collapse:collapse;font-size:.85rem}
th{text-align:left;padding:.55rem .75rem;background:#f5f7fa;border-bottom:2px solid #e1e5eb;color:#5a6a7a;font-weight:700;font-size:.7rem;text-transform:uppercase;letter-spacing:.05em;white-space:nowrap}
td{padding:.55rem .75rem;border-bottom:1px solid #f0f2f5;vertical-align:top}
tr:last-child td{border-bottom:none}
tbody tr:hover td{background:#f8f9fb}
.btn{display:inline-flex;align-items:center;gap:.3rem;padding:.45rem 1rem;border-radius:4px;font-size:.82rem;font-weight:600;border:none;cursor:pointer;letter-spacing:.02em;text-decoration:none}
.btn:hover{text-decoration:none}
.btn-primary{background:#003594;color:#fff}
.btn-primary:hover{background:#002268}
.btn-danger{background:#A6192E;color:#fff}
.btn-danger:hover{background:#8a1425}
.btn-secondary{background:#f0f2f5;color:#333;border:1px solid #d0d5dd}
.btn-secondary:hover{background:#e5e8ec}
.btn-sm{padding:.28rem .65rem;font-size:.75rem}
.btn-group{display:flex;gap:.4rem}
input,select,textarea{width:100%;padding:.55rem .75rem;border:1px solid #d0d5dd;border-radius:4px;font-family:inherit;font-size:.9rem;color:#1a2332;background:#fff}
input:focus,select:focus,textarea:focus{outline:none;border-color:#003594;box-shadow:0 0 0 2px rgba(0,53,148,.15)}
textarea{resize:vertical;min-height:75px}
label{display:block;font-size:.78rem;font-weight:700;color:#5a6a7a;margin-bottom:.3rem;text-transform:uppercase;letter-spacing:.04em}
.form-group{margin-bottom:.9rem}
.form-row{display:grid;gap:.9rem}
.col-2{grid-template-columns:1fr 1fr}
.col-3{grid-template-columns:1fr 1fr 1fr}
.col-4{grid-template-columns:1fr 1fr 1fr 1fr}
fieldset{border:1px solid #e1e5eb;border-radius:6px;padding:1.1rem 1.25rem;margin-bottom:1.25rem}
legend{font-weight:700;color:#002554;font-size:.82rem;padding:0 .4rem;letter-spacing:.04em;text-transform:uppercase}
.alert{padding:.75rem 1rem;border-radius:4px;margin-bottom:1rem;font-size:.9rem}
.alert-success{background:#e8f5e9;color:#2e7d32;border-left:4px solid #4caf50}
.alert-error{background:#ffebee;color:#c62828;border-left:4px solid #f44336}
.badge{display:inline-block;padding:.15rem .45rem;border-radius:3px;font-size:.7rem;font-weight:700;white-space:nowrap}
.badge-North{background:#e3f2fd;color:#0d47a1}
.badge-Central{background:#f3e5f5;color:#4a148c}
.badge-South{background:#e8f5e9;color:#1b5e20}
.badge-paid{background:#e8f5e9;color:#1b5e20}
.badge-unpaid{background:#ffebee;color:#c62828}
.filter-bar{display:flex;gap:.75rem;flex-wrap:wrap;align-items:flex-end;margin-bottom:1.25rem}
.filter-bar .form-group{margin:0;flex:1;min-width:130px}
.count{font-size:.82rem;color:#5a6a7a;margin-top:.5rem}
.actions{white-space:nowrap;position:sticky;right:0;background:#fff;box-shadow:-3px 0 6px rgba(0,0,0,.06)}
tbody tr:hover .actions{background:#f8f9fb}
th.actions-head{position:sticky;right:0;background:#f5f7fa;box-shadow:-3px 0 6px rgba(0,0,0,.06)}
@media(max-width:640px){.col-2,.col-3,.col-4{grid-template-columns:1fr}.topbar{flex-direction:column;align-items:flex-start}}
</style>
'; }

function admin_header(string $title): void {
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">';
    echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>' . h($title) . ' — Members Admin</title>';
    echo '<link rel="icon" type="image/png" href="../logo01.png" />';
    echo admin_css();
    echo '</head><body>';
    echo '<div class="topbar">';
    echo '<a href="dashboard.php" class="topbar-title" style="color:#fff;text-decoration:none;display:flex;align-items:center;gap:.65rem"><img src="../logo01.png" alt="" style="height:32px;border-radius:3px"><span>USAFA Parents Club of Alabama <small>Club Portal</small></span></a>';
    echo '<nav>';
    echo '<a href="dashboard.php" title="Home">🏠</a>';
    echo '<a href="../index.html" style="font-size:.75rem;opacity:.55;color:rgba(255,255,255,.8);text-decoration:none" title="Go to the public website">View Site</a>';
    if (can_view_member_pii()) echo '<a href="index.php">Members</a>';
    if (can_manage_finances() && !is_member()) {
        $pending_cnt = 0;
        try { $pending_cnt = (int)get_pdo()->query("SELECT COUNT(*) FROM purchases WHERE status='pending'")->fetchColumn(); } catch(Exception $e) {}
        $badge = $pending_cnt > 0 ? ' <span style="background:#A6192E;color:#fff;font-size:.6rem;padding:.1rem .4rem;border-radius:99px;vertical-align:middle;font-weight:700">' . $pending_cnt . '</span>' : '';
        echo '<a href="purchases.php">Finance' . $badge . '</a>';
    }
    $open_tickets = 0;
    try { $open_tickets = (int)get_pdo()->query("SELECT COUNT(*) FROM tickets WHERE status != 'resolved'")->fetchColumn(); } catch(Exception $e) {}
    $tbadge = (can_manage_tickets() && $open_tickets > 0) ? ' <span style="background:#f57c00;color:#fff;font-size:.6rem;padding:.1rem .4rem;border-radius:99px;vertical-align:middle;font-weight:700">' . $open_tickets . '</span>' : '';
    echo '<a href="helpdesk.php">🎫 Support' . $tbadge . '</a>';
    echo '<a href="change-password.php" style="font-size:.75rem;opacity:.55;color:rgba(255,255,255,.8);text-decoration:none;margin-left:.25rem" title="Change password">' . h(current_user_name()) . ' 🔑</a>';
    echo '<form method="POST" action="logout.php" style="display:inline;margin:0">' . csrf_field()
       . '<button type="submit" style="background:none;border:none;padding:0;font:inherit;color:rgba(255,255,255,.8);font-size:.85rem;cursor:pointer">Log Out</button></form>';
    echo '</nav></div>';
    if (is_impersonating()) {
        echo '<div style="background:#f57c00;color:#fff;padding:.6rem 1.5rem;display:flex;justify-content:space-between;align-items:center;gap:1rem;font-size:.85rem;flex-wrap:wrap">';
        echo '<span>🔀 Viewing as <strong>' . h(current_user_name()) . '</strong> (' . h(ucfirst($_SESSION['role'] ?? '')) . ') — testing what this role sees.</span>';
        echo '<form method="POST" action="impersonate.php" style="margin:0">' . csrf_field()
           . '<input type="hidden" name="action" value="stop">'
           . '<button type="submit" style="background:#fff;color:#bf360c;border:none;padding:.35rem .9rem;border-radius:4px;font-size:.8rem;font-weight:700;cursor:pointer">Return to My Account</button></form>';
        echo '</div>';
    }
    echo '<div class="main">';
}

function admin_footer(): void {
    echo '</div></body></html>';
}

const ELECTION_POSITIONS = ['President', 'Vice President', 'Secretary', 'Treasurer'];

// Shared by my-committees.php (member self-service) and volunteers.php
// (admin tagging raw public submissions) so both stay in sync.
const COMMITTEES = ['Fundraising', 'Events & Socials', 'Communications', 'Care Packages', 'Membership Outreach', 'Sendoff Support'];

const REGIONS = ['', 'North', 'Central', 'South'];
const PAYMENT_METHODS   = ['', 'Check', 'Venmo', 'PayPal'];
const TICKET_CATEGORIES = ['', 'Website Issue', 'Admin Portal Issue', 'Email / Google Workspace', 'Login / Password Help', 'Add Event to Calendar', 'Site Suggestion', 'Other'];
const TICKET_STATUSES   = ['open'=>'Open','in_progress'=>'In Progress','resolved'=>'Resolved'];
const TICKET_PRIORITIES = ['low'=>'Low','medium'=>'Medium','high'=>'High'];
const PURCHASE_CATEGORIES = ['', 'Supplies', 'Food & Beverages', 'Decorations', 'Postage / Shipping', 'Printing', 'Equipment', 'Venue / Facility', 'Transportation', 'Awards / Recognition', 'Technology / Domain Hosting', 'Non-Profit Fees', 'Other'];
const PURCHASE_EVENTS     = ['', 'Parents Weekend', 'Care Packages', 'Appointee Send-off', 'Taste of Home', 'Birthday / Gift', 'General Operations', 'Other'];
const PURCHASE_STATUSES   = ['pending' => 'Pending', 'approved' => 'Approved', 'submitted' => 'Submitted', 'paid' => 'Paid'];
// Shared between income.php (the ledger) and manual-receipts.php (the
// receipt-sending tool) so their type/color/method lists can't drift apart.
const INCOME_SOURCE_TYPES = [
    'dues'        => 'Dues',
    'sponsorship' => 'Sponsorship',
    'event_fee'   => 'Event Fee',
    'donation'    => 'Donation',
    'other'       => 'Other',
];
const INCOME_TYPE_COLORS = [
    'dues'        => '#1565c0',
    'sponsorship' => '#6a1b9a',
    'event_fee'   => '#1b5e20',
    'donation'    => '#e65100',
    'other'       => '#5a6a7a',
];
const INCOME_PAYMENT_METHODS = ['Check','Cash','Venmo','Zelle','PayPal','Bank Transfer','Other'];
// Sequence position of each purchase status — used both to render the
// progress stepper and to stop a Treasurer's raw Status-field edit from
// skipping the workflow forward (see purchase-form.php).
const STATUS_ORDER = ['pending' => 0, 'approved' => 1, 'submitted' => 2, 'paid' => 3];
// 2031 is deliberately absent: the class that will become 2031 is still at
// Prep School and hasn't matriculated yet, so "2031" and "Prep School"
// would otherwise represent the exact same cohort under two labels.
// Re-add it once that class actually starts at the Academy.
// 2026 is also deliberately absent: that class graduated and every member
// has been moved to Graduate + archived (see graduate-class.php), so no
// active record should ever hold class_year='2026' again.
const CLASS_YEARS     = ['', '2027', '2028', '2029', '2030', 'Prep School', 'Graduate'];
const CLASS_YEAR_LIST = ['2027', '2028', '2029', '2030', 'Prep School', 'Graduate'];

// Used for the Cadet "Gender" field, which drives pronoun placeholders
// ({he_she}, {him_her}, {his_her}) in the automated birthday emails. Left
// blank on a profile, those placeholders fall back to "they/them/their".
const GENDERS = ['', 'Male', 'Female'];

// "Missing Data" filter — shared by the Member Directory and Compose Email
// pages so both stay in sync on the same set of options/SQL.
const MISSING_DATA_OPTIONS = [
    ''            => 'Any',
    'po_box'      => 'PO Box',
    'cadet_email' => 'Cadet Email',
    'cadet_phone' => 'Cadet Phone',
    'birthday'    => 'Birthday',
    'squadron'    => 'Squadron',
];

function missing_data_sql(string $key): ?string {
    switch ($key) {
        case 'po_box':      return "(cadet_po_box IS NULL OR cadet_po_box = '')";
        case 'cadet_email': return "(cadet_email IS NULL OR cadet_email = '')";
        case 'cadet_phone': return "(cadet_cell IS NULL OR cadet_cell = '')";
        case 'birthday':    return '(cadet_birthday IS NULL)';
        case 'squadron':    return "(bct_squadron IS NULL OR bct_squadron = '') AND (fall_squadron IS NULL OR fall_squadron = '') AND (squadron_yr2_4 IS NULL OR squadron_yr2_4 = '')";
        default:            return null;
    }
}

const FIELDS = [
    'class_year','cadet_last_name','cadet_suffix','cadet_first_name','cadet_middle_name','nickname','cadet_gender','cadet_birthday','cadet_po_box',
    'cadet_email','cadet_cell','bct_squadron','bct_flight','fall_squadron','squadron_yr2_4',
    'parent1_last_name','parent1_first_name','parent1_email','parent1_cell',
    'parent1_street','parent1_city','parent1_state','parent1_zip',
    'parent2_last_name','parent2_first_name','parent2_email','parent2_cell',
    'parent2_street','parent2_city','parent2_state','parent2_zip',
    'al_region','remarks','photo_consent','directory_consent','parent1_is_board_member','parent2_is_board_member'
    // Deliberately excludes membership_paid / membership_year /
    // membership_paid_through / membership_paid_years — those are all
    // written together by save_dues_years(), never as plain form fields
    // (see the "Years Paid" checkboxes in member_form()).
];

// Membership dues helpers (cadet_dues_years, parse_dues_years,
// dues_years_price, save_dues_years, membership_year) live in lib.php now,
// not here — admin/mailer.php's automated reminder functions need them
// too, and mailer.php is required directly by the CLI cron entry point
// (admin/automated-emails-cron.php), which never loads this file.

// ── Homepage "Member Photos" slideshow (site_photos table) ────────────────
// Shared by admin/gallery.php (direct uploads) and admin/photo-submissions.php
// (approved member submissions) so both paths enforce the same 30-day /
// max-count limits.
function get_gallery_limit(PDO $pdo): int {
    $row = $pdo->query("SELECT setting_value FROM site_settings WHERE setting_key='gallery_max_photos'")->fetch();
    $val = $row ? (int)$row['setting_value'] : 20;
    return max(1, min(100, $val));
}

function gallery_cleanup(PDO $pdo, string $dir, int $limit): void {
    // Delete photos older than 30 days
    $old = $pdo->query("SELECT id, filename FROM site_photos WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)")->fetchAll();
    foreach ($old as $p) {
        if (preg_match('/^[a-zA-Z0-9._-]+$/', $p['filename'])) @unlink($dir . $p['filename']);
        $pdo->prepare('DELETE FROM site_photos WHERE id=?')->execute([$p['id']]);
    }
    // Enforce photo limit — delete oldest beyond limit
    $count = (int)$pdo->query('SELECT COUNT(*) FROM site_photos')->fetchColumn();
    if ($count > $limit) {
        $excess = $pdo->query("SELECT id, filename FROM site_photos ORDER BY id ASC LIMIT " . (int)($count - $limit))->fetchAll();
        foreach ($excess as $p) {
            if (preg_match('/^[a-zA-Z0-9._-]+$/', $p['filename'])) @unlink($dir . $p['filename']);
            $pdo->prepare('DELETE FROM site_photos WHERE id=?')->execute([$p['id']]);
        }
    }
}

function member_form(array $m = [], bool $is_edit = false): void {
    $v = function(string $k) use ($m): string {
        return h($m[$k] ?? '');
    };
    $sel = function(string $field, array $opts) use ($m): string {
        $out = '<select name="' . $field . '">';
        foreach ($opts as $o) {
            $s = ($m[$field] ?? '') === $o ? ' selected' : '';
            $out .= '<option value="' . h($o) . '"' . $s . '>' . ($o === '' ? '— select —' : h($o)) . '</option>';
        }
        return $out . '</select>';
    };

    echo '<fieldset><legend>Cadet</legend>';
    echo '<div class="form-row col-4">';
    echo '<div class="form-group"><label>Class Year *</label>' . $sel('class_year', CLASS_YEARS) . '</div>';
    echo '<div class="form-group"><label>Last Name *</label><input name="cadet_last_name" value="' . $v('cadet_last_name') . '" required></div>';
    echo '<div class="form-group"><label>First Name</label><input name="cadet_first_name" value="' . $v('cadet_first_name') . '"></div>';
    echo '<div class="form-group"><label>Middle Name</label><input name="cadet_middle_name" value="' . $v('cadet_middle_name') . '"></div>';
    echo '</div>';
    echo '<div class="form-row col-4">';
    echo '<div class="form-group"><label>Nickname</label><input name="nickname" value="' . $v('nickname') . '"></div>';
    echo '<div class="form-group"><label>Birthday</label><input type="date" name="cadet_birthday" value="' . $v('cadet_birthday') . '"></div>';
    echo '<div class="form-group"><label>PO Box</label><input name="cadet_po_box" value="' . $v('cadet_po_box') . '"></div>';
    echo '<div class="form-group"><label>Cell</label><input type="tel" name="cadet_cell" value="' . $v('cadet_cell') . '"></div>';
    echo '</div>';
    echo '<div class="form-row col-3">';
    echo '<div class="form-group"><label>Suffix <span style="font-weight:400;font-size:.72rem;color:#9aa5b4">e.g. Jr., III</span></label><input name="cadet_suffix" value="' . $v('cadet_suffix') . '" maxlength="20"></div>';
    echo '<div class="form-group"><label>Email</label><input type="email" name="cadet_email" value="' . $v('cadet_email') . '"></div>';
    echo '<div class="form-group"><label>Gender</label>' . $sel('cadet_gender', GENDERS) . '</div>';
    echo '</div>';
    echo '</fieldset>';

    echo '<fieldset><legend>Squadron Assignments</legend>';
    echo '<div class="form-row col-4">';
    echo '<div class="form-group"><label>BCT Squadron</label><input name="bct_squadron" value="' . $v('bct_squadron') . '"></div>';
    echo '<div class="form-group"><label>BCT Flight</label><input name="bct_flight" value="' . $v('bct_flight') . '"></div>';
    echo '<div class="form-group"><label>Fall Squadron</label><input name="fall_squadron" value="' . $v('fall_squadron') . '"></div>';
    echo '<div class="form-group"><label>Yr 2–4 Squadron</label><input name="squadron_yr2_4" value="' . $v('squadron_yr2_4') . '"></div>';
    echo '</div></fieldset>';

    echo '<fieldset><legend>Parent / Contact 1</legend>';
    echo '<div class="form-row col-4">';
    echo '<div class="form-group"><label>Last Name</label><input name="parent1_last_name" value="' . $v('parent1_last_name') . '"></div>';
    echo '<div class="form-group"><label>First Name</label><input name="parent1_first_name" value="' . $v('parent1_first_name') . '"></div>';
    echo '<div class="form-group"><label>Email</label><input type="email" name="parent1_email" value="' . $v('parent1_email') . '"></div>';
    echo '<div class="form-group"><label>Cell</label><input type="tel" name="parent1_cell" value="' . $v('parent1_cell') . '"></div>';
    echo '</div>';
    echo '<div class="form-row col-4">';
    echo '<div class="form-group"><label>Street</label><input name="parent1_street" value="' . $v('parent1_street') . '"></div>';
    echo '<div class="form-group"><label>City</label><input name="parent1_city" value="' . $v('parent1_city') . '"></div>';
    echo '<div class="form-group"><label>State</label><input name="parent1_state" maxlength="2" value="' . $v('parent1_state') . '"></div>';
    echo '<div class="form-group"><label>Zip</label><input name="parent1_zip" value="' . $v('parent1_zip') . '"></div>';
    echo '</div></fieldset>';

    // Detect if parent 2 already matches parent 1's last name / address. For
    // a brand-new member (Add Member, no data yet) default to "Same" — most
    // families share a last name and address, and this matches the public
    // Application/Update forms' default. Editing an existing member still
    // reflects real data.
    $last_same = $is_edit
        ? (!empty($m['parent1_last_name']) && ($m['parent1_last_name'] ?? '') === ($m['parent2_last_name'] ?? ''))
        : true;
    $addr_same = $is_edit
        ? (!empty($m['parent1_street'])
            && ($m['parent1_street'] ?? '') === ($m['parent2_street'] ?? '')
            && ($m['parent1_city']   ?? '') === ($m['parent2_city']   ?? ''))
        : true;

    echo '<fieldset><legend>Parent / Contact 2</legend>';
    echo '<div class="form-group" style="margin-bottom:.75rem">';
    echo '<label>Is the Last Name the Same as Parent 1?</label>';
    echo '<div style="display:flex;gap:1.5rem;margin-top:.3rem">';
    echo '<label style="display:flex;align-items:center;gap:.4rem;font-weight:400;font-size:.9rem;text-transform:none;letter-spacing:0;cursor:pointer">'
       . '<input type="radio" name="p2_last_same" value="1" style="width:auto" onchange="syncP2Last(this)"' . ($last_same ? ' checked' : '') . '> Same</label>';
    echo '<label style="display:flex;align-items:center;gap:.4rem;font-weight:400;font-size:.9rem;text-transform:none;letter-spacing:0;cursor:pointer">'
       . '<input type="radio" name="p2_last_same" value="0" style="width:auto" onchange="syncP2Last(this)"' . (!$last_same ? ' checked' : '') . '> Different</label>';
    echo '</div></div>';
    echo '<div class="form-row col-4">';
    echo '<div class="form-group" id="p2-last-wrap" style="' . ($last_same ? 'opacity:.5;pointer-events:none' : '') . '"><label>Last Name</label><input id="p2_last_name" name="parent2_last_name" value="' . $v('parent2_last_name') . '"></div>';
    echo '<div class="form-group"><label>First Name</label><input name="parent2_first_name" value="' . $v('parent2_first_name') . '"></div>';
    echo '<div class="form-group"><label>Email</label><input type="email" name="parent2_email" value="' . $v('parent2_email') . '"></div>';
    echo '<div class="form-group"><label>Cell</label><input type="tel" name="parent2_cell" value="' . $v('parent2_cell') . '"></div>';
    echo '</div>';
    echo '<div class="form-group" style="margin:.25rem 0 .75rem">';
    echo '<label>Address</label>';
    echo '<div style="display:flex;gap:1.5rem;margin-top:.3rem">';
    echo '<label style="display:flex;align-items:center;gap:.4rem;font-weight:400;font-size:.9rem;text-transform:none;letter-spacing:0;cursor:pointer">'
       . '<input type="radio" name="p2_addr_same" value="1" style="width:auto" onchange="syncP2Addr(this)"' . ($addr_same ? ' checked' : '') . '> Same as Parent 1</label>';
    echo '<label style="display:flex;align-items:center;gap:.4rem;font-weight:400;font-size:.9rem;text-transform:none;letter-spacing:0;cursor:pointer">'
       . '<input type="radio" name="p2_addr_same" value="0" style="width:auto" onchange="syncP2Addr(this)"' . (!$addr_same ? ' checked' : '') . '> Different address</label>';
    echo '</div></div>';
    echo '<div id="p2-addr-fields" style="' . ($addr_same ? 'opacity:.5;pointer-events:none' : '') . '">';
    echo '<div class="form-row col-4">';
    echo '<div class="form-group"><label>Street</label><input id="p2_street" name="parent2_street" value="' . $v('parent2_street') . '"></div>';
    echo '<div class="form-group"><label>City</label><input id="p2_city" name="parent2_city" value="' . $v('parent2_city') . '"></div>';
    echo '<div class="form-group"><label>State</label><input id="p2_state" name="parent2_state" maxlength="2" value="' . $v('parent2_state') . '"></div>';
    echo '<div class="form-group"><label>Zip</label><input id="p2_zip" name="parent2_zip" value="' . $v('parent2_zip') . '"></div>';
    echo '</div></div></fieldset>';
    echo '<script>
function syncP2Last(radio) {
  var same = radio.value === "1";
  var wrap = document.getElementById("p2-last-wrap");
  wrap.style.opacity = same ? ".5" : "1";
  wrap.style.pointerEvents = same ? "none" : "auto";
  if (same) {
    document.getElementById("p2_last_name").value = document.querySelector("[name=parent1_last_name]").value;
  }
}
(function(){ var r = document.querySelector("[name=p2_last_same]:checked"); if (r) syncP2Last(r); })();
(function(){
  var el = document.querySelector("[name=parent1_last_name]");
  if (el) el.addEventListener("input", function(){
    var r = document.querySelector("[name=p2_last_same]:checked");
    if (r && r.value === "1") syncP2Last(r);
  });
})();

function syncP2Addr(radio) {
  var same = radio.value === "1";
  var wrap = document.getElementById("p2-addr-fields");
  wrap.style.opacity = same ? ".5" : "1";
  wrap.style.pointerEvents = same ? "none" : "auto";
  if (same) {
    document.getElementById("p2_street").value = document.querySelector("[name=parent1_street]").value;
    document.getElementById("p2_city").value   = document.querySelector("[name=parent1_city]").value;
    document.getElementById("p2_state").value  = document.querySelector("[name=parent1_state]").value;
    document.getElementById("p2_zip").value    = document.querySelector("[name=parent1_zip]").value;
  }
}
// Sync on load if "same" is pre-selected
(function(){ var r = document.querySelector("[name=p2_addr_same]:checked"); if (r) syncP2Addr(r); })();
// Keep Parent 2 synced live while typing Parent 1\'s address, if "Same" is selected
["parent1_street","parent1_city","parent1_state","parent1_zip"].forEach(function(name){
  var el = document.querySelector("[name=" + name + "]");
  if (el) el.addEventListener("input", function(){
    var r = document.querySelector("[name=p2_addr_same]:checked");
    if (r && r.value === "1") syncP2Addr(r);
  });
});
</script>';

    echo '<fieldset><legend>Region, Consents &amp; Notes</legend>';
    echo '<div class="form-row col-2">';
    echo '<div class="form-group"><label>AL Region</label>' . $sel('al_region', REGIONS) . '</div>';
    echo '<div class="form-group"><label>Remarks</label><textarea name="remarks">' . $v('remarks') . '</textarea></div>';
    echo '</div>';
    $board1 = !empty($m['parent1_is_board_member']);
    $board2 = !empty($m['parent2_is_board_member']);
    echo '<div class="form-group" style="margin-top:.5rem">';
    echo '<label>Board Member</label>';
    echo '<div style="display:flex;gap:1.5rem;margin-top:.4rem">';
    echo '<label style="display:flex;align-items:center;gap:.4rem;font-weight:400;font-size:.95rem;text-transform:none;letter-spacing:0;cursor:pointer">'
       . '<input type="checkbox" name="parent1_is_board_member" value="1" style="width:auto"' . ($board1 ? ' checked' : '') . '> Parent 1</label>';
    echo '<label style="display:flex;align-items:center;gap:.4rem;font-weight:400;font-size:.95rem;text-transform:none;letter-spacing:0;cursor:pointer">'
       . '<input type="checkbox" name="parent2_is_board_member" value="1" style="width:auto"' . ($board2 ? ' checked' : '') . '> Parent 2</label>';
    echo '</div></div>';
    echo '<div class="form-row col-2" style="margin-top:.5rem">';
    $pc = $m['photo_consent'] ?? '';
    echo '<div class="form-group"><label>Photo Consent</label><div style="display:flex;gap:1.5rem;margin-top:.4rem">';
    echo '<label style="display:flex;align-items:center;gap:.4rem;font-weight:400;font-size:.95rem;text-transform:none;letter-spacing:0;cursor:pointer"><input type="radio" name="photo_consent" value="Yes" style="width:auto" ' . ($pc==='Yes'?'checked':'') . '> Yes</label>';
    echo '<label style="display:flex;align-items:center;gap:.4rem;font-weight:400;font-size:.95rem;text-transform:none;letter-spacing:0;cursor:pointer"><input type="radio" name="photo_consent" value="No" style="width:auto" ' . ($pc==='No'?'checked':'') . '> No</label>';
    echo '</div></div>';
    $dc = $m['directory_consent'] ?? '';
    echo '<div class="form-group"><label>Directory Consent</label><div style="display:flex;gap:1.5rem;margin-top:.4rem">';
    echo '<label style="display:flex;align-items:center;gap:.4rem;font-weight:400;font-size:.95rem;text-transform:none;letter-spacing:0;cursor:pointer"><input type="radio" name="directory_consent" value="Yes" style="width:auto" ' . ($dc==='Yes'?'checked':'') . '> Yes</label>';
    echo '<label style="display:flex;align-items:center;gap:.4rem;font-weight:400;font-size:.95rem;text-transform:none;letter-spacing:0;cursor:pointer"><input type="radio" name="directory_consent" value="No" style="width:auto" ' . ($dc==='No'?'checked':'') . '> No</label>';
    echo '</div></div>';
    echo '</div></fieldset>';

    dues_years_fieldset($m);
}

// The "Membership Dues" per-year checkbox fieldset — factored out of
// member_form() so it can also stand alone on admin/edit-dues.php, a
// treasurer-accessible page that only touches dues (Treasurer alone
// doesn't pass can_manage_members(), so it can't reach the full Edit
// Member form member_form() lives on, but it does pass can_mark_dues()
// and needs a way to check/uncheck a specific year rather than only the
// blunt "mark current year" / "mark all 4" bulk actions on the roster).
function dues_years_fieldset(array $m): void {
    $cadet_years = cadet_dues_years($m['class_year'] ?? '');
    $paid_years  = parse_dues_years($m['membership_paid_years'] ?? '');
    $cur_year    = membership_year();

    echo '<fieldset><legend>Membership Dues</legend>';
    if (empty($cadet_years)) {
        echo '<p style="color:#9aa5b4;font-size:.85rem">Set a Class Year above to manage dues years for this cadet.</p>';
        if ($paid_years) {
            echo '<p style="font-size:.85rem;color:#5a6a7a;margin-top:.5rem">Dues history on file (read-only once graduated): <strong>' . h(implode(', ', $paid_years)) . '</strong></p>';
        }
    } else {
        $is_paid_now = in_array($cur_year, $paid_years, true);
        echo '<div style="margin-bottom:.75rem"><span class="badge ' . ($is_paid_now ? 'badge-paid' : 'badge-unpaid') . '">'
           . ($is_paid_now ? '✓ Paid for ' . h($cur_year) : '✗ Not Paid for ' . h($cur_year)) . '</span></div>';
        echo '<div class="form-group"><label>Years Paid <span style="font-weight:400;font-size:.72rem;color:#9aa5b4">'
           . '$75 each — check all 4 undergrad years for the $275 bulk rate. Reflects the Class Year above as of page load; save and reopen if you just changed it.</span></label>';
        echo '<div style="display:flex;gap:1.25rem;flex-wrap:wrap;margin-top:.4rem" id="dues-year-checks">';
        foreach ($cadet_years as $yr) {
            $checked = in_array($yr, $paid_years, true);
            $tag = $yr === $cur_year ? ' <span style="font-weight:400;font-size:.72rem;color:#1565c0">(current)</span>' : '';
            echo '<label style="display:flex;align-items:center;gap:.4rem;font-weight:600;font-size:.9rem;text-transform:none;letter-spacing:0;cursor:pointer">'
               . '<input type="checkbox" name="dues_years[]" value="' . h($yr) . '" style="width:auto" onchange="calcDuesTotal()"' . ($checked ? ' checked' : '') . '> '
               . h($yr) . $tag . '</label>';
        }
        echo '</div></div>';
        echo '<button type="button" class="btn btn-secondary btn-sm" style="margin-top:.5rem" onclick="checkAllUndergradDues()">Check all 4 undergrad years ($275)</button>';
        echo '<div style="margin-top:.65rem;font-size:.85rem;color:#5a6a7a">Total: $<strong id="dues-total-preview">' . dues_years_price($paid_years, $cadet_years) . '</strong></div>';
        echo '<script>
function calcDuesTotal() {
  var boxes = document.querySelectorAll("#dues-year-checks input[type=checkbox]");
  var checked = []; var all = [];
  boxes.forEach(function(b){ all.push(b.value); if (b.checked) checked.push(b.value); });
  var undergrad = all.slice(-4);
  var hasFullUndergrad = undergrad.length === 4 && undergrad.every(function(y){ return checked.indexOf(y) !== -1; });
  var total;
  if (hasFullUndergrad) {
    total = 275;
    if (all.length === 5 && checked.indexOf(all[0]) !== -1) total += 75;
  } else {
    total = checked.length * 75;
  }
  document.getElementById("dues-total-preview").textContent = total;
}
function checkAllUndergradDues() {
  var boxes = document.querySelectorAll("#dues-year-checks input[type=checkbox]");
  var all = []; boxes.forEach(function(b){ all.push(b); });
  all.slice(-4).forEach(function(b){ b.checked = true; });
  calcDuesTotal();
}
</script>';
    }
    echo '</fieldset>';
}
