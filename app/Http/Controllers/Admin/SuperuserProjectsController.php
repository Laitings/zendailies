<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Repositories\ProjectRepository;
use App\Support\View;
use App\Support\Csrf;

final class SuperuserProjectsController
{
    public function __construct(private ProjectRepository $projects) {}

    private function assertGlobalAccess(): void
    {
        if (session_status() === PHP_SESSION_NONE) session_start();

        $sess = $_SESSION;
        $acct = $sess['account'] ?? null;

        // Check both common locations for the Superuser flag
        $isSuper = !empty($sess['is_superuser']) || ($acct && !empty($acct['is_superuser']));
        // Check for the Global Admin role
        $isAdmin = ($acct && ($acct['user_role'] ?? '') === 'admin');

        if (!$isSuper && !$isAdmin) {
            http_response_code(403);
            echo "Forbidden: Global Admin or Superuser access required.";
            exit;
        }
    }

    public function index(): void
    {
        $this->assertGlobalAccess(); // Replaced require_superuser()
        $rows = $this->projects->listAll();
        View::render('admin/projects/index', [
            'projects' => $rows,
            'title'    => 'Projects',
        ]);
    }

    public function createForm(): void
    {
        $this->assertGlobalAccess(); // Replaced require_superuser()
        View::render('admin/projects/create', [
            'title' => 'Create Project',
            'csrf'  => Csrf::token(),
        ]);
    }

    public function edit(string $id): void
    {
        $this->assertGlobalAccess(); // Ensure security on edit links
        $project = $this->projects->findByUuid($id);
        if (!$project) {
            http_response_code(404);
            echo "Project not found.";
            return;
        }

        View::render('admin/projects/edit', [
            'title'   => 'Edit Project',
            'project' => $project,
            'csrf'    => Csrf::token(),
            'errors'  => [],
            'old'     => [
                'title'  => $project['title'],
                'code'   => $project['code'],
                'status' => $project['status'],
            ],
        ]);
    }

    public function update(string $id): void
    {
        // 1. Security check: Ensure only Global Admins or Superusers can update projects
        $this->assertGlobalAccess();

        // 2. CSRF Validation
        Csrf::validateOrAbort($_POST['csrf'] ?? null);

        // 3. Gather and normalize inputs
        $title  = trim((string)($_POST['title'] ?? ''));
        $code   = strtoupper(trim((string)($_POST['code']  ?? '')));
        $status = (string)($_POST['status'] ?? 'active');

        $errors = [];
        if ($title === '')  $errors[] = 'Title is required.';
        if ($code === '')   $errors[] = 'Code is required.';

        // Validate code format
        if ($code !== '' && !preg_match('/^[A-Z0-9_-]{2,32}$/', $code)) {
            $errors[] = 'Code must use A–Z, 0–9, _ or -, and be 2–32 characters.';
        }

        if (!in_array($status, ['active', 'archived'], true)) {
            $errors[] = 'Invalid status.';
        }

        // 4. Ensure the project exists
        $project = $this->projects->findByUuid($id);
        if (!$project) {
            http_response_code(404);
            echo "Project not found.";
            return;
        }

        // 5. Check for code uniqueness if the code was changed
        if (!$errors && $code !== $project['code']) {
            if ($this->projects->existsByCode($code)) {
                $errors[] = 'This project code is already in use by another show.';
            }
        }

        if ($errors) {
            View::render('admin/projects/edit', [
                'title'   => 'Edit Project',
                'project' => $project,
                'csrf'    => Csrf::token(),
                'errors'  => $errors,
                'old'     => compact('title', 'code', 'status'),
            ]);
            return;
        }

        try {
            // 6. Persist changes to the database
            $this->projects->updateByUuid($id, $title, $code, $status);
            header('Location: /admin/projects?updated=1');
            exit;
        } catch (\Throwable $e) {
            View::render('admin/projects/edit', [
                'title'   => 'Edit Project',
                'project' => $project,
                'csrf'    => Csrf::token(),
                'errors'  => [$e->getMessage()],
                'old'     => compact('title', 'code', 'status'),
            ]);
        }
    }

    public function store(): void
    {
        $this->assertGlobalAccess(); // Replaced require_superuser()
        Csrf::validateOrAbort($_POST['csrf'] ?? null);

        $title  = trim($_POST['title'] ?? '');
        $code   = strtoupper(trim($_POST['code'] ?? ''));
        $status = ($_POST['status'] ?? 'active') === 'archived' ? 'archived' : 'active';

        $errors = [];
        if ($title === '') $errors['title'] = 'Title is required';
        if ($code  === '') $errors['code']  = 'Code is required';

        if (!$errors && !preg_match('/^[A-Z0-9_-]{2,32}$/', $code)) {
            $errors['code'] = 'Use A–Z, 0–9, _ or -, 2–32 chars';
        }

        if (!$errors && $this->projects->existsByCode($code)) {
            $errors['code'] = 'Code already exists';
        }

        if ($errors) {
            View::render('admin/projects/create', [
                'title'  => 'Create Project',
                'errors' => $errors,
                'old'    => ['title' => $title, 'code' => $code, 'status' => $status],
                'csrf'   => Csrf::token(),
            ]);
            return;
        }

        $this->projects->create($title, $code, $status);
        header('Location: /admin/projects');
        exit;
    }
}
