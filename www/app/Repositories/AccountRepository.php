<?php

namespace App\Repositories;

use App\Support\DB;
use PDO; // our PDO singleton

final class AccountRepository
{
    /**
     * List users for the admin Users page.
     *
     * @param string      $viewerPersonUuid  BIN_TO_UUID(person_id,1) of the logged-in person.
     * @param bool        $viewerIsSuperuser Whether the viewer is a superuser.
     * @param string|null $projectFilterUuid Optional project UUID to filter on (BIN_TO_UUID(id,1)).
     * @param string      $sortColumn        The column to sort by.
     * @param string      $sortDirection     'asc' or 'desc'.
     */
    public function listForAdmin(
        string $viewerPersonUuid,
        bool $viewerIsSuperuser,
        ?string $projectFilterUuid = null,
        string $sortColumn = 'name',
        string $sortDirection = 'asc'
    ): array {
        $pdo = DB::pdo();

        // Sanitize sort direction
        $dir = strtolower($sortDirection) === 'desc' ? 'DESC' : 'ASC';

        // ---------- SUPERUSER: can see all users ----------
        if ($viewerIsSuperuser) {
            $sql = "
            SELECT
                BIN_TO_UUID(a.id, 1) AS account_uuid,
                a.email               AS account_email,
                a.user_role,
                a.is_superuser,
                a.status,
                a.setup_token,
                p.first_name,
                p.last_name,
                em.value              AS person_email,
                ph.value              AS person_phone,
                GROUP_CONCAT(DISTINCT pr.title ORDER BY pr.title SEPARATOR ', ') AS project_list
            FROM accounts a
            JOIN accounts_persons ap
                ON ap.account_id = a.id
            JOIN persons p
                ON p.id = ap.person_id
            LEFT JOIN person_contacts em
                ON em.person_id = p.id
               AND em.type = 'email'
               AND em.is_primary = 1
            LEFT JOIN person_contacts ph
                ON ph.person_id = p.id
               AND ph.type = 'phone'
               AND ph.is_primary = 1
            LEFT JOIN project_members pm
                ON pm.person_id = p.id
            LEFT JOIN projects pr
                ON pr.id = pm.project_id
        ";

            $params = [];

            // Base filter: exclude soft-deleted users
            $sql .= " WHERE a.status != 'deleted' ";

            // If a specific project is chosen, append it to the existing WHERE clause
            if ($projectFilterUuid !== null) {
                $sql .= " AND pm.project_id = UUID_TO_BIN(:proj, 1) ";
                $params[':proj'] = $projectFilterUuid;
            }

            // Whitelist sortable columns
            $sortMap = [
                'name'     => 'p.first_name ' . $dir . ', p.last_name ' . $dir,
                'email'    => 'a.email ' . $dir,
                'role'     => 'a.user_role ' . $dir . ', a.is_superuser ' . $dir,
                'status'   => 'a.status ' . $dir,
                'projects' => 'project_list ' . $dir,
            ];

            $orderBy = $sortMap[$sortColumn] ?? null;

            // Default sort if column is invalid or not provided
            if (!$orderBy) {
                $orderBy = 'p.last_name ASC, p.first_name ASC';
            }




            $sql .= "
            GROUP BY
                a.id,
                a.email,
                a.user_role,
                a.is_superuser,
                a.status,
                a.setup_token,
                p.id,
                p.first_name,
                p.last_name,
                em.value,
                ph.value
            ORDER BY {$orderBy}
        ";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }

        // ---------- NON-SUPERUSER ADMIN: Global View with Project Privacy ----------
        $sql = "
        SELECT
            BIN_TO_UUID(a.id, 1) AS account_uuid,
            a.email               AS account_email,
            a.user_role,
            a.is_superuser,
            a.status,
            a.setup_token,
            p.first_name,
            p.last_name,
            em.value              AS person_email,
            ph.value              AS person_phone,
            -- PRIVACY FIX: Only show project titles if the viewer is an admin on them
            GROUP_CONCAT(DISTINCT 
                CASE WHEN vpm.is_project_admin = 1 THEN pr.title END 
                ORDER BY pr.title SEPARATOR ', '
            ) AS project_list
        FROM accounts a
        JOIN accounts_persons ap
            ON ap.account_id = a.id
        JOIN persons p
            ON p.id = ap.person_id
        LEFT JOIN person_contacts em
            ON em.person_id = p.id
           AND em.type = 'email'
           AND em.is_primary = 1
        LEFT JOIN person_contacts ph
            ON ph.person_id = p.id
           AND ph.type = 'phone'
           AND ph.is_primary = 1
        -- Look at all project memberships for the users
        LEFT JOIN project_members pm
            ON pm.person_id = p.id
        LEFT JOIN projects pr
            ON pr.id = pm.project_id
        -- Check if the VIEWING DIT is an admin on those specific projects
        LEFT JOIN project_members vpm
            ON vpm.project_id = pm.project_id
           AND vpm.person_id  = UUID_TO_BIN(:viewer_pid, 1)
           AND vpm.is_project_admin = 1
    ";

        $params = [
            ':viewer_pid' => $viewerPersonUuid,
        ];

        // Base filter: hide soft-deleted users
        $sql .= " WHERE a.status != 'deleted' ";

        // If a specific project is chosen, append it
        if ($projectFilterUuid !== null) {
            $sql .= " AND pm.project_id = UUID_TO_BIN(:proj, 1) ";
            $params[':proj'] = $projectFilterUuid;
        }

        // Sort logic (Matching Superuser logic)
        $sortMap = [
            'name'     => 'p.first_name ' . $dir . ', p.last_name ' . $dir,
            'email'    => 'a.email ' . $dir,
            'role'     => 'a.user_role ' . $dir . ', a.is_superuser ' . $dir,
            'status'   => 'a.status ' . $dir,
            'projects' => 'project_list ' . $dir,
        ];

        $orderBy = $sortMap[$sortColumn] ?? 'p.last_name ASC, p.first_name ASC';

        $sql .= "
        GROUP BY
            a.id, a.email, a.user_role, a.is_superuser, a.status, a.setup_token,
            p.id, p.first_name, p.last_name, em.value, ph.value
        ORDER BY {$orderBy}
    ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * List projects visible to this viewer (for the Users filter dropdown).
     *
     * Superuser: all projects.
     * Admin: only projects where they are project_admin.
     */
    public function listProjectsForUser(string $viewerPersonUuid, bool $viewerIsSuperuser): array
    {
        $pdo = DB::pdo();

        if ($viewerIsSuperuser) {
            $sql = "
            SELECT
                BIN_TO_UUID(p.id, 1) AS project_uuid,
                p.title
            FROM projects p
            ORDER BY p.title
        ";
            $stmt = $pdo->query($sql);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }

        $sql = "
        SELECT DISTINCT
            BIN_TO_UUID(p.id, 1) AS project_uuid,
            p.title
        FROM projects p
        JOIN project_members pm
            ON pm.project_id = p.id
        WHERE pm.person_id = UUID_TO_BIN(:pid, 1)
          AND pm.is_project_admin = 1
        ORDER BY p.title
    ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([':pid' => $viewerPersonUuid]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
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
                a.user_role,
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
