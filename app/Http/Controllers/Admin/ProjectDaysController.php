<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Support\DB;
use App\Support\View;

class ProjectDaysController
{
    /**
     * Authorization helper: superuser OR project admin for the given project.
     */
    private function canManageProject(string $projectUuid): bool
    {
        $session = $_SESSION ?? [];
        $isSuper = (bool)(
            ($session['is_superuser'] ?? 0) ||
            (($session['account']['is_superuser'] ?? 0) == 1)
        );
        if ($isSuper) {
            return true;
        }

        $personUuid = $session['person_uuid'] ?? ($session['person_id'] ?? null);
        if (!$personUuid) {
            return false;
        }

        $pdo = \App\Support\DB::pdo();
        $q = $pdo->prepare("
            SELECT pm.is_project_admin
            FROM project_members pm
            WHERE pm.project_id = UUID_TO_BIN(:p,1)
              AND pm.person_id  = UUID_TO_BIN(:u,1)
            LIMIT 1
        ");
        $q->execute([':p' => $projectUuid, ':u' => $personUuid]);
        $isAdmin = (int)($q->fetchColumn() ?: 0);

        return $isAdmin === 1;
    }

    public function index(string $projectUuid)
    {
        // Ensure header context is set
        if (empty($_SESSION['current_project']['uuid']) || $_SESSION['current_project']['uuid'] !== $projectUuid) {
            $repo = $repo ?? new \App\Repositories\ProjectRepository(\App\Support\DB::pdo());
            $p = $repo->findByUuid($projectUuid);
            if ($p) {
                $_SESSION['current_project'] = [
                    'uuid'  => $p['id'] ?? $projectUuid,
                    'title' => $p['title'] ?? '',
                    'code'  => $p['code'] ?? '',
                ];
                // ✅ Add this block so admins don’t lose the Users link on deep-link/refresh
                $isSuper    = !empty($_SESSION['account']['is_superuser']);
                $personUuid = $_SESSION['person_uuid'] ?? null;
                $isProjAdmin = $isSuper || ($personUuid && $repo->isProjectAdmin($personUuid, $projectUuid));
                $_SESSION['current_project_flags'] = [
                    'is_member'        => true,
                    'is_project_admin' => (bool)$isProjAdmin,
                ];
            }
        }

        $pdo = DB::pdo();

        // --- Session snapshot ---
        $session = $_SESSION ?? [];

        // ? Robust superuser detection (supports both shapes)
        $isSuper = (bool)(
            ($session['is_superuser'] ?? 0) ||
            (($session['account']['is_superuser'] ?? 0) == 1)
        );

        // Prefer person_uuid cached during login, but accept legacy keys
        $personUuid  = $session['person_uuid'] ?? ($session['person_id'] ?? null);
        $accountUuid = $session['account']['id'] ?? ($session['account_uuid'] ?? null);
        // Track project-admin flag for view + filtering
        $isProjectAdmin = false;

        // If not superuser and missing person_uuid, resolve via accounts_persons
        if (!$isSuper && !$personUuid && $accountUuid) {
            $stmt = $pdo->prepare("
                SELECT BIN_TO_UUID(person_id,1) AS person_uuid
                FROM accounts_persons
                WHERE account_id = UUID_TO_BIN(:acc,1)
                LIMIT 1
            ");
            $stmt->execute([':acc' => $accountUuid]);
            if ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $personUuid = $row['person_uuid'] ?? null;
                if ($personUuid) {
                    $_SESSION['person_uuid'] = $personUuid; // cache for next requests
                }
            }
        }

        // --- AuthZ: allow superuser; otherwise require membership ---
        if (!$isSuper) {
            if (!$personUuid) {
                http_response_code(403);
                echo "Forbidden (no person bound to session)";
                return;
            }

            $stmt = $pdo->prepare("
                SELECT pm.is_project_admin
                FROM project_members pm
                WHERE pm.project_id = UUID_TO_BIN(:pid,1)
                  AND pm.person_id  = UUID_TO_BIN(:person_id,1)
                LIMIT 1
            ");
            $stmt->execute([
                ':pid'       => $projectUuid,
                ':person_id' => $personUuid,
            ]);

            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (!$row) {
                http_response_code(403);
                echo "Forbidden";
                return;
            }

            // Store project-admin flag for later (view + filtering)
            $isProjectAdmin = ((int)($row['is_project_admin'] ?? 0) === 1);
        } else {
            // Superusers are always treated as power users in the view
            $isProjectAdmin = true;
        }




        // --- Load project (title/code) ---
        $project = $pdo->prepare("
            SELECT BIN_TO_UUID(p.id,1) AS project_uuid, p.title, p.code, p.status, p.created_at
            FROM projects p
            WHERE p.id = UUID_TO_BIN(:pid,1)
            LIMIT 1
        ");
        $project->execute([':pid' => $projectUuid]);
        $projectRow = $project->fetch(\PDO::FETCH_ASSOC);
        if (!$projectRow) {
            http_response_code(404);
            echo "Project not found";
            return;
        }

        // --- Sorting --------------------------------------------------------
        $allowedSorts = ['title', 'shoot_date', 'unit', 'clip_count'];
        $sort = $_GET['sort'] ?? 'shoot_date';
        $dir  = strtoupper($_GET['dir'] ?? 'DESC');

        if (!in_array($sort, $allowedSorts, true)) {
            $sort = 'shoot_date';
        }
        if (!in_array($dir, ['ASC', 'DESC'], true)) {
            $dir = 'DESC';
        }

        // map column keys to real SQL columns
        $colMap = [
            'title'       => 'd.title',
            'shoot_date'  => 'd.shoot_date',
            'unit'        => 'd.unit',
            'clip_count'  => 'c.cnt'
        ];
        $orderBy = $colMap[$sort] . ' ' . $dir;

        // --- Days + clip counts (single query) ---
        // Regular users should only see published days
        $where = "d.project_id = UUID_TO_BIN(:pid,1)";
        if (!$isSuper && !$isProjectAdmin) {
            $where .= " AND d.published_at IS NOT NULL";
        }

        $sql = "
            SELECT
                BIN_TO_UUID(d.id,1) AS day_uuid,
                d.shoot_date,
                d.unit,
                d.title,
                d.published_at,
                COALESCE(c.cnt, 0) AS clip_count
            FROM days d
            LEFT JOIN (
                SELECT day_id, COUNT(*) AS cnt
                FROM clips
                GROUP BY day_id
            ) c ON c.day_id = d.id
            WHERE $where
            ORDER BY $orderBy, d.created_at DESC
        ";


        $daysStmt = $pdo->prepare($sql);
        $daysStmt->execute([':pid' => $projectUuid]);
        $days = $daysStmt->fetchAll(\PDO::FETCH_ASSOC);

        // --- Build route helpers for the view ---
        $routes = [
            'days_base'   => "/admin/projects/{$projectUuid}/days",
            'clips_base'  => "/admin/projects/{$projectUuid}/days",
            'player_base' => "/admin/projects/{$projectUuid}/days",
            'new_day'     => "/admin/projects/{$projectUuid}/days/new",
        ];

        // NEW: Detect device and choose layout
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $isMobile = preg_match('/(android|iphone|ipad|mobile)/i', $ua);

        $viewFile = $isMobile ? 'admin/days/index_mobile' : 'admin/days/index';
        $layoutFile = $isMobile ? 'layout/mobile' : 'layout/main';

        View::render($viewFile, [
            'layout'         => $layoutFile, // Pass dynamic layout
            'project'        => $projectRow,
            'days'           => $days,
            'routes'         => $routes,
            'sort'           => $sort,
            'dir'            => $dir,
            'isSuperuser'    => $isSuper ? 1 : 0,
            'isProjectAdmin' => $isProjectAdmin ? 1 : 0,
        ]);

        return; // Explicit return
    }

    public function publish(string $projectUuid, string $dayUuid): void
    {
        // Auth
        $account    = $_SESSION['account'] ?? null;
        $personUuid = $_SESSION['person_uuid'] ?? null;

        if (!$account || !$personUuid) {
            http_response_code(403);
            echo "Forbidden";
            return;
        }

        $pdo = DB::pdo();

        // Verify project admin or superuser
        $isSuperuser = (int)($account['is_superuser'] ?? 0);

        $stmt = $pdo->prepare("
        SELECT is_project_admin 
        FROM project_members 
        WHERE project_id = UUID_TO_BIN(:p,1)
          AND person_id  = UUID_TO_BIN(:u,1)
        LIMIT 1
    ");
        $stmt->execute([
            ':p' => $projectUuid,
            ':u' => $personUuid
        ]);

        $isProjectAdmin = (int)($stmt->fetchColumn() ?: 0);

        if (!$isSuperuser && !$isProjectAdmin) {
            http_response_code(403);
            echo "Forbidden";
            return;
        }

        // Update days.published_at
        $sendEmail = ($_POST['send_email'] ?? '0') === '1' ? 1 : 0;

        $upd = $pdo->prepare("
        UPDATE days
        SET published_at = NOW()
        WHERE id = UUID_TO_BIN(:d,1)
          AND project_id = UUID_TO_BIN(:p,1)
        LIMIT 1
    ");

        $upd->execute([
            ':d' => $dayUuid,
            ':p' => $projectUuid,
        ]);

        // (Later we will use sendEmail)
        $_SESSION['publish_feedback'] = [
            'published'  => true,
            'send_email' => $sendEmail,
        ];

        header("Location: /admin/projects/$projectUuid/days/$dayUuid/clips");
        exit;
    }

    public function unpublish(string $projectUuid, string $dayUuid): void
    {
        // Auth
        $account    = $_SESSION['account'] ?? null;
        $personUuid = $_SESSION['person_uuid'] ?? null;

        if (!$account || !$personUuid) {
            http_response_code(403);
            echo "Forbidden";
            return;
        }

        $pdo = DB::pdo();

        // Verify project admin or superuser
        $isSuperuser = (int)($account['is_superuser'] ?? 0);

        $stmt = $pdo->prepare("
        SELECT is_project_admin
        FROM project_members
        WHERE project_id = UUID_TO_BIN(:p,1)
          AND person_id  = UUID_TO_BIN(:u,1)
        LIMIT 1
    ");
        $stmt->execute([
            ':p' => $projectUuid,
            ':u' => $personUuid,
        ]);

        $isProjectAdmin = (int)($stmt->fetchColumn() ?: 0);

        if (!$isSuperuser && !$isProjectAdmin) {
            http_response_code(403);
            echo "Forbidden";
            return;
        }

        // Unpublish: set published_at back to NULL
        $upd = $pdo->prepare("
        UPDATE days
        SET published_at = NULL
        WHERE id = UUID_TO_BIN(:d,1)
          AND project_id = UUID_TO_BIN(:p,1)
        LIMIT 1
    ");
        $upd->execute([
            ':d' => $dayUuid,
            ':p' => $projectUuid,
        ]);

        $_SESSION['publish_feedback'] = [
            'published'  => false,
            'send_email' => 0,
        ];

        header("Location: /admin/projects/$projectUuid/days/$dayUuid/clips");
        exit;
    }


    public function createForm(string $projectUuid): void
    {
        // remove $params usage:
        // $projectUuid = $params['projectUuid'] ?? null;  // <- delete

        if (!$projectUuid) {
            http_response_code(400);
            echo "Bad request";
            return;
        }

        $pdo = \App\Support\DB::pdo();
        $stmt = $pdo->prepare("
            SELECT BIN_TO_UUID(id,1) AS project_uuid, title, code, status
            FROM projects
            WHERE id = UUID_TO_BIN(:p,1)
        ");
        $stmt->execute([':p' => $projectUuid]);
        $project = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [
            'project_uuid' => $projectUuid,
            'title' => 'Project',
            'code' => '',
            'status' => ''
        ];

        \App\Support\View::render('admin/days/create', [
            'project'      => $project,
            'project_uuid' => $projectUuid,
            'errors'       => $_SESSION['flash_errors'] ?? [],
            'old'          => $_SESSION['flash_old'] ?? [],
        ]);

        unset($_SESSION['flash_errors'], $_SESSION['flash_old']);
    }

    public function store(string $projectUuid): void
    {
        if (!$projectUuid) {
            http_response_code(400);
            echo "Bad request";
            return;
        }

        $shoot_date = trim($_POST['shoot_date'] ?? '');
        $unit       = trim($_POST['unit'] ?? '');
        $notes      = trim($_POST['notes'] ?? '');
        $title = trim($_POST['title'] ?? '');

        $errors = [];
        if ($shoot_date === '') $errors['shoot_date'] = 'Shoot date is required.';

        if ($errors) {
            $_SESSION['flash_errors'] = $errors;
            $_SESSION['flash_old'] = ['shoot_date' => $shoot_date, 'unit' => $unit, 'notes' => $notes];
            header("Location: /admin/projects/{$projectUuid}/days/new");
            exit;
        }

        $pdo = \App\Support\DB::pdo();
        $pdo->beginTransaction();
        try {
            $chk = $pdo->prepare("SELECT 1 FROM projects WHERE id = UUID_TO_BIN(:p,1) LIMIT 1");
            $chk->execute([':p' => $projectUuid]);
            if (!$chk->fetchColumn()) {
                throw new \RuntimeException('Project not found');
            }

            $stmt = $pdo->prepare("
            INSERT INTO days (id, project_id, shoot_date, unit, title, notes)
            VALUES (UUID_TO_BIN(UUID(),1), UUID_TO_BIN(:p,1), :d, NULLIF(:u,''), NULLIF(:t,''), NULLIF(:n,''))
            ");
            $stmt->execute([
                ':p' => $projectUuid,
                ':d' => $shoot_date,
                ':u' => $unit,
                ':t' => $title,    // NEW
                ':n' => $notes,
            ]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            $_SESSION['flash_errors'] = ['_general' => 'Could not create day: ' . $e->getMessage()];
            $_SESSION['flash_old']    = ['shoot_date' => $shoot_date, 'unit' => $unit, 'notes' => $notes];
            header("Location: /admin/projects/{$projectUuid}/days/new");
            exit;
        }

        header("Location: /admin/projects/{$projectUuid}/days");
        exit;
    }

    public function editForm(string $projectUuid, string $dayUuid): void
    {
        $pdo = \App\Support\DB::pdo();

        // AuthZ: superuser OR project admin
        if (!$this->canManageProject($projectUuid)) {
            http_response_code(403);
            echo "Forbidden";
            return;
        }

        // Project
        $p = $pdo->prepare("
        SELECT BIN_TO_UUID(p.id,1) AS project_uuid, p.title, p.code, p.status
        FROM projects p
        WHERE p.id = UUID_TO_BIN(:p,1)
        LIMIT 1
    ");
        $p->execute([':p' => $projectUuid]);
        $project = $p->fetch(\PDO::FETCH_ASSOC);
        if (!$project) {
            http_response_code(404);
            echo "Project not found";
            return;
        }

        // Day
        $d = $pdo->prepare("
            SELECT BIN_TO_UUID(d.id,1) AS day_uuid,
                d.title, d.shoot_date, d.unit, d.notes
            FROM days d
            WHERE d.id = UUID_TO_BIN(:d,1)
            AND d.project_id = UUID_TO_BIN(:p,1)
            LIMIT 1
        ");
        $d->execute([':d' => $dayUuid, ':p' => $projectUuid]);
        $day = $d->fetch(\PDO::FETCH_ASSOC);
        if (!$day) {
            http_response_code(404);
            echo "Day not found";
            return;
        }

        \App\Support\View::render('admin/days/edit', [
            'project'      => $project,
            'project_uuid' => $projectUuid,
            'day'          => $day,
            'errors'       => $_SESSION['form_errors'] ?? [],
            'old'          => $_SESSION['form_old'] ?? [],
        ]);

        unset($_SESSION['form_errors'], $_SESSION['form_old']);
    }

    public function edit(string $projectUuid, string $dayUuid): void
    {
        $pdo = \App\Support\DB::pdo();

        if (!$this->canManageProject($projectUuid)) {
            http_response_code(403);
            echo "Forbidden";
            return;
        }

        // gather + minimal validate
        $title      = trim((string)($_POST['title'] ?? ''));
        $shoot_date = trim((string)($_POST['shoot_date'] ?? ''));
        $unit       = trim((string)($_POST['unit'] ?? ''));
        $notes      = trim((string)($_POST['notes'] ?? ''));

        $errors = [];
        if ($shoot_date === '') {
            $errors['shoot_date'] = 'Shoot date is required.';
        }

        if ($errors) {
            $_SESSION['form_errors'] = $errors;
            $_SESSION['form_old']    = [
                'title'      => $title,
                'shoot_date' => $shoot_date,
                'unit'       => $unit,
                'notes'      => $notes,
            ];
            header("Location: /admin/projects/$projectUuid/days/$dayUuid/edit");
            return;
        }

        // update
        $q = $pdo->prepare("
            UPDATE days
            SET title = :title,
                shoot_date = :shoot_date,
                unit = :unit,
                notes = :notes
            WHERE id = UUID_TO_BIN(:d,1)
            AND project_id = UUID_TO_BIN(:p,1)
            LIMIT 1
        ");
        $q->execute([
            ':title'      => ($title !== '' ? $title : null),
            ':shoot_date' => $shoot_date,
            ':unit'       => ($unit !== '' ? $unit : null),
            ':notes'      => ($notes !== '' ? $notes : null),
            ':d'          => $dayUuid,
            ':p'          => $projectUuid,
        ]);

        // back to list
        header("Location: /admin/projects/$projectUuid/days");
    }

    public function deleteConfirm(string $projectUuid, string $dayUuid): void
    {
        $pdo = \App\Support\DB::pdo();

        // AuthZ: superuser or project admin
        $isSuper = (int)($_SESSION['account']['is_superuser'] ?? 0) === 1;
        $person  = $_SESSION['person_uuid'] ?? null;
        $isAdmin = 0;
        if (!$isSuper && $person) {
            $q = $pdo->prepare("
            SELECT pm.is_project_admin
            FROM project_members pm
            WHERE pm.project_id = UUID_TO_BIN(:p,1)
              AND pm.person_id  = UUID_TO_BIN(:u,1)
            LIMIT 1
        ");
            $q->execute([':p' => $projectUuid, ':u' => $person]);
            $isAdmin = (int)($q->fetchColumn() ?: 0);
        }
        if (!$isSuper && !$isAdmin) {
            http_response_code(403);
            echo "Forbidden";
            return;
        }

        // Fetch project + day + clip count
        $proj = $pdo->prepare("SELECT title, code FROM projects WHERE id = UUID_TO_BIN(:p,1)");
        $proj->execute([':p' => $projectUuid]);
        $project = $proj->fetch(\PDO::FETCH_ASSOC) ?: ['title' => 'Project', 'code' => ''];

        $dayS = $pdo->prepare("
        SELECT BIN_TO_UUID(d.id,1) AS day_uuid, d.shoot_date, d.title
        FROM days d
        WHERE d.id = UUID_TO_BIN(:d,1) AND d.project_id = UUID_TO_BIN(:p,1) LIMIT 1
    ");
        $dayS->execute([':d' => $dayUuid, ':p' => $projectUuid]);
        $day = $dayS->fetch(\PDO::FETCH_ASSOC);
        if (!$day) {
            http_response_code(404);
            echo "Day not found";
            return;
        }

        $cntS = $pdo->prepare("SELECT COUNT(*) FROM clips WHERE project_id = UUID_TO_BIN(:p,1) AND day_id = UUID_TO_BIN(:d,1)");
        $cntS->execute([':p' => $projectUuid, ':d' => $dayUuid]);
        $clipCount = (int)$cntS->fetchColumn();

        // Issue one-time token for this specific day delete
        if (!isset($_SESSION['csrf_tokens'])) $_SESSION['csrf_tokens'] = [];
        if (!isset($_SESSION['csrf_tokens']['delete_day'])) $_SESSION['csrf_tokens']['delete_day'] = [];

        $token = bin2hex(random_bytes(32));
        $_SESSION['csrf_tokens']['delete_day'][$dayUuid] = $token;

        \App\Support\View::render('admin/days/delete', [
            'project_uuid' => $projectUuid,
            'project'      => $project,
            'day'          => $day,
            'clip_count'   => $clipCount,
            'csrf'         => $token,
        ]);
    }

    public function destroy(string $projectUuid, string $dayUuid): void
    {
        // --- CSRF (your existing scheme) ---
        $token = $_POST['csrf_token'] ?? '';
        $expected = $_SESSION['csrf_tokens']['delete_day'][$dayUuid] ?? '';
        unset($_SESSION['csrf_tokens']['delete_day'][$dayUuid]); // consume to prevent replay

        if (!$expected || !hash_equals($expected, $token)) {
            http_response_code(419);
            echo "CSRF token mismatch";
            return;
        }

        $pdo = \App\Support\DB::pdo();

        // --- AuthZ (your existing scheme) ---
        $isSuper = (int)($_SESSION['account']['is_superuser'] ?? 0) === 1;
        $person  = $_SESSION['person_uuid'] ?? null;
        $isAdmin = 0;
        if (!$isSuper && $person) {
            $q = $pdo->prepare("
            SELECT pm.is_project_admin
            FROM project_members pm
            WHERE pm.project_id = UUID_TO_BIN(:p,1)
              AND pm.person_id  = UUID_TO_BIN(:u,1)
            LIMIT 1
        ");
            $q->execute([':p' => $projectUuid, ':u' => $person]);
            $isAdmin = (int)($q->fetchColumn() ?: 0);
        }
        if (!$isSuper && !$isAdmin) {
            http_response_code(403);
            echo "Forbidden";
            return;
        }

        // --- Sanity: confirm day belongs to project ---
        $chk = $pdo->prepare("
        SELECT d.id AS day_bin, p.id AS project_bin
        FROM days d
        JOIN projects p ON p.id = d.project_id
        WHERE d.id = UUID_TO_BIN(:d,1)
          AND p.id = UUID_TO_BIN(:p,1)
        LIMIT 1
    ");
        $chk->execute([':d' => $dayUuid, ':p' => $projectUuid]);
        $rel = $chk->fetch(\PDO::FETCH_ASSOC);
        if (!$rel) {
            http_response_code(404);
            echo "Day not found";
            return;
        }

        // --- FILE DELETE (do this BEFORE DB deletes so we can read paths) ---
        // New bases only (no legacy)
        $fsBase     = rtrim(getenv('ZEN_STOR_DIR') ?: '/var/www/html/data/zendailies/uploads', '/');
        $publicBase = rtrim(getenv('ZEN_STOR_PUBLIC_BASE') ?: '/data/zendailies/uploads', '/');

        // Collect all public paths for this day
        $stmt = $pdo->prepare("
        SELECT ca.storage_path
        FROM clip_assets ca
        JOIN clips c ON c.id = ca.clip_id
        WHERE c.project_id = UUID_TO_BIN(:p,1)
          AND c.day_id     = UUID_TO_BIN(:d,1)
    ");
        $stmt->execute([':p' => $projectUuid, ':d' => $dayUuid]);
        $paths = $stmt->fetchAll(\PDO::FETCH_COLUMN) ?: [];

        $errors  = [];
        foreach ($paths as $publicPath) {
            if (!is_string($publicPath) || $publicPath === '') continue;

            // Must be under our public base
            if (!str_starts_with($publicPath, $publicBase . '/')) {
                $errors[] = "Skip (outside base): $publicPath";
                continue;
            }

            // Map public -> filesystem
            $abs = $fsBase . substr($publicPath, strlen($publicBase));

            // Normalize path using realpath of parent
            $parent     = dirname($abs);
            $parentReal = realpath($parent);
            if ($parentReal === false) {
                // parent missing already; nothing to delete
                $errors[] = "Parent missing: $parent";
                continue;
            }
            $absReal = $parentReal . DIRECTORY_SEPARATOR . basename($abs);

            // Safety: ensure we're still under fsBase
            if (!str_starts_with($absReal, $fsBase . '/')) {
                $errors[] = "Unsafe path: $absReal";
                continue;
            }

            if (is_file($absReal)) {
                if (!@unlink($absReal)) {
                    $errors[] = "Failed to delete: $absReal";
                }
            } else {
                // Not a file / already gone; log lightly
                $errors[] = "Not a file or missing: $absReal";
            }
        }

        // Best-effort tidy: remove empty day and project dirs in the new layout
        $dayDir  = $fsBase . '/' . $projectUuid . '/' . $dayUuid;
        $projDir = $fsBase . '/' . $projectUuid;
        @rmdir($dayDir);
        @rmdir($projDir);

        // --- DB DELETE (your original logic) ---
        $pdo->beginTransaction();
        try {
            // Collect clip ids for the day
            $ids = $pdo->prepare("
            SELECT id
            FROM clips
            WHERE project_id = UUID_TO_BIN(:p,1)
              AND day_id     = UUID_TO_BIN(:d,1)
        ");
            $ids->execute([':p' => $projectUuid, ':d' => $dayUuid]);
            $clipIds = $ids->fetchAll(\PDO::FETCH_COLUMN);

            if ($clipIds) {
                $in = implode(',', array_fill(0, count($clipIds), '?'));

                // dependent rows (if FKs don't cascade)
                $pdo->prepare("DELETE FROM clip_assets WHERE clip_id IN ($in)")->execute($clipIds);
                $pdo->prepare("DELETE FROM clip_sensitive_acl WHERE clip_id IN ($in)")->execute($clipIds);
                // add other clip-scoped tables if needed (comments, markers, etc.)
                $pdo->prepare("DELETE FROM clips WHERE id IN ($in)")->execute($clipIds);
            }

            // delete the day
            $delDay = $pdo->prepare("
            DELETE FROM days
            WHERE id = UUID_TO_BIN(:d,1)
              AND project_id = UUID_TO_BIN(:p,1)
            LIMIT 1
        ");
            $delDay->execute([':d' => $dayUuid, ':p' => $projectUuid]);

            // (Optional) write an audit row if you have events_audit
            // $pdo->prepare("INSERT INTO events_audit (...) VALUES (...)")->execute([...]);

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            http_response_code(500);
            echo "Failed to delete day: " . $e->getMessage();
            return;
        }

        // Redirect back to project days list
        header("Location: /admin/projects/{$projectUuid}/days?deleted=1");
        exit;
    }
}
