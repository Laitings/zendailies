<?php

namespace App\Http\Controllers;

use App\Repositories\ProjectRepository;
use App\Support\DB;
use App\Support\View;

final class DashboardController
{
    public function index(): void
    {
        if (session_status() === \PHP_SESSION_NONE) session_start();

        $acct = $_SESSION['account'] ?? [];
        $isSuper = !empty($acct['is_superuser']);
        $personUuid = $_SESSION['person_uuid'] ?? null;

        $repo = new ProjectRepository(DB::pdo());

        $projects = $isSuper
            ? $repo->listAll()
            : ($personUuid ? $repo->listForPerson($personUuid) : []);

        // If a non-superuser has exactly one project, auto-enter it.
        if (!$isSuper && count($projects) === 1) {
            $p = $projects[0];
            $uuid  = $p['id'] ?? $p['project_uuid'] ?? null;
            if ($uuid) {
                header('Location: /projects/' . rawurlencode($uuid) . '/enter');
                exit;
            }
        }

        $_SESSION['project_access_count'] = count($projects);

        View::render('dashboard/index', [
            'projects'   => $projects,
            'isSuper'    => $isSuper,
            'personUuid' => $personUuid,
        ]);
    }
}
