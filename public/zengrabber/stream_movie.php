<?php
// zengrabber/stream_movie.php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require __DIR__ . '/config.php';

$token = $_GET['t'] ?? '';
if ($token === '') {
    http_response_code(400);
    echo 'Missing token';
    exit;
}

$pdo = zg_pdo();

// Fetch invite + movie, similar to grab.php
$sql = "
    SELECT
        il.id          AS invite_id,
        il.is_finalized,
        il.expires_at,
        m.id           AS movie_id,
        m.proxy_path
    FROM invite_links il
    JOIN movies m ON m.id = il.movie_id
    WHERE il.token = :token
      AND m.is_active = 1
    LIMIT 1
";

$stmt = $pdo->prepare($sql);
$stmt->execute([':token' => $token]);
$row = $stmt->fetch();

if (!$row) {
    http_response_code(404);
    echo 'Invalid or expired link';
    exit;
}

// Optional: block playback for finalized / expired invites
if (!empty($row['is_finalized'])) {
    http_response_code(403);
    echo 'Invite has been finalized';
    exit;
}

if (!empty($row['expires_at']) && $row['expires_at'] < date('Y-m-d H:i:s')) {
    http_response_code(403);
    echo 'Invite has expired';
    exit;
}

// Map proxy_path (which is a /data/... URL) to the real file on disk
$publicPath = trim((string)$row['proxy_path']);

if ($publicPath === '') {
    http_response_code(500);
    echo 'Missing proxy path';
    exit;
}

$storRoot = rtrim(getenv('ZEN_STOR_DIR') ?: '/var/www/html/data', '/');

// If proxy_path starts with /data/, strip that, otherwise just trim leading slash
if (strpos($publicPath, '/data/') === 0) {
    $relative = substr($publicPath, strlen('/data/')); // e.g. zengrabber/movies/file.mp4
} else {
    $relative = ltrim($publicPath, '/');
}

$fullPath = $storRoot . '/' . $relative;

if (!is_file($fullPath)) {
    http_response_code(404);
    echo 'File not found';
    exit;
}

$size = filesize($fullPath);
$mime = 'video/mp4'; // adjust if you ever serve other formats

// Basic byte-range streaming so seeking works
$fp     = fopen($fullPath, 'rb');
$start  = 0;
$length = $size;

header('Content-Type: ' . $mime);
header('Accept-Ranges: bytes');

if (isset($_SERVER['HTTP_RANGE'])) {
    // Parse "bytes=start-end"
    if (preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $m)) {
        $rangeStart = $m[1] === '' ? 0 : (int)$m[1];
        $rangeEnd   = $m[2] === '' ? $size - 1 : (int)$m[2];

        if ($rangeStart > $rangeEnd || $rangeStart >= $size) {
            // Invalid range
            header('HTTP/1.1 416 Range Not Satisfiable');
            header('Content-Range: bytes */' . $size);
            fclose($fp);
            exit;
        }

        if ($rangeEnd >= $size) {
            $rangeEnd = $size - 1;
        }

        $start  = $rangeStart;
        $length = $rangeEnd - $rangeStart + 1;

        header('HTTP/1.1 206 Partial Content');
        header("Content-Range: bytes $rangeStart-$rangeEnd/$size");
    }
}

header('Content-Length: ' . $length);

// Stream in chunks
fseek($fp, $start);
$chunkSize = 8192;

while (!feof($fp) && $length > 0) {
    $readSize = ($length > $chunkSize) ? $chunkSize : $length;
    $buffer   = fread($fp, $readSize);
    echo $buffer;
    flush();
    $length -= $readSize;
}

fclose($fp);
exit;
