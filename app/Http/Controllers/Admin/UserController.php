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
        $users = (new AccountRepository())->listForAdmin(); // next step
        return View::render('admin/users/index', ['users' => $users]);
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

        $pw    = (string)($_POST['password'] ?? '');
        $pwc   = (string)($_POST['password_confirm'] ?? '');

        $errors = [];
        if ($first === '') $errors[] = 'First name is required.';
        if ($last  === '') $errors[] = 'Last name is required.';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'A valid email is required.';
        if (!in_array($role, ['regular', 'admin'], true)) $errors[] = 'Invalid role.';
        if ($pw === '' || $pwc === '') $errors[] = 'Password and confirmation are required.';
        if ($pw !== $pwc) $errors[] = 'Passwords do not match.';
        if (strlen($pw) < 8) $errors[] = 'Password must be at least 8 characters.';

        if ($errors) {
            return View::render('admin/users/create', [
                'errors' => $errors,
                'old'    => [
                    'first_name'   => $first,
                    'last_name'    => $last,
                    'email'        => $email,
                    'phone'        => $phone,
                    'user_role'    => $role,
                    'is_superuser' => $isSU,
                ],
            ]);
        }

        $pdo = DB::pdo();
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        try {
            $pdo->beginTransaction();

            // Uniqueness check for account email
            $stmt = $pdo->prepare("SELECT 1 FROM accounts WHERE email = :e LIMIT 1");
            $stmt->execute([':e' => $email]);
            if ($stmt->fetch()) {
                throw new \RuntimeException('That email is already in use.');
            }

            // Create UUIDs explicitly so we can link immediately
            // (we use UUIDv1 with swap flag=1 to match schema’s BIN ordering)
            $personUuid  = $this->uuidStr($pdo);
            $personBin   = $this->uuidToBin($pdo, $personUuid);

            $accountUuid = $this->uuidStr($pdo);
            $accountBin  = $this->uuidToBin($pdo, $accountUuid);

            // Insert person
            $stmt = $pdo->prepare("
                INSERT INTO persons (id, first_name, last_name)
                VALUES (:id, :fn, :ln)
            ");
            $stmt->execute([
                ':id' => $personBin,
                ':fn' => $first,
                ':ln' => $last,
            ]);

            // Insert account
            $hash = password_hash($pw, PASSWORD_BCRYPT);
            $stmt = $pdo->prepare("
                INSERT INTO accounts (id, email, password_hash, user_role, is_superuser, mfa_policy, status)
                VALUES (:id, :email, :hash, :role, :su, 'optional', 'active')
            ");
            $stmt->execute([
                ':id'    => $accountBin,
                ':email' => $email,
                ':hash'  => $hash,
                ':role'  => $role,
                ':su'    => $isSU,
            ]);

            // Link account <-> person
            $stmt = $pdo->prepare("
                INSERT INTO accounts_persons (account_id, person_id)
                VALUES (:aid, :pid)
            ");
            $stmt->execute([
                ':aid' => $accountBin,
                ':pid' => $personBin,
            ]);

            // person_contacts: primary email
            $contactIdEmail = $this->uuidToBin($pdo, $this->uuidStr($pdo));
            $stmt = $pdo->prepare("
                INSERT INTO person_contacts (id, person_id, type, value, is_primary, verified)
                VALUES (:id, :pid, 'email', :val, 1, 0)
            ");
            $stmt->execute([
                ':id'  => $contactIdEmail,
                ':pid' => $personBin,
                ':val' => $email,
            ]);

            // person_contacts: optional phone
            if ($phone !== '') {
                $contactIdPhone = $this->uuidToBin($pdo, $this->uuidStr($pdo));
                $stmt = $pdo->prepare("
                    INSERT INTO person_contacts (id, person_id, type, value, is_primary, verified)
                    VALUES (:id, :pid, 'phone', :val, 1, 0)
                ");
                $stmt->execute([
                    ':id'  => $contactIdPhone,
                    ':pid' => $personBin,
                    ':val' => $phone,
                ]);
            }

            $pdo->commit();

            // Redirect to Users list
            header('Location: /admin/users');
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();

            return View::render('admin/users/create', [
                'errors' => [$e->getMessage()],
                'old'    => [
                    'first_name'   => $first,
                    'last_name'    => $last,
                    'email'        => $email,
                    'phone'        => $phone,
                    'user_role'    => $role,
                    'is_superuser' => $isSU,
                ],
            ]);
        }
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
        $phone = trim((string)($_POST['phone'] ?? ''));
        $role  = (string)($_POST['user_role'] ?? 'regular');
        $isSU  = !empty($_POST['is_superuser']) ? 1 : 0;
        $status = (string)($_POST['status'] ?? 'active'); // active, disabled, etc. based on your enum

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
        $chk = $pdo->prepare("SELECT 1 FROM accounts WHERE email = :e AND id <> :me LIMIT 1");
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
                ':st'    => $status,
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
