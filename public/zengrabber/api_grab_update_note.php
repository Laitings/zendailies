<?php
// api_grab_update_note.php
ini_set('display_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json');

require __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Invalid method']);
    exit;
}

$pdo     = zg_pdo();
$token   = $_POST['token']   ?? '';
$grabId  = isset($_POST['grab_id']) ? (int)$_POST['grab_id'] : 0;
$noteRaw = $_POST['note']    ?? '';

$note = trim($noteRaw);

if ($token === '' || $grabId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Missing token or grab_id']);
    exit;
}

try {
    // 1) Make sure this grab belongs to the invite token
    $stmt = $pdo->prepare("
        SELECT g.id
        FROM grabs g
        JOIN invite_links il ON il.id = g.invite_id
        WHERE g.id = :grab_id
          AND il.token = :token
        LIMIT 1
    ");
    $stmt->execute([
        ':grab_id' => $grabId,
        ':token'   => $token,
    ]);

    $row = $stmt->fetch();
    if (!$row) {
        echo json_encode(['ok' => false, 'error' => 'Grab not found for this token']);
        exit;
    }

    // 2) Update note
    $upd = $pdo->prepare("
        UPDATE grabs
        SET note = :note
        WHERE id = :grab_id
        LIMIT 1
    ");
    $upd->execute([
        ':note'    => $note,
        ':grab_id' => $grabId,
    ]);

    // Fetch the updated grab data to send back full info
    $stmt = $pdo->prepare("SELECT id, frame_number, timecode, thumbnail_path, doodle_path, note FROM grabs WHERE id = ?");
    $stmt->execute([$grabId]);
    $g = $stmt->fetch();

    echo json_encode([
        'ok'   => true,
        'grab' => [
            'id'            => (int)$g['id'],
            'frame_number'  => (int)$g['frame_number'],
            'timecode'      => $g['timecode'],
            'thumbnail_url' => $g['thumbnail_path'],
            'doodle_url'    => $g['doodle_path'], // Essential for keeping the doodle visible
            'note'          => $g['note'],
        ],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('api_grab_update_note error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'Server error']);
    exit;
}
