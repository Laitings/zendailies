<?php

declare(strict_types=1);

/**
 * Global bootstrap — safe to include multiple times.
 * Guards re-declarations and re-execution.
 */
if (defined('APP_BOOTSTRAPPED')) {
    return;
}
define('APP_BOOTSTRAPPED', true);

/* -------------------------
 | Project base helper (guarded)
 * ------------------------- */
if (!function_exists('base_path')) {
    function base_path(string $path = ''): string
    {
        $root = dirname(__DIR__);
        return $path ? ($root . '/' . ltrim($path, '/')) : $root;
    }
}

/* -------------------------
 | Load .env and export to ENV (idempotent)
 * ------------------------- */
if (!function_exists('load_env_once')) {
    function load_env_once(): void
    {
        static $loaded = false;
        if ($loaded) return;

        $envFile = base_path('.env');
        if (is_file($envFile) && is_readable($envFile)) {
            $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            foreach ($lines as $ln) {
                if ($ln === '' || $ln[0] === '#') continue;
                $pos = strpos($ln, '=');
                if ($pos === false) continue;
                $k = trim(substr($ln, 0, $pos));
                $v = trim(substr($ln, $pos + 1));
                if ($v !== '' && ($v[0] === '"' || $v[0] === "'")) {
                    $v = trim($v, "\"'");
                }
                // Export to all common places
                putenv("$k=$v");
                $_ENV[$k]    = $v;
                $_SERVER[$k] = $v;
            }

            // Provide canonical + alias pairs so both styles work everywhere
            $pairs = [
                // canonical => alias-from-.env
                'DB_DATABASE' => 'DB_NAME',
                'DB_USERNAME' => 'DB_USER',
                'DB_PASSWORD' => 'DB_PASS',
            ];
            foreach ($pairs as $canon => $alias) {
                $canonVal = getenv($canon);
                $aliasVal = getenv($alias);
                if ($canonVal === false && $aliasVal !== false) {
                    putenv("$canon=$aliasVal");
                    $_ENV[$canon]    = $aliasVal;
                    $_SERVER[$canon] = $aliasVal;
                }
                // Also set the reverse if only canonical exists (defensive)
                if ($aliasVal === false && $canonVal !== false) {
                    putenv("$alias=$canonVal");
                    $_ENV[$alias]    = $canonVal;
                    $_SERVER[$alias] = $canonVal;
                }
            }
        }

        $loaded = true;
    }
}
load_env_once();

/* -------------------------
 | Session (idempotent)
 * ------------------------- */
if (session_status() !== PHP_SESSION_ACTIVE) {
    // session_name('ZENDLYSESSID'); // optional fixed name
    session_start();
}

/* -------------------------
 | Small helpers (guarded)
 * ------------------------- */

if (!function_exists('env')) {
    /**
     * Robust env reader: checks $_ENV, $_SERVER, then getenv().
     */
    function env(string $key, ?string $default = null): ?string
    {
        $v = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        return ($v === false || $v === null) ? $default : $v;
    }
}

if (!function_exists('db')) {
    /**
     * Global PDO getter (singleton per request).
     * Uses the same aliasing guarantees provided by load_env_once().
     */
    function db(): PDO
    {
        static $pdo = null;
        if ($pdo instanceof PDO) {
            return $pdo;
        }

        $host    = env('DB_HOST', 'mysql84');
        $port    = env('DB_PORT', '3306');
        $dbName  = env('DB_DATABASE', env('DB_NAME', 'zendailies_db'));
        $user    = env('DB_USERNAME', env('DB_USER', 'zendailies_user'));

        // 1. Get default password from env
        $pass = env('DB_PASSWORD', env('DB_PASS', ''));

        // 2. CHECK FOR SECRET FILE OVERRIDE
        $passFile = env('DB_PASS_FILE');
        if ($passFile && file_exists($passFile)) {
            $pass = trim(file_get_contents($passFile));
        }

        $charset = env('DB_CHARSET', 'utf8mb4');

        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            $host,
            $port,
            $dbName,
            $charset
        );

        $pdo = new PDO($dsn, $user, $pass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        return $pdo;
    }
}

if (!function_exists('require_superuser')) {
    /**
     * Superuser gate used by controllers.
     * Accepts either $_SESSION['account'] or $_SESSION['user'] shape.
     */
    function require_superuser(): void
    {
        $acct = $_SESSION['account'] ?? ($_SESSION['user'] ?? null);
        $isSuper = isset($acct['is_superuser']) && (int)$acct['is_superuser'] === 1;

        if (!$isSuper) {
            http_response_code(403);
            echo 'Forbidden';
            exit;
        }
    }
}
