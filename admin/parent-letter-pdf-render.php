<?php
/**
 * Shared tFPDF rendering for one parent letter "page" — used by both
 * parent-letters-pdf.php (single letter, public/token-scoped) and
 * admin/parent-letters-pdf-all.php (every letter, admin-only batch).
 * Kept as one function so the two output paths can never visually drift
 * apart from each other.
 */
require_once __DIR__ . '/lib/tfpdf/tfpdf.php';
require_once __DIR__ . '/lib/tfpdf/font/unifont/ttfonts.php';

// Registers the Kalam handwritten font and Cinzel (the site's heading font,
// used only for the letterhead's club-name banner) on a fresh document.
// Call once per tFPDF instance, before any AddPage()/render calls.
function setup_parent_letter_pdf(tFPDF $pdf): void {
    $pdf->AddFont('Kalam', '', 'Kalam-Regular.ttf', true);
    $pdf->AddFont('Kalam', 'B', 'Kalam-Bold.ttf', true);
    $pdf->AddFont('Cinzel', '', 'Cinzel-Regular.ttf', true);
    $pdf->SetMargins(1, 0.75, 1);
    $pdf->SetAutoPageBreak(true, 0.75);
}

// Draws the Alabama flag (white field, red St. Andrew's cross) as vectors —
// same shape as the inline SVG used in the site nav — so no separate image
// asset is needed.
function draw_alabama_flag(tFPDF $pdf, float $x, float $y, float $w, float $h): void {
    $pdf->SetFillColor(255, 255, 255);
    $pdf->Rect($x, $y, $w, $h, 'F');
    $pdf->SetDrawColor(166, 25, 46);
    $pdf->SetLineWidth($w * (9 / 60));
    $pdf->Line($x, $y, $x + $w, $y + $h);
    $pdf->Line($x + $w, $y, $x, $y + $h);
    $pdf->SetLineWidth(0.01);
}

// Renders one letter as its own page: letterhead (logo, club name banner,
// Alabama flag), tagline, date, body text.
function render_parent_letter_pdf_page(tFPDF $pdf, string $letterDate, string $letterBody): void {
    $pdf->AddPage();
    $page_width  = 8.5;
    $left_margin = 1;
    $right_margin = 1;
    $top_y = 0.75;

    // Logo and flag are sized to the same bounding-box AREA rather than
    // the same width or height — the logo is a tall portrait shape and
    // the flag is a wide rectangle, so matching just one dimension made
    // one of them look much smaller than the other despite "matching."
    $target_area = 0.38; // sq in

    // Logo — left
    $logo_path = __DIR__ . '/../logo01.png';
    $logo_h = sqrt($target_area);
    $logo_w = $logo_h;
    if (file_exists($logo_path)) {
        $dims = @getimagesize($logo_path);
        if ($dims) {
            $aspect = $dims[0] / $dims[1]; // width/height
            $logo_h = sqrt($target_area / $aspect);
            $logo_w = $logo_h * $aspect;
        }
        $pdf->Image($logo_path, $left_margin, $top_y, $logo_w, $logo_h);
    }

    // Alabama flag — right (60:40 aspect ratio)
    $flag_aspect = 60 / 40;
    $flag_h = sqrt($target_area / $flag_aspect);
    $flag_w = $flag_h * $flag_aspect;
    $flag_x = $page_width - $right_margin - $flag_w;
    draw_alabama_flag($pdf, $flag_x, $top_y, $flag_w, $flag_h);

    // Club name banner — center, between logo and flag
    $text_x = $left_margin + $logo_w + 0.25;
    $text_w = $flag_x - 0.25 - $text_x;
    $pdf->SetXY($text_x, $top_y);
    $pdf->SetFont('Cinzel', '', 18);
    $pdf->SetTextColor(0, 37, 84);
    $pdf->MultiCell($text_w, 0.28, 'USAFA Parents Club of Alabama', 0, 'C');
    $banner_text_bottom = $pdf->GetY();

    // Tagline sits directly under the banner text itself, confined to the
    // same narrow column between the logo and flag (not the full page
    // width) so it can't run into either image — independent of the
    // flanking logo/flag height, so it doesn't drift down just because one
    // of those images happens to be tall.
    $pdf->SetXY($text_x, $banner_text_bottom + 0.08);
    $pdf->SetFont('Kalam', '', 10);
    $pdf->SetTextColor(90, 106, 122);
    $pdf->MultiCell($text_w, 0.2, 'A volunteer-run nonprofit supporting Alabama USAFA families. alabamafalcons.org', 0, 'C');
    $tagline_bottom = $pdf->GetY();

    // Whatever comes next clears the tagline and both flanking images,
    // whichever ends up lower.
    $pdf->SetY(max($tagline_bottom, $top_y + $logo_h, $top_y + $flag_h) + 0.25);

    $pdf->SetFont('Kalam', '', 13);
    $pdf->SetTextColor(51, 51, 51);
    $pdf->Cell(0, 0.25, $letterDate, 0, 1, 'R');
    $pdf->Ln(0.2);

    $pdf->SetFont('Kalam', '', 20);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->MultiCell(0, 0.34, $letterBody, 0, 'L');
}

// Renders a divider page announcing whose letter follows — used only by
// the admin batch download, so a printed stack still lines up with
// alphabetized envelopes even after the PDF pages get separated.
function render_parent_letter_separator_page(tFPDF $pdf, string $cadetFullName): void {
    $pdf->AddPage();
    $pdf->SetY(4.5);
    $pdf->SetFont('Kalam', 'B', 34);
    $pdf->SetTextColor(0, 37, 84);
    $pdf->MultiCell(0, 0.6, $cadetFullName, 0, 'C');
}
