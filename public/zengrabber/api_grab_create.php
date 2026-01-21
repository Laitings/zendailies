<?php
// api_grab_create.php
ini_set('display_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json');

require __DIR__ . '/config.php';

$pdo = zg_pdo();

// --- 1. Read input ---
$token        = $_POST['token']        ?? '';
$frameNumber  = isset($_POST['frame_number']) ? (int)$_POST['frame_number'] : 0;
$timecode     = $_POST['timecode']     ?? '';
$imageData    = $_POST['image_data']   ?? '';
$existingGrabId = isset($_POST['grab_id']) ? (int)$_POST['grab_id'] : 0; //

if ($token === '' || $frameNumber <= 0 || $timecode === '' || $imageData === '') {
    echo json_encode([
        'ok'    => false,
        'error' => 'Missing required fields.',
    ]);
    exit;
}

// --- 2. Resolve invite + movie ---
$sql = "
    SELECT
        il.id        AS invite_id,
        il.movie_id  AS movie_id,
        il.is_finalized
    FROM invite_links il
    WHERE il.token = :token
    LIMIT 1
";
$stmt = $pdo->prepare($sql);
$stmt->execute([':token' => $token]);
$invite = $stmt->fetch();

if (!$invite) {
    echo json_encode([
        'ok'    => false,
        'error' => 'Invalid token.',
    ]);
    exit;
}

// If finalized, do not allow new grabs
if (!empty($invite['is_finalized'])) {
    echo json_encode([
        'ok'    => false,
        'error' => 'This grab list has been finalized.',
    ]);
    exit;
}

$movieId  = (int)$invite['movie_id'];
$inviteId = (int)$invite['invite_id'];

// --- 3. Decode data URL (image/jpeg base64) ---
if (!preg_match('#^data:image/jpeg;base64,#', $imageData)) {
    echo json_encode([
        'ok'    => false,
        'error' => 'Invalid image data format.',
    ]);
    exit;
}

$base64 = substr($imageData, strlen('data:image/jpeg;base64,'));
$binary = base64_decode($base64, true);

if ($binary === false) {
    echo json_encode([
        'ok'    => false,
        'error' => 'Could not decode image data.',
    ]);
    exit;
}

// --- 4. Determine storage path (shared /data, per-token folder) ---
// Same root strategy as Zendailies: env ZEN_STOR_DIR or default
$storRoot = rtrim(getenv('ZEN_STOR_DIR') ?: '/var/www/html/data', '/');

// Filesystem path:   /var/www/html/data/zengrabber/grabs/{token}
// Public URL path:   /data/zengrabber/grabs/{token}/filename.jpg
$grabDirFs   = $storRoot . '/zengrabber/grabs/' . $token;
$grabDirUrl  = '/data/zengrabber/grabs/' . rawurlencode($token);

if (!is_dir($grabDirFs)) {
    if (!mkdir($grabDirFs, 0775, true) && !is_dir($grabDirFs)) {
        echo json_encode([
            'ok'    => false,
            'error' => 'Failed to create storage directory.',
        ]);
        exit;
    }
}

// Use a consistent name if overwriting to avoid orphaned files
$suffix = $existingGrabId ? 'update_' . time() : time();
$filename = 'frame_' . $frameNumber . '_' . $suffix . '.jpg';
$thumbFs  = $grabDirFs . '/' . $filename;
$thumbUrl = $grabDirUrl . '/' . rawurlencode($filename);

// --- 5b. Save High-Res Doodle PNG if provided ---
$doodleUrl = null;
if (!empty($_POST['doodle_data'])) {
    $doodleData = $_POST['doodle_data'];
    // We expect image/png for transparency
    if (preg_match('#^data:image/png;base64,#', $doodleData)) {
        $d64 = substr($doodleData, strlen('data:image/png;base64,'));
        $dBinary = base64_decode($d64, true);

        if ($dBinary !== false) {
            $doodleFilename = 'doodle_' . $frameNumber . '_' . time() . '.png';
            $doodleFs = $grabDirFs . '/' . $doodleFilename;
            if (file_put_contents($doodleFs, $dBinary) !== false) {
                $doodleUrl = $grabDirUrl . '/' . rawurlencode($doodleFilename);
            }
        }
    }
}

