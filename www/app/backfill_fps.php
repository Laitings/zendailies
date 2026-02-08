<?php
// CLI bootstrap for backfill_fps.php

// 1) Resolve project root and make it CWD
$root = realpath(__DIR__ . '/..');
if ($root === false) {
    fwrite(STDERR, "[FATAL] Cannot resolve project root from " . __DIR__ . PHP_EOL);
    exit(1);
}
chdir($root);

// 2) Try known bootstrap/autoload locations (root first, then /app, then Composer)
$candidates = [
    $root . '/bootstrap.php',        // canonical (preferred)
    $root . '/app/bootstrap.php',    // some snapshots keep it under /app
    $root . '/autoload.php',         // legacy manual autoloader
    $root . '/app/autoload.php',     // legacy alt path
    $root . '/vendor/autoload.php',  // Composer (fallback if your DB bootstrap runs elsewhere)
];

$loaded = false;
foreach ($candidates as $p) {
    if (is_file($p)) {
        require_once $p;
        $loaded = true;
        break;
    }
}

if (!$loaded) {
    fwrite(STDERR, "[FATAL] No bootstrap/autoload found. Tried:\n  - " . implode("\n  - ", $candidates) . PHP_EOL);
    exit(1);
}

use App\Support\DB;

// 3) DB handle via your project DB wrapper
$pdo = DB::pdo();



// Adjust table/column names if yours differ:
$sql = "SELECT id, file_path, proxy_path, fps, fps_num, fps_den FROM clips";
$rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

function probeFps($absPath): ?array
{
    if (!$absPath || !is_file($absPath)) return null;

    $cmd = sprintf(
        'ffprobe -v error -select_streams v:0 -show_entries stream=r_frame_rate,avg_frame_rate -of json %s',
        escapeshellarg($absPath)
    );
    $out = shell_exec($cmd);
    if (!$out) return null;
    $j = json_decode($out, true);
    $s = $j['streams'][0] ?? null;
    if (!$s) return null;

    // Prefer r_frame_rate; fall back to avg_frame_rate
    $rate = $s['r_frame_rate'] ?? $s['avg_frame_rate'] ?? null; // e.g. "24000/1001" or "24/1"
    if (!$rate || $rate === '0/0') return null;

    // Parse "num/den"
    if (!preg_match('#^(\d+)\s*/\s*(\d+)$#', $rate, $m)) return null;
    $num = (int)$m[1];
    $den = (int)$m[2];
    if ($num <= 0 || $den <= 0) return null;

    // decimal (keep 3 decimals)
    $dec = round($num / $den, 3);

    return ['fps' => $dec, 'num' => $num, 'den' => $den];
}

$upd = $pdo->prepare("UPDATE clips SET fps = :fps, fps_num = :n, fps_den = :d WHERE id = :id");

$root = '/var/www/html'; // base in the container
$ok = $fail = 0;

foreach ($rows as $r) {
    if (!empty($r['fps'])) {
        $ok++;
        continue;
    }

    // decide path: prefer proxy if it exists
    $rel = $r['proxy_path'] ?: $r['file_path']; // adjust column names if needed
    $abs = $rel ? $root . '/' . ltrim($rel, '/') : null;

    $p = probeFps($abs);
    if (!$p) {
        $fail++;
        continue;
    }

    $upd->execute([
        ':fps' => $p['fps'],
        ':n'   => $p['num'],
        ':d'   => $p['den'],
        ':id'  => $r['id'],
    ]);
    $ok++;
}

echo "Updated/kept: {$ok}, missing/failed: {$fail}\n";
