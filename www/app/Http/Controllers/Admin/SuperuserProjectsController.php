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
        $this->assertGlobalAccess();
        Csrf::validateOrAbort($_POST['csrf'] ?? null);

        $title  = trim($_POST['title'] ?? '');
        $code   = strtoupper(trim($_POST['code'] ?? ''));
        $status = (string)($_POST['status'] ?? 'active');
        // FIX: Capture the aspect ratio from the form
        $aspectRatio = (float)($_POST['default_aspect_ratio'] ?? 1.78);

        $errors = [];
        if ($title === '') $errors['title'] = 'Title is required';
        if ($code  === '') $errors['code']  = 'Code is required';

        if ($errors) {
            $project = $this->projects->findByUuid($id);
            View::render('admin/projects/edit', [
                'title'   => 'Edit Project',
                'project' => $project,
                'csrf'    => Csrf::token(),
                'errors'  => $errors,
                // FIX: Pass it back so it doesn't reset to 1.78 on error
                'old'     => ['title' => $title, 'code' => $code, 'status' => $status, 'default_aspect_ratio' => $aspectRatio],
            ]);
            return;
        }

        try {
            // FIX: Pass the 5th argument ($aspectRatio) to the repository
            $this->projects->updateByUuid($id, $title, $code, $status, $aspectRatio);
            header('Location: /admin/projects');
            exit;
        } catch (\Exception $e) {
            $project = $this->projects->findByUuid($id);
            View::render('admin/projects/edit', [
                'title'   => 'Edit Project',
                'project' => $project,
                'csrf'    => Csrf::token(),
                'errors'  => [$e->getMessage()],
                // FIX: Ensure compact includes the aspect ratio
                'old'     => compact('title', 'code', 'status', 'aspectRatio'),
            ]);
        }
    }

    public function store(): void
    {
        $this->assertGlobalAccess();
        Csrf::validateOrAbort($_POST['csrf'] ?? null);

        $title  = trim($_POST['title'] ?? '');
        $code   = strtoupper(trim($_POST['code'] ?? ''));
        $status = ($_POST['status'] ?? 'active') === 'archived' ? 'archived' : 'active';
        $aspectRatio = (float)($_POST['default_aspect_ratio'] ?? 1.78);

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
                // FIX: Pass the ratio back to "old" so it persists during error re-renders
                'old'    => ['title' => $title, 'code' => $code, 'status' => $status, 'default_aspect_ratio' => $aspectRatio],
                'csrf'   => Csrf::token(),
            ]);
            return;
        }

        $this->projects->create($title, $code, $status, $aspectRatio);
        header('Location: /admin/projects');
        exit;
    }

    /**
     * Deactivate project - prevents non-admin access
     */
    public function deactivate(string $id): void
    {
        $this->assertGlobalAccess();

        try {
            $this->projects->updateStatus($id, 'disabled');
            header('Location: /admin/projects');
            exit;
        } catch (\Exception $e) {
            http_response_code(500);
            echo "Error deactivating project: " . $e->getMessage();
        }
    }

    /**
     * Reactivate a disabled project
     */
    public function reactivate(string $id): void
    {
        $this->assertGlobalAccess();

        try {
            $this->projects->updateStatus($id, 'active');
            header('Location: /admin/projects');
            exit;
        } catch (\Exception $e) {
            http_response_code(500);
            echo "Error reactivating project: " . $e->getMessage();
        }
    }

    /**
     * Delete project with selective file deletion
     */
    public function delete(string $id): void
    {
        // Superuser only for permanent deletion
        if (session_status() === PHP_SESSION_NONE) session_start();
        $sess = $_SESSION;
        $acct = $sess['account'] ?? null;
        $isSuper = !empty($sess['is_superuser']) || ($acct && !empty($acct['is_superuser']));

        if (!$isSuper) {
            http_response_code(403);
            echo "Forbidden: Superuser access required for deletion.";
            exit;
        }

        Csrf::validateOrAbort($_POST['_csrf'] ?? null);

        $project = $this->projects->findByUuid($id);
        if (!$project) {
            http_response_code(404);
            echo "Project not found.";
            return;
        }

        // Verify confirmation code
        $confirmCode = strtoupper(trim($_POST['confirm_code'] ?? ''));
        if ($confirmCode !== $project['code']) {
            http_response_code(400);
            echo "Project code confirmation failed.";
            return;
        }

        // Get deletion options - UPDATED for source/proxies split
        $deleteSource    = !empty($_POST['delete_source']);
        $deleteProxies   = !empty($_POST['delete_proxies']);
        $deletePosters   = !empty($_POST['delete_posters']);
        $deleteWaveforms = !empty($_POST['delete_waveforms']);
        $deleteMetadata  = !empty($_POST['delete_metadata']);
        $deleteComments  = !empty($_POST['delete_comments']);
        $deleteDatabase  = !empty($_POST['delete_database']);

        try {
            // Delete files from storage
            if ($deleteSource || $deleteProxies || $deletePosters || $deleteWaveforms) {
                $this->deleteProjectFiles($id, $deleteSource, $deleteProxies, $deletePosters, $deleteWaveforms);
            }

            // Delete database records
            if ($deleteDatabase) {
                // When deleting database, we delete everything
                $this->projects->permanentDelete($id, true, true);
            } else {
                // Soft delete - mark with deleted_at and store archival options
                $archivalOptions = [
                    'source' => $deleteSource,
                    'proxies' => $deleteProxies,
                    'posters' => $deletePosters,
                    'waveforms' => $deleteWaveforms,
                    'metadata' => $deleteMetadata,
                    'comments' => $deleteComments,
                    'deleted_by' => $acct['account_email'] ?? 'unknown',
                    'deleted_at_timestamp' => date('Y-m-d H:i:s')
                ];
                $this->projects->softDelete($id, $archivalOptions);
            }

            header('Location: /admin/projects');
            exit;
        } catch (\Exception $e) {
            http_response_code(500);
            echo "Error deleting project: " . $e->getMessage();
        }
    }

    /**
     * Delete project files from storage based on new folder structure
     */
    private function deleteProjectFiles(
        string $projectUuid,
        bool $deleteSource,
        bool $deleteProxies,
        bool $deletePosters,
        bool $deleteWaveforms
    ): void {
        $storageDir = getenv('ZEN_STOR_DIR');
        if (!$storageDir) {
            throw new \Exception('Storage directory not configured');
        }

        $deletedCount = 0;
        $errors = [];

        // Delete source files (originals)
        if ($deleteSource) {
            $sourceDir = $storageDir . '/source/' . $projectUuid;
            $deletedCount += $this->deleteFilesInDirectory($sourceDir, '/\.(mp4|mov|avi|mkv)$/i', $errors);
        }

        // Delete proxies (web versions)
        if ($deleteProxies) {
            $proxiesDir = $storageDir . '/proxies/' . $projectUuid;
            $deletedCount += $this->deleteFilesInDirectory($proxiesDir, '/\.(mp4|mov|avi|mkv)$/i', $errors);
        }

        // Delete posters
        if ($deletePosters) {
            $postersDir = $storageDir . '/posters/' . $projectUuid;
            $deletedCount += $this->deleteFilesInDirectory($postersDir, '/\.(jpg|jpeg|png|webp)$/i', $errors);
        }

        // Delete waveforms
        if ($deleteWaveforms) {
            $waveformsDir = $storageDir . '/waveforms/' . $projectUuid;
            $deletedCount += $this->deleteFilesInDirectory($waveformsDir, '/\.(png|json)$/i', $errors);
        }

        // Clean up empty project directories
        $this->cleanupEmptyProjectDirs($storageDir, $projectUuid);

        // Log any errors (optional - you can remove this if you don't have logging)
        if (!empty($errors)) {
            error_log("Project deletion warnings for $projectUuid: " . implode(", ", $errors));
        }
    }

    /**
     * Delete files matching a pattern in a directory
     */
    private function deleteFilesInDirectory(string $dir, string $pattern, array &$errors): int
    {
        if (!is_dir($dir)) {
            return 0; // Directory doesn't exist, nothing to delete
        }

        $deletedCount = 0;

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($iterator as $file) {
                if (!$file->isFile()) {
                    continue;
                }

                $filename = $file->getFilename();

                if (preg_match($pattern, $filename)) {
                    try {
                        if (unlink($file->getPathname())) {
                            $deletedCount++;
                        } else {
                            $errors[] = "Failed to delete: " . $file->getPathname();
                        }
                    } catch (\Exception $e) {
                        $errors[] = "Error deleting {$filename}: " . $e->getMessage();
                    }
                }
            }
        } catch (\Exception $e) {
            $errors[] = "Error scanning directory $dir: " . $e->getMessage();
        }

        return $deletedCount;
    }

    /**
     * Clean up empty directories for a project across all storage types
     */
    private function cleanupEmptyProjectDirs(string $storageDir, string $projectUuid): void
    {
        $subdirs = ['source', 'proxies', 'posters', 'waveforms'];

        foreach ($subdirs as $subdir) {
            $projectDir = $storageDir . '/' . $subdir . '/' . $projectUuid;
            if (is_dir($projectDir)) {
                $this->removeEmptyDirs($projectDir);
            }
        }
    }

    /**
     * Remove empty directories recursively
     */
    private function removeEmptyDirs(string $dir): void
    {
        if (!is_dir($dir)) return;

        $items = scandir($dir);
        if (count($items) <= 2) { // Only . and ..
            rmdir($dir);
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeEmptyDirs($path);
            }
        }

        // Check again after cleaning subdirs
        $items = scandir($dir);
        if (count($items) <= 2) {
            rmdir($dir);
        }
    }
}
