#!/usr/bin/env php
<?php
/**
 * tools/encode_worker.php — background encoder for proxy jobs
 *
 * Runs forever: claims one queued job, marks running, spawns ffmpeg,
 * streams -progress output, updates jobs_queue.progress_pct + heartbeat,
 * handles cancel/timeout, and finalizes (mark done/failed).
 */

declare(strict_types=1);

// ── Bootstrap your app (DB, env, helpers)
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../vendor/autoload.php';

use App\Support\DB;

$pdo = DB::pdo();
$hostname = php_uname('n') ?: 'worker';
$pid = getmypid();
$workerId = "$hostname:$pid";

// Directories / paths
$storDir    = rtrim(getenv('ZEN_STOR_DIR') ?: '/var/www/html/data', '/');
$publicBase = rtrim(getenv('ZEN_STOR_PUBLIC_BASE') ?: '/data', '/');

// Simple logger
function wlog(string $msg): void
{
    $ts = date('Y-m-d H:i:s');
    fwrite(STDERR, "[$ts] $msg\n");
}

// Graceful stop flag (Ctrl+C / SIGTERM inside container)
$stop = false;
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, function () use (&$stop) {
        $stop = true;
    });
    pcntl_signal(SIGINT,  function () use (&$stop) {
        $stop = true;
    });
}

