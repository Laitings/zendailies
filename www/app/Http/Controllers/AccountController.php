<?php

namespace App\Http\Controllers;

use App\Support\DB;
use App\Support\View;
use App\Support\Csrf;
use PDO;
use Throwable;

class AccountController
{
    public function index()
    {
        if (session_status() === PHP_SESSION_NONE) session_start();

        // AuthController stores this as a UUID string
        $personUuid = $_SESSION['person_uuid'] ?? null;
        $accountIdBin = $_SESSION['account']['id'] ?? null;

        if (!$personUuid || !$accountIdBin) {
            header('Location: /auth/login');
            exit;
        }

        $pdo = \App\Support\DB::pdo();

        // We fetch everything tied to the Person UUID
        $sql = "
        SELECT 
            BIN_TO_UUID(a.id, 1) AS account_uuid,
            a.email AS email,
            a.created_at,
            p.first_name,
            p.last_name,
            -- Get primary phone from person_contacts
            (SELECT value FROM person_contacts 
             WHERE person_id = p.id AND type='phone' AND is_primary=1 
             LIMIT 1) AS phone
        FROM persons p
        JOIN accounts_persons ap ON ap.person_id = p.id
        JOIN accounts a ON a.id = ap.account_id
        WHERE p.id = UUID_TO_BIN(:puuid, 1)
        LIMIT 1
    ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([':puuid' => $personUuid]);
        $user = $stmt->fetch(\PDO::FETCH_ASSOC);

        // FALLBACK: If DB fetch fails for some reason, use session data
        if (!$user) {
            $user = [
                'first_name' => $_SESSION['account']['first_name'] ?? '',
                'last_name'  => $_SESSION['account']['last_name'] ?? '',
                'email'      => $_SESSION['account']['email'] ?? '',
                'phone'      => ''
            ];
        }

        return \App\Support\View::render('account/index', ['user' => $user]);
    }

