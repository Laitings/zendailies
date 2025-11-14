<?php

namespace App\Support;

use PDO;
use PDOException;

final class DB
{
    private static ?PDO $pdo = null;

    /**
     * Returns a singleton PDO using ENV with robust aliasing:
     * - DB_DATABASE | DB_NAME
     * - DB_USERNAME | DB_USER
     * - DB_PASSWORD | DB_PASS
     */
    public static function pdo(): PDO
    {
        if (self::$pdo instanceof PDO) {
            return self::$pdo;
        }

        // Read with alias fallbacks (env() may not exist here, so use globals/getenv)
        $host    = $_ENV['DB_HOST']     ?? $_SERVER['DB_HOST']     ?? getenv('DB_HOST')     ?: 'mysql84';
        $port    = $_ENV['DB_PORT']     ?? $_SERVER['DB_PORT']     ?? getenv('DB_PORT')     ?: '3306';
        $db      = $_ENV['DB_DATABASE'] ?? $_SERVER['DB_DATABASE'] ?? getenv('DB_DATABASE')
            ?: $_ENV['DB_NAME']     ?? $_SERVER['DB_NAME']     ?? getenv('DB_NAME')     ?: 'zendailies_db';
        $user    = $_ENV['DB_USERNAME'] ?? $_SERVER['DB_USERNAME'] ?? getenv('DB_USERNAME')
            ?: $_ENV['DB_USER']     ?? $_SERVER['DB_USER']     ?? getenv('DB_USER')     ?: 'zendailies_user';
        $pass    = $_ENV['DB_PASSWORD'] ?? $_SERVER['DB_PASSWORD'] ?? getenv('DB_PASSWORD')
            ?: $_ENV['DB_PASS']     ?? $_SERVER['DB_PASS']     ?? getenv('DB_PASS')     ?: '';
        $charset = $_ENV['DB_CHARSET']  ?? $_SERVER['DB_CHARSET']  ?? getenv('DB_CHARSET')  ?: 'utf8mb4';

        $dsn = "mysql:host={$host};port={$port};dbname={$db};charset={$charset}";
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        try {
            self::$pdo = new PDO($dsn, $user, $pass, $options);
            return self::$pdo;
        } catch (PDOException $e) {
            // Keep output minimal for web, log details for debugging
            error_log('DB connection failed: ' . $e->getMessage());
            http_response_code(500);
            die('DB connection failed.');
        }
    }
}
