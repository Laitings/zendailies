<?php
// zengrabber/export_grabs_pdf.php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require __DIR__ . '/config.php';
require __DIR__ . '/lib/fpdf.php';

$pdo   = zg_pdo();
$token = $_GET['t'] ?? '';

if ($token === '') {
    http_response_code(400);
    echo "Missing token.";
    exit;
}

// 1) Fetch invite + movie
$sql = "SELECT il.id AS invite_id, il.full_name AS reviewer_name, m.id AS movie_id, m.title AS movie_title 
        FROM invite_links il JOIN movies m ON m.id = il.movie_id 
        WHERE il.token = :token LIMIT 1";
$stmt = $pdo->prepare($sql);
$stmt->execute([':token' => $token]);
$invite = $stmt->fetch();

if (!$invite) {
    http_response_code(404);
    echo "Invalid link.";
    exit;
}

// 2) Fetch grabs with created_by_name
$grabStmt = $pdo->prepare("SELECT frame_number, timecode, thumbnail_path, note, created_by_name 
                           FROM grabs WHERE movie_id = :movie_id AND invite_id = :invite_id 
                           ORDER BY frame_number ASC");
$grabStmt->execute([':movie_id' => $invite['movie_id'], ':invite_id' => $invite['invite_id']]);
$grabs = $grabStmt->fetchAll();

function pdf_text($str)
{
    $res = iconv('UTF-8', 'windows-1252//TRANSLIT', $str ?? '');
    return ($res === false) ? '' : $res;
}

/**
 * FIXED PATH RESOLUTION FOR WAMP/WINDOWS
 */
function resolve_thumb_path(string $thumbPath)
{
    $clean = strtok($thumbPath, '?') ?: '';

    // Convert web URL path (/data/...) to Windows path (C:/wamp64/www/...)
    // DIRECTORY_SEPARATOR is the correct PHP constant
    $storageBase = realpath(__DIR__ . '/../../../data');

    if ($storageBase && strpos($clean, '/data/') === 0) {
        $fsPath = $storageBase . substr($clean, strlen('/data'));

        // Normalize slashes for Windows/WAMP
        $fsPath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $fsPath);

        if (is_file($fsPath)) return $fsPath;
    }

    return null;
}

class ZengrabPDF extends FPDF
{
    public $movieTitle;
    public $headerMetaString;

    function Header()
    {
        // 1. Header Bar Background
        $this->SetFillColor(19, 21, 27);
        $this->Rect(0, 0, 210, 18, 'F');

        // 2. Resolve and Draw Zen Logo
        // We use __DIR__ to ensure the path is absolute for FPDF
        $logoPath = __DIR__ . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'img' . DIRECTORY_SEPARATOR . 'zen_logo.png';
        $logoExists = file_exists($logoPath);

        if ($logoExists) {
            // Position logo at (10, 3) with a height of 12mm
            $this->Image($logoPath, 10, 3, 0, 12);
        }

        // 3. Header Text (offset if logo exists)
        $textX = $logoExists ? 24 : 10;
        $this->SetXY($textX, 6);
        $this->SetTextColor(255, 255, 255);
        $this->SetFont('Arial', 'B', 14);
        $this->Cell(35, 6, 'ZENREVIEW', 0, 0, 'L');

        // 4. Movie Title & Metadata
        $this->SetXY(60, 5);
        $this->SetFont('Arial', 'B', 12);
        $this->Cell(135, 4, $this->movieTitle, 0, 1, 'R');

        $this->SetXY(60, 10);
        $this->SetTextColor(58, 160, 255);
        $this->SetFont('Arial', '', 8);
        $this->Cell(135, 4, $this->headerMetaString, 0, 0, 'R');

        $this->Ln(15);
    }
}

$pdf = new ZengrabPDF('P', 'mm', 'A4');
$pdf->movieTitle = pdf_text($invite['movie_title']);
$pdf->headerMetaString = 'Review Session   |   ' . count($grabs) . ' Grabs   |   ' . date('d M Y - H:i');
$pdf->SetAutoPageBreak(true, 25);
$pdf->AddPage();

$imgW = 110;
$imgH = $imgW * 9 / 16;
$marginX = 15;
$globalCount = 1;

foreach ($grabs as $g) {
    // Determine height of comment block to avoid page break issues
    $nbLines = !empty($g['note']) ? count(explode("\n", $g['note'])) : 0;
    $textH = ($nbLines * 4.5) + 10;
    $totalH = max($imgH, $textH);

    if ($pdf->GetY() + $totalH + 20 > 275) {
        $pdf->AddPage();
    }

    $currentY = $pdf->GetY();

    // Draw Thumb
    $thumbFs = resolve_thumb_path($g['thumbnail_path']);
    $pdf->SetDrawColor(58, 160, 255);
    $pdf->Rect($marginX - 0.5, $currentY - 0.5, $imgW + 1, $imgH + 1);
    if ($thumbFs) {
        $pdf->Image($thumbFs, $marginX, $currentY, $imgW, $imgH);
    } else {
        $pdf->SetXY($marginX, $currentY + ($imgH / 2));
        $pdf->Cell($imgW, 5, 'Thumbnail Missing', 0, 0, 'C');
    }

    // Info Section
    $pdf->SetXY($marginX + $imgW + 8, $currentY);
    $pdf->SetFont('Arial', 'B', 11);
    $pdf->SetTextColor(58, 160, 255);
    $pdf->Cell(60, 6, '#' . $globalCount . '  ' . $g['timecode'], 0, 1);

    $pdf->SetX($marginX + $imgW + 8);
    $pdf->SetFont('Arial', 'I', 9);
    $pdf->SetTextColor(120, 120, 120);
    $pdf->Cell(60, 5, pdf_text('By: ' . ($g['created_by_name'] ?? 'Anonymous')), 0, 1);

    // Comment Flow
    $pdf->SetXY($marginX + $imgW + 8, $pdf->GetY() + 2);
    $pdf->SetFont('Arial', '', 9);
    $pdf->SetTextColor(40, 40, 40);
    $pdf->MultiCell(65, 4.5, pdf_text($g['note']), 0, 'L');

    $pdf->SetY(max($currentY + $imgH, $pdf->GetY()) + 18);
    $globalCount++;
}

// 1. Sanitize the movie title for a filename
$safeMovieTitle = preg_replace('~[^A-Za-z0-9_-]+~', '_', $invite['movie_title']);

// 2. Generate the date and time in 24h format
$timestamp = date('Y-m-d_H-i');

// 3. Combine them
$fileName = 'Zenreview_' . $safeMovieTitle . '_' . $timestamp . '.pdf';

// 4. Send headers
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $fileName . '"');
$pdf->Output('I', $fileName);
