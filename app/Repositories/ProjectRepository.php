<?php

namespace App\Repositories;

use PDO;
use PDOException;

class ProjectRepository
{
    public function __construct(private PDO $pdo) {}

    public function listAll(): array
    {
        $sql = "SELECT BIN_TO_UUID(id,1) AS id, title, code, status, created_at
                FROM projects
                ORDER BY created_at DESC";
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
        $st = $this->pdo->prepare("
            DELETE FROM project_members
            WHERE project_id = UUID_TO_BIN(:p_uuid_d,1)
              AND person_id  = UUID_TO_BIN(:person_uuid_d,1)
            LIMIT 1
        ");
        $st->execute([
            ':p_uuid_d'      => $projectUuid,
            ':person_uuid_d' => $personUuid,
        ]);
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
}
