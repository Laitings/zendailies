<?php
// api_grab_delete_doodle.php
ini_set('display_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json');

require __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Invalid method']);
    exit;
}

$token           = $_POST['token']            ?? '';
$grabId          = $_POST['grab_id']           ?? '';
$cleanImageData  = $_POST['clean_image_data'] ?? ''; // New field for restoring the thumb

if ($token === '' || $grabId === '') {
    echo json_encode(['ok' => false, 'error' => 'Missing fields']);
    exit;
}

$pdo = zg_pdo();

// 1. Resolve token and verify ownership
$stmt = $pdo->prepare("
    SELECT g.id, g.doodle_path, g.thumbnail_path
    FROM grabs g
    JOIN invite_links il ON il.id = g.invite_id
    WHERE g.id = :id AND il.token = :token
    LIMIT 1
");
$stmt->execute([':id' => (int)$grabId, ':token' => $token]);
$grab = $stmt->fetch();

if (!$grab) {
    echo json_encode(['ok' => false, 'error' => 'Grab not found']);
    exit;
}

$storageBase = realpath(__DIR__ . '/../../../data');

// 2. Delete the actual Doodle PNG file
if ($storageBase !== false && !empty($grab['doodle_path'])) {
    $fsPath = $storageBase . substr($grab['doodle_path'], strlen('/data'));
    if (is_file($fsPath)) {
        @unlink($fsPath);
    }
}

// 3. If a clean frame was sent, overwrite the current thumbnail
if ($cleanImageData !== '' && $storageBase !== false) {
    if (preg_match('#^data:image/jpeg;base64,#', $cleanImageData)) {
        $base64 = substr($cleanImageData, strlen('data:image/jpeg;base64,'));
        $binary = base64_decode($base64, true);

        if ($binary !== false) {
            $thumbFs = $storageBase . substr($grab['thumbnail_path'], strlen('/data'));
            // Overwrite the existing file on disk with the clean frame
            file_put_contents($thumbFs, $binary);
        }
    }
}

// 4. Update DB to remove the doodle reference
$pdo->prepare("UPDATE grabs SET doodle_path = NULL WHERE id = :id")
    ->execute([':id' => (int)$grabId]);

// 5. Return success and the path so JS can force a reload
echo json_encode([
    'ok'            => true,
    'thumbnail_url' => $grab['thumbnail_path']
]);
