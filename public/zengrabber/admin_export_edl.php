<?php
// zengrabber/admin_export_edl.php
require __DIR__ . '/config.php';

ini_set('display_errors', 1);
error_reporting(E_ALL);

$pdo = zg_pdo();

$invite_id = isset($_GET['invite_id']) ? (int)$_GET['invite_id'] : 0;
if ($invite_id <= 0) {
    http_response_code(400);
    echo "Missing invite_id";
    exit;
}

// Fetch invite + movie
$sql = "
    SELECT
        il.*,
        m.id        AS movie_id,
        m.title     AS movie_title,
        m.reel_name AS movie_reel,
        m.fps_num,
        m.fps_den,
        m.start_tc
    FROM invite_links il
    JOIN movies m ON m.id = il.movie_id
    WHERE il.id = :invite_id
    LIMIT 1
";
$stmt = $pdo->prepare($sql);
$stmt->execute([':invite_id' => $invite_id]);
$invite = $stmt->fetch();

if (!$invite) {
    http_response_code(404);
    echo "Invite not found";
    exit;
}

$movieId    = (int)$invite['movie_id'];
$movieTitle = $invite['movie_title'] ?? 'Untitled';
$reelName   = $invite['movie_reel'] ?: 'REEL001';
$fullName   = $invite['full_name'] ?? 'Unknown';
$fpsNum     = (int)$invite['fps_num'];
$fpsDen     = (int)$invite['fps_den'];
$startTc    = $invite['start_tc'] ?: '01:00:00:00';

if ($fpsNum <= 0 || $fpsDen <= 0) {
    $fpsNum = 25;
    $fpsDen = 1;
}
$fps = $fpsNum / $fpsDen;

// Normalize reel to max 8 chars, uppercase
$reelName = strtoupper(substr($reelName, 0, 8));

// Fetch grabs
$grabStmt = $pdo->prepare("
    SELECT id, frame_number, timecode, note
    FROM grabs
    WHERE invite_id = :invite_id
    ORDER BY frame_number ASC
");
$grabStmt->execute([':invite_id' => $invite_id]);
$grabs = $grabStmt->fetchAll();

// --- TC helpers ---
function zg_tc_to_frames(string $tc, float $fps): int
{
    $parts = explode(':', $tc);
    if (count($parts) !== 4) {
        return 0;
    }
    $h  = (int)$parts[0];
    $m  = (int)$parts[1];
    $s  = (int)$parts[2];
    $ff = (int)$parts[3];

    $totalSeconds = ($h * 3600) + ($m * 60) + $s;
    return (int)round($totalSeconds * $fps + $ff);
}

function zg_frames_to_tc(int $frames, float $fps): string
{
    $f = $frames % (int)$fps;
    $totalSeconds = (int)(($frames - $f) / $fps);
    $s = $totalSeconds % 60;
    $totalMinutes = (int)(($totalSeconds - $s) / 60);
    $m = $totalMinutes % 60;
    $h = (int)(($totalMinutes - $m) / 60);

    $pad = static function (int $n): string {
        return $n < 10 ? '0' . $n : (string)$n;
    };

    return $pad($h) . ':' . $pad($m) . ':' . $pad($s) . ':' . $pad($f);
}

// Record timeline starts at 01:00:00:00
$recordBaseTc     = '01:00:00:00';
$recordBaseFrames = zg_tc_to_frames($recordBaseTc, $fps);

$lines = [];

// Header
$lines[] = 'TITLE: ' . $movieTitle . ' – ' . $fullName;
$lines[] = 'FCM: NON-DROP FRAME';
$lines[] = ''; // blank line

$eventIndex = 1;

foreach ($grabs as $g) {
    $eventNum = str_pad((string)$eventIndex, 3, '0', STR_PAD_LEFT);

    $srcInTc  = $g['timecode'];
    $srcInFr  = zg_tc_to_frames($srcInTc, $fps);
    $srcOutFr = $srcInFr + 1; // 1-frame event
    $srcOutTc = zg_frames_to_tc($srcOutFr, $fps);

    $recInFr  = $recordBaseFrames + ($eventIndex - 1);
    $recOutFr = $recInFr + 1;
    $recInTc  = zg_frames_to_tc($recInFr, $fps);
    $recOutTc = zg_frames_to_tc($recOutFr, $fps);

    // Main event line
    $mainLine = sprintf(
        "%s  %-8s V     C  %s %s %s %s",
        $eventNum,
        $reelName,
        $srcInTc,
        $srcOutTc,
        $recInTc,
        $recOutTc
    );
    $lines[] = $mainLine;

    // Optional note line
    $note = trim($g['note'] ?? '');
    if ($note !== '') {
        $lines[] = sprintf(
            "* LOC: %s NOTE: %s",
            $srcInTc,
            $note
        );
    }

    $lines[] = ''; // blank line between events
    $eventIndex++;
}

$edlContent = implode("\r\n", $lines);

// Output as download
$filename = sprintf(
    'zengrabber_movie%d_invite%d.edl',
    $movieId,
    $invite_id
);

header('Content-Type: text/plain; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($edlContent));

echo $edlContent;
