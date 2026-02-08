<?php
// public/admin/run_backfill_fps.php — robust FPS backfill runner with diagnostics

declare(strict_types=1);

// --- Diagnostics: show errors while we run (remove afterward) ---
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

// Return plain text so we can read output easily in browser
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

// 3) Require login + superuser (adjust if admins may run it)
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

// 4) DB handle (via App\Support\DB)
use App\Support\DB;

try {
    $pdo = DB::pdo();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Throwable $e) {
    http_response_code(500);
    exit("FATAL: DB connection failed: " . $e->getMessage() . PHP_EOL);
}

// 5) Find ffprobe (web context may not have PATH)
$ffprobe = getenv('FFPROBE_BIN') ?: '/usr/bin/ffprobe';
if (!is_file($ffprobe) || !is_executable($ffprobe)) {
    // common alternate path in Debian/Ubuntu images
    if (is_file('/usr/bin/ffprobe') && is_executable('/usr/bin/ffprobe')) {
        $ffprobe = '/usr/bin/ffprobe';
    } elseif (is_file('/usr/local/bin/ffprobe') && is_executable('/usr/local/bin/ffprobe')) {
        $ffprobe = '/usr/local/bin/ffprobe';
    } else {
        echo "WARNING: ffprobe not found or not executable at '$ffprobe'.\n";
        echo "Set env FFPROBE_BIN or install ffprobe. Aborting.\n";
        exit(1);
    }
}

// 6) Helper: probe FPS safely
function probeFps(string $ffprobe, ?string $absPath): ?array
{
    if (!$absPath || !is_file($absPath)) return null;

    $cmd = sprintf(
        '%s -v error -select_streams v:0 -show_entries stream=r_frame_rate,avg_frame_rate -of json %s 2>&1',
        escapeshellcmd($ffprobe),
        escapeshellarg($absPath)
    );
    $out = shell_exec($cmd);
    if (!$out) return null;

    $j = json_decode($out, true);
    if (!is_array($j)) return null;
    $s = $j['streams'][0] ?? null;
    if (!$s) return null;

    $rate = $s['r_frame_rate'] ?? $s['avg_frame_rate'] ?? null; // e.g. "24000/1001"
    if (!$rate || $rate === '0/0') return null;

    if (!preg_match('#^(\d+)\s*/\s*(\d+)$#', $rate, $m)) return null;
    $num = (int)$m[1];
    $den = (int)$m[2];
    if ($num <= 0 || $den <= 0) return null;

    $dec = round($num / $den, 3);
    return ['fps' => $dec, 'num' => $num, 'den' => $den];
}

// 7) Query clips to backfill (adapt columns if your schema differs)
try {
    $rows = $pdo->query("SELECT id, file_path, proxy_path, fps, fps_num, fps_den FROM clips")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    http_response_code(500);
    exit("FATAL: Query failed: " . $e->getMessage() . PHP_EOL);
}

$upd = $pdo->prepare("UPDATE clips SET fps = :fps, fps_num = :n, fps_den = :d WHERE id = :id");

// Base path used when stored paths are relative (keeps absolute paths untouched)
$fsRoot = '/var/www/html';

$ok = 0;
$updated = 0;
$fail = 0;
$missingFiles = 0;
$probeErrors = 0;

foreach ($rows as $r) {
    // Already has fps? keep it.
    if (!empty($r['fps'])) {
        $ok++;
        continue;
    }

    // Prefer proxy if present
    $rel = $r['proxy_path'] ?: $r['file_path'] ?: '';
    $abs = $rel
        ? (str_starts_with($rel, '/') ? $rel : $fsRoot . '/' . ltrim($rel, '/'))
        : null;

    if (!$abs || !is_file($abs)) {
        $missingFiles++;
        $fail++;
        continue;
    }

    $p = probeFps($ffprobe, $abs);
    if (!$p) {
        $probeErrors++;
        $fail++;
        continue;
    }

    try {
        $upd->execute([
            ':fps' => $p['fps'],
            ':n'   => $p['num'],
            ':d'   => $p['den'],
            ':id'  => $r['id'],
        ]);
        $updated++;
        $ok++;
    } catch (Throwable $e) {
        $fail++;
        echo "ERROR: Update failed for clip {$r['id']}: " . $e->getMessage() . "\n";
    }
}

// 8) Summary
echo "Backfill FPS complete\n";
echo "Clips total: " . count($rows) . "\n";
echo "Updated:     {$updated}\n";
echo "Kept:        {$ok}\n";
echo "Failed:      {$fail}\n";
if ($missingFiles > 0) echo " - Missing files: {$missingFiles}\n";
if ($probeErrors > 0)  echo " - ffprobe errors: {$probeErrors}\n";

// 9) Hint for typical 500s
// Check the Apache/PHP-FPM error log if you still see a blank 500:
//   - Debian/Ubuntu Apache:   /var/log/apache2/error.log
//   - PHP-FPM in container:   docker logs <php-fpm-container>
