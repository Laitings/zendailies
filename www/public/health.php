<?php

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

// 1) Autoload
require_once __DIR__ . '/../app/bootstrap.php';

// 2) Load .env (same 3-target loader as index.php)
$envFile = __DIR__ . '/../.env';
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        [$k, $v] = array_pad(explode('=', $line, 2), 2, '');
        $k = trim($k);
        $v = trim($v);
        if ($k === '') continue;
        putenv("$k=$v");
        $_ENV[$k]    = $v;
        $_SERVER[$k] = $v;
    }
}

// 3) Bootstrap (gives you db(), etc.)
require __DIR__ . '/../app/bootstrap.php';


// 2) Read env (what the app will use)
$host = getenv('DB_HOST') ?: '(not set)';
$port = getenv('DB_PORT') ?: '(default 3306 assumed)';
$name = getenv('DB_NAME') ?: '(not set)';
$user = getenv('DB_USER') ?: '(not set)';
$char = getenv('DB_CHARSET') ?: '(default utf8mb4 assumed)';

echo "ENV:\n";
echo "  DB_HOST = {$host}\n";
echo "  DB_PORT = {$port}\n";
echo "  DB_NAME = {$name}\n";
echo "  DB_USER = {$user}\n";
echo "  DB_CHARSET = {$char}\n\n";



// 3) Try a direct PDO (bypasses your DB class to isolate issues)
if ($host !== '(not set)' && $name !== '(not set)' && $user !== '(not set)') {
    $dsn = "mysql:host={$host};" . (($port && $port !== '(default 3306 assumed)') ? "port={$port};" : "") .
        "dbname={$name};charset=" . ($char !== '(default utf8mb4 assumed)' ? $char : 'utf8mb4');
    echo "Direct PDO test:\n";
    try {
        $pdo = new PDO($dsn, getenv('DB_USER') ?: '', getenv('DB_PASS') ?: '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $ver = $pdo->query('SELECT VERSION() AS v')->fetch()['v'] ?? 'unknown';
        echo "  OK ✅  Connected. MySQL version: {$ver}\n\n";
    } catch (Throwable $e) {
        echo "  FAIL ❌  {$e->getMessage()}\n\n";
    }
} else {
    echo "Direct PDO test skipped: missing ENV values.\n\n";
}

// --- Autoload debug for App\Support\DB ---
$prefix  = 'App\\';
$baseDir = __DIR__ . '/../app/';
$klass   = 'App\\Support\\DB';
$relative = substr($klass, strlen($prefix));
$expected = $baseDir . str_replace('\\', '/', $relative) . '.php';

echo "Autoload expects: $expected\n";
echo "is_file(expected)? " . (is_file($expected) ? "YES ✅\n" : "NO ❌\n");

// Try a manual require_once fallback so health.php never fails on this:
if (!class_exists($klass, false) && is_file($expected)) {
    require_once $expected;
}
echo "class_exists(App\\Support\\DB) after fallback: " . (class_exists($klass) ? "YES ✅\n" : "NO ❌\n");


// 4) Try via your App\Support\DB (uses the same ENV + your class)
echo "App\\Support\\DB class_exists(): " . (class_exists(\App\Support\DB::class) ? "YES ✅\n" : "NO ❌\n");

echo "DB::pdo() test:\n";
try {
    $pdo2 = \App\Support\DB::pdo();
    echo "  OK ✅  Connected. MySQL version: " . $pdo2->query('SELECT VERSION()')->fetchColumn() . "\n\n";
} catch (Throwable $e) {
    echo "  FAIL ❌  " . $e->getMessage() . "\n\n";
}
echo "Looking for /app/Support/DB.php: " . (file_exists(__DIR__ . '/../app/Support/DB.php') ? "FOUND\n" : "MISSING\n");
echo "App\\Support\\DB class_exists(): " . (class_exists(\App\Support\DB::class) ? "YES ✅\n" : "NO ❌\n");


echo "\nHint:\n";
echo " - If host cannot be resolved: the PHP container may not share a network with mysql (DB_HOST wrong).\n";
echo " - If Access denied: DB_USER/DB_PASS wrong or lacks privileges.\n";
echo " - If Connection refused: mysql not reachable on that host:port.\n";
echo " - If Unknown database: DB_NAME is wrong or not created.\n";


echo "PHP OK<br>";
require __DIR__ . '/../vendor/autoload.php';
echo "Autoload OK<br>";

$paths = [
    __DIR__ . '/../app/bootstrap.php',
    __DIR__ . '/../app/Support/Csrf.php',
];
foreach ($paths as $p) {
    echo (is_file($p) ? "Found: " : "Missing: ") . htmlspecialchars($p) . "<br>";
}

require __DIR__ . '/../app/bootstrap.php';
echo "Bootstrap OK<br>";

// Try loading the class explicitly:
if (class_exists(\App\Support\Csrf::class)) {
    echo "Csrf class OK<br>";
} else {
    echo "Csrf class NOT FOUND<br>";
}
