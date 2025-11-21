<?php

namespace App\Http\Controllers;

use App\Services\AuthService;
use App\Support\View;

final class AuthController
{
    public function __construct(private AuthService $auth) {}

    // Render the login form (no top bar; uses layout/auth.php)
    public function showLogin(): void
    {
        if (session_status() === \PHP_SESSION_NONE) {
            session_start();
        }

        // One-time CSRF token for login form
        if (empty($_SESSION['csrf_login'])) {
            $_SESSION['csrf_login'] = bin2hex(random_bytes(32));
        }

        $error = $_SESSION['flash_error'] ?? null;
        unset($_SESSION['flash_error']);

        $title         = 'Sign in • Zentropa Dailies';
        $prefill_email = $_SESSION['last_login_email'] ?? '';
        $csrf          = $_SESSION['csrf_login'];

        View::render('auth/login', compact('title', 'error', 'prefill_email', 'csrf'));
    }

    // Handle POST /auth/login
    // Handle POST /auth/login
    public function login(): void
    {
        if (session_status() === \PHP_SESSION_NONE) {
            session_start();
        }

        $posted = $_POST ?? [];
        $token  = (string)($posted['csrf'] ?? '');

        // CSRF (single, canonical check)
        if (empty($_SESSION['csrf_login']) || !hash_equals($_SESSION['csrf_login'], $token)) {
            $_SESSION['flash_error']      = 'Security check failed. Please try again.';
            $_SESSION['last_login_email'] = $posted['email'] ?? '';
            header('Location: /auth/login', true, 302);
            exit;
        }
        // One-time token — invalidate after successful check
        unset($_SESSION['csrf_login']);

        // Basic input validation
        $email = trim($posted['email'] ?? '');
        $pass  = (string)($posted['password'] ?? '');
        if ($email === '' || $pass === '') {
            $this->fail('Please enter email and password.', $email);
        }

        // Authenticate
        $row = $this->auth->attempt($email, $pass);
        if (!$row) {
            $this->fail('Invalid credentials.', $email);
        }

        // Good practice on login
        session_regenerate_id(true);

        // Normalize session shape
        $_SESSION['account'] = [
            'id'           => $row['account_id'],
            'email'        => $row['email'],
            'is_superuser' => (int)($row['is_superuser'] ?? 0),
            'user_role'    => $row['user_role'] ?? 'regular',   // <-- add this
            'status'       => $row['status'] ?? null,           // optional, nice to have
            'first_name'   => $row['first_name'] ?? null,
            'display_name' => $row['display_name'] ?? null,
        ];
        // Back-compat alias
        $_SESSION['user'] = $_SESSION['account'];


        // ? New: resolve and cache person_uuid for RBAC / project access
        try {
            $pdo = \App\Support\DB::pdo();
            $stmt = $pdo->prepare("
            SELECT BIN_TO_UUID(person_id,1) AS person_uuid
            FROM accounts_persons
            WHERE account_id = UUID_TO_BIN(:acc,1)
            LIMIT 1
        ");
            $stmt->execute([':acc' => $row['account_id']]);
            $p = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($p && !empty($p['person_uuid'])) {
                $_SESSION['person_uuid'] = $p['person_uuid'];
            }
        } catch (\Throwable $e) {
            error_log('Warning: unable to resolve person_uuid: ' . $e->getMessage());
        }

        // Clear any leftover prefill once success
        unset($_SESSION['last_login_email']);

        // After normalizing $_SESSION['account']...
        header('Location: /dashboard', true, 302);
        exit;
    }


    // Clear session and go back to login
    public function logout(): void
    {
        if (session_status() === \PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
        header('Location: /auth/login', true, 302);
        exit;
    }

    private function fail(string $msg, string $emailForPrefill = ''): void
    {
        $_SESSION['flash_error']      = $msg;
        $_SESSION['last_login_email'] = $emailForPrefill;
        header('Location: /auth/login', true, 302);
        exit;
    }
}
