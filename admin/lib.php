<?php
// Tiny dependency-free helpers shared by both the authenticated admin panel
// (auth.php) and the public-facing form handlers (e.g. membership-handler.php)
// that intentionally don't pull in the full admin bootstrap.

// Strips punctuation/whitespace differences so "Jimmerson, Jr" and
// "Jimmerson, Jr." compare equal for duplicate-member detection.
function normalize_name(string $s): string {
    $s = preg_replace('/[.,]/', '', $s);
    return strtolower(trim(preg_replace('/\s+/', ' ', $s)));
}

// Drops a trailing suffix token from an already-normalize_name()'d last
// name, so a legacy record where the suffix is still crammed into
// cadet_last_name (e.g. "jimmerson jr", from before cadet_suffix existed)
// compares equal to the same family's clean "jimmerson" + a separate
// suffix field. Used wherever a submitted/looked-up last name is matched
// against what's on file, so old dirty data doesn't silently mismatch.
function strip_name_suffix(string $normalized): string {
    return trim(preg_replace('/\s+(jr|sr|ii|iii|iv|v)$/i', '', $normalized));
}

// Starts a session under its own cookie name, distinct from the admin
// panel's 'usafa_admin' session (auth.php's start_session()) — used to bind
// a successful public-form identity lookup (see update-lookup.php) to the
// specific browser that performed it, so update-handler.php doesn't have to
// trust resubmitted last-name/year/email values that could otherwise be
// fabricated or replayed directly against that endpoint without ever
// passing a real lookup. Deliberately a separate cookie so a board member
// browsing the public site in one tab while logged into /admin/ in another
// never shares session state between the two.
function start_verification_session(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_name('usafa_verify');
        $is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict', 'secure' => $is_https]);
        session_start();
    }
}

// Cadet's full name — "First Middle Last Suffix", whitespace-collapsed.
// Built in one place so a display site (or an automated email — this file
// is required directly by the cron entry point, which never loads
// auth.php) can't forget cadet_suffix the way over a dozen separately
// hand-rolled concatenations did before this existed. Accepts any array
// with the usual cadet_* keys (a `members` row, or a narrower SELECT that
// still includes them).
function cadet_full_name(array $m): string {
    return trim(preg_replace('/\s+/', ' ',
        ($m['cadet_first_name'] ?? '') . ' ' . ($m['cadet_middle_name'] ?? '') . ' ' .
        ($m['cadet_last_name'] ?? '') . ' ' . ($m['cadet_suffix'] ?? '')
    ));
}

// "Last Suffix" — the leading half of the "Last, First Middle" display
// convention used in tables, headings, and dropdowns throughout admin/.
function cadet_last_name_suffixed(array $m): string {
    return trim(($m['cadet_last_name'] ?? '') . ' ' . ($m['cadet_suffix'] ?? ''));
}

// [he/she, him/her, his/her] for the {he_she}/{him_her}/{his_her} placeholders
// in automated emails. Falls back to gender-neutral "they/them/their" when
// cadet_gender is blank, so older profiles that predate the field (or simply
// leave it unset) still produce a grammatical email instead of a blank spot.
function cadet_pronouns(string $gender): array {
    switch (strtolower(trim($gender))) {
        case 'male':   return ['he', 'him', 'his'];
        case 'female': return ['she', 'her', 'her'];
        default:       return ['they', 'them', 'their'];
    }
}

// Class year that graduates this club year (spring commencement) — the
// "outgoing" class. The club year runs July-June, so this stays the same
// value from July through the following June, then rolls over.
function outgoing_class_year(): string {
    $m = (int)date('n'); $y = (int)date('Y');
    return (string)($m >= 7 ? $y : $y - 1);
}

// The 4 currently-enrolled undergrad class years (e.g. ['2027','2028','2029','2030']
// from July 2026 through June 2027), computed from today's date so class-year
// lists never need manual upkeep as classes graduate each summer. Lives here
// (not auth.php) so the cron entry point can filter on it too.
function current_class_years(): array {
    $base = (int)outgoing_class_year();
    return [(string)($base+1), (string)($base+2), (string)($base+3), (string)($base+4)];
}

