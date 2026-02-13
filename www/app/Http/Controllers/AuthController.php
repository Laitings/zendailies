<?php

namespace App\Http\Controllers;

use App\Services\AuthService;
use App\Support\View;
use App\Support\DB;
use App\Support\Mailer;

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

    // -------------------------------------------------------------------------
    // PASSWORD RECOVERY
    // -------------------------------------------------------------------------

    public function showForgotPassword(): void
    {
        if (session_status() === \PHP_SESSION_NONE) session_start();

        $error = $_SESSION['flash_error'] ?? null;
        $success = $_SESSION['flash_success'] ?? null;
        unset($_SESSION['flash_error'], $_SESSION['flash_success']);

        View::render('auth/forgot-password', [
            'title' => 'Reset Password • Zentropa Dailies',
            'error' => $error,
            'success' => $success
        ]);
    }

    public function handleForgotPassword(): void
    {
        if (session_status() === \PHP_SESSION_NONE) session_start();

        $email = trim($_POST['email'] ?? '');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $_SESSION['flash_error'] = 'Please enter a valid email address.';
            header('Location: /auth/forgot-password');
            exit;
        }

        $pdo = DB::pdo();

        // 1. Find account AND First Name
        // We join to persons to get the name for the email greeting
        $stmt = $pdo->prepare("
            SELECT a.id, p.first_name 
            FROM accounts a
            LEFT JOIN accounts_persons ap ON ap.account_id = a.id
            LEFT JOIN persons p ON p.id = ap.person_id
            WHERE a.email = :email AND a.status = 'active' 
            LIMIT 1
        ");
        $stmt->execute([':email' => $email]);
        $account = $stmt->fetch();

        if ($account) {
            // 2. Generate token & hash
            $token = bin2hex(random_bytes(32));
            $hash  = hash('sha256', $token);

            // Generate valid UUID v4
            $data = random_bytes(16);
            $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
            $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
            $uuid = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));

            // 3. Store in auth_recovery_codes
            $insert = $pdo->prepare("
                INSERT INTO auth_recovery_codes (id, account_id, code_hash, created_at)
                VALUES (UUID_TO_BIN(:uuid, 1), :aid, :hash, NOW())
            ");

            $insert->execute([
                ':uuid' => $uuid,
                ':aid'  => $account['id'],
                ':hash' => $hash
            ]);

            // 4. Send Email (Using ZENTROPA DAILIES Template)
            // Detect Protocol/Host
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https" : "http";
            $host     = $_SERVER['HTTP_HOST'];
            $resetUrl = "{$protocol}://{$host}/auth/reset-password?id=$uuid&token=$token";

            $firstName = $account['first_name'] ?? 'there';

            try {
                $mail = Mailer::create();
                $mail->addAddress($email);
                $mail->isHTML(true);
                $mail->Subject = 'Reset Password: Zentropa Dailies';

                $mail->Body = "
                    <!DOCTYPE html>
                    <html xmlns:v='urn:schemas-microsoft-com:vml' xmlns:o='urn:schemas-microsoft-com:office:office'>
                    <head>
                        <meta charset='utf-8'>
                        <meta name='viewport' content='width=device-width, initial-scale=1'>
                        <meta http-equiv='X-UA-Compatible' content='IE=edge'>
                        <style>
                            body { margin: 0; padding: 0; }
                            @media only screen and (max-width: 600px) {
                                .content { padding: 30px 20px !important; }
                            }
                        </style>
                    </head>

                    <body style='margin:0; padding:0; width:100%; background-color:#f4f6f9; font-family:Arial,sans-serif;'>

                    <table role='presentation' width='100%' cellpadding='0' cellspacing='0' border='0' style='background-color:#f4f6f9; width:100%; mso-table-lspace:0pt; mso-table-rspace:0pt;'>
                        <tr>
                            <td align='center' style='padding:50px 20px;'>

                                <table role='presentation' width='100%' cellpadding='0' cellspacing='0' border='0' style='max-width:600px; background-color:#ffffff; border-radius:8px; box-shadow:0 2px 8px rgba(0,0,0,0.08); mso-table-lspace:0pt; mso-table-rspace:0pt;'>
                                    
                                    <tr>
                                        <td style='padding:40px 40px 30px 40px; text-align:center; border-bottom:3px solid #3aa0ff;'>
                                            <h1 style='margin:0; color:#3aa0ff; font-size:14px; text-transform:uppercase; letter-spacing:0.2em; font-weight:800; font-family:Arial,sans-serif;'>
                                                ZENTROPA DAILIES
                                            </h1>
                                        </td>
                                    </tr>
                                    
                                    <tr>
                                        <td class='content' style='padding:45px 50px;'>
                                            <h2 style='margin:0 0 24px 0; font-size:24px; font-weight:700; color:#1a1a1a; font-family:Arial,sans-serif;'>
                                                Password Reset
                                            </h2>

                                            <p style='font-size:16px; line-height:1.6; color:#4a5568; margin:0 0 12px 0; font-family:Arial,sans-serif;'>
                                                Hi " . htmlspecialchars($firstName) . ",
                                            </p>
                                            
                                            <p style='font-size:16px; line-height:1.6; color:#4a5568; margin:0 0 32px 0; font-family:Arial,sans-serif;'>
                                                We received a request to reset your password. Click the button below to create a new one.
                                            </p>

                                            <table role='presentation' width='100%' cellpadding='0' cellspacing='0' border='0' style='margin:0 0 32px 0; mso-table-lspace:0pt; mso-table-rspace:0pt;'>
                                                <tr>
                                                    <td align='center' style='padding:0;'>
                                                        <a href='{$resetUrl}' style='background-color:#3aa0ff; color:#ffffff; padding:18px 40px; border-radius:5px; text-decoration:none; font-size:16px; font-weight:700; display:inline-block; font-family:Arial,sans-serif; mso-hide:all;'>
                                                            Reset Password
                                                        </a>
                                                        </td>
                                                </tr>
                                            </table>

                                            <table role='presentation' width='100%' cellpadding='0' cellspacing='0' border='0' style='padding-top:28px; border-top:1px solid #e2e8f0; mso-table-lspace:0pt; mso-table-rspace:0pt;'>
                                                <tr>
                                                    <td>
                                                        <p style='font-size:13px; line-height:1.5; color:#718096; margin:0 0 8px 0; font-family:Arial,sans-serif;'>
                                                            If the button doesn't work, copy and paste this link into your browser:
                                                        </p>
                                                        <p style='font-size:13px; line-height:1.5; margin:0; font-family:Arial,sans-serif;'>
                                                            <a href='{$resetUrl}' style='color:#3aa0ff; text-decoration:none; word-break:break-all;'>
                                                                {$resetUrl}
                                                            </a>
                                                        </p>
                                                    </td>
                                                </tr>
                                            </table>
                                        </td>
                                    </tr>

                                    <tr>
                                        <td style='padding:30px 40px; background-color:#f7fafc; text-align:center; border-radius:0 0 8px 8px;'>
                                            <p style='margin:0 0 8px 0; font-size:12px; color:#718096; font-family:Arial,sans-serif;'>
                                                This link is valid for 1 hour. If you did not request this, please ignore this email.
                                            </p>
                                            <p style='margin:0; font-size:11px; color:#a0aec0; font-family:Arial,sans-serif;'>
                                                © " . date('Y') . " Zentropa Post Production
                                            </p>
                                        </td>
                                    </tr>
                                </table>

                                </td>
                        </tr>
                    </table>

                    </body>
                    </html>
                    ";

                $mail->AltBody = "Hi {$firstName},\n\n"
                    . "We received a request to reset your password.\n\n"
                    . "Please click the link below to set a new password:\n"
                    . "{$resetUrl}\n\n"
                    . "This link is valid for 1 hour.\n\n"
                    . "© " . date('Y') . " Zentropa Post Production";

                $mail->send();
            } catch (\Exception $e) {
                error_log("Mail Error: " . $e->getMessage());
            }
        }

        // Always show success to prevent email enumeration
        $_SESSION['flash_success'] = 'If an account exists for that email, we have sent a reset link.';
        header('Location: /auth/forgot-password');
        exit;
    }

    public function showResetPassword(): void
    {
        if (session_status() === \PHP_SESSION_NONE) session_start();

        $id    = $_GET['id'] ?? '';
        $token = $_GET['token'] ?? '';
        $error = $_SESSION['flash_error'] ?? null;
        unset($_SESSION['flash_error']);

        View::render('auth/reset-password', [
            'title' => 'Set New Password',
            'id'    => $id,
            'token' => $token,
            'error' => $error
        ]);
    }

    public function handleResetPassword(): void
    {
        if (session_status() === \PHP_SESSION_NONE) session_start();

        $id    = $_POST['id'] ?? '';
        $token = $_POST['token'] ?? '';
        $pw    = $_POST['password'] ?? '';
        $pwc   = $_POST['password_confirm'] ?? '';

        if (strlen($pw) < 8 || $pw !== $pwc) {
            $_SESSION['flash_error'] = 'Passwords must match and be at least 8 characters.';
            header("Location: /auth/reset-password?id=$id&token=$token");
            exit;
        }

        $pdo = DB::pdo();

        // 1. Validate Token
        // Check if code exists, hash matches, not used, and created within last hour
        $sql = "
            SELECT account_id 
            FROM auth_recovery_codes 
            WHERE id = UUID_TO_BIN(:id, 1) 
              AND code_hash = :hash
              AND used_at IS NULL
              AND created_at > (NOW() - INTERVAL 1 HOUR)
            LIMIT 1
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':id'   => $id,
            ':hash' => hash('sha256', $token)
        ]);

        $row = $stmt->fetch();

        if (!$row) {
            View::render('auth/error', ['message' => 'This password reset link is invalid or has expired.']);
            return;
        }

        // 2. Update Password
        $newHash = password_hash($pw, PASSWORD_BCRYPT);

        $pdo->beginTransaction();
        try {
            // Update Account
            $upd = $pdo->prepare("UPDATE accounts SET password_hash = :hash WHERE id = :aid");
            $upd->execute([':hash' => $newHash, ':aid' => $row['account_id']]);

            // Mark Token Used
            $mark = $pdo->prepare("UPDATE auth_recovery_codes SET used_at = NOW() WHERE id = UUID_TO_BIN(:id, 1)");
            $mark->execute([':id' => $id]);

            $pdo->commit();

            $_SESSION['flash_success'] = 'Password updated. You can now sign in.';
            header('Location: /auth/login?setup_success=1');
            exit;
        } catch (\Exception $e) {
            $pdo->rollBack();
            error_log("Reset PW Error: " . $e->getMessage());
            $_SESSION['flash_error'] = 'A system error occurred. Please try again.';
            header("Location: /auth/reset-password?id=$id&token=$token");
            exit;
        }
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

        // Handle missing token
        if (!$token) {
            return View::render('auth/error', [
                'title'   => 'Missing Token',
                'message' => 'No invitation token was provided. Please use the link sent to your email.'
            ]);
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

        // Handle expired or non-existent token
        if (!$account) {
            return View::render('auth/error', [
                'title'   => 'Link Expired',
                'message' => 'This invitation link has expired or is no longer valid. Please contact your DIT or Administrator for a new invite.'
            ]);
        }

        return View::render('auth/setup-password', [
            'token' => $token,
            'email' => $account['email']
        ]);
    }

    public function handleSetupPassword()
    {
        $token = $_POST['token'] ?? '';
        $pw    = $_POST['password'] ?? '';
        $pwc   = $_POST['password_confirm'] ?? '';

        // 1. Basic validation
        if ($pw !== $pwc || strlen($pw) < 8) {
            header("Location: /setup-password?token=$token&error=invalid");
            exit;
        }

        $pdo = \App\Support\DB::pdo();
        $hash = password_hash($pw, PASSWORD_BCRYPT);

        // 2. Prepare the statement (Do NOT chain execute here)
        // We change "WHERE id = :id" to "WHERE setup_token = :token"
        $stmt = $pdo->prepare("
        UPDATE accounts 
        SET password_hash = :h, 
            setup_token = NULL, 
            setup_expires_at = NULL, 
            status = 'active' 
        WHERE setup_token = :token
    ");

        // 3. Execute and check if it worked
        $stmt->execute([':h' => $hash, ':token' => $token]);

        // Check rowCount to ensure the token actually existed and wasn't already used
        if ($stmt->rowCount() > 0) {
            // Success
            header('Location: /auth/login?setup_success=1');
            exit;
        } else {
            // Token was invalid or already used
            header("Location: /setup-password?token=$token&error=expired");
            exit;
        }
    }
}
