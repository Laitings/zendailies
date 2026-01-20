<?php

namespace App\Http\Controllers\Admin;

use App\Repositories\AccountRepository;
use App\Support\Csrf;
use App\Support\DB;
use App\Support\View;
use PDO;
use Throwable;

class UserController
{
    public function index()
    {

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $account    = $_SESSION['account']     ?? null;
        $personUuid = $_SESSION['person_uuid'] ?? null;

        if (!$account) {
            http_response_code(403);
            echo "Forbidden.";
            return;
        }

        $isSuperuser = !empty($account['is_superuser']);

        $repo = new AccountRepository();

        // Try to recover person_uuid from account if it's missing
        // NOTE: login usually stores BIN_TO_UUID(a.id,1) as 'id'
        if (!$personUuid && !empty($account['id'])) {
            $resolved = $repo->firstPersonIdForAccount($account['id']);
            if ($resolved) {
                $personUuid = $resolved;
                $_SESSION['person_uuid'] = $personUuid;
            }
        }

        // Read project filter from query string (?project=UUID or ?project=all)
        $projectFilter = isset($_GET['project']) ? trim((string)$_GET['project']) : '';

        // Normalize: 'all' or empty string => no specific project filter
        if ($projectFilter === '' || $projectFilter === 'all') {
            $projectFilter = null;
        }

        // Read sort parameters from query string
        $sort = trim((string)($_GET['sort'] ?? 'name'));
        $dir  = strtolower(trim((string)($_GET['dir'] ?? 'asc')));
        if (!in_array($dir, ['asc', 'desc'])) {
            $dir = 'asc';
        }

        $projects = [];
        $users    = [];

        if ($isSuperuser) {
            // Superuser: personUuid not needed for filters, see all projects/users
            $projects = $repo->listProjectsForUser('', true);
            $users    = $repo->listForAdmin('', true, $projectFilter, $sort, $dir);
        } else {
            // Non-superuser admin: if we *still* don't have a person UUID,
            // we can't know their projects → show an empty list instead of 403.
            if ($personUuid) {
                $projects = $repo->listProjectsForUser($personUuid, false);
                $users    = $repo->listForAdmin($personUuid, false, $projectFilter, $sort, $dir);
            } else {
                // No person link → no projects, no users; page still loads.
                $projects = [];
                $users    = [];
            }
        }

        return View::render('admin/users/index', [
            'users'            => $users,
            'projects'         => $projects,
            'selected_project' => $projectFilter,  // may be null
            'is_superuser'     => $isSuperuser,
            'sort'             => $sort,
            'dir'              => $dir,
        ]);
    }



    public function create()
    {
        return View::render('admin/users/create');
    }

    public function store()
    {
        if (session_status() === PHP_SESSION_NONE) session_start();
        Csrf::validateOrAbort($_POST['_csrf'] ?? null);

        // --- Gather + basic validate ---
        $first = trim((string)($_POST['first_name'] ?? ''));
        $last  = trim((string)($_POST['last_name']  ?? ''));
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $phone = trim((string)($_POST['phone'] ?? ''));
        $role  = (string)($_POST['user_role'] ?? 'regular');
        $isSU  = !empty($_POST['is_superuser']) ? 1 : 0;

        // Note: Password validation removed as we now use setup tokens

        $errors = [];
        if ($first === '') $errors[] = 'First name is required.';
        if ($last  === '') $errors[] = 'Last name is required.';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'A valid email is required.';

        if ($errors) {
            return View::render('admin/users/create', [
                'errors' => $errors,
                'old'    => $_POST,
            ]);
        }

        $pdo = DB::pdo();
        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("SELECT 1 FROM accounts WHERE email = :e AND status != 'deleted' LIMIT 1");
            $stmt->execute([':e' => $email]);
            if ($stmt->fetch()) {
                throw new \RuntimeException('That email is already in use.');
            }

            $personBin   = $this->uuidToBin($pdo, $this->uuidStr($pdo));
            $accountBin  = $this->uuidToBin($pdo, $this->uuidStr($pdo));

            // Generate Invitation Token
            $setupToken = bin2hex(random_bytes(32));
            $expiresAt  = date('Y-m-d H:i:s', strtotime('+48 hours'));

            // Insert person
            $pdo->prepare("INSERT INTO persons (id, first_name, last_name) VALUES (:id, :fn, :ln)")
                ->execute([':id' => $personBin, ':fn' => $first, ':ln' => $last]);

            // Insert account with setup token
            $pdo->prepare("
                INSERT INTO accounts (id, email, password_hash, user_role, is_superuser, mfa_policy, status, setup_token, setup_expires_at)
                VALUES (:id, :email, '*', :role, :su, 'optional', 'active', :token, :expires)
            ")->execute([
                ':id'      => $accountBin,
                ':email'   => $email,
                ':role'    => $role,
                ':su'      => $isSU,
                ':token'   => $setupToken,
                ':expires' => $expiresAt
            ]);