// The current club year as a "YYYY-YYYY" string (e.g. "2026-2027"),
// flipping over every July 1. Lives here (not auth.php) so mailer.php's
// automated reminder functions can use it too — mailer.php is required
// directly by the CLI cron entry point, which never loads auth.php.
function membership_year(): string {
    $m = (int)date('n'); $y = (int)date('Y');
    return $m >= 7 ? $y . '-' . ($y + 1) : ($y - 1) . '-' . $y;
}

// ── Membership dues ─────────────────────────────────────────────────────
// Dues are tracked as the specific set of club years a family has paid for
// (e.g. "2026-2027,2028-2029" — not necessarily consecutive), stored in
// members.membership_paid_years. membership_paid and
// membership_paid_through stay in sync as computed conveniences so the
// many admin pages that just need "are they currently in good standing"
// don't need to know about the underlying set — see save_dues_years()
// below, the one place that writes all of these.

// One row per parent-on-file for the Parents Club Badges tracker (badges.php,
// badges-report.php) — not per cadet, since cadets don't get a badge but both
// parents on a member record can. Always rebuilt from `members` rather than
// trusted from a form, and shared by both pages so their notion of "who's a
// badge candidate" can't drift apart.
function badge_slots(PDO $pdo): array {
    $stmt = $pdo->query("SELECT id, class_year, cadet_first_name, cadet_middle_name, cadet_last_name, cadet_suffix,
                   parent1_first_name, parent1_last_name, parent1_city,
                   parent2_first_name, parent2_last_name, parent2_city, membership_paid
            FROM members WHERE archived = 0
            ORDER BY class_year ASC, cadet_last_name ASC, cadet_first_name ASC");

    $slots = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $mem) {
        $cadet = cadet_full_name($mem);
        $paid = !empty($mem['membership_paid']);
        $p1 = trim($mem['parent1_first_name'] . ' ' . $mem['parent1_last_name']);
        $p2 = trim(($mem['parent2_first_name'] ?? '') . ' ' . ($mem['parent2_last_name'] ?? ''));
        if ($p1 !== '') $slots[] = ['member_id' => (int)$mem['id'], 'slot' => 1, 'name' => $p1, 'cadet' => $cadet, 'class_year' => $mem['class_year'], 'city' => $mem['parent1_city'], 'paid' => $paid];
        if ($p2 !== '') $slots[] = ['member_id' => (int)$mem['id'], 'slot' => 2, 'name' => $p2, 'cadet' => $cadet, 'class_year' => $mem['class_year'], 'city' => $mem['parent2_city'], 'paid' => $paid];
    }
    return $slots;
}

// The ordered list of club-year strings ("2026-2027") this cadet's dues
// can ever apply to: their 4 years as an undergrad, computed backward
// from their graduating class_year, plus one earlier Prep School year for
// cadets currently at Prep — whose eventual graduating class is always
// outgoing_class_year()+5 (see admin/auth.php's CLASS_YEARS comment: that
// future class is deliberately absent from CLASS_YEAR_LIST until they
// matriculate, since until then "Prep School" and that class year are the
// same cohort). Returns [] for 'Graduate', blank, or anything that isn't
// a real class — those cadets have no dues years to select.
function cadet_dues_years(string $class_year): array {
    if ($class_year === 'Prep School') {
        $grad = (int)outgoing_class_year() + 5;
    } elseif (ctype_digit($class_year)) {
        $grad = (int)$class_year;
    } else {
        return [];
    }
    $years = [];
    if ($class_year === 'Prep School') {
        $prep_start = $grad - 5;
        $years[] = $prep_start . '-' . ($prep_start + 1);
    }
    for ($i = 4; $i >= 1; $i--) {
        $s = $grad - $i;
        $years[] = $s . '-' . ($s + 1);
    }
    return $years;
}

// Splits the stored comma-separated column back into an array. Tolerant
// of null/empty (a member with no dues history yet).
function parse_dues_years(?string $csv): array {
    if (!$csv) return [];
    return array_values(array_filter(array_map('trim', explode(',', $csv))));
}

