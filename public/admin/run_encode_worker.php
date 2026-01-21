<?php
// public/admin/run_encode_worker.php — robust proxy encoder runner (mirrors run_backfill_fps.php)

declare(strict_types=1);

// --- Diagnostics ---
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

// Plain text is useful even if triggered via browser
header('Content-Type: text/plain; charset=utf-8');

// 1) Resolve project root: two levels up from /public/admin/
$root = realpath(__DIR__ . '/../../');
if ($root === false) {
    http_response_code(500);
    exit("FATAL: Cannot resolve project root from " . __DIR__ . PHP_EOL);
}

// 2) Load the real bootstrap (app/bootstrap.php)
$bootstrap = $root . '/app/bootstrap.php';
if (!is_file($bootstrap)) {
    http_response_code(500);
    exit("FATAL: bootstrap not found: $bootstrap" . PHP_EOL);
}
require_once $bootstrap;

// --- Load .env manually for CLI ---
$env = [];
$envFile = $root . '/.env';
if (is_file($envFile) && is_readable($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $ln) {
        if ($ln[0] === '#') continue;
        $pos = strpos($ln, '=');
        if ($pos === false) continue;
        $k = trim(substr($ln, 0, $pos));
        $v = trim(substr($ln, $pos + 1));
        if ($v !== '' && ($v[0] === '"' || $v[0] === "'")) $v = trim($v, "\"'");
        $env[$k] = $v;
        // expose to getenv() too
        putenv("$k=$v");
        $_ENV[$k]    = $v;
        $_SERVER[$k] = $v;
    }
}

// 3) Require login + superuser for web; bypass when running via CLI
if (PHP_SAPI !== 'cli') {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $acct = $_SESSION['account'] ?? null;
    if (!$acct || empty($acct['email'])) {
        http_response_code(403);
        exit("Forbidden: please log in.\n");
    }
    if (empty($acct['is_superuser'])) {
        http_response_code(403);
        exit("Forbidden: superuser only.\n");
    }
} else {
    // Optional: set a synthetic session identity for logs/audits
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $_SESSION['account'] = $_SESSION['account'] ?? [
        'email' => 'cli@localhost',
        'is_superuser' => 1,
    ];
}

// 4) DB handle — try App\Support\DB then raw PDO with ENV aliasing
use App\Support\DB;

$pdo = null;
$err = null;

try {
    $pdo = DB::pdo();
    if ($pdo instanceof PDO) {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }
} catch (Throwable $e) {
    $err = $e;
}

if (!$pdo) {
    $driver  = getenv('DB_DRIVER')   ?: 'mysql';
    $host    = getenv('DB_HOST')     ?: 'localhost';
    $port    = getenv('DB_PORT')     ?: '3306';
    // Alias both styles (your .env uses DB_NAME/DB_USER/DB_PASS)
    $db      = getenv('DB_DATABASE') ?: getenv('DB_NAME') ?: '';
    $user    = getenv('DB_USERNAME') ?: getenv('DB_USER') ?: '';
    $pass    = getenv('DB_PASSWORD') ?: getenv('DB_PASS') ?: '';
    $charset = getenv('DB_CHARSET')  ?: 'utf8mb4';

    $dsn = "{$driver}:host={$host};port={$port};dbname={$db};charset={$charset}";

    // Quick TCP reachability (non-fatal)
    $tcp = @fsockopen($host, (int)$port, $errno, $errstr, 1.0);
    if ($tcp) fclose($tcp);

    try {
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } catch (Throwable $e2) {
        echo "DB connection failed.\n";
        echo "DSN: {$dsn}\n";
        echo "User: " . ($user !== '' ? $user : '(empty)') . "\n";
        echo "TCP: " . ($tcp ? 'reachable' : 'UNREACHABLE') . "\n";
        if ($err) echo "Helper error: " . $err->getMessage() . "\n";
        echo "PDO error: " . $e2->getMessage() . "\n";
        exit(1);
    }
}

if (!$pdo) {
    echo "DB connection failed (unknown reason).\n";
    if ($err) echo "Helper error: " . $err->getMessage() . "\n";
    exit(1);
}

// --- Continue with encode worker logic ---

