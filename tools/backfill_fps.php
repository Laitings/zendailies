<?php
// CLI bootstrap for backfill_fps.php  — header fixed to load /app/bootstrap.php and DB

// 1) Resolve project root (…/zendailies) and switch CWD
$root = realpath(__DIR__ . '/..');
if ($root === false) {
    fwrite(STDERR, "[FATAL] Cannot resolve project root from " . __DIR__ . PHP_EOL);
    exit(1);
}
chdir($root);

// 2) Load your real bootstrap (it wires env + autoload)
$bootstrap = $root . '/app/bootstrap.php';
if (!is_file($bootstrap)) {
    fwrite(STDERR, "[FATAL] bootstrap not found at $bootstrap\n");
    exit(1);
}
require_once $bootstrap;

// 3) Ensure the App\ autoloader exists; as a safety net, add a tiny PSR-4 loader
if (!class_exists('App\\Support\\DB')) {
    spl_autoload_register(function ($class) use ($root) {
        $prefix = 'App\\';
        if (strncmp($class, $prefix, strlen($prefix)) !== 0) return;
        $rel = substr($class, strlen($prefix));
        $file = $root . '/app/' . str_replace('\\', '/', $rel) . '.php';
        if (is_file($file)) require $file;
    });
    // direct include as last resort
    if (!class_exists('App\\Support\\DB') && is_file($root . '/app/Support/DB.php')) {
        require_once $root . '/app/Support/DB.php';
    }
}

if (!class_exists('App\\Support\\DB')) {
    fwrite(STDERR, "[FATAL] App\\Support\\DB not found after bootstrap; check autoload.\n");
    exit(1);
}

use App\Support\DB;

// 4) Get PDO early (before your $sql use)
$pdo = DB::pdo();
if (!$pdo instanceof PDO) {
    fwrite(STDERR, "DB connection failed.\n");
    exit(1);
}


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