// $75/year, except a $275 bulk rate once all 4 of a cadet's own undergrad
// years are paid — however that happened, whether checked individually
// over time or all at once. This is the same discount the old Annual/
// 4-Year plan toggle gave, just derived from the actual years paid
// instead of a separate "plan type" field that could disagree with them.
function dues_years_price(array $paid_years, array $cadet_years): int {
    // No resolvable window (e.g. class_year is 'Graduate' or blank) — fall
    // back to pricing whatever years are actually on file directly, rather
    // than intersecting against an empty window and always landing on $0
    // regardless of real payment history.
    if (!$cadet_years) return count(array_unique($paid_years)) * 75;
    $undergrad = array_slice($cadet_years, -4);
    $has_full_undergrad = count($undergrad) === 4 && !array_diff($undergrad, $paid_years);
    if (!$has_full_undergrad) {
        return count(array_intersect($cadet_years, $paid_years)) * 75;
    }
    $price = 275;
    $prep_year = count($cadet_years) === 5 ? $cadet_years[0] : null;
    if ($prep_year !== null && in_array($prep_year, $paid_years, true)) $price += 75;
    return $price;
}

// The one place that writes membership_paid_years — always keeps
// membership_paid (is the *current* club year in the set?) and
// membership_paid_through (the latest paid year on file) refreshed in the
// same call, so those two derived columns can never drift out of sync
// with the underlying set. $touch_year controls whether
// membership_year — which now means "last time this record's dues were
// actually touched," not a plan start — gets bumped to today's club year;
// pass false for a passive recompute (e.g. the annual rollover) that
// isn't a new payment.
//
// Also logs the financial delta to income_entries (source_type='dues'),
// the same table every other income source in admin/income.php uses,
// rather than admin/income.php trying to re-derive "how much came in this
// year" from a member's current total on read — which would double-count
// prior years' payments every time just one more year gets added later.
// Only *increases* are logged automatically; removing a year (correcting
// a mistake) never auto-creates a negative entry — a real refund is a
// treasurer's manual call, not something this should silently infer.
// $before lets a caller that already fetched class_year/membership_paid_years
// (and ideally the cadet name columns) for this member pass it straight in,
// instead of this function re-querying a row the caller already has — used
// by bulk-action.php, which fetches every selected member in one batched
// query up front. Only consulted when $touch_year is true; the passive
// recompute path (reset-dues.php) skips this fetch/lookup entirely since it
// never logs income and the "before" data would just go unused.
function save_dues_years(PDO $pdo, int $member_id, array $years, bool $touch_year = true, ?array $before = null, ?string $payment_method_override = null, ?string $notes_override = null): void {
    $years = array_values(array_unique(array_filter($years)));
    sort($years);

    $row = $before;
    if ($touch_year && $row === null) {
        $before_stmt = $pdo->prepare('SELECT class_year, membership_paid_years, cadet_first_name, cadet_middle_name, cadet_last_name, cadet_suffix FROM members WHERE id = ?');
        $before_stmt->execute([$member_id]);
        $row = $before_stmt->fetch(PDO::FETCH_ASSOC);
    }

    $csv     = implode(',', $years);
    $paid    = in_array(membership_year(), $years, true) ? 1 : 0;
    $through = $years ? end($years) : '';
    if ($touch_year) {
        $pdo->prepare('UPDATE members SET membership_paid_years=?, membership_paid=?, membership_paid_through=?, membership_year=? WHERE id=?')
            ->execute([$csv, $paid, $through, membership_year(), $member_id]);
    } else {
        $pdo->prepare('UPDATE members SET membership_paid_years=?, membership_paid=?, membership_paid_through=? WHERE id=?')
            ->execute([$csv, $paid, $through, $member_id]);
    }

    if ($touch_year && $row) {
        $cadet_years = cadet_dues_years($row['class_year'] ?? '');
        $old_years   = parse_dues_years($row['membership_paid_years']);
        $delta = dues_years_price($years, $cadet_years) - dues_years_price($old_years, $cadet_years);
        if ($delta > 0) {
            $added = array_diff($years, $old_years);
            $cadet_name = trim(preg_replace('/\s+/', ' ', ($row['cadet_first_name'] ?? '') . ' ' . ($row['cadet_middle_name'] ?? '') . ' ' . ($row['cadet_last_name'] ?? '') . ' ' . ($row['cadet_suffix'] ?? '')));
            $pdo->prepare('INSERT INTO income_entries (entry_date, source, source_type, description, amount, payment_method, notes, received_by) VALUES (CURDATE(), ?, ?, ?, ?, ?, ?, ?)')
                ->execute([
                    $cadet_name ?: ('Member #' . $member_id),
                    'dues',
                    'Dues — ' . implode(', ', $added),
                    $delta,
                    $payment_method_override ?? '',
                    $notes_override ?? 'Recorded automatically from dues years update',
                    $_SESSION['user_id'] ?? null,
                ]);
        }
    }
}

