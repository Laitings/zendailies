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
    /**
     * Handle POST /auth/login
     */
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

        // Authenticate (Returns account data)
        $row = $this->auth->attempt($email, $pass);
        if (!$row) {
            $this->fail('Invalid credentials.', $email);
        }

        // Good practice on login
        session_regenerate_id(true);

        // --- FETCH NAMES FROM PERSONS TABLE ---
        // We join accounts_persons to persons to get the actual name data for the session
        $firstName = null;
        $lastName  = null;
        $displayName = null;
        $personUuid = null;

        try {
            $pdo = \App\Support\DB::pdo();
            $stmt = $pdo->prepare("
                SELECT 
                    BIN_TO_UUID(p.id,1) AS person_uuid,
                    p.first_name, 
                    p.last_name, 
                    p.display_name
                FROM persons p
                JOIN accounts_persons ap ON ap.person_id = p.id
                WHERE ap.account_id = UUID_TO_BIN(:acc,1)
                LIMIT 1
            ");
            $stmt->execute([':acc' => $row['account_id']]);
            $person = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($person) {
                $firstName   = $person['first_name'];
                $lastName    = $person['last_name'];
                $displayName = $person['display_name'];
                $personUuid  = $person['person_uuid'];
            }
        } catch (\Throwable $e) {
            error_log('Warning: unable to resolve names or person_uuid: ' . $e->getMessage());
        }

        // Normalize session shape
        $_SESSION['account'] = [
            'id'           => $row['account_id'],
            'email'        => $row['email'],
            'is_superuser' => (int)($row['is_superuser'] ?? 0),
            'user_role'    => $row['user_role'] ?? 'regular',
            'status'       => $row['status'] ?? null,
            'first_name'   => $firstName,
            'last_name'    => $lastName,
            'display_name' => $displayName,
        ];

        // Ensure layouts find the name data they need for initials
        $_SESSION['person_uuid'] = $personUuid;
        $_SESSION['person']      = $_SESSION['account'];
        $_SESSION['user']        = $_SESSION['account'];

        // Clear any leftover prefill once success
        unset($_SESSION['last_login_email']);

        // Final redirect to the dashboard
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

    public function showSetupPassword()
    {
        $token = $_GET['token'] ?? '';
        if (!$token) {
            return View::render('auth/error', ['message' => 'Invalid or missing invitation token.']);
        }

        $pdo = \App\Support\DB::pdo();
        $stmt = $pdo->prepare("
        SELECT id, email 
        FROM accounts 
        WHERE setup_token = :token AND setup_expires_at > NOW() 
        LIMIT 1
    ");
        $stmt->execute([':token' => $token]);
        $account = $stmt->fetch();

        if (!$account) {
            return View::render('auth/error', ['message' => 'This invitation has expired or is invalid.']);
        }

        return View::render('auth/setup-password', ['token' => $token, 'email' => $account['email']]);
    }

    public function handleSetupPassword()
    {
        $token = $_POST['token'] ?? '';
        $pw    = $_POST['password'] ?? '';
        $pwc   = $_POST['password_confirm'] ?? '';

        if ($pw !== $pwc || strlen($pw) < 8) {
            // Simple error handling for this demo
            header("Location: /setup-password?token=$token&error=invalid");
            exit;
        }

        $pdo = \App\Support\DB::pdo();
        $hash = password_hash($pw, PASSWORD_BCRYPT);

        $stmt = $pdo->prepare("
        UPDATE accounts 
        SET password_hash = :hash, 
            setup_token = NULL, 
            setup_expires_at = NULL,
            status = 'active'
        WHERE setup_token = :token
    ");

        if ($stmt->execute([':hash' => $hash, ':token' => $token])) {
            // Automatically log them in or redirect to login
            header('Location: /auth/login?setup_success=1');
            exit;
        }
    }
}
