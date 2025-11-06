<?php

namespace App\Http\Controllers\Admin;

use App\Repositories\ProjectRepository;
use App\Support\Csrf;
use App\Support\DB;
use App\Support\View;
use PDO;

class ProjectMembersController
{
    private ProjectRepository $projects;

    public function __construct(?ProjectRepository $projects = null)
    {
        // Allow zero-arg construction from Router
        $this->projects = $projects ?? new ProjectRepository(DB::pdo());
    }

    /**
     * Resolve session context once in a consistent way.
     * Returns [bool $isSuper, ?string $personUuid]
     */
    private function resolveSession(): array
    {
        if (session_status() === \PHP_SESSION_NONE) session_start();

        $sess        = $_SESSION ?? [];
        $pdo         = DB::pdo();
        $isSuper     = (bool)(($sess['is_superuser'] ?? 0) || (($sess['account']['is_superuser'] ?? 0) == 1));
        $personUuid  = $sess['person_uuid'] ?? ($sess['person_id'] ?? null);
        $accountUuid = $sess['account']['id'] ?? ($sess['account_uuid'] ?? null);

        // If not superuser and no person_uuid in session, try accounts_persons
        if (!$isSuper && !$personUuid && $accountUuid) {
            $stmt = $pdo->prepare("
                SELECT BIN_TO_UUID(person_id,1) AS person_uuid
                FROM accounts_persons
                WHERE account_id = UUID_TO_BIN(:acc,1)
                LIMIT 1
            ");
            $stmt->execute([':acc' => $accountUuid]);
            if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $personUuid = $row['person_uuid'] ?? null;
                if ($personUuid) $_SESSION['person_uuid'] = $personUuid; // cache
            }
        }

        return [$isSuper, $personUuid];
    }

    /**
     * Single guard used by all actions: superuser OR project-admin
     */
    private function assertProjectAdminOrSuperuser(string $projectUuid): void
    {
        [$isSuper, $personUuid] = $this->resolveSession();

        if ($isSuper) {
            $_SESSION['current_project_flags']['is_project_admin'] = true; // for topbar
            return;
        }

        if (!$personUuid) {
            http_response_code(403);
            echo 'Forbidden';
            exit;
        }

        if (!$this->projects->isProjectAdmin($personUuid, $projectUuid)) {
            http_response_code(403);
            echo 'Forbidden';
            exit;
        }

        $_SESSION['current_project_flags']['is_project_admin'] = true; // for topbar
    }

    /**
     * Keep topbar context coherent on deep links
     */
    private function ensureProjectContext(string $projectUuid): void
    {
        if (empty($_SESSION['current_project']['uuid']) || $_SESSION['current_project']['uuid'] !== $projectUuid) {
            $p = $this->projects->findByUuid($projectUuid);
            if ($p) {
                $_SESSION['current_project'] = [
                    'uuid'  => $p['id'] ?? $projectUuid,
                    'title' => $p['title'] ?? '',
                    'code'  => $p['code'] ?? '',
                ];
            }
        }
    }

    // ---------------- Actions ----------------

    public function members(string $projectUuid): void
    {
        $this->assertProjectAdminOrSuperuser($projectUuid);
        $this->ensureProjectContext($projectUuid);

        $proj = $this->projects->projectBrief($projectUuid);
        if (!$proj) {
            http_response_code(404);
            echo "Project not found.";
            return;
        }

        $members = $this->projects->listMembers($projectUuid);
        View::render('admin/projects/members', [
            'project' => $proj,
            'members' => $members,
            'csrf'    => Csrf::token(),
            'errors'  => [],
            'old'     => [],
        ]);
    }

    public function addMember(string $projectUuid): void
    {
        $this->assertProjectAdminOrSuperuser($projectUuid);
        $this->ensureProjectContext($projectUuid);
        Csrf::validateOrAbort($_POST['csrf'] ?? null);

        $email       = strtolower(trim((string)($_POST['email'] ?? '')));
        $role        = (string)($_POST['role'] ?? 'reviewer');
        $isAdmin     = !empty($_POST['is_project_admin']) ? 1 : 0;
        $canDownload = !empty($_POST['can_download']) ? 1 : 0;

        $errors = [];
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'A valid email is required.';
        if (!in_array($role, ['producer', 'director', 'post_supervisor', 'editor', 'assistant_editor', 'script_supervisor', 'dop', 'dit', 'reviewer'], true)) {
            $errors[] = 'Invalid role.';
        }

        $proj = $this->projects->projectBrief($projectUuid);
        if (!$proj) {
            http_response_code(404);
            echo "Project not found.";
            return;
        }

        if ($errors) {
            $members = $this->projects->listMembers($projectUuid);
            View::render('admin/projects/members', [
                'project' => $proj,
                'members' => $members,
                'csrf'    => Csrf::token(),
                'errors'  => $errors,
                'old'     => compact('email', 'role'),
            ]);
            return;
        }

        try {
            $this->projects->addMemberByEmail($projectUuid, $email, $role, $isAdmin, $canDownload);
            header('Location: /admin/projects/' . $projectUuid . '/members');
            exit;
        } catch (\Throwable $e) {
            $members = $this->projects->listMembers($projectUuid);
            View::render('admin/projects/members', [
                'project' => $proj,
                'members' => $members,
                'csrf'    => Csrf::token(),
                'errors'  => [$e->getMessage()],
                'old'     => compact('email', 'role'),
            ]);
        }
    }

    public function updateMember(string $projectUuid, string $personUuid): void
    {
        $this->assertProjectAdminOrSuperuser($projectUuid);
        $this->ensureProjectContext($projectUuid);
        Csrf::validateOrAbort($_POST['csrf'] ?? null);

        $role        = (string)($_POST['role'] ?? 'reviewer');
        $isAdmin     = !empty($_POST['is_project_admin']) ? 1 : 0;
        $canDownload = !empty($_POST['can_download']) ? 1 : 0;

        if (!in_array($role, ['producer', 'director', 'post_supervisor', 'editor', 'assistant_editor', 'script_supervisor', 'dop', 'dit', 'reviewer'], true)) {
            http_response_code(400);
            echo "Invalid role.";
            return;
        }

        try {
            $this->projects->updateMember($projectUuid, $personUuid, $role, $isAdmin, $canDownload);
            header('Location: /admin/projects/' . $projectUuid . '/members');
            exit;
        } catch (\Throwable $e) {
            http_response_code(500);
            echo htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
        }
    }

    public function removeMember(string $projectUuid, string $personUuid): void
    {
        $this->assertProjectAdminOrSuperuser($projectUuid);
        $this->ensureProjectContext($projectUuid);
        Csrf::validateOrAbort($_POST['csrf'] ?? null);

        try {
            $this->projects->removeMember($projectUuid, $personUuid);
            header('Location: /admin/projects/' . $projectUuid . '/members');
            exit;
        } catch (\Throwable $e) {
            http_response_code(500);
            echo htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
        }
    }
}
