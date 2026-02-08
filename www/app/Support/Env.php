<?php
namespace App\Support;

final class Env {
    public static function load(string $file): void {
        if (!is_file($file)) return;
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if ($line[0]==='#') continue;
            [$k,$v] = array_map('trim', explode('=', $line, 2));
            if ($k === '') continue;
            // strip surrounding quotes if present
            $v = trim($v, " \t\n\r\0\x0B\"'");
            $_ENV[$k] = $v;
            putenv("$k=$v");
        }
    }
    public static function get(string $key, ?string $default=null): ?string {
        $v = $_ENV[$key] ?? getenv($key);
        return $v !== false && $v !== null ? $v : $default;
    }
}
