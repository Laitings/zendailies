<?php
// zengrabber/admin_reopen_invite.php
require __DIR__ . '/config.php';

ini_set('display_errors', 1);
error_reporting(E_ALL);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo "Method not allowed";
    exit;
}

$invite_id = isset($_POST['invite_id']) ? (int)$_POST['invite_id'] : 0;
$movie_id  = isset($_POST['movie_id']) ? (int)$_POST['movie_id'] : 0;

if ($invite_id <= 0 || $movie_id <= 0) {
    http_response_code(400);
    echo "Missing invite_id or movie_id";
    exit;
}

$pdo = zg_pdo();

// Make sure invite belongs to given movie (safety)
$stmt = $pdo->prepare("SELECT id, movie_id FROM invite_links WHERE id = :id LIMIT 1");
$stmt->execute([':id' => $invite_id]);
$invite = $stmt->fetch();

if (!$invite || (int)$invite['movie_id'] !== $movie_id) {
    http_response_code(404);
    echo "Invite not found";
    exit;
}

// Reopen: clear is_finalized flag
$upd = $pdo->prepare("
    UPDATE invite_links
    SET is_finalized = 0,
        finalized_at = NULL
    WHERE id = :id
");
$upd->execute([':id' => $invite_id]);

header('Location: admin_invites.php?movie_id=' . $movie_id);
exit;
