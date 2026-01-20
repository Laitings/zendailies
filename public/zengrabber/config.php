<?php
// zengrabber/config.php

// Adjust these to match your MySQL service
const ZG_DB_HOST = 'mysql84';
const ZG_DB_NAME = 'zengrabber_db';
const ZG_DB_USER = 'zengrabber_user';
const ZG_DB_PASS = '1brimstix2';

function zg_pdo(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = 'mysql:host=' . ZG_DB_HOST . ';dbname=' . ZG_DB_NAME . ';charset=utf8mb4';

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    $pdo = new PDO($dsn, ZG_DB_USER, ZG_DB_PASS, $options);
    return $pdo;
}
