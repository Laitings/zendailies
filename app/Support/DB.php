<?php

namespace App\Support;

use PDO;
use PDOException;

final class DB
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo) return self::$pdo;

        $host    = $_ENV['DB_HOST']    ?? $_SERVER['DB_HOST']    ?? getenv('DB_HOST')    ?: 'mysql84';
        $port    = $_ENV['DB_PORT']    ?? $_SERVER['DB_PORT']    ?? getenv('DB_PORT')    ?: '3306';
        $db      = $_ENV['DB_NAME']    ?? $_SERVER['DB_NAME']    ?? getenv('DB_NAME')    ?: 'zendailies_db';
        $user    = $_ENV['DB_USER']    ?? $_SERVER['DB_USER']    ?? getenv('DB_USER')    ?: 'zendailies_user';
        $pass    = $_ENV['DB_PASS']    ?? $_SERVER['DB_PASS']    ?? getenv('DB_PASS')    ?: '';
        $charset = $_ENV['DB_CHARSET'] ?? $_SERVER['DB_CHARSET'] ?? getenv('DB_CHARSET') ?: 'utf8mb4';

        $dsn = "mysql:host=$host;port=$port;dbname=$db;charset=$charset";
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        try {
            self::$pdo = new PDO($dsn, $user, $pass, $options);
            return self::$pdo;
        } catch (PDOException $e) {
            http_response_code(500);
            die('DB connection failed.');
        }
    }
}
