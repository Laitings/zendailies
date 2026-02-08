<?php

namespace App\Http\Controllers\Admin;

use App\Support\DB;
use App\Support\View;
use PDO;

final class WorkerController
{
    // app/Http/Controllers/Admin/WorkerController.php

    // [SEARCH AND REPLACE at Line 13]
    public function index(): void
    {
        if (session_status() === PHP_SESSION_NONE) session_start();

        $account = $_SESSION['account'] ?? [];
        $personUuid = $_SESSION['person_uuid'] ?? null;
        $isSuperuser = !empty($account['is_superuser']);
        $userRole = $account['user_role'] ?? 'regular';
        $isGlobalAdmin = ($userRole === 'admin' || $isSuperuser);

        $pdo = DB::pdo();

        // Determine Project Admin status for the current session context
        $isProjectAdmin = 0;
        if (!$isGlobalAdmin && $personUuid) {
            $admStmt = $pdo->prepare("
            SELECT COUNT(*) 
            FROM project_members 
            WHERE person_id = UUID_TO_BIN(:person, 1) 
              AND is_project_admin = 1 
              AND is_active = 1
        ");
            $admStmt->execute([':person' => $personUuid]);
            $isProjectAdmin = (int)$admStmt->fetchColumn();
        }

        // Permission Guard: Must be some form of Admin
        if (!$isGlobalAdmin && !$isProjectAdmin) {
            http_response_code(403);
            exit("Forbidden");
        }

        $hideDone = (int)($_GET['hide_done'] ?? 0);
        $params = [];

        // --- ACL Filtering ---
        // If not a global admin, only show jobs for projects they manage
        $aclJoin = "";
        $whereClauses = [];

        if ($hideDone) {
            $whereClauses[] = "j.state != 'done'";
        }

        if (!$isGlobalAdmin) {
            $aclJoin = "INNER JOIN project_members pm ON pm.project_id = c.project_id";
            $whereClauses[] = "pm.person_id = UUID_TO_BIN(:person_uuid, 1) AND pm.is_active = 1";
            $params[':person_uuid'] = $personUuid;
        }

        $whereSql = !empty($whereClauses) ? "WHERE " . implode(" AND ", $whereClauses) : "";

        $sql = "
        SELECT j.*, j.task_type, bin_to_uuid(j.clip_id,1) as clip_uuid, c.file_name, p.title as project_title
        FROM jobs_queue j
        LEFT JOIN clips c ON c.id = j.clip_id
        LEFT JOIN projects p ON p.id = c.project_id
        $aclJoin
        $whereSql
        ORDER BY j.created_at DESC 
        LIMIT 100
    ";

        $jobs = $pdo->prepare($sql);
        $jobs->execute($params);
        $rows = $jobs->fetchAll(PDO::FETCH_ASSOC);

        $isPaused = file_exists(getenv('ZEN_STOR_DIR') . '/.worker_pause');

        View::render('admin/jobs/index', [
            'jobs'          => $rows,
            'isPaused'      => $isPaused,
            'hideDone'      => $hideDone,
            'isGlobalAdmin' => $isGlobalAdmin // Pass to view to hide global controls
        ]);
    }

    public function requeue(string $id): void
    {
        $pdo = DB::pdo();
        $st = $pdo->prepare("
        UPDATE jobs_queue 
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
    // [SEARCH AND REPLACE at Line 63]
    public function statusSummary(): void
    {
        header('Content-Type: application/json');
        if (session_status() === PHP_SESSION_NONE) session_start();

        $account = $_SESSION['account'] ?? [];
        $personUuid = $_SESSION['person_uuid'] ?? null;
        $isSuperuser = !empty($account['is_superuser']);
        $isGlobalAdmin = (($account['user_role'] ?? '') === 'admin' || $isSuperuser);

        $pdo = DB::pdo();
        $params = [];
        $aclSql = "";

        if (!$isGlobalAdmin && $personUuid) {
            $aclSql = "
            INNER JOIN clips c ON c.id = jq.clip_id
            INNER JOIN project_members pm ON pm.project_id = c.project_id
            WHERE pm.person_id = UUID_TO_BIN(:person, 1) AND pm.is_active = 1 AND
        ";
            $params[':person'] = $personUuid;
        } else {
            $aclSql = "WHERE ";
        }

        $sql = "
        SELECT state, COUNT(*) as total 
        FROM jobs_queue jq
        $aclSql jq.state IN ('queued', 'running')
        GROUP BY state
    ";

        $st = $pdo->prepare($sql);
        $st->execute($params);
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