// --- 5. Handle File Saving (Overwrite or New) ---
if (file_put_contents($thumbFs, $binary) === false) {
    echo json_encode(['ok' => false, 'error' => 'Failed to save thumbnail on disk.']);
    exit;
}

// Logic to determine if we update or insert
// --- 6. Intelligent Overwrite Check ---
// We check if a grab already exists for this frame for this specific user/invite
$checkStmt = $pdo->prepare("SELECT id, thumbnail_path, doodle_path FROM grabs WHERE invite_id = ? AND frame_number = ? LIMIT 1");
$checkStmt->execute([$inviteId, $frameNumber]);
$existingGrab = $checkStmt->fetch();

$storageBase = realpath(__DIR__ . '/../../../data');

if ($existingGrab) {
    $grabId = (int)$existingGrab['id'];
    $createdBy = $_POST['created_by_name'] ?? 'Anonymous';

    // 1. Clean up old files from disk to prevent ghost files
    if ($storageBase) {
        foreach (['thumbnail_path', 'doodle_path'] as $col) {
            if (!empty($existingGrab[$col]) && strpos($existingGrab[$col], '/data/') === 0) {
                $fsPath = $storageBase . substr($existingGrab[$col], strlen('/data'));
                if (is_file($fsPath)) @unlink($fsPath);
            }
        }
    }

    // 2. Update the existing record
    $sql = "UPDATE grabs 
            SET thumbnail_path = :thumb_path, 
                doodle_path = :doodle_path, 
                created_by_name = :created_by,
                created_at = NOW() 
            WHERE id = :id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':thumb_path'  => $thumbUrl,
        ':doodle_path' => $doodleUrl,
        ':created_by'  => $createdBy,
        ':id'          => $grabId
    ]);
} else {
    // 3. No grab exists here, create a new one
    $createdBy = $_POST['created_by_name'] ?? 'Anonymous';

    $sql = "INSERT INTO grabs (movie_id, invite_id, frame_number, timecode, thumbnail_path, doodle_path, note, created_by_name) 
    VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
    $stmt = $pdo->prepare($sql);

    // Fixed: Using the correct variable names defined at the top of your script
    $stmt->execute([
        $movieId,
        $inviteId,
        $frameNumber,
        $timecode,
        $thumbUrl,
        $doodleUrl,
        '', // Initial empty note
        $createdBy
    ]);
    $grabId = (int)$pdo->lastInsertId();
}

// --- 7. Return JSON shaped as the JS expects ---
// Fetch the note in case of an update so we don't return empty string for an existing comment
$finalStmt = $pdo->prepare("
    SELECT
        id,
        note,
        created_by_name
    FROM grabs
    WHERE id = ?
    LIMIT 1
");
$finalStmt->execute([$grabId]);
$finalRow = $finalStmt->fetch(PDO::FETCH_ASSOC) ?: ['note' => '', 'created_by_name' => 'Anonymous'];

$finalNote = $finalRow['note'] ?? '';
$finalCreatedByName = $finalRow['created_by_name'] ?? 'Anonymous';

$grab = [
    'id'             => $grabId,
    'frame_number'   => $frameNumber,
    'timecode'       => $timecode,
    'thumbnail_url'  => $thumbUrl . '?v=' . time(),
    'doodle_url'     => $doodleUrl ? ($doodleUrl . '?v=' . time()) : null,
    'note'           => $finalNote,
    'created_by_name' => $finalCreatedByName,
];

echo json_encode([
    'ok'   => true,
    'grab' => $grab,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