// Resolve envs used for path mapping
$storDir    = rtrim(getenv('ZEN_STOR_DIR') ?: '/var/www/html/data', '/');
$publicBase = rtrim(getenv('ZEN_STOR_PUBLIC_BASE') ?: '/data', '/');

// Simple logger
function wlog(string $msg): void
{
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . "] $msg\n");
}

// Graceful stop
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

// Helpers
function nap(float $sec): void
{
    usleep((int)($sec * 1_000_000));
    if (function_exists('pcntl_signal_dispatch')) pcntl_signal_dispatch();
}

function computePercent(int $outMs, ?int $durMs): int
{
    if (!$durMs || $durMs <= 0) return max(0, min(99, (int)floor($outMs / 1000)));
    return max(0, min(99, (int)floor(($outMs / $durMs) * 100)));
}

function ensureDir(string $path): void
{
    $dir = dirname($path);
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("Failed to create directory: $dir");
        }
    }
}

function buildFfmpegCmd(string $src, string $dstPart, string $preset): string
{
    // Waveform Preset
    if ($preset === 'waveform') {
        return implode(' ', [
            'ffmpeg',
            '-v',
            'quiet',
            '-i',
            escapeshellarg($src),
            '-ac',
            '1',
            '-filter:a',
            'aresample=8000',
            '-map',
            '0:a',
            '-f',
            's16le',
            '-', // Output raw PCM to stdout for the PHP logic to process
        ]);
    }

    // Default Proxy Preset
    $vf = "scale='min(1280,iw)':'min(720,ih)':force_original_aspect_ratio=decrease";
    return implode(' ', [
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
        '-threads',
        '2',
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
        '-f',
        'mp4',
        escapeshellarg($dstPart),
    ]);
}

/**
 * Processes a waveform job: Extracts PCM and calculates peaks
 */
function processWaveformJob(string $src, string $dst): bool
{
    $tmpPcm = $dst . '.pcm';
    // Extract raw PCM 16-bit mono at 8000Hz
    $cmd = "ffmpeg -v quiet -y -i " . escapeshellarg($src) . " -ac 1 -filter:a aresample=8000 -f s16le " . escapeshellarg($tmpPcm);
    exec($cmd);

    if (!is_file($tmpPcm)) return false;

    $data = file_get_contents($tmpPcm);
    $samples = unpack('s*', $data); // Unpack as signed 16-bit integers
    @unlink($tmpPcm);

    if (!$samples) return false;

    $peaks = [];
    $sampleCount = count($samples);
    $groupSize = max(1, (int)floor($sampleCount / 1200)); // Target ~1200 bars

    for ($i = 1; $i <= $sampleCount; $i += $groupSize) {
        $max = 0;
        for ($j = 0; $j < $groupSize && ($i + $j) <= $sampleCount; $j++) {
            $val = abs($samples[$i + $j] / 32768); // Normalize to 0.0 - 1.0
            if ($val > $max) $max = $val;
        }
        $peaks[] = round($max, 4);
    }

    return file_put_contents($dst, json_encode($peaks)) !== false;
}

$hostname = php_uname('n') ?: 'worker';
$pid = getmypid();
$workerId = "$hostname:$pid";

