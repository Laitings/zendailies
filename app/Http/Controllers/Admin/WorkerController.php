<?php

namespace App\Http\Controllers\Admin;

use App\Support\DB;
use App\Support\View;
use PDO;

final class WorkerController
{
    // app/Http/Controllers/Admin/WorkerController.php

    public function index(): void
    {
        if (empty($_SESSION['account']['is_superuser'])) {
            http_response_code(403);
            exit("Forbidden");
        }

        $hideDone = (int)($_GET['hide_done'] ?? 0);
        $where = $hideDone ? "WHERE j.state != 'done'" : "";

        $pdo = DB::pdo();
        $jobs = $pdo->query("
        SELECT j.*, bin_to_uuid(j.clip_id,1) as clip_uuid, c.file_name, p.title as project_title
        FROM encode_jobs j
        LEFT JOIN clips c ON c.id = j.clip_id
        LEFT JOIN projects p ON p.id = c.project_id
        $where
        ORDER BY j.created_at DESC 
        LIMIT 100
    ")->fetchAll(PDO::FETCH_ASSOC);

        $isPaused = file_exists(getenv('ZEN_STOR_DIR') . '/.worker_pause');

        View::render('admin/jobs/index', [
            'jobs'     => $jobs,
            'isPaused' => $isPaused,
            'hideDone' => $hideDone
        ]);
    }

    public function requeue(string $id): void
    {
        $pdo = DB::pdo();
        $st = $pdo->prepare("
        UPDATE encode_jobs 
        SET state = 'queued', 
            progress_pct = 0, 
            attempts = 0, 
            worker_id = NULL,
            started_at = NULL,
            finished_at = NULL,
            updated_at = NOW() 
        WHERE id = :id 
        LIMIT 1
    ");
        $st->execute([':id' => $id]);

        header("Location: /admin/jobs");
        exit;
    }

    /**
     * JSON Summary for the persistent Top Bar
     */
    public function statusSummary(): void
    {
        header('Content-Type: application/json');
        $pdo = DB::pdo();
        $st = $pdo->prepare("
            SELECT state, COUNT(*) as total 
            FROM encode_jobs 
            WHERE state IN ('queued', 'running')
            GROUP BY state
        ");
        $st->execute();
        $counts = $st->fetchAll(PDO::FETCH_KEY_PAIR);

        echo json_encode([
            'queued'  => (int)($counts['queued'] ?? 0),
            'running' => (int)($counts['running'] ?? 0)
        ]);
    }

    /**
     * Toggle the worker state (Pause/Resume)
     */
    public function toggle(): void
    {
        $pauseFile = getenv('ZEN_STOR_DIR') . '/.worker_pause';
        if (file_exists($pauseFile)) {
            unlink($pauseFile);
        } else {
            file_put_contents($pauseFile, 'PAUSED');
        }
        header("Location: /admin/jobs");
        exit;
    }
}