// Job Drop Night's eligible class year — normally the class about to
// graduate (outgoing_class_year()+1), same reasoning as
// current_class_years(). But real Job Drop timing doesn't necessarily line
// up with the July 1 rollover (same gap admin/graduate-class.php already
// handles for commencement vs. the club's fiscal year), so an officer can
// override it manually via job_drop_settings.override_class_year — falls
// back to the automatic value if unset, blank, or the table/column isn't
// migrated yet. Required directly by job-drop-feed.php (no admin/auth.php
// dependency) as well as by the authenticated Job Drop Night pages.
function job_drop_eligible_year(PDO $pdo): string {
    $auto = (string)((int)outgoing_class_year() + 1);
    try {
        $override = $pdo->query('SELECT override_class_year FROM job_drop_settings WHERE id=1')->fetchColumn();
        if ($override !== false && $override !== null && trim((string)$override) !== '') {
            return trim((string)$override);
        }
    } catch (Exception $e) {
        // Table/column not migrated yet — fall back to automatic.
    }
    return $auto;
}

// Neutralizes CSV/TSV formula injection: if a cell's first character is
// one Excel/Sheets treats as a formula trigger (=, +, -, @, or a tab/CR
// that could smuggle one in after whitespace-trimming), prefix it with a
// leading apostrophe so spreadsheet apps render it as literal text instead
// of executing it. Used by any export (lists.php, index.php) that writes
// free-text, staff-entered fields like `remarks` into a CSV/TSV cell.
function csv_formula_safe(string $v): string {
    return preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v;
}

