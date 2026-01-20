<?php

namespace App\Http\Controllers;

use App\Repositories\ProjectRepository;
use App\Support\DB;

final class ProjectContextController
{
    public function enter(string $projectUuid): void
    {
        if (session_status() === \PHP_SESSION_NONE) session_start();

        $acct = $_SESSION['account'] ?? [];
        $isSuper = !empty($acct['is_superuser']);
        $personUuid = $_SESSION['person_uuid'] ?? null;

        $pdo = \App\Support\DB::pdo();

        // --- FETCH PROJECT & ROLE IN ONE QUERY ---
        $stmt = $pdo->prepare("
            SELECT 
                BIN_TO_UUID(p.id,1) as uuid, 
                p.title, 
                p.code,
                pm.role, 
                pm.is_project_admin
            FROM projects p
            LEFT JOIN project_members pm ON pm.project_id = p.id 
                AND pm.person_id = UUID_TO_BIN(:person,1)
                AND pm.is_active = 1
            WHERE p.id = UUID_TO_BIN(:p,1)
            LIMIT 1
        ");
        $stmt->execute([':p' => $projectUuid, ':person' => $personUuid]);
        $project = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$project) {
            http_response_code(404);
            echo "Project not found";
            return;
        }

        // Authorization
        if (!$isSuper) {
            if (!$personUuid || empty($project['role'])) {
                http_response_code(403);
                echo "Forbidden: You are not a member of this project.";
                return;
            }
        }

        // Maintain access-count (optional, keeping your original logic)
        if (!$isSuper) {
            $repo = new \App\Repositories\ProjectRepository($pdo);
            $_SESSION['project_access_count'] = count($repo->listForPerson($personUuid));
        } else {
            $_SESSION['project_access_count'] = null;
        }

        // --- SAVE CONTEXT TO SESSION ---
        $_SESSION['current_project'] = [
            'uuid'  => $project['uuid'],
            'title' => $project['title'],
            'code'  => $project['code'],
        ];

        $_SESSION['current_project_role']  = strtolower($project['role'] ?? '');
        $_SESSION['current_project_flags'] = [
            'is_member'        => true,
            'is_project_admin' => $isSuper || (int)($project['is_project_admin'] ?? 0) === 1,
        ];

        header('Location: /admin/projects/' . rawurlencode($projectUuid) . '/days', true, 302);
        exit;
    }

    public function leave(): void
    {
        if (session_status() === \PHP_SESSION_NONE) session_start();
        unset($_SESSION['current_project']);
        header('Location: /dashboard', true, 302);
        exit;
    }
}
