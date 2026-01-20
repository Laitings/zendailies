<?php
// zengrabber/admin_export_marker_edl_avid.php
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

$movieId    = (int)$invite['movie_id'];
$movieTitle = $invite['movie_title'] ?? ('Movie ' . $movieId);
$reel       = trim((string)($invite['movie_reel'] ?? ''));

if ($reel === '') {
    $reel = '001';
}
$reel = substr($reel, 0, 8);

// FPS (kept for compatibility; not used in marker-list export)
$fpsNum = (int)$invite['fps_num'];
$fpsDen = (int)$invite['fps_den'];
if ($fpsNum <= 0) {
    $fpsNum = 25;
}
if ($fpsDen <= 0) {
    $fpsDen = 1;
}
$fps = $fpsNum / $fpsDen;

// Avid marker color (must match Avid names)
$allowedColors = ['red', 'green', 'blue', 'cyan', 'magenta', 'yellow', 'black', 'white'];

$markerColor = strtolower(trim((string)($_GET['color'] ?? 'red')));
if (!in_array($markerColor, $allowedColors, true)) {
    $markerColor = 'red';
}

// Avid "user" column (use person's full name; spaces allowed)
$avidUser = 'Zengrabber';
if (!empty($invite['full_name'])) {
    // Keep letters, numbers, spaces, dot, underscore, dash
    $avidUser = trim(preg_replace('/[^A-Za-z0-9 ._-]+/u', '', (string)$invite['full_name']));
}
if ($avidUser === '') {
    $avidUser = 'Zengrabber';
}

// Fetch grabs
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

// NOTE: Avid Media Composer marker list import BREAKS on CRLF.
// IMPORTANT: Avid marker lists MUST use LF (not CRLF)
$nl   = "\n";
$lines = [];

foreach ($grabs as $g) {
    $tc   = (string)($g['timecode'] ?? '');
    $note = trim((string)($g['note'] ?? ''));

    // Comment text (keep Danish, but normalize punctuation + remove emoji)
    $markerText = $note !== '' ? $note : 'Marker';

    // Normalize “fancy” punctuation that can break Avid parsing
    $markerText = strtr($markerText, [
        "“" => '"',
        "”" => '"',
        "„" => '"',
        "’" => "'",
        "‘" => "'",
        "…" => "...",
    ]);

    // Remove emoji / pictographs (keep Danish letters)
    $markerText = preg_replace('/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]/u', '', $markerText);

    // Tabs/newlines are fatal in tab-delimited format
    $markerText = str_replace(["\t", "\r", "\n"], ' ', $markerText);

    // Collapse whitespace
    $markerText = trim(preg_replace('/\s+/', ' ', $markerText));

    // Exact Avid marker list format:
    // User<TAB>TC<TAB>V1<TAB>color<TAB>comment<TAB>1
    $lines[] = implode("\t", [
        $avidUser,
        $tc,
        'V1',
        $markerColor,
        $markerText,
        '1'
    ]);
}

// Output (ensure CRLF and final newline)
$body = implode($nl, $lines) . $nl;

$slug     = preg_replace('~[^A-Za-z0-9._-]+~', '_', $movieTitle);
$filename = $slug . '_avid_markers.txt';

header('Content-Type: text/plain; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

echo $body;
