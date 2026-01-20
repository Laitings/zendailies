<?php
// zengrabber/api_grab_delete.php
ini_set('display_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json');

require __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Invalid method']);
    exit;
}

$token   = $_POST['token']   ?? '';
$grabId  = $_POST['grab_id'] ?? '';

if ($token === '' || $grabId === '') {
    echo json_encode(['ok' => false, 'error' => 'Missing fields']);
    exit;
}

$pdo = zg_pdo();

// Resolve token → invite + movie
$sql = "
    SELECT
        il.id  AS invite_id,
        m.id   AS movie_id
    FROM invite_links il
    JOIN movies m ON m.id = il.movie_id
    WHERE il.token = :token
    LIMIT 1
";
$stmt = $pdo->prepare($sql);
$stmt->execute([':token' => $token]);
$link = $stmt->fetch();

if (!$link) {
    echo json_encode(['ok' => false, 'error' => 'Invalid token']);
    exit;
}

$inviteId = (int)$link['invite_id'];
$movieId  = (int)$link['movie_id'];

// Get grab row and ensure it belongs to this invite/movie
$stmt = $pdo->prepare("
    SELECT id, thumbnail_path, doodle_path
    FROM grabs
    WHERE id = :id
      AND movie_id = :movie_id
      AND invite_id = :invite_id
    LIMIT 1
");
$stmt->execute([
    ':id'        => (int)$grabId,
    ':movie_id'  => $movieId,
    ':invite_id' => $inviteId,
]);

$grab = $stmt->fetch();

if (!$grab) {
    echo json_encode(['ok' => false, 'error' => 'Grab not found']);
    exit;
}

// Map web path /data/... → FS path /var/www/html/data/...
$thumbPath = $grab['thumbnail_path'] ?? '';
if (is_string($thumbPath) && strpos($thumbPath, '/data/') === 0) {
    $storageBase = realpath(__DIR__ . '/../../../data');
    if ($storageBase !== false) {
        $fsPath = $storageBase . substr($thumbPath, strlen('/data'));
        if (is_file($fsPath)) {
            // Delete Thumbnail
            @unlink($fsPath);

            // Delete Doodle PNG if it exists
            $doodlePath = $grab['doodle_path'] ?? '';
            if (is_string($doodlePath) && strpos($doodlePath, '/data/') === 0) {
                $fsDoodlePath = $storageBase . substr($doodlePath, strlen('/data'));
                if (is_file($fsDoodlePath)) {
                    @unlink($fsDoodlePath);
                }
            }
        }
    }
}

// Delete DB row
$pdo->prepare("DELETE FROM grabs WHERE id = :id")->execute([
    ':id' => (int)$grabId,
]);

echo json_encode(['ok' => true]);
