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

        $repo = new ProjectRepository(DB::pdo());
        $project = $repo->findByUuid($projectUuid);
        if (!$project) {
            http_response_code(404);
            echo "Project not found";
            return;
        }

        // Authorization: superuser OR member of this project
        if (!$isSuper) {
            if (!$personUuid || !$repo->personHasProject($personUuid, $projectUuid)) {
                http_response_code(403);
                echo "Forbidden";
                return;
            }
        }

        // Maintain access-count in session for the topbar logic
        if (!$isSuper) {
            $repo = $repo ?? new \App\Repositories\ProjectRepository(\App\Support\DB::pdo());
            $_SESSION['project_access_count'] = count($repo->listForPerson($personUuid));
        } else {
            // superusers can access all projects; leave as null or a high number
            $_SESSION['project_access_count'] = null;
        }


        // Compute and cache role flags for this project (used by topbar + guards)
        $isMember = $isSuper || ($personUuid && $repo->isProjectMember($personUuid, $projectUuid));
        $isProjAdmin = $isSuper || ($personUuid && $repo->isProjectAdmin($personUuid, $projectUuid));

        $_SESSION['current_project'] = [
            'uuid'  => $project['id'] ?? $projectUuid,
            'title' => $project['title'] ?? '',
            'code'  => $project['code'] ?? '',
        ];

        $_SESSION['current_project_flags'] = [
            'is_member'      => (bool)$isMember,
            'is_project_admin' => (bool)$isProjAdmin,
        ];

        // Default landing: Days
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
