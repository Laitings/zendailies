<?php

namespace App\Support;

final class Csrf
{
    private const KEY = '_csrf';

    public static function token(): string
    {
        if (empty($_SESSION[self::KEY])) {
            $_SESSION[self::KEY] = bin2hex(random_bytes(32));
        }
        return $_SESSION[self::KEY];
    }

    public static function validateOrAbort(?string $token): void
    {
        $ok = is_string($token) && hash_equals($_SESSION[self::KEY] ?? '', $token);
        if (!$ok) {
            http_response_code(419); // authentication timeout / csrf
            echo "CSRF check failed";
            exit;
        }
    }
}
