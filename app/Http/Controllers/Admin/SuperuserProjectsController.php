<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Repositories\ProjectRepository;
use App\Support\View;
use App\Support\Csrf;

final class SuperuserProjectsController
{
    public function __construct(private ProjectRepository $projects) {}

    public function index(): void
    {
        \require_superuser();
        $rows = $this->projects->listAll();
        View::render('admin/projects/index', [
            'projects' => $rows,
            'title'    => 'Projects',
        ]);
    }

    public function createForm(): void
    {
        \require_superuser();
        View::render('admin/projects/create', [
            'title' => 'Create Project',
            'csrf'  => Csrf::token(),
        ]);
    }

    public function edit(string $id): void
    {
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
        Csrf::validateOrAbort($_POST['csrf'] ?? null);

        $title  = trim((string)($_POST['title'] ?? ''));
        $code   = trim((string)($_POST['code']  ?? ''));
        $status = (string)($_POST['status'] ?? 'active');

        $errors = [];
        if ($title === '')  $errors[] = 'Title is required.';
        if ($code === '')   $errors[] = 'Code is required.';
        if (!in_array($status, ['active', 'archived'], true)) $errors[] = 'Invalid status.';

        $project = $this->projects->findByUuid($id);
        if (!$project) {
            http_response_code(404);
            echo "Project not found.";
            return;
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
            $this->projects->updateByUuid($id, $title, $code, $status);
            header('Location: /admin/projects');
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
        \require_superuser();
        Csrf::validateOrAbort($_POST['csrf'] ?? null);

        $title  = trim($_POST['title'] ?? '');
        $code   = strtoupper(trim($_POST['code'] ?? ''));
        $status = ($_POST['status'] ?? 'active') === 'archived' ? 'archived' : 'active';

        $errors = [];
        if ($title === '') $errors['title'] = 'Title is required';
        if ($code  === '') $errors['code']  = 'Code is required';
        if (!preg_match('/^[A-Z0-9_-]{2,32}$/', $code)) {
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
