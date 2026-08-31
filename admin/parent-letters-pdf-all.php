<?php
/**
 * Parent Letters — Download All as PDF
 * Every saved letter, grouped by cadet and sorted alphabetically by last
 * name, with one separator page per cadet (not per letter) ahead of that
 * cadet's whole group of letters — so a printed stack still lines up with
 * alphabetized envelopes after the single PDF gets separated into pages.
 * Sent as a direct download, not opened for printing.
 */
require_once __DIR__ . '/auth.php';
require_login();
if (!can_manage_members() && !is_secretary() && !is_treasurer()) { header('Location: dashboard.php?denied=1'); exit; }
$pdo = get_pdo();

$letters = $pdo->query(
    "SELECT pl.letter_body, pl.created_at, m.id AS member_id,
            m.cadet_first_name, m.cadet_middle_name, m.cadet_last_name, m.cadet_suffix
     FROM parent_letters pl
     JOIN members m ON m.id = pl.member_id
     ORDER BY m.cadet_last_name ASC, m.cadet_first_name ASC, pl.created_at ASC"
)->fetchAll(PDO::FETCH_ASSOC);

if (empty($letters)) {
    flash('error', 'No letters saved yet.');
    header('Location: parent-letters.php'); exit;
}

require_once __DIR__ . '/parent-letter-pdf-render.php';

$pdf = new tFPDF('P', 'in', 'Letter');
setup_parent_letter_pdf($pdf);

$last_member_id = null;
foreach ($letters as $l) {
    $cadet_name = trim($l['cadet_first_name'] . ' ' . $l['cadet_middle_name'] . ' ' . $l['cadet_last_name'] . ' ' . $l['cadet_suffix']);
    $cadet_name = preg_replace('/\s+/', ' ', $cadet_name) ?: 'Cadet';
    $letter_date = date('F j, Y', strtotime($l['created_at']));

    if ($l['member_id'] !== $last_member_id) {
        render_parent_letter_separator_page($pdf, $cadet_name);
        $last_member_id = $l['member_id'];
    }
    render_parent_letter_pdf_page($pdf, $letter_date, $l['letter_body']);
}

$pdf->Output('D', 'Parent-Letters-A-to-Z-' . date('Y-m-d') . '.pdf');
