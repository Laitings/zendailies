<?php
// zengrabber/api_finalize_invite.php
ini_set('display_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json');

require __DIR__ . '/config.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Optional: load Composer autoloader if present (for PHPMailer)
$mailAvailable = false;
$autoloadPath  = __DIR__ . '/vendor/autoload.php';

if (file_exists($autoloadPath)) {
    require $autoloadPath;
    if (class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
        $mailAvailable = true;
    } else {
        error_log('ZENGRABBER finalize: PHPMailer class not found after autoload.');
    }
} else {
    error_log('ZENGRABBER finalize: vendor/autoload.php missing, skipping mail setup.');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Invalid method']);
    exit;
}

$token = $_POST['token'] ?? '';

if ($token === '') {
    echo json_encode(['ok' => false, 'error' => 'Missing token']);
    exit;
}

try {
    $pdo = zg_pdo();
} catch (Throwable $e) {
    error_log('ZENGRABBER finalize: DB connect error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'DB connection error']);
    exit;
}

try {
    $pdo->beginTransaction();

    // Lock the invite row so two people can’t finalize at the same time
    $stmt = $pdo->prepare("
        SELECT
            il.*,
            m.title      AS movie_title,
            a.full_name  AS admin_name,
            a.email      AS admin_email
        FROM invite_links il
        JOIN movies m ON m.id = il.movie_id
        LEFT JOIN admins a ON a.id = il.created_by_admin_id
        WHERE il.token = :token
        FOR UPDATE
    ");
    $stmt->execute([':token' => $token]);
    $invite = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$invite) {
        $pdo->rollBack();
        echo json_encode(['ok' => false, 'error' => 'Invite not found']);
        return;
    }

    // Already finalized? Just return ok=true so the UI doesn’t block
    if ((int)$invite['is_finalized'] === 1) {
        $pdo->commit();
        echo json_encode(['ok' => true, 'alreadyFinalized' => true]);
        return;
    }

    // Mark as finalized
    $upd = $pdo->prepare("
        UPDATE invite_links
        SET is_finalized = 1,
            finalized_at  = NOW()
        WHERE id = :id
    ");
    $upd->execute([':id' => $invite['id']]);

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('ZENGRABBER finalize: DB error: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'DB error']);
    exit;
}

// --- Optional: send mail to admin, but NEVER fail finalization because of this ---

try {
    $adminEmail = trim($invite['admin_email'] ?? '');
    $adminName  = $invite['admin_name'] ?? '';
    $movieTitle = $invite['movie_title'] ?? '';
    $inviteName = $invite['full_name'] ?? '';

    // If we don't know who to mail, just skip quietly
    if ($mailAvailable && $adminEmail !== '') {
        $mailHost     = getenv('MAIL_HOST') ?: '';
        $mailPort     = (int)(getenv('MAIL_PORT') ?: 0);
        $mailSecure   = getenv('MAIL_SECURE') ?: 'tls';
        $mailUser     = getenv('MAIL_USER') ?: '';
        $mailFrom     = getenv('MAIL_FROM') ?: $mailUser;
        $mailPassFile = getenv('MAIL_PASS_FILE') ?: '';

        $mailPass = '';
        if ($mailPassFile && file_exists($mailPassFile)) {
            $mailPass = trim((string)file_get_contents($mailPassFile));
        }

        if ($mailHost && $mailPort && $mailUser && $mailPass) {
            $mailer = new PHPMailer(true);
            $mailer->isSMTP();
            $mailer->Host       = $mailHost;
            $mailer->Port       = $mailPort;
            $mailer->SMTPAuth   = true;
            $mailer->Username   = $mailUser;
            $mailer->Password   = $mailPass;
            $mailer->SMTPSecure = $mailSecure; // 'tls' from env

            $mailer->CharSet = 'UTF-8';

            $mailer->setFrom($mailFrom ?: $mailUser, 'Zengrabber');
            $mailer->addAddress($adminEmail, $adminName);

            $subjectMovie = $movieTitle !== '' ? " – {$movieTitle}" : '';
            $mailer->Subject = "Zengrabber: List finalized{$subjectMovie}";

            $inviteNameSafe = $inviteName ?: 'A reviewer';
            $movieTitleSafe = $movieTitle ?: 'a movie';

            $body  = "{$inviteNameSafe} has finalized their Zengrabber screengrab list";
            if ($movieTitleSafe) {
                $body .= " for \"{$movieTitleSafe}\".";
            } else {
                $body .= '.';
            }

            $body .= "\n\n";
            $body .= "You can now log into the admin interface to review and export EDL/PDF.\n\n";
            $body .= "This is an automatic message from Zengrabber.\n";

            $mailer->Body = $body;

            $mailer->send();
            error_log("ZENGRABBER finalize: Mail sent to {$adminEmail}");
        } else {
            error_log('ZENGRABBER finalize: Mail config incomplete, skipping mail.');
        }
    } else {
        if (!$mailAvailable) {
            error_log('ZENGRABBER finalize: PHPMailer not available, skipping mail.');
        } elseif ($adminEmail === '') {
            error_log('ZENGRABBER finalize: No admin email on invite, skipping mail.');
        }
    }
} catch (Throwable $e) {
    // Don’t break finalization because of mail issues
    error_log('ZENGRABBER finalize: Mail error: ' . $e->getMessage());
}

// Final JSON response for the frontend
echo json_encode(['ok' => true]);