    public function update()
    {
        if (session_status() === PHP_SESSION_NONE) session_start();
        Csrf::validateOrAbort($_POST['_csrf'] ?? null);

        $pdo = DB::pdo();
        $personUuid = $_SESSION['person_uuid'] ?? '';

        // 1. Fetch fresh IDs
        $stmt = $pdo->prepare("
            SELECT account_id, person_id 
            FROM accounts_persons 
            WHERE person_id = UUID_TO_BIN(:puuid, 1) 
            LIMIT 1
        ");
        $stmt->execute([':puuid' => $personUuid]);
        $ids = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$ids) {
            return View::render('account/index', ['errors' => ["Account context lost. Please re-login."]]);
        }

        $myAccountIdBin = $ids['account_id'];
        $myPersonIdBin  = $ids['person_id'];

        $first = trim((string)($_POST['first_name'] ?? ''));
        $last  = trim((string)($_POST['last_name']  ?? ''));
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $phone = trim((string)($_POST['phone'] ?? ''));
        $pw    = (string)($_POST['password'] ?? '');
        $pwc   = (string)($_POST['password_confirm'] ?? '');

        $errors = [];
        if (!$first || !$last) $errors[] = "Name fields cannot be empty.";
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "Valid email required.";

        if ($pw !== '') {
            if ($pw !== $pwc) $errors[] = "Passwords do not match.";
            if (strlen($pw) < 8) $errors[] = "Password must be at least 8 characters.";
        }

        // Uniqueness check for email
        $chk = $pdo->prepare("SELECT 1 FROM accounts WHERE email = :e AND id != :me LIMIT 1");
        $chk->bindValue(':e', $email);
        $chk->bindValue(':me', $myAccountIdBin, PDO::PARAM_LOB);
        $chk->execute();
        if ($chk->fetch()) $errors[] = "That email is already taken.";

        if ($errors) {
            return View::render('account/index', ['errors' => $errors, 'user' => $_POST]);
        }

        try {
            $pdo->beginTransaction();

            // 2. Update Person
            $pdo->prepare("UPDATE persons SET first_name = :f, last_name = :l WHERE id = :pid")
                ->execute([':f' => $first, ':l' => $last, ':pid' => $myPersonIdBin]);

            // 3. Update Account Email
            $pdo->prepare("UPDATE accounts SET email = :e WHERE id = :aid")
                ->execute([':e' => $email, ':aid' => $myAccountIdBin]);

            // 4. Update Phone (Constraint-Safe Upsert)
            if ($phone !== '') {
                // First, remove the primary flag from all your phones
                $pdo->prepare("UPDATE person_contacts SET is_primary = 0 WHERE person_id = :pid AND type = 'phone'")
                    ->execute([':pid' => $myPersonIdBin]);

                // Check if THIS specific phone number already exists for you
                $checkPh = $pdo->prepare("SELECT id FROM person_contacts WHERE person_id = :pid AND type = 'phone' AND value = :val LIMIT 1");
                $checkPh->execute([':pid' => $myPersonIdBin, ':val' => $phone]);
                $existingPhone = $checkPh->fetch();

                if ($existingPhone) {
                    // It exists! Just make it primary.
                    $pdo->prepare("UPDATE person_contacts SET is_primary = 1 WHERE id = :id")
                        ->execute([':id' => $existingPhone['id']]);
                } else {
                    // It's a brand new number. Try to update the old primary row, or insert new.
                    $upOld = $pdo->prepare("UPDATE person_contacts SET value = :val, is_primary = 1 WHERE person_id = :pid AND type = 'phone' LIMIT 1");
                    $upOld->execute([':val' => $phone, ':pid' => $myPersonIdBin]);

                    if ($upOld->rowCount() === 0) {
                        $pdo->prepare("
                            INSERT INTO person_contacts (id, person_id, type, value, is_primary, verified)
                            VALUES (UUID_TO_BIN(UUID(), 1), :pid, 'phone', :val, 1, 0)
                        ")->execute([':pid' => $myPersonIdBin, ':val' => $phone]);
                    }
                }
            }

            // 5. Update Password
            if ($pw !== '') {
                $hash = password_hash($pw, PASSWORD_BCRYPT);
                $pdo->prepare("UPDATE accounts SET password_hash = :h WHERE id = :aid")
                    ->execute([':h' => $hash, ':aid' => $myAccountIdBin]);
            }

            $pdo->commit();

            // Sync Session
            $_SESSION['account']['first_name']   = $first;
            $_SESSION['account']['last_name']    = $last;
            $_SESSION['account']['display_name'] = $first . ' ' . $last;
            $_SESSION['account']['email']        = $email;
            $_SESSION['person'] = $_SESSION['account'];
            $_SESSION['user']   = $_SESSION['account'];

            header('Location: /account?success=1');
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            return View::render('account/index', ['errors' => [$e->getMessage()], 'user' => $_POST]);
        }
    }

    public function mobileEdit()
    {
        if (session_status() === PHP_SESSION_NONE) session_start();
        $personUuid = $_SESSION['person_uuid'] ?? null;

        if (!$personUuid) {
            header('Location: /auth/login');
            exit;
        }

        $pdo = \App\Support\DB::pdo();
        $stmt = $pdo->prepare("
        SELECT first_name, last_name, display_name 
        FROM persons WHERE id = UUID_TO_BIN(:id, 1)
    ");
        $stmt->execute([':id' => $personUuid]);
        $person = $stmt->fetch(\PDO::FETCH_ASSOC);

        // This points to app/Views/account/index_mobile.php
        \App\Support\View::render('account/index_mobile', [
            'person' => $person,
            'layout' => 'layout/mobile'
        ]);
    }

    public function mobileUpdate()
    {
        if (session_status() === PHP_SESSION_NONE) session_start();

        $personUuid = $_SESSION['person_uuid'] ?? null;
        $accountIdBin = $_SESSION['account']['id'] ?? null;

        // DEBUG START
        $logFile = __DIR__ . '/../../../debug_update.txt';
        $debug = "[UPDATE LOG " . date('H:i:s') . "]\n";
        $debug .= "Person UUID: " . ($personUuid ?? 'MISSING') . "\n";
        $debug .= "Account ID (Bin): " . ($accountIdBin ? 'EXISTS' : 'MISSING') . "\n";
        $debug .= "POST Data: " . json_encode($_POST) . "\n";

        if (!$personUuid || !$accountIdBin) {
            file_put_contents($logFile, $debug . "FAILURE: Missing Session Data\n\n", FILE_APPEND);
            header('Location: /auth/login');
            exit;
        }

        $pdo = DB::pdo();
        $pdo->beginTransaction();

        try {
            // 1. Update Persons (Remove display_name from here!)
            $stP = $pdo->prepare("
            UPDATE persons 
            SET first_name = :f, last_name = :l
            WHERE id = UUID_TO_BIN(:id, 1)
        ");
            $stP->execute([
                ':f' => $_POST['first_name'],
                ':l' => $_POST['last_name'],
                ':id' => $personUuid
            ]);
            $debug .= "Persons Rows Affected: " . $stP->rowCount() . "\n";

            // 2. Update Account (Email)
            $stA = $pdo->prepare("UPDATE accounts SET email = :e WHERE id = :aid");
            $stA->execute([':e' => $_POST['email'], ':aid' => $accountIdBin]);
            $debug .= "Account Rows Affected: " . $stA->rowCount() . "\n";

            // 3. Password
            if (!empty($_POST['new_password'])) {
                $hash = password_hash($_POST['new_password'], PASSWORD_BCRYPT);
                $stPw = $pdo->prepare("UPDATE accounts SET password_hash = :h WHERE id = :aid");
                $stPw->execute([':h' => $hash, ':aid' => $accountIdBin]);
                $debug .= "Password Updated: Yes\n";
            }

            $pdo->commit();
            $debug .= "TRANSACTION COMMITTED\n";

            // 4. Sync Session (Construct display_name manually for the session)
            $_SESSION['account']['first_name']   = $_POST['first_name'];
            $_SESSION['account']['last_name']    = $_POST['last_name'];
            $_SESSION['account']['display_name'] = $_POST['first_name'] . ' ' . $_POST['last_name'];
            $_SESSION['account']['email']        = $_POST['email'];
            $_SESSION['person'] = $_SESSION['account'];

            file_put_contents($logFile, $debug . "SUCCESS\n\n", FILE_APPEND);
            header('Location: /account/mobile?success=1');
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $debug .= "EXCEPTION: " . $e->getMessage() . "\n";
            file_put_contents($logFile, $debug . "FAILED\n\n", FILE_APPEND);
            header('Location: /account/mobile?error=1');
        }
        exit;
    }
}
