<?php
// zengrabber/admin_export_marker_edl_resolve.php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require __DIR__ . '/admin_auth.php';

$admin = zg_require_admin();
$pdo   = zg_pdo();

$inviteId = isset($_GET['invite_id']) ? (int)$_GET['invite_id'] : 0;
if ($inviteId <= 0) {
    http_response_code(400);
    echo "Missing invite_id.";
    exit;
}

// Fetch invite + movie
$sql = "
    SELECT
        il.*,
        m.title      AS movie_title,
        m.reel_name  AS movie_reel,
        m.fps_num,
        m.fps_den
    FROM invite_links il
    JOIN movies m ON m.id = il.movie_id
    WHERE il.id = :invite_id
    LIMIT 1
";
$stmt = $pdo->prepare($sql);
$stmt->execute([':invite_id' => $inviteId]);
$invite = $stmt->fetch();

if (!$invite) {
    http_response_code(404);
    echo "Invite not found.";
    exit;
}

$movieId   = (int)$invite['movie_id'];
$movieTitle = $invite['movie_title'] ?? ('Movie ' . $movieId);
$reel      = trim((string)($invite['movie_reel'] ?? ''));

// Normalize reel name (Resolve usually doesn’t care much, but keep it sane)
if ($reel === '') {
    $reel = '001';
}
$reel = substr($reel, 0, 8);

// FPS
$fpsNum = (int)$invite['fps_num'];
$fpsDen = (int)$invite['fps_den'];
if ($fpsNum <= 0) {
    $fpsNum = 25;
}
if ($fpsDen <= 0) {
    $fpsDen = 1;
}
$fps = $fpsNum / $fpsDen;

// Fetch grabs (markers)
$grabSql = "
    SELECT frame_number, timecode, note
    FROM grabs
    WHERE movie_id = :movie_id
      AND invite_id = :invite_id
    ORDER BY frame_number ASC
";
$grabStmt = $pdo->prepare($grabSql);
$grabStmt->execute([
    ':movie_id'  => $movieId,
    ':invite_id' => $inviteId,
]);
$grabs = $grabStmt->fetchAll();

if (!$grabs) {
    echo "No grabs found for this invite.";
    exit;
}

// Helper: add frames to TC (HH:MM:SS:FF)
function tc_add_frames(string $tc, int $framesToAdd, int $fps): string
{
    if (!preg_match('/^(\d{2}):(\d{2}):(\d{2}):(\d{2})$/', $tc, $m)) {
        return $tc;
    }

    $h = (int)$m[1];
    $m2 = (int)$m[2];
    $s = (int)$m[3];
    $f = (int)$m[4];

    $totalFrames = ($h * 3600 + $m2 * 60 + $s) * $fps + $f + $framesToAdd;
    if ($totalFrames < 0) {
        $totalFrames = 0;
    }

    $newSeconds = intdiv($totalFrames, $fps);
    $newFrames  = $totalFrames % $fps;

    $newH = intdiv($newSeconds, 3600);
    $newSeconds -= $newH * 3600;
    $newM = intdiv($newSeconds, 60);
    $newS = $newSeconds - $newM * 60;

    $newH = $newH % 24; // keep it 00-23

    return sprintf('%02d:%02d:%02d:%02d', $newH, $newM, $newS, $newFrames);
}

// Marker color (Resolve)
$allowedColors = [
    'ResolveColorBlue',
    'ResolveColorCyan',
    'ResolveColorGreen',
    'ResolveColorYellow',
    'ResolveColorRed',
    'ResolveColorPink',
    'ResolveColorPurple',
    'ResolveColorFuchsia',
    'ResolveColorRose',
    'ResolveColorLavender',
    'ResolveColorSky',
    'ResolveColorMint',
    'ResolveColorLemon',
    'ResolveColorSand',
    'ResolveColorCocoa',
    'ResolveColorCream',
];

$markerColor = trim((string)($_GET['color'] ?? 'ResolveColorSand'));
if (!in_array($markerColor, $allowedColors, true)) {
    $markerColor = 'ResolveColorSand';
}

// Build Resolve-style marker EDL
$nl   = "\r\n";
$lines = [];

// Header
$lines[] = 'TITLE: ' . $movieTitle . ' - Resolve markers';
$lines[] = 'FCM: NON-DROP FRAME';
$lines[] = ''; // blank

$eventNum = 1;

foreach ($grabs as $g) {
    $tc   = $g['timecode'];
    $note = trim((string)($g['note'] ?? ''));

    // 1-frame marker event
    $tcOut = tc_add_frames($tc, 1, (int)round($fps));

    $numStr = str_pad((string)$eventNum, 3, '0', STR_PAD_LEFT);
    $srcIn  = $tc;
    $srcOut = $tcOut;
    $recIn  = $tc;
    $recOut = $tcOut;

    // Event line:
    // 001  001      V     C        10:24:00:23 10:24:01:00 10:24:00:23 10:24:01:00
    $lines[] = sprintf(
        '%s  %-8s V     C        %s %s %s %s',
        $numStr,
        $reel,
        $srcIn,
        $srcOut,
        $recIn,
        $recOut
    );

    // Comment line (Resolve marker style)
    // <note> |C:ResolveColorSand |M:Marker 15 |D:1
    $commentText = $note !== '' ? $note : '';
    $lines[] = $commentText . ' |C:' . $markerColor . ' |M:Marker ' . $numStr . ' |D:1';


    // Blank line between events
    $lines[] = '';

    $eventNum++;
}

// Output
$body = implode($nl, $lines);

// Simple filename slug
$slug = preg_replace('~[^A-Za-z0-9._-]+~', '_', $movieTitle);
$filename = $slug . '_resolve_markers.edl';

header('Content-Type: text/plain; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

echo $body;
