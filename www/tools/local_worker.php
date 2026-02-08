#!/usr/bin/env php
<?php
/**
 * tools/local_worker.php — Universal CPU Worker
 * Handles: Emails (HTML), Posters (from time), and Waveforms
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../vendor/autoload.php';

use App\Support\DB;
use App\Support\Mailer;
use App\Services\FFmpegService;

// Ensure this path matches your container's ffmpeg location
$ffmpegPath = trim(shell_exec('which ffmpeg') ?: '/usr/bin/ffmpeg');
$ffmpeg = new FFmpegService($ffmpegPath);

$pdo = DB::pdo();
$workerId = "cpu_worker_" . getmypid();

echo "[$workerId] Started. Listening on 'default' queue (Emails, Posters, Waveforms)...\n";

while (true) {
    $pdo->beginTransaction();

    // 1. Claim ANY job from the 'default' queue
    $stmt = $pdo->prepare("
        SELECT * FROM jobs_queue 
        WHERE queue = 'default'
          AND state = 'queued' 
        ORDER BY priority ASC, created_at ASC 
        LIMIT 1 
        FOR UPDATE SKIP LOCKED
    ");
    $stmt->execute();
    $job = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$job) {
        $pdo->rollBack();
        sleep(2);
        continue;
    }

    // 2. Mark as running
    $pdo->prepare("UPDATE jobs_queue SET state = 'running', worker_id = ?, started_at = NOW() WHERE id = ?")
        ->execute([$workerId, $job['id']]);
    $pdo->commit();

    $id = $job['id'];
    $preset = $job['preset'];
    echo "[$workerId] Job #$id ($preset) started...\n";

    try {
        $payload = json_decode($job['payload'] ?? '{}', true);
        $source  = $job['source_path'];
        $target  = $job['target_path'];

        switch ($preset) {

            // ==========================
            // CASE 1: EMAILS
            // ==========================
            case 'email':
                $email = $payload['email'] ?? null;
                $recipientName = $payload['first_name'] ?? 'User';

                if (!$email) throw new Exception("No email address in payload");

                $mail = Mailer::create();
                $mail->addAddress($email, $recipientName);
                $mail->isHTML(true);
                $mail->Subject = "New Dailies: " . ($payload['project_title'] ?? 'Zentropa');

                // Your HTML Design
                $mail->Body = "
                <!DOCTYPE html>
                <html>
                <head>
                    <meta charset='utf-8'>
                    <meta name='viewport' content='width=device-width, initial-scale=1'>
                    <style>
                        body { margin: 0; padding: 0; }
                        @media only screen and (max-width: 600px) { .content { padding: 30px 20px !important; } }
                    </style>
                </head>
                <body style='margin:0; padding:0; width:100%; background-color:#f4f6f9; font-family:Arial,sans-serif;'>
                <table role='presentation' width='100%' cellpadding='0' cellspacing='0' border='0' style='background-color:#f4f6f9;'>
                    <tr>
                        <td align='center' style='padding:50px 20px;'>
                            <table role='presentation' width='100%' cellpadding='0' cellspacing='0' border='0' style='max-width:600px; background-color:#ffffff; border-radius:8px; box-shadow:0 2px 8px rgba(0,0,0,0.08);'>
                                <tr>
                                    <td style='padding:40px 40px 30px 40px; text-align:center; border-bottom:3px solid #3aa0ff;'>
                                        <h1 style='margin:0; color:#3aa0ff; font-size:14px; text-transform:uppercase; letter-spacing:0.2em; font-weight:800;'>ZENTROPA DAILIES</h1>
                                    </td>
                                </tr>
                                <tr>
                                    <td class='content' style='padding:45px 50px;'>
                                        <h2 style='margin:0 0 24px 0; font-size:24px; font-weight:700; color:#1a1a1a;'>New dailies published for " . htmlspecialchars($payload['day_title'] ?? 'New Day') . "</h2>
                                        <p style='font-size:16px; line-height:1.6; color:#4a5568;'>Hi " . htmlspecialchars($recipientName) . ",</p>
                                        <p style='font-size:16px; line-height:1.6; color:#4a5568; margin-bottom: 30px;'>
                                            New clips have been published for <strong>" . htmlspecialchars($payload['project_title'] ?? 'Project') . "</strong>:<br>
                                            " . htmlspecialchars($payload['day_title'] ?? 'New Day') . "
                                        </p>
                                        <table role='presentation' width='100%' cellpadding='0' cellspacing='0' border='0'>
                                            <tr>
                                                <td align='center'>
                                                    <a href='" . ($payload['link'] ?? '#') . "' style='background-color:#3aa0ff; color:#ffffff; padding:18px 40px; border-radius:5px; text-decoration:none; font-size:16px; font-weight:700; display:inline-block;'>View Dailies</a>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                                <tr>
                                    <td style='padding:30px 40px; background-color:#f7fafc; text-align:center; border-radius:0 0 8px 8px;'>
                                        <p style='margin:0; font-size:11px; color:#a0aec0;'>© " . date('Y') . " Zentropa Post Production</p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>
                </body>
                </html>";

                $mail->AltBody = "Hi {$recipientName},\n\nNew dailies have been published for " . ($payload['project_title'] ?? 'Project') . ": " . ($payload['day_title'] ?? 'New Day') . "\n\nView them here: " . ($payload['link'] ?? '');

                if (!$mail->send()) {
                    throw new Exception("Mailer failed: " . $mail->ErrorInfo);
                }
                break;

            // ==========================
            // CASE 2: POSTERS
            // ==========================
            case 'poster':
                if (!file_exists($source)) throw new Exception("Source file missing: $source");

                // Use the same method as the Controller to ensure consistency
                // Controller uses: generatePoster($source, $dest, $seekSeconds, 640)
                $seekSeconds = isset($payload['time']) ? (int)$payload['time'] : 2;
                $width       = 640;

                // Ensure target doesn't block FFmpeg (delete if exists)
                if (file_exists($target)) {
                    @unlink($target);
                }

                // Ensure directory exists
                if (!is_dir(dirname($target))) {
                    @mkdir(dirname($target), 0777, true);
                }

                // Use generatePoster (instead of FromTime) to match Controller logic
                $res = $ffmpeg->generatePoster($source, $target, $seekSeconds, $width);

                if (!$res['ok']) {
                    throw new Exception("FFmpeg Poster Error: " . ($res['err'] ?? 'Unknown'));
                }

                // --- Register asset in DB ---
                if (!empty($payload['public_path']) && !empty($job['clip_id'])) {
                    $publicPath = $payload['public_path'];
                    $fileSize   = @filesize($target) ?: null;

                    // Get dimensions for the DB
                    [$imgW, $imgH] = @getimagesize($target) ?: [null, null];

                    // 1. Clean up old asset
                    $del = $pdo->prepare("DELETE FROM clip_assets WHERE clip_id = ? AND asset_type = 'poster'");
                    $del->execute([$job['clip_id']]);

                    // 2. Insert new asset
                    $ins = $pdo->prepare("
                        INSERT INTO clip_assets 
                        (id, clip_id, asset_type, storage_path, byte_size, width, height, codec, created_at)
                        VALUES (uuid_to_bin(uuid(),1), ?, 'poster', ?, ?, ?, ?, 'image/jpeg', NOW())
                    ");
                    $ins->execute([$job['clip_id'], $publicPath, $fileSize, $imgW, $imgH]);

                    // Optional: Update clip ingest_state ONLY if metadata exists (to satisfy Check Constraint)
                    $upd = $pdo->prepare("
                        UPDATE clips 
                        SET ingest_state = 'ready' 
                        WHERE id = ? 
                          AND ingest_state = 'provisional'
                          AND scene IS NOT NULL 
                          AND slate IS NOT NULL 
                          AND take IS NOT NULL
                    ");
                    $upd->execute([$job['clip_id']]);
                }
                break;

            // ==========================
            // CASE 3: WAVEFORMS
            // ==========================
            case 'waveform':
                if (!file_exists($source)) throw new Exception("Source file missing: $source");

                // Using your specific JSON function that handles writing to file
                // ==========================
                // CASE 3: WAVEFORMS
                // ==========================
            case 'waveform':
                if (!file_exists($source)) throw new Exception("Source file missing: $source");

                // Using your specific JSON function that handles writing to file
                $res = $ffmpeg->generateWaveformJson($source, $target);

                if (!$res['ok']) {
                    throw new Exception("Waveform Error: " . ($res['err'] ?? 'Unknown error'));
                }

                // --- ADDED: Register asset in DB if public_path is provided ---
                if (!empty($payload['public_path']) && !empty($job['clip_id'])) {
                    $publicPath = $payload['public_path'];
                    $byteSize   = @filesize($target) ?: null;

                    // 1. Remove old waveform entry for this clip to avoid duplicates
                    $del = $pdo->prepare("DELETE FROM clip_assets WHERE clip_id = ? AND asset_type = 'waveform'");
                    $del->execute([$job['clip_id']]);

                    // 2. Insert new entry
                    $ins = $pdo->prepare("
                        INSERT INTO clip_assets 
                        (id, clip_id, asset_type, storage_path, byte_size, codec, created_at)
                        VALUES (uuid_to_bin(uuid(),1), ?, 'waveform', ?, ?, 'json', NOW())
                    ");
                    $ins->execute([$job['clip_id'], $publicPath, $byteSize]);
                }
                break;

            default:
                throw new Exception("Unknown preset type: $preset");
        }

        // Success
        $pdo->prepare("UPDATE jobs_queue SET state = 'done', progress_pct = 100, finished_at = NOW() WHERE id = ?")
            ->execute([$id]);
        echo "[$workerId] Job #$id ($preset) DONE.\n";
    } catch (Throwable $e) {
        // Failure
        $pdo->prepare("UPDATE jobs_queue SET state = 'failed', error_msg = ? WHERE id = ?")
            ->execute([substr($e->getMessage(), 0, 255), $id]);
        echo "[$workerId] Job #$id FAILED: " . $e->getMessage() . "\n";
    }
}
