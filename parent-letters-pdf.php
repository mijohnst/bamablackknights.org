<?php
/**
 * Parent Letters — PDF Download
 * Generates a real PDF (via the vendored tFPDF library) for one saved
 * letter and sends it as a direct download. Scoped to the member_id bound
 * to the verifyToken so a guessed letter id from another family can't be
 * downloaded.
 */

require_once __DIR__ . '/admin/auth.php';

start_verification_session();
$token     = trim((string)($_GET['token'] ?? ''));
$letter_id = (int)($_GET['id'] ?? 0);
$verified  = $_SESSION['letters_verified'][$token] ?? null;

if (!$verified || ($verified['expires'] ?? 0) < time() || !$letter_id) {
    http_response_code(403);
    echo 'Your session expired. Please go back to the Parent Letters page and use "Find My Record" again.';
    exit();
}
$member_id = (int)$verified['member_id'];

$pdo = get_pdo();
$stmt = $pdo->prepare('SELECT pl.letter_body, pl.created_at, m.cadet_first_name
                        FROM parent_letters pl
                        JOIN members m ON m.id = pl.member_id
                        WHERE pl.id = ? AND pl.member_id = ?');
$stmt->execute([$letter_id, $member_id]);
$letter = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$letter) {
    http_response_code(404);
    echo 'That letter could not be found.';
    exit();
}

require_once __DIR__ . '/admin/parent-letter-pdf-render.php';

$cadet_first = $letter['cadet_first_name'] ?: 'Cadet';
$letter_date = date('F j, Y', strtotime($letter['created_at']));
$safe_name   = preg_replace('/[^A-Za-z0-9]+/', '', $cadet_first) ?: 'Cadet';
$filename    = 'Letter-to-' . $safe_name . '-' . date('Y-m-d', strtotime($letter['created_at'])) . '.pdf';

$pdf = new tFPDF('P', 'in', 'Letter');
setup_parent_letter_pdf($pdf);
render_parent_letter_pdf_page($pdf, $letter_date, $letter['letter_body']);
$pdf->Output('D', $filename);