// Helper: claim the next job atomically (MySQL 8 SKIP LOCKED)
function claimNextJob(PDO $pdo, string $workerId): ?array
{
    $pdo->beginTransaction();
    // Pick highest priority (lowest number), FIFO by id
    $stmt = $pdo->prepare("
        SELECT *
        FROM jobs_queue
        WHERE task_type = 'video' AND state = 'queued'
        ORDER BY priority ASC, id ASC
        LIMIT 1
        FOR UPDATE SKIP LOCKED
    ");
    $stmt->execute();
    $job = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$job) {
        $pdo->commit();
        return null;
    }

    $upd = $pdo->prepare("
        UPDATE jobs_queue
        SET state='running',
            worker_id=:w,
            started_at=NOW(),
            last_heartbeat=NOW(),
            progress_pct=0
        WHERE id=:id
        LIMIT 1
    ");
    $upd->execute([':w' => $workerId, ':id' => $job['id']]);
    $pdo->commit();

    // Refresh view of row
    $r = $pdo->prepare("SELECT * FROM jobs_queue WHERE id=:id");
    $r->execute([':id' => $job['id']]);
    return $r->fetch(PDO::FETCH_ASSOC) ?: $job;
}

// Helper: update progress + heartbeat
$stProgress = $pdo->prepare("
    UPDATE jobs_queue
    SET progress_pct = :p,
        last_heartbeat = NOW()
    WHERE id = :id
    LIMIT 1
");

// Helper: mark finished
$stDone = $pdo->prepare("
    UPDATE jobs_queue
    SET state='done',
        progress_pct=100,
        finished_at=NOW(),
        updated_at=NOW()
    WHERE id=:id
    LIMIT 1
");

// Helper: mark failed (+ attempts++)
$stFail = $pdo->prepare("
    UPDATE jobs_queue
    SET state='failed',
        attempts=attempts+1,
        finished_at=NOW(),
        updated_at=NOW()
    WHERE id=:id
    LIMIT 1
");

// Helper: check cancel flag
$stCancelCheck = $pdo->prepare("SELECT cancel_requested FROM jobs_queue WHERE id=:id");

// Helper: tiny sleep with signal dispatch
function nap(float $sec): void
{
    $us = (int)($sec * 1_000_000);
    usleep($us);
    if (function_exists('pcntl_signal_dispatch')) pcntl_signal_dispatch();
}

// Compute percent from out_time_ms and clip duration
function computePercent(int $outMs, ?int $durMs): int
{
    if (!$durMs || $durMs <= 0) {
        // unknown duration → show capped progress (0..99)
        return max(0, min(99, (int)floor($outMs / 1000)));
    }
    $pct = (int)floor(($outMs / $durMs) * 100);
    return max(0, min(99, $pct));
}

// Build ffmpeg command string for preset
function buildFfmpegCmd(string $src, string $dstPart, string $preset, ?int $fpsNum, ?int $fpsDen): string
{
    // Keep source fps unless preset forces it (we don't force here).
    // Scale: cap height at 720, keep aspect, no upscale beyond width.
    $vf = "scale='min(1280,iw)':'min(720,ih)':force_original_aspect_ratio=decrease";

    $bits = [
        'ffmpeg',
        '-hide_banner',
        '-v',
        'warning',
        '-y',
        '-nostdin',
        '-stats_period',
        '0.5',
        '-i',
        escapeshellarg($src),
        '-vf',
        escapeshellarg($vf),
        '-c:v',
        'libx264',
        '-preset',
        'veryfast',
        '-profile:v',
        'high',
        '-pix_fmt',
        'yuv420p',
        '-movflags',
        '+faststart',
        '-c:a',
        'aac',
        '-b:a',
        '128k',
        '-progress',
        'pipe:1',
        '-nostats',
        escapeshellarg($dstPart),
    ];

    return implode(' ', $bits);
}

// Ensure target folder exists
function ensureDir(string $path): void
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("Failed to create directory: $dir");
        }
    }
}

wlog("Encode worker started as $workerId");

while (!$stop) {
    $job = claimNextJob($pdo, $workerId);
    if (!$job) {
        nap(1.0);
        continue;
    }

    $jobId   = (int)$job['id'];
    $src     = $job['source_path'];
    $dst     = $job['target_path'];
    $preset  = $job['preset'];
    $dstPart = $dst . '.part';

    // Guard: source must exist
    if (!is_file($src)) {
        wlog("#$jobId FAIL: source not found: $src");
        $stFail->execute([':id' => $jobId]);
        continue;
    }

    // Read clip duration & fps rational (if available) for better ETA
    $clipQ = $pdo->prepare("
        SELECT duration_ms, fps_num, fps_den
        FROM clips
        WHERE id = :cid
        LIMIT 1
    ");
    $clipQ->execute([':cid' => $job['clip_id']]);
    $clip = $clipQ->fetch(PDO::FETCH_ASSOC) ?: ['duration_ms' => null, 'fps_num' => null, 'fps_den' => null];
    $durMs  = $clip['duration_ms'] ? (int)$clip['duration_ms'] : null;
    $fpsNum = $clip['fps_num'] ? (int)$clip['fps_num'] : null;
    $fpsDen = $clip['fps_den'] ? (int)$clip['fps_den'] : null;

    // Ensure destination dir exists
    try {
        ensureDir($dst);
    } catch (Throwable $e) {
        wlog("#$jobId FAIL: " . $e->getMessage());
        $stFail->execute([':id' => $jobId]);
        continue;
    }

    // Build command and store for audit
    $cmd = buildFfmpegCmd($src, $dstPart, $preset, $fpsNum, $fpsDen);
    $updCmd = $pdo->prepare("UPDATE jobs_queue SET ffmpeg_cmd=:cmd, log_path=:log, updated_at=NOW() WHERE id=:id LIMIT 1");
    $logPath = dirname($dst) . '/encode_' . basename($dst) . '.log';
    $updCmd->execute([':cmd' => $cmd, ':log' => $logPath, ':id' => $jobId]);

    wlog("#$jobId RUN → $cmd");

    // Spawn ffmpeg; capture stdout (-progress), and stderr to a log
    $descriptorspec = [
        0 => ['pipe', 'r'],                   // stdin
        1 => ['pipe', 'w'],                   // stdout (progress)
        2 => ['file', $logPath, 'a'],         // stderr → file
    ];

    $proc = proc_open($cmd, $descriptorspec, $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($proc)) {
        wlog("#$jobId FAIL: proc_open error");
        $stFail->execute([':id' => $jobId]);
        continue;
    }
    fclose($pipes[0]); // we don't write to stdin

    stream_set_blocking($pipes[1], false);
    $outTimeMs = 0;
    $lastUpdate = 0;

    // Progress loop
    while (true) {
        $status = proc_get_status($proc);
        $stdout = stream_get_contents($pipes[1]);
        if ($stdout !== false && $stdout !== '') {
            // Parse ffmpeg -progress key=value lines
            // Keys of interest: out_time_ms=123456, progress=continue|end
            $lines = preg_split('/\r\n|\r|\n/', $stdout);
            foreach ($lines as $ln) {
                if ($ln === '') continue;
                if (str_starts_with($ln, 'out_time_ms=')) {
                    $outTimeMs = (int)substr($ln, strlen('out_time_ms='));
                } elseif (str_starts_with($ln, 'progress=')) {
                    $progWord = substr($ln, strlen('progress='));
                    // Rate-limit DB writes ~5/sec
                    $now = microtime(true);
                    if ($progWord === 'continue') {
                        if ($now - $lastUpdate >= 0.2) {
                            $pct = computePercent($outTimeMs, $durMs);
                            $stProgress->execute([':p' => $pct, ':id' => $jobId]);
                            $lastUpdate = $now;
                        }
                    } elseif ($progWord === 'end') {
                        // Write 99 now; we'll set 100 on success
                        $stProgress->execute([':p' => 99, ':id' => $jobId]);
                    }
                }
            }
        }

        // Cancel?
        $stCancelCheck->execute([':id' => $jobId]);
        $cancel = (int)$stCancelCheck->fetchColumn() === 1;
        if ($cancel) {
            wlog("#$jobId CANCEL requested");
            proc_terminate($proc, 15);
            nap(0.5);
            proc_terminate($proc, 9);
            $stFail->execute([':id' => $jobId]);
            @fclose($pipes[1]);
            @proc_close($proc);
            @unlink($dstPart);
            continue 2; // next job
        }

        if (!$status['running']) {
            @fclose($pipes[1]);
            $code = proc_close($proc);
            if ($code === 0 && is_file($dstPart)) {
                // Atomically finalize
                if (@rename($dstPart, $dst) === false) {
                    // fallback copy/unlink
                    @copy($dstPart, $dst);
                    @unlink($dstPart);
                }

                // Insert / upsert a clip_assets record for the new proxy
                try {
                    $byteSize = @filesize($dst) ?: 0;
                    $storagePath = $dst;
                    if (str_starts_with($dst, $storDir)) {
                        $tail = substr($dst, strlen($storDir));
                        $storagePath = $publicBase . $tail; // e.g. /data/...
                    }
                    $ins = $pdo->prepare("
                        INSERT INTO clip_assets (clip_id, asset_type, storage_path, byte_size, created_at, updated_at)
                        VALUES (:cid, 'proxy_web', :path, :size, NOW(), NOW())
                    ");
                    $ins->execute([
                        ':cid'  => $job['clip_id'],
                        ':path' => $storagePath,
                        ':size' => $byteSize,
                    ]);
                } catch (Throwable $e) {
                    wlog("#$jobId WARN: asset insert failed: " . $e->getMessage());
                }

                $stDone->execute([':id' => $jobId]);
                wlog("#$jobId DONE");
            } else {
                wlog("#$jobId FAIL: ffmpeg exit code $code");
                @unlink($dstPart);
                $stFail->execute([':id' => $jobId]);
            }
            break;
        }

        nap(0.1);
        if ($stop) break;
    }

    if ($stop) break;
}

wlog("Encode worker $workerId stopped");