            $pdo->prepare("INSERT INTO accounts_persons (account_id, person_id) VALUES (:aid, :pid)")
                ->execute([':aid' => $accountBin, ':pid' => $personBin]);

            $pdo->prepare("INSERT INTO person_contacts (id, person_id, type, value, is_primary) VALUES (UUID_TO_BIN(UUID(),1), :pid, 'email', :val, 1)")
                ->execute([':pid' => $personBin, ':val' => $email]);

            if ($phone !== '') {
                $pdo->prepare("INSERT INTO person_contacts (id, person_id, type, value, is_primary) VALUES (UUID_TO_BIN(UUID(),1), :pid, 'phone', :val, 1)")
                    ->execute([':pid' => $personBin, ':val' => $phone]);
            }

            $pdo->commit();

            try {
                $mail = \App\Support\Mailer::create();
                $mail->addAddress($email, "$first $last");
                $mail->isHTML(true);
                $mail->Subject = "Invitation: Zentropa Dailies";

                // Automatically detect the protocol and host (IP/Domain)
                $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https" : "http";
                $host = $_SERVER['HTTP_HOST']; // This will be your IP like 192.168.x.x or a domain
                $setupUrl = "{$protocol}://{$host}/setup-password?token=" . $setupToken;

                // Fixed: $first is not defined in sendInvite() method
                $firstName = $first;

                $mail->Body = "
                    <!DOCTYPE html>
                    <html xmlns:v='urn:schemas-microsoft-com:vml' xmlns:o='urn:schemas-microsoft-com:office:office'>
                    <head>
                        <meta charset='utf-8'>
                        <meta name='viewport' content='width=device-width, initial-scale=1'>
                        <!--[if !mso]><!-->
                        <meta http-equiv='X-UA-Compatible' content='IE=edge'>
                        <!--<![endif]-->
                        <style>
                            body {
                                margin: 0;
                                padding: 0;
                            }
                            @media only screen and (max-width: 600px) {
                                .content {
                                    padding: 30px 20px !important;
                                }
                            }
                        </style>
                    </head>

                    <body style='margin:0; padding:0; width:100%; background-color:#f4f6f9; font-family:Arial,sans-serif;'>

                    <table role='presentation' width='100%' cellpadding='0' cellspacing='0' border='0' style='background-color:#f4f6f9; width:100%; mso-table-lspace:0pt; mso-table-rspace:0pt;'>
                        <tr>
                            <td align='center' style='padding:50px 20px;'>

                                <!--[if mso]>
                                <table role='presentation' width='600' cellpadding='0' cellspacing='0' border='0'>
                                    <tr>
                                        <td>
                                <![endif]-->

                                <!-- Main Card -->
                                <table role='presentation' width='100%' cellpadding='0' cellspacing='0' border='0' style='max-width:600px; background-color:#ffffff; border-radius:8px; box-shadow:0 2px 8px rgba(0,0,0,0.08); mso-table-lspace:0pt; mso-table-rspace:0pt;'>
                                    
                                    <!-- Header with Brand -->
                                    <tr>
                                        <td style='padding:40px 40px 30px 40px; text-align:center; border-bottom:3px solid #3aa0ff;'>
                                            <h1 style='margin:0; color:#3aa0ff; font-size:14px; text-transform:uppercase; letter-spacing:0.2em; font-weight:800; font-family:Arial,sans-serif;'>
                                                ZENTROPA DAILIES
                                            </h1>
                                        </td>
                                    </tr>
                                    
                                    <!-- Content -->
                                    <tr>
                                        <td class='content' style='padding:45px 50px;'>
                                            <h2 style='margin:0 0 24px 0; font-size:24px; font-weight:700; color:#1a1a1a; font-family:Arial,sans-serif;'>
                                                Account Invitation
                                            </h2>

                                            <p style='font-size:16px; line-height:1.6; color:#4a5568; margin:0 0 12px 0; font-family:Arial,sans-serif;'>
                                                Hi " . htmlspecialchars($firstName) . ",
                                            </p>
                                            
                                            <p style='font-size:16px; line-height:1.6; color:#4a5568; margin:0 0 32px 0; font-family:Arial,sans-serif;'>
                                                Your account on the Zentropa Dailies platform is ready. Click the button below to set your password and access the system.
                                            </p>

                                            <!-- Button -->
                                            <table role='presentation' width='100%' cellpadding='0' cellspacing='0' border='0' style='margin:0 0 32px 0; mso-table-lspace:0pt; mso-table-rspace:0pt;'>
                                                <tr>
                                                    <td align='center' style='padding:0;'>
                                                        <!--[if mso]>
                                                        <v:roundrect xmlns:v='urn:schemas-microsoft-com:vml' xmlns:w='urn:schemas-microsoft-com:office:word' href='{$setupUrl}' style='height:54px; v-text-anchor:middle; width:240px;' arcsize='9%' strokecolor='#3aa0ff' fillcolor='#3aa0ff'>
                                                            <w:anchorlock/>
                                                            <center style='color:#ffffff; font-family:Arial,sans-serif; font-size:16px; font-weight:bold;'>
                                                                Set Up Your Account
                                                            </center>
                                                        </v:roundrect>
                                                        <![endif]-->
                                                        <!--[if !mso]><!-->
                                                        <a href='{$setupUrl}' style='background-color:#3aa0ff; color:#ffffff; padding:18px 40px; border-radius:5px; text-decoration:none; font-size:16px; font-weight:700; display:inline-block; font-family:Arial,sans-serif; mso-hide:all;'>
                                                            Set Up Your Account
                                                        </a>
                                                        <!--<![endif]-->
                                                    </td>
                                                </tr>
                                            </table>

                                            <!-- Alternative Link -->
                                            <table role='presentation' width='100%' cellpadding='0' cellspacing='0' border='0' style='padding-top:28px; border-top:1px solid #e2e8f0; mso-table-lspace:0pt; mso-table-rspace:0pt;'>
                                                <tr>
                                                    <td>
                                                        <p style='font-size:13px; line-height:1.5; color:#718096; margin:0 0 8px 0; font-family:Arial,sans-serif;'>
                                                            If the button doesn't work, copy and paste this link into your browser:
                                                        </p>
                                                        <p style='font-size:13px; line-height:1.5; margin:0; font-family:Arial,sans-serif;'>
                                                            <a href='{$setupUrl}' style='color:#3aa0ff; text-decoration:none; word-break:break-all;'>
                                                                {$setupUrl}
                                                            </a>
                                                        </p>
                                                    </td>
                                                </tr>
                                            </table>
                                        </td>
                                    </tr>

                                    <!-- Footer -->
                                    <tr>
                                        <td style='padding:30px 40px; background-color:#f7fafc; text-align:center; border-radius:0 0 8px 8px;'>
                                            <p style='margin:0 0 8px 0; font-size:12px; color:#718096; font-family:Arial,sans-serif;'>
                                                This invitation will expire in 48 hours.
                                            </p>
                                            <p style='margin:0; font-size:11px; color:#a0aec0; font-family:Arial,sans-serif;'>
                                                © " . date('Y') . " Zentropa Post Production
                                            </p>
                                        </td>
                                    </tr>
                                </table>

                                <!--[if mso]>
                                        </td>
                                    </tr>
                                </table>
                                <![endif]-->

                            </td>
                        </tr>
                    </table>

                    </body>
                    </html>
                    ";

                $mail->AltBody = "Hi {$firstName},\n\n"
                    . "Your account on Zentropa Dailies is ready.\n\n"
                    . "Please set up your account by visiting the link below:\n"
                    . "{$setupUrl}\n\n"
                    . "This link will expire in 48 hours.\n\n"
                    . "© " . date('Y') . " Zentropa Post Production";

                $mail->send();
            } catch (\Exception $e) {
                // We log the error but don't stop the process, 
                // since the user is already created in the DB.
                error_log("Failed to send invitation to $email: " . $e->getMessage());
            }

