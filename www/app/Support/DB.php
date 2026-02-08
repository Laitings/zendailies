<?php

declare(strict_types=1);

namespace App\Support;

use PDO;
use PDOException;
use RuntimeException;

class DB
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $host = self::env('DB_HOST', '127.0.0.1');
            $port = self::env('DB_PORT', '3306');
            $db   = self::env('DB_NAME', 'zendailies_db');
            $user = self::env('DB_USER', 'root');
            $charset = self::env('DB_CHARSET', 'utf8mb4');

            // --- SECRET FILE LOGIC START ---
            // 1. Check if a secret file path is defined
            $passFile = self::env('DB_PASS_FILE');

            // 2. Default to standard env password
            $pass = self::env('DB_PASS', '');

            // 3. If secret file exists, read it and OVERRIDE the env password
            if ($passFile && file_exists($passFile)) {
                $pass = trim(file_get_contents($passFile));
            }
            // --- SECRET FILE LOGIC END ---

            // === DEBUG BLOCK (Delete after fixing) ===
            if (empty($pass)) {
                echo "<pre><h3>Debug Database Connection</h3>";
                echo "<strong>DB_PASS_FILE variable:</strong> " . ($passFile ?: '(EMPTY - .env not loaded?)') . "\n";
                echo "<strong>File path:</strong> " . $passFile . "\n";
                echo "<strong>File exists?</strong> " . (file_exists($passFile) ? 'YES' : 'NO') . "\n";
                echo "<strong>File content length:</strong> " . strlen(trim(file_get_contents($passFile) ?: '')) . "\n";
                die("Stopped execution.");
            }
            // =========================================

            $dsn = "mysql:host={$host};port={$port};dbname={$db};charset={$charset}";

            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];

            try {
                self::$pdo = new PDO($dsn, $user, $pass, $options);
            } catch (PDOException $e) {
                // In production, log this instead of showing it
                throw new RuntimeException('DB Connection Failed: ' . $e->getMessage());
            }
        }

        return self::$pdo;
    }

    /**
     * Helper to read $_ENV or getenv() with fallback
     */
    private static function env(string $key, string $default = ''): string
    {
        if (isset($_ENV[$key])) {
            return (string)$_ENV[$key];
        }
        $val = getenv($key);
        if ($val !== false) {
            return (string)$val;
        }
        return $default;
    }
}
