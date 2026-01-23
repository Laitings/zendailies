<?php

namespace App\Repositories;

use PDO;
use PDOException;

class ProjectRepository
{
    public function __construct(private PDO $pdo) {}

    public function listAll(): array
    {
        // Fix: Join 'days' instead of 'shooting_days'
        $sql = "
        SELECT 
            BIN_TO_UUID(p.id, 1) AS id, 
            p.title, 
            p.code, 
            p.status, 
            p.created_at,
            COUNT(d.id) AS day_count
        FROM projects p
        LEFT JOIN days d ON d.project_id = p.id
        GROUP BY p.id
        ORDER BY p.created_at DESC";

        return $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    }

    public function existsByCode(string $code): bool
    {
        $stmt = $this->pdo->prepare("SELECT 1 FROM projects WHERE code = ? LIMIT 1");
        $stmt->execute([$code]);
        return (bool)$stmt->fetchColumn();
    }

    public function create(string $title, string $code, string $status = 'active'): string
    {
        // returns UUID string (ordered)
        $this->pdo->beginTransaction();
        try {
            $uuid = $this->pdo->query("SELECT UUID()")->fetchColumn(); // UUID string
            $stmt = $this->pdo->prepare("
                INSERT INTO projects (id, title, code, status)
                VALUES (UUID_TO_BIN(?,1), ?, ?, ?)
            ");
            $stmt->execute([$uuid, $title, $code, $status]);
            $this->pdo->commit();
            return $uuid;
        } catch (PDOException $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function listForPerson(string $personUuid): array
    {
        $sql = "SELECT BIN_TO_UUID(p.id,1) AS id, p.title, p.code, p.status, p.created_at
            FROM projects p
            JOIN project_members pm ON pm.project_id = p.id
            WHERE pm.person_id = UUID_TO_BIN(:pid,1)
            ORDER BY p.created_at DESC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':pid' => $personUuid]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    public function listAllAvailableUsers(): array
    {
        $pdo = \App\Support\DB::pdo();
        $sql = "
        SELECT 
            BIN_TO_UUID(p.id, 1) as person_uuid, 
            p.first_name, 
            p.last_name, 
            a.email
        FROM persons p
        JOIN accounts_persons ap ON p.id = ap.person_id
        JOIN accounts a ON ap.account_id = a.id
        WHERE a.status = 'active'
        ORDER BY p.last_name ASC, p.first_name ASC
    ";
        return $pdo->query($sql)->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function personHasProject(string $personUuid, string $projectUuid): bool
    {
        $stmt = $this->pdo->prepare("
        SELECT 1
        FROM project_members
        WHERE person_id = UUID_TO_BIN(:pid,1)
          AND project_id = UUID_TO_BIN(:prj,1)
        LIMIT 1
    ");
        $stmt->execute([':pid' => $personUuid, ':prj' => $projectUuid]);
        return (bool)$stmt->fetchColumn();
    }

    public function findByUuid(string $uuid): ?array
    {
        $sql = "SELECT BIN_TO_UUID(id,1) AS id, title, code, status, created_at, updated_at
            FROM projects
            WHERE id = UUID_TO_BIN(:uuid, 1)
            LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':uuid' => $uuid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function updateByUuid(string $uuid, string $title, string $code, string $status): void
    {
        // Enforce unique code (excluding this project)
        $chk = $this->pdo->prepare("
        SELECT 1 FROM projects
        WHERE code = :code_chk AND id <> UUID_TO_BIN(:uuid_chk, 1)
        LIMIT 1
    ");
        $chk->execute([':code_chk' => $code, ':uuid_chk' => $uuid]);
        if ($chk->fetch()) {
            throw new \RuntimeException('Project code already in use.');
        }

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare("
            UPDATE projects
               SET title = :title_u,
                   code  = :code_u,
                   status= :status_u
             WHERE id = UUID_TO_BIN(:uuid_u, 1)
             LIMIT 1
        ");
            $stmt->execute([
                ':title_u'  => $title,
                ':code_u'   => $code,
                ':status_u' => $status,
                ':uuid_u'   => $uuid,
            ]);
            $this->pdo->commit();
        } catch (PDOException $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function listMembers(string $projectUuid): array
    {
        $sql = "
        SELECT
            BIN_TO_UUID(pm.person_id,1) AS person_uuid,
            p.first_name, p.last_name, p.display_name,
            pm.role, pm.is_project_admin, pm.can_download,
            pm.created_at,
            -- show a primary email if present
            (SELECT pc.value FROM person_contacts pc
               WHERE pc.person_id = pm.person_id AND pc.type='email' AND pc.is_primary=1
               ORDER BY pc.created_at ASC LIMIT 1) AS email
        FROM project_members pm
        JOIN persons p ON p.id = pm.person_id
        WHERE pm.project_id = UUID_TO_BIN(:proj_uuid,1)
        ORDER BY p.last_name, p.first_name
        ";
        $st = $this->pdo->prepare($sql);
        $st->execute([':proj_uuid' => $projectUuid]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public function addMemberByEmail(string $projectUuid, string $email, string $role, int $isAdmin, int $canDownload): void
    {
        // Find person by accounts.email OR person_contacts.email
        $find = $this->pdo->prepare("
            SELECT person_id
            FROM accounts_persons ap
            JOIN accounts a ON a.id = ap.account_id
            WHERE a.email = :em1
            UNION
            SELECT pc.person_id
            FROM person_contacts pc
            WHERE pc.type='email' AND pc.value = :em2
            LIMIT 1
        ");
        $find->execute([':em1' => $email, ':em2' => $email]);
        $row = $find->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new \RuntimeException('No user found with that email.');
        }
        $personIdBin = $row['person_id'];

        // Upsert-ish: check if already member
        $chk = $this->pdo->prepare("
            SELECT 1 FROM project_members
            WHERE project_id = UUID_TO_BIN(:p_uuid,1) AND person_id = :pid
            LIMIT 1
        ");
        $chk->execute([':p_uuid' => $projectUuid, ':pid' => $personIdBin]);
        if ($chk->fetch()) {
            throw new \RuntimeException('That person is already a member of this project.');
        }

        $ins = $this->pdo->prepare("
            INSERT INTO project_members (project_id, person_id, role, is_project_admin, can_download)
            VALUES (UUID_TO_BIN(:p_uuid_ins,1), :pid_ins, :role_ins, :is_admin_ins, :can_dl_ins)
        ");
        $ins->execute([
            ':p_uuid_ins'  => $projectUuid,
            ':pid_ins'     => $personIdBin,
            ':role_ins'    => $role,
            ':is_admin_ins' => $isAdmin,
            ':can_dl_ins'  => $canDownload,
        ]);
    }

    public function updateMember(string $projectUuid, string $personUuid, string $role, int $isAdmin, int $canDownload): void
    {
        $st = $this->pdo->prepare("
            UPDATE project_members
               SET role = :role_u,
                   is_project_admin = :is_admin_u,
                   can_download = :can_dl_u
             WHERE project_id = UUID_TO_BIN(:p_uuid_u,1)
               AND person_id  = UUID_TO_BIN(:person_uuid_u,1)
             LIMIT 1
        ");
        $st->execute([
            ':role_u'         => $role,
            ':is_admin_u'     => $isAdmin,
            ':can_dl_u'       => $canDownload,
            ':p_uuid_u'       => $projectUuid,
            ':person_uuid_u'  => $personUuid,
        ]);
    }

    public function removeMember(string $projectUuid, string $personUuid): void
    {
        // 1. Remove from Project Crew
        $st1 = $this->pdo->prepare("
            DELETE FROM project_members
            WHERE project_id = UUID_TO_BIN(:p_uuid, 1)
              AND person_id  = UUID_TO_BIN(:person_uuid, 1)
            LIMIT 1
        ");
        $st1->execute([':p_uuid' => $projectUuid, ':person_uuid' => $personUuid]);

        // 2. Cleanup: Remove from all Sensitive Groups in this project
        $st2 = $this->pdo->prepare("
            DELETE FROM sensitive_group_members 
            WHERE person_id = UUID_TO_BIN(:person_uuid, 1)
              AND group_id IN (SELECT id FROM sensitive_groups WHERE project_id = UUID_TO_BIN(:p_uuid, 1))
        ");
        $st2->execute([':p_uuid' => $projectUuid, ':person_uuid' => $personUuid]);
    }

    public function projectBrief(string $projectUuid): ?array
    {
        $st = $this->pdo->prepare("
            SELECT BIN_TO_UUID(id,1) AS id, title, code, status
            FROM projects
            WHERE id = UUID_TO_BIN(:uuid_b,1)
            LIMIT 1
        ");
        $st->execute([':uuid_b' => $projectUuid]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function isProjectMember(string $personUuid, string $projectUuid): bool
    {
        $stmt = $this->pdo->prepare("
        SELECT 1
        FROM project_members
        WHERE person_id = UUID_TO_BIN(:pid,1)
          AND project_id = UUID_TO_BIN(:prj,1)
        LIMIT 1
    ");
        $stmt->execute([':pid' => $personUuid, ':prj' => $projectUuid]);
        return (bool)$stmt->fetchColumn();
    }

    public function isProjectAdmin(string $personUuid, string $projectUuid): bool
    {
        $stmt = $this->pdo->prepare("
        SELECT 1
        FROM project_members
        WHERE person_id = UUID_TO_BIN(:pid,1)
          AND project_id = UUID_TO_BIN(:prj,1)
          AND is_project_admin = 1
        LIMIT 1
    ");
        $stmt->execute([':pid' => $personUuid, ':prj' => $projectUuid]);
        return (bool)$stmt->fetchColumn();
    }

    /**
     * List all security groups for a specific project
     */
    public function listSensitiveGroups(string $projectUuid): array
    {
        $sql = "SELECT 
                    BIN_TO_UUID(sg.id, 1) as group_uuid,
                    sg.name,
                    sg.description,
                    sg.created_at,
                    (SELECT COUNT(*) FROM sensitive_group_members WHERE group_id = sg.id) as member_count
                FROM sensitive_groups sg
                WHERE sg.project_id = UUID_TO_BIN(:puuid, 1)
                ORDER BY sg.name ASC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['puuid' => $projectUuid]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Create a new security group
     */
    public function createSensitiveGroup(string $projectUuid, string $name): bool
    {
        $sql = "INSERT INTO sensitive_groups (id, project_id, name) 
                VALUES (UUID_TO_BIN(UUID(), 1), UUID_TO_BIN(:puuid, 1), :name)";

        return $this->pdo->prepare($sql)->execute([
            'puuid' => $projectUuid,
            'name'  => $name
        ]);
    }

    /**
     * Fetch a single security group's details
     */
    public function getSensitiveGroup(string $groupUuid): ?array
    {
        $st = $this->pdo->prepare("
            SELECT BIN_TO_UUID(id,1) AS id, name, description 
            FROM sensitive_groups 
            WHERE id = UUID_TO_BIN(:uuid, 1) LIMIT 1
        ");
        $st->execute([':uuid' => $groupUuid]);
        return $st->fetch(\PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * List all persons currently in a specific group
     */
    public function listGroupMembers(string $groupUuid): array
    {
        $sql = "SELECT BIN_TO_UUID(p.id, 1) as person_uuid, p.display_name, p.first_name, p.last_name, 
                       pm.role,
                       (SELECT pc.value FROM person_contacts pc WHERE pc.person_id = p.id AND pc.type='email' AND pc.is_primary=1 LIMIT 1) as email
                FROM persons p
                JOIN sensitive_group_members sgm ON p.id = sgm.person_id
                -- Link to project_members to get the role within this project
                JOIN sensitive_groups sg ON sgm.group_id = sg.id
                JOIN project_members pm ON (p.id = pm.person_id AND sg.project_id = pm.project_id)
                WHERE sgm.group_id = UUID_TO_BIN(:group_uuid, 1)
                ORDER BY p.last_name ASC";
        $st = $this->pdo->prepare($sql);
        $st->execute(['group_uuid' => $groupUuid]);
        return $st->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function addGroupMember(string $groupUuid, string $personUuid): void
    {
        $st = $this->pdo->prepare("
            INSERT IGNORE INTO sensitive_group_members (group_id, person_id)
            VALUES (UUID_TO_BIN(:gid, 1), UUID_TO_BIN(:pid, 1))
        ");
        $st->execute(['gid' => $groupUuid, 'pid' => $personUuid]);
    }

    public function removeGroupMember(string $groupUuid, string $personUuid): void
    {
        $st = $this->pdo->prepare("
            DELETE FROM sensitive_group_members 
            WHERE group_id = UUID_TO_BIN(:gid, 1) AND person_id = UUID_TO_BIN(:pid, 1)
        ");
        $st->execute(['gid' => $groupUuid, 'pid' => $personUuid]);
    }

    public function deleteSensitiveGroup(string $groupUuid): void
    {
        $st = $this->pdo->prepare("DELETE FROM sensitive_groups WHERE id = UUID_TO_BIN(:uuid, 1) LIMIT 1");
        $st->execute([':uuid' => $groupUuid]);
    }

    public function addGroupMembersBatch(string $groupUuid, array $personUuids): void
    {
        $this->pdo->beginTransaction();
        try {
            $st = $this->pdo->prepare("INSERT IGNORE INTO sensitive_group_members (group_id, person_id) VALUES (UUID_TO_BIN(:gid, 1), UUID_TO_BIN(:pid, 1))");
            foreach ($personUuids as $uuid) {
                $st->execute(['gid' => $groupUuid, 'pid' => $uuid]);
            }
            $this->pdo->commit();
        } catch (\Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function removeGroupMembersBatch(string $groupUuid, array $personUuids): void
    {
        $this->pdo->beginTransaction();
        try {
            $st = $this->pdo->prepare("DELETE FROM sensitive_group_members WHERE group_id = UUID_TO_BIN(:gid, 1) AND person_id = UUID_TO_BIN(:pid, 1)");
            foreach ($personUuids as $uuid) {
                $st->execute(['gid' => $groupUuid, 'pid' => $uuid]);
            }
            $this->pdo->commit();
        } catch (\Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
}
