<?php
namespace App\Support;

final class Auth {
    public static function startSession(): void {
        if (session_status() === PHP_SESSION_NONE) {
            // Store sessions in /var/www/html/data/zendailies/storage/sessions
            $sessDir = dirname(__DIR__,3).'/data/zendailies/storage/sessions';
            if (is_dir($sessDir)) session_save_path($sessDir);
            session_name('ZENDLYSESSID');
            session_start();
        }
    }

    public static function check(): bool {
        return !empty($_SESSION['account']);
    }

    public static function user(): ?array {
        return $_SESSION['account'] ?? null;
    }

    public static function login(string $email, string $password): bool {
        // TEMP: .env-based test user until DB auth is implemented
        $testEmail = \App\Support\Env::get('AUTH_TEST_EMAIL');
        $testHash  = \App\Support\Env::get('AUTH_TEST_PASSWORD_HASH');
        if ($testEmail && strcasecmp($email, $testEmail) === 0 && $testHash && password_verify($password, $testHash)) {
            $_SESSION['account'] = [
                'email'        => $email,
                'is_superuser' => true,
                'id'           => '00000000-0000-0000-0000-000000000001',
            ];
            return true;
        }
        return false;
    }

    public static function logout(): void {
        $_SESSION = [];
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time()-42000, $params["path"], $params["domain"], $params["secure"], $params["httponly"]);
        }
        session_destroy();
    }
}
