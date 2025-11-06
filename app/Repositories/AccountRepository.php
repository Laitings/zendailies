<?php

namespace App\Repositories;

use App\Support\DB; // our PDO singleton

final class AccountRepository
{
    public function listForAdmin(): array
    {
        $sql = "
        SELECT
          BIN_TO_UUID(a.id, 1)        AS account_uuid,
          a.email                      AS account_email,
          a.status,
          a.is_superuser,
          a.user_role,
          p.first_name,
          p.last_name,
          -- primary email/phone from person_contacts (if present)
          (SELECT pc.value FROM person_contacts pc
            WHERE pc.person_id = ap.person_id AND pc.type='email' AND pc.is_primary=1
            LIMIT 1) AS person_email,
          (SELECT pc.value FROM person_contacts pc
            WHERE pc.person_id = ap.person_id AND pc.type='phone' AND pc.is_primary=1
            LIMIT 1) AS person_phone
        FROM accounts a
        LEFT JOIN accounts_persons ap ON ap.account_id = a.id
        LEFT JOIN persons p ON p.id = ap.person_id
        ORDER BY p.last_name, p.first_name, a.email
        ";
        $stmt = DB::pdo()->query($sql);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }


    // Get one active account by email, including one linked person (if any).
    public function findActiveByEmail(string $email): ?array
    {
        $sql = "
            SELECT
                BIN_TO_UUID(a.id, 1)           AS id,
                a.email,
                a.password_hash,
                a.is_superuser,
                a.mfa_policy,
                a.status,

                -- Linked person (pick the first one by created_at)
                j.person_uuid,
                j.first_name,
                j.last_name,
                j.display_name
            FROM accounts a
            LEFT JOIN (
                SELECT ap.account_id,
                       BIN_TO_UUID(p.id, 1) AS person_uuid,
                       p.first_name,
                       p.last_name,
                       p.display_name,
                       ROW_NUMBER() OVER (PARTITION BY ap.account_id ORDER BY p.created_at, p.id) AS rn
                FROM accounts_persons ap
                JOIN persons p ON p.id = ap.person_id
            ) j ON j.account_id = a.id AND j.rn = 1
            WHERE a.email = :email AND a.status = 'active'
            LIMIT 1
        ";

        $stmt = DB::pdo()->prepare($sql);
        $stmt->execute([':email' => $email]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    // (Optional) Keep this if you still need raw person UUID lookups elsewhere.
    public function firstPersonIdForAccount(string $accountUuid): ?string
    {
        $sql = "
            SELECT BIN_TO_UUID(ap.person_id, 1) AS person_uuid
            FROM accounts_persons ap
            JOIN persons p ON p.id = ap.person_id
            WHERE ap.account_id = UUID_TO_BIN(:aid, 1)
            ORDER BY p.created_at, p.id
            LIMIT 1
        ";
        $stmt = DB::pdo()->prepare($sql);
        $stmt->execute([':aid' => $accountUuid]);
        $row = $stmt->fetch();
        return $row['person_uuid'] ?? null;
    }
}
