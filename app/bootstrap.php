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
 | Session (idempotent)
 * ------------------------- */
if (session_status() !== PHP_SESSION_ACTIVE) {
    // If you have a custom name/save_path elsewhere, leave this as-is.
    // session_name('ZENDLYSESSID'); // <- uncomment if you want a fixed name
    session_start();
}

/* -------------------------
 | Small helpers (guarded)
 * ------------------------- */

if (!function_exists('base_path')) {
    function base_path(string $path = ''): string
    {
        $root = dirname(__DIR__);
        return $path ? ($root . '/' . ltrim($path, '/')) : $root;
    }
}

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
     */
    function db(): PDO
    {
        static $pdo = null;
        if ($pdo instanceof PDO) {
            return $pdo;
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            env('DB_HOST', 'mysql84'),
            env('DB_PORT', '3306'),
            env('DB_NAME', 'zendailies_db'),
            env('DB_CHARSET', 'utf8mb4')
        );

        $pdo = new PDO(
            $dsn,
            env('DB_USER', 'zendailies_user'),
            env('DB_PASS', '')
        );
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
