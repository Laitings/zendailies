<?php // tools/bootstrap_cli.php
declare(strict_types=1);

// Project root from /tools
$root = realpath(__DIR__ . '/..');
if ($root === false) {
    fwrite(STDERR, "FATAL: Cannot resolve project root.\n");
    exit(1);
}

// Load the same app bootstrap
require_once $root . '/app/bootstrap.php';

// Load .env into getenv/$_ENV/$_SERVER for CLI
$envFile = $root . '/.env';
if (is_file($envFile) && is_readable($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $ln) {
        if ($ln === '' || $ln[0] === '#') continue;
        $pos = strpos($ln, '=');
        if ($pos === false) continue;
        $k = trim(substr($ln, 0, $pos));
        $v = trim(substr($ln, $pos + 1));
        if ($v !== '' && ($v[0] === '"' || $v[0] === "'")) $v = trim($v, "\"'");
        putenv("$k=$v");
        $_ENV[$k]    = $v;
        $_SERVER[$k] = $v;
    }
}