// Sanitizes the President's Letter rich-text field (admin/settings.php) —
// the one place this codebase stores free-form HTML and renders it
// unescaped, both back into the Quill editor and on the public
// president-letter.html page. Reduces the submitted HTML to a small
// allow-list of tags/attributes rather than escaping it (the whole point
// of the field is to store real HTML): everything Quill's own toolbar can
// produce (bold/italic/underline/strike/headers/lists/links/inline images/
// color/background) survives; <script>, <iframe>, event-handler
// attributes, javascript: URLs, and arbitrary inline CSS do not, whether
// they came from a paste or a hand-crafted payload.
function sanitize_rich_html(string $html): string {
    if ($html === '') return '';
    if (!class_exists('DOMDocument')) {
        // No DOM extension available — fail safe by stripping all markup
        // rather than passing raw HTML through unsanitized.
        return htmlspecialchars(strip_tags($html), ENT_QUOTES, 'UTF-8');
    }

    $allowed_tags        = ['p','br','strong','b','em','i','u','s','a','ul','ol','li','h1','h2','h3','h4','blockquote','span','img'];
    $allowed_style_props = ['color','background-color','text-align','font-size'];

    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    // The XML PI is the standard workaround for DOMDocument's broken
    // default UTF-8 handling; it becomes a document-level node, not a
    // child of our wrapper <div>, so it never ends up in the output.
    $doc->loadHTML('<?xml encoding="utf-8"?><div id="sanitize-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();

    $root = $doc->getElementsByTagName('div')->item(0);
    if (!$root) return '';

    sanitize_html_node_tree($root, $allowed_tags, $allowed_style_props);

    $out = '';
    foreach (iterator_to_array($root->childNodes) as $child) {
        $out .= $doc->saveHTML($child);
    }
    return $out;
}

// Depth-first: tags never welcome in stored content (script/iframe/etc.)
// are removed entirely, taking their contents with them. Any other
// disallowed tag is unwrapped instead — replaced by its own children — so
// plain text a user typed inside an unrecognized tag isn't silently
// dropped. Allowed tags keep only a validated, explicit per-tag attribute
// allow-list; everything else on them is stripped.
function sanitize_html_node_tree(DOMNode $node, array $allowed_tags, array $allowed_style_props): void {
    $always_strip_entirely = ['script','style','iframe','object','embed','form','link','meta','svg','math'];

    foreach (iterator_to_array($node->childNodes) as $child) {
        if ($child->nodeType === XML_COMMENT_NODE) { $node->removeChild($child); continue; }
        if ($child->nodeType !== XML_ELEMENT_NODE) continue;
        /** @var DOMElement $child */
        $tag = strtolower($child->tagName);

        if (in_array($tag, $always_strip_entirely, true)) {
            $node->removeChild($child);
            continue;
        }

        sanitize_html_node_tree($child, $allowed_tags, $allowed_style_props);

        if (!in_array($tag, $allowed_tags, true)) {
            while ($child->firstChild) { $node->insertBefore($child->firstChild, $child); }
            $node->removeChild($child);
            continue;
        }

        $keep = match ($tag) {
            'a'     => ['href'],
            'img'   => ['src', 'alt'],
            'span'  => ['style'],
            default => [],
        };
        $attr_names = [];
        foreach ($child->attributes as $attr) { $attr_names[] = $attr->name; }
        foreach ($attr_names as $name) {
            $lower = strtolower($name);
            if (!in_array($lower, $keep, true)) { $child->removeAttribute($name); continue; }
            $val = $child->getAttribute($name);
            if ($lower === 'href') {
                if (!preg_match('~^(https?://|mailto:)~i', trim($val))) $child->removeAttribute('href');
                else $child->setAttribute('rel', 'noopener');
            } elseif ($lower === 'src') {
                $is_safe_data_image = preg_match('~^data:image/(png|jpe?g|gif|webp);base64,~i', trim($val));
                $is_safe_http       = preg_match('~^https?://~i', trim($val));
                if (!$is_safe_data_image && !$is_safe_http) $child->removeAttribute('src');
            } elseif ($lower === 'style') {
                $child->setAttribute('style', sanitize_style_value($val, $allowed_style_props));
            }
        }
    }
}

// Keeps only a small allow-list of CSS properties from a Quill-generated
// style attribute (e.g. "color: rgb(0,0,0); background-color: #fff"),
// dropping anything else — including url(), expression(), or any property
// name that could smuggle behavior through inline CSS.
function sanitize_style_value(string $style, array $allowed_props): string {
    $out = [];
    foreach (explode(';', $style) as $decl) {
        $decl = trim($decl);
        if ($decl === '' || !str_contains($decl, ':')) continue;
        [$prop, $val] = array_map('trim', explode(':', $decl, 2));
        $prop = strtolower($prop);
        if (!in_array($prop, $allowed_props, true)) continue;
        if (!preg_match('/^[#a-zA-Z0-9 .,%()-]*$/', $val)) continue;
        if (stripos($val, 'url(') !== false || stripos($val, 'expression(') !== false) continue;
        $out[] = "$prop: $val";
    }
    return implode('; ', $out);
}

// Extracts the 11-character video ID from any common YouTube URL shape
// (watch?v=, youtu.be/, embed/, shorts/, with or without extra query
// params) — or returns null if the string isn't recognizably a YouTube
// link at all. Never trust a submitted URL directly as an iframe src or
// href; only ever build one from an ID this function has validated.
function extract_youtube_id(string $url): ?string {
    $url = trim($url);
    if ($url === '') return null;
    $patterns = [
        '~youtu\.be/([A-Za-z0-9_-]{11})~i',
        '~youtube(?:-nocookie)?\.com/watch\?(?:[^#]*&)?v=([A-Za-z0-9_-]{11})~i',
        '~youtube(?:-nocookie)?\.com/embed/([A-Za-z0-9_-]{11})~i',
        '~youtube(?:-nocookie)?\.com/shorts/([A-Za-z0-9_-]{11})~i',
    ];
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $url, $m)) return $m[1];
    }
    return null;
}

// Shared by digest-generate.php (fresh AI output) and digest-catalog.php
// (re-derives the plain-text copy after a saved digest is hand-edited) so
// both stay in sync on the same conversion.
function digest_html_to_text(string $html): string {
    // Pull the URL out before strip_tags() below discards it — the model is
    // explicitly told to "preserve links exactly," so the plain-text copy
    // (Copy Plain Text, and the Catalog's list snippet) needs to keep it too.
    $html = preg_replace('/<a\s[^>]*href=["\']([^"\']*)["\'][^>]*>(.*?)<\/a>/is', '$2 ($1)', $html);
    $text = trim(html_entity_decode(strip_tags(str_replace(
        ['<li>', '</li>', '</p>', '</h3>'],
        ['• ', "\n", "\n\n", "\n\n"],
        $html
    )), ENT_QUOTES, 'UTF-8'));
    return preg_replace("/\n{3,}/", "\n\n", $text);
}