// Prepared statements used repeatedly
$stClaim = $pdo->prepare("
    SELECT * FROM encode_jobs
    WHERE state = 'queued'
    ORDER BY priority ASC, id ASC
    LIMIT 1
    FOR UPDATE SKIP LOCKED
");
$stMarkRunning = $pdo->prepare("
    UPDATE encode_jobs
    SET state='running', worker_id=:w, started_at=NOW(), last_heartbeat=NOW(), progress_pct=0
    WHERE id=:id LIMIT 1
");
$stRefresh      = $pdo->prepare("SELECT * FROM encode_jobs WHERE id=:id");
$stProgress     = $pdo->prepare("UPDATE encode_jobs SET progress_pct=:p, last_heartbeat=NOW() WHERE id=:id LIMIT 1");
$stDone         = $pdo->prepare("UPDATE encode_jobs SET state='done', progress_pct=100, finished_at=NOW(), updated_at=NOW() WHERE id=:id LIMIT 1");
$stFail         = $pdo->prepare("UPDATE encode_jobs SET state='failed', attempts=attempts+1, finished_at=NOW(), updated_at=NOW() WHERE id=:id LIMIT 1");
$stCancelCheck  = $pdo->prepare("SELECT cancel_requested FROM encode_jobs WHERE id=:id");
$stSetCmd       = $pdo->prepare("UPDATE encode_jobs SET ffmpeg_cmd=:cmd, log_path=:log, updated_at=NOW() WHERE id=:id LIMIT 1");
$stClipMeta     = $pdo->prepare("SELECT duration_ms FROM clips WHERE id=:cid LIMIT 1");

// make sure we only ever have one proxy_web asset per clip
$stDeleteAsset  = $pdo->prepare("
    DELETE FROM clip_assets
    WHERE clip_id = :cid AND asset_type = 'proxy_web'
");

$stRunningCount = $pdo->prepare("SELECT COUNT(*) FROM encode_jobs WHERE state = 'running'");

$stInsertAsset  = $pdo->prepare("
    INSERT INTO clip_assets (clip_id, asset_type, storage_path, byte_size, created_at)
    VALUES (:cid, :type, :path, :size, NOW())
");

wlog("encode worker started: $workerId");

while (!$stop) {
    $pauseFile = $storDir . '/.worker_pause';
    if (file_exists($pauseFile)) {
        if ($runningNow === 0) wlog("Worker Paused via Console. Waiting...");
        nap(5.0);
        continue;
    }
    // Enforce a global max of 2 running encode jobs
    $stRunningCount->execute();
    $runningNow = (int)$stRunningCount->fetchColumn();
    if ($runningNow >= 2) {
        // Too many jobs already running – wait a bit and try again
        nap(1.0);
        continue;
    }

    // Claim one job atomically

    $pdo->beginTransaction();
    $stClaim->execute();
    $job = $stClaim->fetch(PDO::FETCH_ASSOC);
    if (!$job) {
        $pdo->commit();
        nap(1.0);
        continue;
    }
    $stMarkRunning->execute([':w' => $workerId, ':id' => $job['id']]);
    $pdo->commit();

    // Refresh view
    $stRefresh->execute([':id' => $job['id']]);
    $job = $stRefresh->fetch(PDO::FETCH_ASSOC) ?: $job;

    $jobId   = (int)$job['id'];
    $src     = $job['source_path'];
    $dst     = $job['target_path'];
    $preset  = $job['preset'];
    $dstPart = $dst . '.part';

    if (!is_file($src)) {
        wlog("#$jobId FAIL: source not found: $src");
        $stFail->execute([':id' => $jobId]);
        continue;
    }

    // Duration for progress %
    $stClipMeta->execute([':cid' => $job['clip_id']]);
    $durMsRow = $stClipMeta->fetch(PDO::FETCH_ASSOC);
    $durMs = $durMsRow && $durMsRow['duration_ms'] ? (int)$durMsRow['duration_ms'] : null;

    try {
        ensureDir($dst);
    } catch (Throwable $e) {
        wlog("#$jobId FAIL: " . $e->getMessage());
        $stFail->execute([':id' => $jobId]);
        continue;
    }

    // --- Determine Public Storage Path ---
    $storagePath = $dst;
    if (str_starts_with($dst, $storDir)) {
        $tail = substr($dst, strlen($storDir));
        $storagePath = $publicBase . $tail;
    }

    // --- Branch: Waveform vs Video Proxy ---
    if ($preset === 'waveform') {
        wlog("#$jobId RUN (Waveform) → $src");
        if (processWaveformJob($src, $dst)) {
            // Register asset as 'waveform'
            $stDeleteGeneric = $pdo->prepare("DELETE FROM clip_assets WHERE clip_id = :cid AND asset_type = 'waveform'");
            $stDeleteGeneric->execute([':cid' => $job['clip_id']]);

            $stInsertAsset->execute([
                ':cid'  => $job['clip_id'],
                ':type' => 'waveform',
                ':path' => $storagePath,
                ':size' => @filesize($dst) ?: 0
            ]);

            $stDone->execute([':id' => $jobId]);
            wlog("#$jobId DONE");
        } else {
            $stFail->execute([':id' => $jobId]);
            wlog("#$jobId FAIL");
        }
        continue; // Move to next job
    }

    // Default Video Logic (proc_open) follows...
    $cmd = buildFfmpegCmd($src, $dstPart, $preset);
    $logPath = dirname($dst) . '/encode_' . basename($dst) . '.log';
    $stSetCmd->execute([':cmd' => $cmd, ':log' => $logPath, ':id' => $jobId]);

    wlog("#$jobId RUN → $cmd");

    $descriptorspec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],          // -progress
        2 => ['file', $logPath, 'a'] // stderr
    ];
    $proc = proc_open($cmd, $descriptorspec, $pipes, null, null, ['bypass_shell' => true]);
    if (!is_resource($proc)) {
        wlog("#$jobId FAIL: proc_open error");
        $stFail->execute([':id' => $jobId]);
        continue;
    }
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);

    $outTimeMs = 0;
    $lastUpdate = 0.0;

    // Progress loop
    while (true) {
        $status = proc_get_status($proc);
        $stdout = stream_get_contents($pipes[1]);
        if ($stdout !== false && $stdout !== '') {
            foreach (preg_split('/\r\n|\r|\n/', $stdout) as $ln) {
                if ($ln === '') continue;
                if (str_starts_with($ln, 'out_time_ms=')) {
                    $outTimeMs = (int)substr($ln, 12);
                } elseif (str_starts_with($ln, 'progress=')) {
                    $word = substr($ln, 9); // continue|end
                    $now = microtime(true);
                    if ($word === 'continue' && $now - $lastUpdate >= 0.25) {
                        $stProgress->execute([':p' => computePercent($outTimeMs, $durMs), ':id' => $jobId]);
                        $lastUpdate = $now;
                    } elseif ($word === 'end') {
                        $stProgress->execute([':p' => 99, ':id' => $jobId]);
                    }
                }
            }
        }

        // Cancel?
        $stCancelCheck->execute([':id' => $jobId]);
        if ((int)$stCancelCheck->fetchColumn() === 1) {
            wlog("#$jobId CANCEL requested");
            proc_terminate($proc, 15);
            nap(0.5);
            proc_terminate($proc, 9);
            @fclose($pipes[1]);
            @proc_close($proc);
            @unlink($dstPart);
            $stFail->execute([':id' => $jobId]);
            continue 2; // next job
        }

        if (!$status['running']) {
            @fclose($pipes[1]);
            $code = proc_close($proc);
            if ($code === 0 && is_file($dstPart)) {
                if (@rename($dstPart, $dst) === false) {
                    @copy($dstPart, $dst);
                    @unlink($dstPart);
                }
                // Map absolute FS path to public /data URL if under stor dir
                $byteSize = @filesize($dst) ?: 0;
                $storagePath = $dst;
                if (str_starts_with($dst, $storDir)) {
                    $tail = substr($dst, strlen($storDir));
                    $storagePath = $publicBase . $tail;
                }

                try {
                    // Update the asset insertion execution
                    $assetType = ($preset === 'waveform') ? 'waveform' : 'proxy_web';

                    // ensure we only have one asset of this type for this clip
                    $stDeleteGeneric = $pdo->prepare("DELETE FROM clip_assets WHERE clip_id = :cid AND asset_type = :type");
                    $stDeleteGeneric->execute([':cid' => $job['clip_id'], ':type' => $assetType]);

                    $stInsertAsset->execute([
                        ':cid'  => $job['clip_id'],
                        ':type' => $assetType,
                        ':path' => $storagePath,
                        ':size' => $byteSize,
                    ]);
                } catch (Throwable $e) {
                    wlog("#$jobId WARN: asset insert failed: " . $e->getMessage());
                }

                $stDone->execute([':id' => $jobId]);
                wlog("#$jobId DONE");
            } else {
                @unlink($dstPart);
                $stFail->execute([':id' => $jobId]);
                wlog("#$jobId FAIL: ffmpeg exit code $code");
            }
            break;
        }

        nap(0.1);
        if ($stop) break;
    }
}

wlog("encode worker stopped: $workerId");