            /* --- CONTINUE TO REDIRECT --- */
            header('Location: /admin/users?invited=1');

            // Here we would trigger the email sending
            // For now, we redirect.
            header('Location: /admin/users?invited=1');
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            return View::render('admin/users/create', ['errors' => [$e->getMessage()], 'old' => $_POST]);
        }
    }

    public function sendInvite($id)
    {
        if (session_status() === PHP_SESSION_NONE) session_start();
        $pdo = DB::pdo();

        // 1. Fetch user details to get the email and name
        $stmt = $pdo->prepare("
            SELECT a.email, p.first_name, p.last_name 
            FROM accounts a
            JOIN accounts_persons ap ON ap.account_id = a.id
            JOIN persons p ON p.id = ap.person_id
            WHERE a.id = UUID_TO_BIN(:id, 1)
        ");
        $stmt->execute([':id' => $id]);
        $user = $stmt->fetch();

        if (!$user) {
            header('Location: /admin/users?error=not_found');
            exit;
        }

        // 2. Generate a fresh token
        $setupToken = bin2hex(random_bytes(32));
        $expiresAt  = date('Y-m-d H:i:s', strtotime('+48 hours'));

        $stmt = $pdo->prepare("
            UPDATE accounts 
            SET setup_token = :token, setup_expires_at = :expires 
            WHERE id = UUID_TO_BIN(:id, 1)
        ");

        $stmt->execute([
            ':token'   => $setupToken,
            ':expires' => $expiresAt,
            ':id'      => $id
        ]);

        // 3. Send the Email via PHPMailer
        try {
            $mail = \App\Support\Mailer::create();
            $mail->addAddress($user['email'], $user['first_name'] . " " . $user['last_name']);
            $mail->isHTML(true);
            $mail->Subject = "New Invitation: Zentropa Dailies";

            // Automatically detect the protocol and host (IP/Domain)
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https" : "http";
            $host = $_SERVER['HTTP_HOST'];
            $setupUrl = "{$protocol}://{$host}/setup-password?token=" . $setupToken;

            $firstName = $user['first_name'] ?? 'User';

            $mail->Body = "
        <!DOCTYPE html>
        <html xmlns:v='urn:schemas-microsoft-com:vml' xmlns:o='urn:schemas-microsoft-com:office:office'>
        <head>
            <meta charset='utf-8'>
            <meta name='viewport' content='width=device-width, initial-scale=1'>
            <meta name='color-scheme' content='dark'>
            <meta name='supported-color-schemes' content='dark'>
            <!--[if !mso]><!-->
            <meta http-equiv='X-UA-Compatible' content='IE=edge'>
            <!--<![endif]-->
            <style>
            :root { color-scheme: dark; supported-color-schemes: dark; }
            @media (prefers-color-scheme: dark) {
            .zd-body { background-color: #0b0c10 !important; }
            .zd-card { background-color: #13151b !important; border: 1px solid #1f232d !important; }
        }
                body {
                    margin: 0;
                    padding: 0;
                }
                @media only screen and (max-width: 600px) {
                    .content {
                        padding: 30px 20px !important;
                    }
                }
            </style>
        </head>

        <body style='margin:0; padding:0; width:100%; background-color:#f4f6f9; font-family:Arial,sans-serif;'>

        <table role='presentation' width='100%' cellpadding='0' cellspacing='0' border='0' style='background-color:#f4f6f9; width:100%; mso-table-lspace:0pt; mso-table-rspace:0pt;'>
            <tr>
                <td align='center' style='padding:50px 20px;'>

                    <!--[if mso]>
                    <table role='presentation' width='600' cellpadding='0' cellspacing='0' border='0'>
                        <tr>
                            <td>
                    <![endif]-->

                    <!-- Main Card -->
                    <table role='presentation' width='100%' cellpadding='0' cellspacing='0' border='0' style='max-width:600px; background-color:#ffffff; border-radius:8px; box-shadow:0 2px 8px rgba(0,0,0,0.08); mso-table-lspace:0pt; mso-table-rspace:0pt;'>
                        
                        <!-- Header with Brand -->
                        <tr>
                            <td style='padding:40px 40px 30px 40px; text-align:center; border-bottom:3px solid #3aa0ff;'>
                                <h1 style='margin:0; color:#3aa0ff; font-size:14px; text-transform:uppercase; letter-spacing:0.2em; font-weight:800; font-family:Arial,sans-serif;'>
                                    ZENTROPA DAILIES
                                </h1>
                            </td>
                        </tr>
                        
                        <!-- Content -->
                        <tr>
                            <td class='content' style='padding:45px 50px;'>
                                <h2 style='margin:0 0 24px 0; font-size:24px; font-weight:700; color:#1a1a1a; font-family:Arial,sans-serif;'>
                                    Account Invitation
                                </h2>

                                <p style='font-size:16px; line-height:1.6; color:#4a5568; margin:0 0 12px 0; font-family:Arial,sans-serif;'>
                                    Hi " . htmlspecialchars($firstName) . ",
                                </p>
                                
                                <p style='font-size:16px; line-height:1.6; color:#4a5568; margin:0 0 32px 0; font-family:Arial,sans-serif;'>
                                    Your account on the Zentropa Dailies platform is ready. Click the button below to set your password and access the system.
                                </p>

                                <!-- Button -->
                                <table role='presentation' width='100%' cellpadding='0' cellspacing='0' border='0' style='margin:0 0 32px 0; mso-table-lspace:0pt; mso-table-rspace:0pt;'>
                                    <tr>
                                        <td align='center' style='padding:0;'>
                                            <!--[if mso]>
                                            <v:roundrect xmlns:v='urn:schemas-microsoft-com:vml' xmlns:w='urn:schemas-microsoft-com:office:word' href='{$setupUrl}' style='height:54px; v-text-anchor:middle; width:240px;' arcsize='9%' strokecolor='#3aa0ff' fillcolor='#3aa0ff'>
                                                <w:anchorlock/>
                                                <center style='color:#ffffff; font-family:Arial,sans-serif; font-size:16px; font-weight:bold;'>
                                                    Set Up Your Account
                                                </center>
                                            </v:roundrect>
                                            <![endif]-->
                                            <!--[if !mso]><!-->
                                            <a href='{$setupUrl}' style='background-color:#3aa0ff; color:#ffffff; padding:18px 40px; border-radius:5px; text-decoration:none; font-size:16px; font-weight:700; display:inline-block; font-family:Arial,sans-serif; mso-hide:all;'>
                                                Set Up Your Account
                                            </a>
                                            <!--<![endif]-->
                                        </td>
                                    </tr>
                                </table>

                                <!-- Alternative Link -->
                                <table role='presentation' width='100%' cellpadding='0' cellspacing='0' border='0' style='padding-top:28px; border-top:1px solid #e2e8f0; mso-table-lspace:0pt; mso-table-rspace:0pt;'>
                                    <tr>
                                        <td>
                                            <p style='font-size:13px; line-height:1.5; color:#718096; margin:0 0 8px 0; font-family:Arial,sans-serif;'>
                                                If the button doesn't work, copy and paste this link into your browser:
                                            </p>
                                            <p style='font-size:13px; line-height:1.5; margin:0; font-family:Arial,sans-serif;'>
                                                <a href='{$setupUrl}' style='color:#3aa0ff; text-decoration:none; word-break:break-all;'>
                                                    {$setupUrl}
                                                </a>
                                            </p>
                                        </td>
                                    </tr>
                                </table>
                            </td>
                        </tr>

                        <!-- Footer -->
                        <tr>
                            <td style='padding:30px 40px; background-color:#f7fafc; text-align:center; border-radius:0 0 8px 8px;'>
                                <p style='margin:0 0 8px 0; font-size:12px; color:#718096; font-family:Arial,sans-serif;'>
                                    This invitation will expire in 48 hours.
                                </p>
                                <p style='margin:0; font-size:11px; color:#a0aec0; font-family:Arial,sans-serif;'>
                                    © " . date('Y') . " Zentropa Post Production
                                </p>
                            </td>
                        </tr>
                    </table>

                    <!--[if mso]>
                            </td>
                        </tr>
                    </table>
                    <![endif]-->

                </td>
            </tr>
        </table>

        </body>
        </html>
        ";

            $mail->AltBody = "Hi {$firstName},\n\n"
                . "An account has been prepared for you on Zentropa Dailies.\n\n"
                . "Please set up your account by visiting the link below:\n"
                . "{$setupUrl}\n\n"
                . "This link will expire in 48 hours.\n\n"
                . "© " . date('Y') . " Zentropa Post Production";

            $mail->send();
        } catch (\Exception $e) {
            error_log("Failed to resend invitation to " . $user['email'] . ": " . $e->getMessage());
            header('Location: /admin/users?error=mail_failed');
            exit;
        }

        header('Location: /admin/users?resent=1');
        exit;
    }

    public function edit($id)
    {
        // $id is the account UUID string in path
        $pdo = \App\Support\DB::pdo();

        // Load account + person + primary email + primary phone (if any)
        $sql = "
        SELECT
            BIN_TO_UUID(a.id, 1)         AS account_uuid,
            a.email                       AS account_email,
            a.user_role,
            a.is_superuser,
            a.status,
            p.first_name,
            p.last_name,
            -- primary email (contacts)
            (SELECT pc.value FROM person_contacts pc
             WHERE pc.person_id = ap.person_id AND pc.type='email' AND pc.is_primary=1
             ORDER BY pc.created_at ASC LIMIT 1) AS primary_email,
            -- primary phone (contacts)
            (SELECT pc.value FROM person_contacts pc
             WHERE pc.person_id = ap.person_id AND pc.type='phone' AND pc.is_primary=1
             ORDER BY pc.created_at ASC LIMIT 1) AS primary_phone
        FROM accounts a
        JOIN accounts_persons ap ON ap.account_id = a.id
        JOIN persons p ON p.id = ap.person_id
        WHERE a.id = UUID_TO_BIN(:aid, 1)
        LIMIT 1
    ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':aid' => $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            http_response_code(404);
            echo "User not found.";
            return;
        }

        return \App\Support\View::render('admin/users/edit', [
            'user' => $row,
        ]);
    }

    public function update($id)
    {
        if (session_status() === PHP_SESSION_NONE) session_start();
        \App\Support\Csrf::validateOrAbort($_POST['_csrf'] ?? null);

        $pdo = \App\Support\DB::pdo();
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        // Normalize inputs
        $first = trim((string)($_POST['first_name'] ?? ''));
        $last  = trim((string)($_POST['last_name']  ?? ''));
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $phone = str_replace(' ', '', trim((string)($_POST['phone'] ?? '')));
        $role  = (string)($_POST['user_role'] ?? 'regular');
        $isSU  = !empty($_POST['is_superuser']) ? 1 : 0;
        $status = (string)($_POST['status'] ?? 'active'); // active, disabled, etc. based on your enum

        // Security Guardrail: Only superusers can modify roles or superuser status.
        // We check the session to see if the LOGGED-IN person is a superuser.
        $viewerIsSuperuser = !empty($_SESSION['account']['is_superuser']);

        if (!$viewerIsSuperuser) {
            // Force the role and superuser status back to the existing values 
            // from the database so they cannot be changed via POST injection.
            $loadExisting = $pdo->prepare("SELECT user_role, is_superuser FROM accounts WHERE id = UUID_TO_BIN(:aid, 1) LIMIT 1");
            $loadExisting->execute([':aid' => $id]);
            $current = $loadExisting->fetch(\PDO::FETCH_ASSOC);

            if ($current) {
                $role = $current['user_role'];
                $isSU = $current['is_superuser'];
            }
        }

        $pw    = (string)($_POST['password'] ?? '');
        $pwc   = (string)($_POST['password_confirm'] ?? '');

        $errors = [];
        if ($first === '') $errors[] = 'First name is required.';
        if ($last  === '') $errors[] = 'Last name is required.';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'A valid email is required.';
        if (!in_array($role, ['regular', 'admin'], true)) $errors[] = 'Invalid role.';
        if (!in_array($status, ['active', 'disabled', 'locked'], true)) $errors[] = 'Invalid status.';
        if ($pw !== '' || $pwc !== '') {
            if ($pw !== $pwc) $errors[] = 'Passwords do not match.';
            if ($pw !== '' && strlen($pw) < 8) $errors[] = 'Password must be at least 8 characters.';
        }

        // Load existing record (account + person id)
        $load = $pdo->prepare("
        SELECT a.id AS account_id, ap.person_id
        FROM accounts a
        JOIN accounts_persons ap ON ap.account_id = a.id
        WHERE a.id = UUID_TO_BIN(:aid, 1)
        LIMIT 1
    ");
        $load->execute([':aid' => $id]);
        $existing = $load->fetch(\PDO::FETCH_ASSOC);

        if (!$existing) {
            http_response_code(404);
            echo "User not found.";
            return;
        }

        $accountIdBin = $existing['account_id'];
        $personIdBin  = $existing['person_id'];

        // Email uniqueness check (exclude current account)
        $chk = $pdo->prepare("
            SELECT 1 FROM accounts 
            WHERE email = :e 
            AND id <> :me 
            AND status != 'deleted' 
            LIMIT 1
        ");
        $chk->execute([':e' => $email, ':me' => $accountIdBin]);
        if ($chk->fetch()) {
            $errors[] = 'That email is already in use by another account.';
        }

        if ($errors) {
            // Rebuild $user array for re-render
            $user = [
                'account_uuid'  => $id,
                'account_email' => $email,
                'user_role'     => $role,
                'is_superuser'  => $isSU,
                'status'        => $status,
                'first_name'    => $first,
                'last_name'     => $last,
                'primary_email' => $email,
                'primary_phone' => $phone,
            ];
            return \App\Support\View::render('admin/users/edit', [
                'user'   => $user,
                'errors' => $errors,
            ]);
        }

        try {
            $pdo->beginTransaction();

            // Update person
            $upP = $pdo->prepare("
            UPDATE persons
            SET first_name = :fn, last_name = :ln
            WHERE id = :pid
        ");
            $upP->execute([':fn' => $first, ':ln' => $last, ':pid' => $personIdBin]);

            // Update account core fields
            $upA = $pdo->prepare("
            UPDATE accounts
            SET email = :email, user_role = :role, is_superuser = :su, status = :st
            WHERE id = :aid
        ");
            $upA->execute([
                ':email' => $email,
                ':role'  => $role,
                ':su'    => $isSU,
                ':st'    => $status, // This correctly handles 'active', 'disabled', or 'locked'
                ':aid'   => $accountIdBin,
            ]);

            // Optional: password change
            if ($pw !== '') {
                $hash = password_hash($pw, PASSWORD_BCRYPT);
                $upPw = $pdo->prepare("UPDATE accounts SET password_hash = :h WHERE id = :aid");
                $upPw->execute([':h' => $hash, ':aid' => $accountIdBin]);
            }

            // person_contacts — ensure primary email matches account email
            // 1) Clear existing primary email flags, then upsert the given email as primary
            $pdo->prepare("
            UPDATE person_contacts
            SET is_primary = 0
            WHERE person_id = :pid AND type = 'email'
        ")->execute([':pid' => $personIdBin]);

            // Try update existing email row to be primary (by value)
            $aff = $pdo->prepare("
                UPDATE person_contacts
                SET is_primary = 1, value = :val_new
                WHERE person_id = :pid AND type='email' AND value = :val_old
            ");
            $aff->execute([
                ':pid'     => $personIdBin,
                ':val_new' => $email,
                ':val_old' => $email, // same value is fine; placeholders must be unique
            ]);

            if ($aff->rowCount() === 0) {
                // If not present, insert one
                $ins = $pdo->prepare("
                INSERT INTO person_contacts (id, person_id, type, value, is_primary, verified)
                VALUES (UUID_TO_BIN(UUID(),1), :pid, 'email', :val, 1, 0)
            ");
                $ins->execute([':pid' => $personIdBin, ':val' => $email]);
            }

            // person_contacts — phone (primary if provided; if blank, we simply leave existing as-is or clear primary)
            if ($phone !== '') {
                // Clear other primary phones
                $pdo->prepare("
                UPDATE person_contacts
                SET is_primary = 0
                WHERE person_id = :pid AND type = 'phone'
            ")->execute([':pid' => $personIdBin]);

                // Try update same phone value to primary
                $upPh = $pdo->prepare("
                    UPDATE person_contacts
                    SET is_primary = 1, value = :val_new
                    WHERE person_id = :pid AND type='phone' AND value = :val_old
                ");
                $upPh->execute([
                    ':pid'     => $personIdBin,
                    ':val_new' => $phone,
                    ':val_old' => $phone,
                ]);

                if ($upPh->rowCount() === 0) {
                    $pdo->prepare("
                    INSERT INTO person_contacts (id, person_id, type, value, is_primary, verified)
                    VALUES (UUID_TO_BIN(UUID(),1), :pid, 'phone', :val, 1, 0)
                ")->execute([':pid' => $personIdBin, ':val' => $phone]);
                }
            } else {
                // Optional policy: if phone cleared, unmark any primary phone (but keep rows)
                $pdo->prepare("
                UPDATE person_contacts
                SET is_primary = 0
                WHERE person_id = :pid AND type='phone'
            ")->execute([':pid' => $personIdBin]);
            }

            $pdo->commit();

            header('Location: /admin/users'); // Back to list (we can add a flash later)
            exit;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();

            $user = [
                'account_uuid'  => $id,
                'account_email' => $email,
                'user_role'     => $role,
                'is_superuser'  => $isSU,
                'status'        => $status,
                'first_name'    => $first,
                'last_name'     => $last,
                'primary_email' => $email,
                'primary_phone' => $phone,
            ];
            return \App\Support\View::render('admin/users/edit', [
                'user'   => $user,
                'errors' => [$e->getMessage()],
            ]);
        }
    }

    /**
     * Toggles account status between 'active' and 'disabled'
     * Restricted to Superusers via logic and Routes.php
     */
    public function toggleStatus($id)
    {
        if (session_status() === PHP_SESSION_NONE) session_start();

        // Final security check in the controller
        if (empty($_SESSION['account']['is_superuser'])) {
            http_response_code(403);
            echo "Forbidden: Superuser access required.";
            exit;
        }

        \App\Support\Csrf::validateOrAbort($_POST['_csrf'] ?? null);
        $pdo = \App\Support\DB::pdo();

        // 1. Get current status
        $stmt = $pdo->prepare("SELECT status FROM accounts WHERE id = UUID_TO_BIN(:id, 1)");
        $stmt->execute([':id' => $id]);
        $currentStatus = $stmt->fetchColumn();

        // 2. Toggle status
        $newStatus = ($currentStatus === 'active') ? 'disabled' : 'active';

        // 3. Update the database
        $pdo->prepare("UPDATE accounts SET status = :st WHERE id = UUID_TO_BIN(:id, 1)")
            ->execute([':st' => $newStatus, ':id' => $id]);

        header('Location: /admin/users?status_updated=1');
        exit;
    }

    /**
     * Soft deletes a user account
     */
    public function destroy($id)
    {
        if (session_status() === PHP_SESSION_NONE) session_start();

        if (empty($_SESSION['account']['is_superuser'])) {
            http_response_code(403);
            exit;
        }

        \App\Support\Csrf::validateOrAbort($_POST['_csrf'] ?? null);
        $pdo = \App\Support\DB::pdo();

        try {
            $pdo->beginTransaction();

            // 1. Find the person_id linked to this account
            $stmt = $pdo->prepare("SELECT person_id FROM accounts_persons WHERE account_id = UUID_TO_BIN(:id, 1) LIMIT 1");
            $stmt->execute([':id' => $id]);
            $personId = $stmt->fetchColumn();

            // 2. Soft delete the account (timestamp the email)
            $pdo->prepare("
                UPDATE accounts 
                SET status = 'deleted', email = CONCAT(email, '--deleted-', UNIX_TIMESTAMP()) 
                WHERE id = UUID_TO_BIN(:id, 1)
            ")->execute([':id' => $id]);

            if ($personId) {
                // 3. De-activate and timestamp the contacts so they are "freed up"
                $pdo->prepare("
            UPDATE person_contacts 
            SET is_primary = 0, 
                value = CONCAT(value, '--deleted-', UNIX_TIMESTAMP())
            WHERE person_id = :pid
        ")->execute([':pid' => $personId]);
            }

            // 4. Remove the person from all projects so they don't appear as "ghost members"
            $pdo->prepare("DELETE FROM project_members WHERE person_id = :pid")
                ->execute([':pid' => $personId]);

            $pdo->commit();
            header('Location: /admin/users?deleted=1');
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log("Deep Soft Delete failed: " . $e->getMessage());
            header('Location: /admin/users?error=delete_failed');
        }
        exit;
    }

    // ---- helpers ----

    private function uuidStr(PDO $pdo): string
    {
        // Get a UUID string from MySQL to guarantee same variant/version as DB defaults
        $stmt = $pdo->query("SELECT UUID() AS u");
        return (string)$stmt->fetchColumn();
    }

    private function uuidToBin(PDO $pdo, string $uuidStr)
    {
        // Convert string -> BINARY(16) with swap flag=1 (same as your schema)
        $stmt = $pdo->prepare("SELECT UUID_TO_BIN(:u, 1)");
        $stmt->execute([':u' => $uuidStr]);
        return $stmt->fetchColumn();
    }
}
