<?php

namespace App\Http\Controllers\Admin;

use App\Support\DB;
use App\Support\View;

final class ProjectClipsController
{
    public function index(string $projectUuid, string $dayUuid): void
    {
        if (!$projectUuid || !$dayUuid) {
            http_response_code(400);
            echo "Bad request";
            return;
        }

        // Session context
        $account      = $_SESSION['account'] ?? null;
        $personUuid   = $_SESSION['person_uuid'] ?? null;
        $isSuperuser  = (int)($account['is_superuser'] ?? 0);

        // Basic project-admin check for visibility relaxation
        $isProjectAdmin = 0;

        // Filters (GET)
        $q = [
            'scene'   => trim($_GET['scene']   ?? ''),
            'slate'   => trim($_GET['slate']   ?? ''),
            'take'    => trim($_GET['take']    ?? ''),
            'camera'  => trim($_GET['camera']  ?? ''),
            'rating'  => trim($_GET['rating']  ?? ''),
            'select'  => trim($_GET['select']  ?? ''), // '', '1', '0'
            'text'    => trim($_GET['text']    ?? ''), // file_name/reel matches
            'sort'    => trim($_GET['sort']    ?? 'scene, slate, take_int, camera'),
            'dir'     => strtoupper(trim($_GET['dir'] ?? 'ASC')),
            'page'    => max(1, (int)($_GET['page'] ?? 1)),
            'per'     => max(10, min(200, (int)($_GET['per'] ?? 50))),
        ];
        if (!in_array($q['dir'], ['ASC', 'DESC'], true)) {
            $q['dir'] = 'ASC';
        }

        // Pagination
        $offset = ($q['page'] - 1) * $q['per'];

        $pdo = DB::pdo();

        // === Guard: regular users cannot access unpublished days ===
        $dayStmt = $pdo->prepare("
            SELECT 
                d.id,
                d.published_at
            FROM days d
            WHERE d.project_id = UUID_TO_BIN(:p, 1)
              AND d.id        = UUID_TO_BIN(:d, 1)
            LIMIT 1
        ");
        $dayStmt->execute([
            ':p' => $projectUuid,
            ':d' => $dayUuid,
        ]);
        $dayRow = $dayStmt->fetch(\PDO::FETCH_ASSOC);

        if (!$dayRow) {
            http_response_code(404);
            echo 'Day not found';
            return;
        }

        if (!$isSuperuser && !$isProjectAdmin && empty($dayRow['published_at'])) {
            http_response_code(403);
            echo 'This day is not published.';
            return;
        }


        // Project header info
        $projStmt = $pdo->prepare("
        SELECT BIN_TO_UUID(p.id,1) AS project_uuid, p.title, p.code, p.status
        FROM projects p
        WHERE p.id = UUID_TO_BIN(:p,1)
        LIMIT 1
    ");
        $projStmt->execute([':p' => $projectUuid]);
        $project = $projStmt->fetch(\PDO::FETCH_ASSOC) ?: [
            'project_uuid' => $projectUuid,
            'title'        => 'Project',
            'code'         => '',
            'status'       => ''
        ];

        // Resolve project-admin for this project
        $isProjectAdmin = 0;
        if ($personUuid) {
            $stmtAdmin = $pdo->prepare("
            SELECT pm.is_project_admin
            FROM project_members pm
            JOIN projects p ON p.id = pm.project_id
            WHERE p.id = UUID_TO_BIN(:project_uuid, 1)
              AND pm.person_id = UUID_TO_BIN(:person_uuid, 1)
            LIMIT 1
        ");
            $stmtAdmin->execute([
                ':project_uuid' => $projectUuid,
                ':person_uuid'  => $personUuid,
            ]);
            $isProjectAdmin = (int)($stmtAdmin->fetchColumn() ?: 0);
        }

        // Build WHERE & params
        $where = [
            'c.project_id = UUID_TO_BIN(:project_uuid, 1)',
            'c.day_id     = UUID_TO_BIN(:day_uuid, 1)'
        ];
        $paramsSql = [
            ':project_uuid' => $projectUuid,
            ':day_uuid'     => $dayUuid,
        ];

        if ($q['scene'] !== '') {
            $where[] = 'c.scene = :scene';
            $paramsSql[':scene'] = $q['scene'];
        }
        if ($q['slate'] !== '') {
            $where[] = 'c.slate = :slate';
            $paramsSql[':slate'] = $q['slate'];
        }
        if ($q['take']  !== '') {
            // numeric vs string
            if (ctype_digit($q['take'])) {
                $where[] = 'c.take_int = :take_int';
                $paramsSql[':take_int'] = (int)$q['take'];
            } else {
                $where[] = 'c.take = :take';
                $paramsSql[':take'] = $q['take'];
            }
        }
        if ($q['camera'] !== '') {
            $where[] = 'c.camera = :camera';
            $paramsSql[':camera'] = $q['camera'];
        }
        if ($q['rating'] !== '') {
            $where[] = 'c.rating = :rating';
            $paramsSql[':rating'] = (int)$q['rating'];
        }
        if ($q['select'] !== '') {
            $where[] = 'c.is_select = :is_select';
            $paramsSql[':is_select'] = (int)$q['select'];
        }
        if ($q['text'] !== '') {
            $where[] = '(c.file_name LIKE :t OR c.reel LIKE :t)';
            $paramsSql[':t'] = '%' . $q['text'] . '%';
        }

        // Sensitive-ACL visibility
        // Show if: no ACL rows (csa.group_id IS NULL)
        //      OR user is superuser/project-admin
        //      OR user belongs to any mapped group
        $visibilitySql = '';
        if (!$isSuperuser && !$isProjectAdmin) {
            $visibilitySql = " AND (csa.group_id IS NULL OR sgm.person_id = UUID_TO_BIN(:viewer_person_uuid, 1))";
            $paramsSql[':viewer_person_uuid'] = $personUuid ?? '00000000-0000-0000-0000-000000000000';
        }

        // Sort whitelist
        $sortWhitelist = [
            'created_at' => 'c.created_at',
            'scene'      => 'c.scene',
            'slate'      => 'c.slate',
            'take'       => 'c.take_int',
            'camera'     => 'c.camera',
            'reel'       => 'c.reel',
            'file'       => 'c.file_name',
            'rating'     => 'c.rating',
            'select'     => 'c.is_select',
            'tc_start'   => 'c.tc_start',
            'tc_end'     => 'c.tc_end',
            'duration'   => 'c.duration_ms',
        ];

        // Parse requested sort (e.g. "scene, slate, take_int, camera")
        $orderParts = [];
        foreach (explode(',', $q['sort']) as $part) {
            $k = trim($part);
            switch ($k) {
                case 'scene':
                case 'slate':
                case 'camera':
                case 'reel':
                case 'file':
                case 'rating':
                case 'select':
                case 'tc_start':
                case 'tc_end':
                case 'duration':
                case 'created_at':
                    $orderParts[] = $sortWhitelist[$k] . ' ' . $q['dir'];
                    break;
                case 'take_int':
                case 'take':
                    $orderParts[] = 'c.take_int ' . $q['dir'];
                    break;
            }
        }
        if (!$orderParts) {
            $orderParts[] = 'c.scene ASC, c.slate ASC, c.take_int ASC, c.camera ASC';
        }

        // Day info
        $dayStmt = $pdo->prepare("
        SELECT 
            BIN_TO_UUID(d.id,1) AS day_uuid,
            d.title,
            d.shoot_date,
            d.notes,
            d.published_at
        FROM days d
        WHERE d.id = UUID_TO_BIN(:d,1)
          AND d.project_id = UUID_TO_BIN(:p,1)
        LIMIT 1
    ");
        $dayStmt->execute([':d' => $dayUuid, ':p' => $projectUuid]);
        $dayInfo = $dayStmt->fetch(\PDO::FETCH_ASSOC) ?: null;

        // Count (with same ACL restrictions)
        $sqlCount = "
        SELECT COUNT(*)
        FROM clips c
        JOIN days d ON d.id = c.day_id
        LEFT JOIN clip_sensitive_acl csa ON csa.clip_id = c.id
        LEFT JOIN sensitive_group_members sgm ON sgm.group_id = csa.group_id
        WHERE " . implode(' AND ', $where) . $visibilitySql . "
    ";
        $stmtCount = $pdo->prepare($sqlCount);
        $stmtCount->execute($paramsSql);
        $total = (int)$stmtCount->fetchColumn();

        // Data (page subset)
        $sql = "
                SELECT
                    BIN_TO_UUID(c.id,1) AS clip_uuid,
                    c.scene,
                    c.slate,
                    c.take,
                    c.take_int,
                    c.camera,
                    c.reel,
                    c.file_name,
                    c.tc_start,
                    c.tc_end,
                    c.duration_ms,
                    c.rating,
                    c.is_select,
                    c.created_at,

                    -- Poster / proxy_web paths (as before)
                    MAX(CASE WHEN a.asset_type='poster' THEN a.storage_path END) AS poster_path,
                    MAX(CASE WHEN a.asset_type='proxy_web'  THEN a.storage_path END) AS proxy_path,

                    -- How many proxies currently exist for this clip
                    (
                        SELECT COUNT(*)
                        FROM clip_assets a2
                        WHERE a2.clip_id = c.id
                        AND a2.asset_type = 'proxy_web'
                    ) AS proxy_count,

                    -- Latest encode job state for this clip (if any)
                    (
                        SELECT ej.state
                        FROM encode_jobs ej
                        WHERE ej.clip_id = c.id
                        ORDER BY ej.id DESC
                        LIMIT 1
                    ) AS job_state,

                    -- Latest encode job progress (0-100)
                    (
                        SELECT ej.progress_pct
                        FROM encode_jobs ej
                        WHERE ej.clip_id = c.id
                        ORDER BY ej.id DESC
                        LIMIT 1
                    ) AS job_progress

                FROM clips c
                JOIN days d ON d.id = c.day_id
                LEFT JOIN clip_assets a ON a.clip_id = c.id
                LEFT JOIN clip_sensitive_acl csa ON csa.clip_id = c.id
                LEFT JOIN sensitive_group_members sgm ON sgm.group_id = csa.group_id
                WHERE " . implode(' AND ', $where) . $visibilitySql . "
                GROUP BY c.id
                ORDER BY " . implode(', ', $orderParts) . "
                LIMIT :limit OFFSET :offset
            ";

        $stmt = $pdo->prepare($sql);

        // bind normal params
        foreach ($paramsSql as $k => $v) {
            if (in_array($k, [':limit', ':offset'], true)) continue;
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit',  $q['per'], \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset,   \PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Camera list (for filter dropdown) (note: this is page cameras, not global)
        $cams = [];
        foreach ($rows as $r) {
            if ($r['camera'] !== null && $r['camera'] !== '') {
                $cams[$r['camera']] = true;
            }
        }
        ksort($cams);
        $cameraOptions = array_keys($cams);

        $dayLabel = '';
        if ($dayInfo) {
            $dayLabel = $dayInfo['title'] !== null && $dayInfo['title'] !== ''
                ? $dayInfo['title']
                : ($dayInfo['shoot_date'] ?? '');
        }

        // --- NEW: compute total runtime for the whole day (all clips in this day),
        // honoring the same ACL visibility restrictions.
        //
        // NOTE: We intentionally do NOT apply pagination here.
        //
        $sqlSum = "
        SELECT COALESCE(SUM(c.duration_ms), 0) AS total_ms
        FROM clips c
        JOIN days d ON d.id = c.day_id
        LEFT JOIN clip_sensitive_acl csa ON csa.clip_id = c.id
        LEFT JOIN sensitive_group_members sgm ON sgm.group_id = csa.group_id
        WHERE " . implode(' AND ', $where) . $visibilitySql . "
    ";
        $stmtSum = $pdo->prepare($sqlSum);
        $stmtSum->execute($paramsSql);
        $dayTotalDurationMs = (int)($stmtSum->fetchColumn() ?? 0);
        // --- /NEW

        // CSRF token for converter-style actions (poster, metadata)
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $converterToken = bin2hex(random_bytes(32));
        $_SESSION['csrf_tokens']['converter'][$dayUuid] = $converterToken;

        $quickToken = \App\Support\Csrf::token();
        $_SESSION['csrf_tokens']['clip_quick'][$dayUuid] = $quickToken;

        View::render('admin/clips/index', [
            'project'                 => $project,
            'project_uuid'            => $projectUuid,
            'day_uuid'                => $dayUuid,
            'day_info'                => $dayInfo,
            'day_label'               => $dayLabel,
            'filters'                 => $q,
            'rows'                    => $rows,
            'total'                   => $total,
            'page'                    => $q['page'],
            'per'                     => $q['per'],
            'cameraOptions'           => $cameraOptions,
            'isSuperuser'             => $isSuperuser,
            'isProjectAdmin'          => $isProjectAdmin,
            'converter_csrf'          => $converterToken,
            'quick_csrf'              => $quickToken,


            // NEW: pass full-day runtime in ms
            'day_total_duration_ms'   => $dayTotalDurationMs,
        ]);
    }

    /**
     * Show the edit form for a single clip (prefilled).
     */
    public function editForm(string $projectUuid, string $dayUuid, string $clipUuid): void
    {
        if (session_status() === PHP_SESSION_NONE) session_start();

        $account     = $_SESSION['account'] ?? null;
        $personUuid  = $_SESSION['person_uuid'] ?? null;
        $isSuperuser = (int)($account['is_superuser'] ?? 0);

        $pdo = DB::pdo();

        // Permission: allow Superuser OR project admin on this project
        $isProjectAdmin = 0;
        if ($personUuid) {
            $stmtAdmin = $pdo->prepare("
                SELECT pm.is_project_admin
                FROM project_members pm
                JOIN projects p ON p.id = pm.project_id
                WHERE p.id = UUID_TO_BIN(:p,1)
                  AND pm.person_id = UUID_TO_BIN(:person,1)
                LIMIT 1
            ");
            $stmtAdmin->execute([':p' => $projectUuid, ':person' => $personUuid]);
            $isProjectAdmin = (int)($stmtAdmin->fetchColumn() ?: 0);
        }
        if (!$isSuperuser && !$isProjectAdmin) {
            http_response_code(403);
            echo "Forbidden";
            return;
        }

        // Project header
        $projStmt = $pdo->prepare("
            SELECT BIN_TO_UUID(p.id,1) AS project_uuid, p.title, p.code, p.status
            FROM projects p
            WHERE p.id = UUID_TO_BIN(:p,1)
            LIMIT 1
        ");
        $projStmt->execute([':p' => $projectUuid]);
        $project = $projStmt->fetch(\PDO::FETCH_ASSOC) ?: [
            'project_uuid' => $projectUuid,
            'title'        => 'Project',
            'code'         => '',
            'status'       => ''
        ];

        // Day info
        $dayStmt = $pdo->prepare("
            SELECT d.shoot_date, d.title
            FROM days d
            WHERE d.id = UUID_TO_BIN(:d,1)
              AND d.project_id = UUID_TO_BIN(:p,1)
            LIMIT 1
        ");
        $dayStmt->execute([':d' => $dayUuid, ':p' => $projectUuid]);
        $dayInfo = $dayStmt->fetch(\PDO::FETCH_ASSOC) ?: null;
        $dayLabel = $dayInfo
            ? (($dayInfo['title'] ?? '') !== '' ? $dayInfo['title'] : ($dayInfo['shoot_date'] ?? ''))
            : '';

        // Clip row (ensure it belongs to project + day)
        $clipStmt = $pdo->prepare("
            SELECT
                BIN_TO_UUID(c.id,1) AS clip_uuid,
                c.scene, c.slate, c.take, c.take_int, c.camera, c.reel,
                c.file_name, c.tc_start, c.tc_end, c.duration_ms,
                c.rating, c.is_select, c.ingest_state,
                c.created_at, c.updated_at
            FROM clips c
            WHERE c.id = UUID_TO_BIN(:clip,1)
              AND c.project_id = UUID_TO_BIN(:p,1)
              AND c.day_id     = UUID_TO_BIN(:d,1)
            LIMIT 1
        ");
        $clipStmt->execute([
            ':clip' => $clipUuid,
            ':p'    => $projectUuid,
            ':d'    => $dayUuid,
        ]);
        $clip = $clipStmt->fetch(\PDO::FETCH_ASSOC);
        if (!$clip) {
            http_response_code(404);
            echo "Clip not found";
            return;
        }

        // Per-clip metadata (key-value) — optional display for later enhancement
        $metaStmt = $pdo->prepare("
            SELECT meta_key, meta_value
            FROM clip_metadata
            WHERE clip_id = UUID_TO_BIN(:clip,1)
            ORDER BY meta_key
        ");
        $metaStmt->execute([':clip' => $clipUuid]);
        $metaRows = $metaStmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        // Resolve FPS from metadata (fallback keys)
        $fps = null;
        $fpsKeyStmt = $pdo->prepare("
    SELECT meta_value
    FROM clip_metadata
    WHERE clip_id = UUID_TO_BIN(:clip,1)
      AND meta_key IN ('fps','FPS','Camera FPS','Frame Rate','FrameRate','CameraFPS')
    ORDER BY FIELD(meta_key,'fps','FPS','Camera FPS','Frame Rate','FrameRate','CameraFPS')
    LIMIT 1
");
        $fpsKeyStmt->execute([':clip' => $clipUuid]);
        $fpsRaw = $fpsKeyStmt->fetchColumn();
        if ($fpsRaw !== false && $fpsRaw !== null) {
            // Normalize e.g. "24.000", "23,976", "24"
            $norm = str_replace(',', '.', (string)$fpsRaw);
            if (preg_match('/^\d+(\.\d+)?$/', trim($norm))) {
                $fps = (float)$norm;
            }
        }
        // Compute duration_pretty using resolved FPS
        if (!empty($clip['duration_ms']) && $fps && $fps > 0) {
            $ms = (int)$clip['duration_ms'];
            $totalSeconds = $ms / 1000;
            $minutes = (int)floor($totalSeconds / 60);
            $seconds = (int)floor(fmod($totalSeconds, 60.0));
            $frames  = (int)floor(($totalSeconds - floor($totalSeconds)) * $fps + 1e-6);
            $maxFrame = max(0, (int)floor($fps) - 1);
            if ($frames > $maxFrame) $frames = $maxFrame;

            $clip['duration_pretty'] = sprintf('%02d:%02d:%02d', $minutes, $seconds, $frames);
            $clip['fps'] = $fps; // make available to the view label
        } else {
            $clip['duration_pretty'] = '';
            if ($fps) {
                $clip['fps'] = $fps;
            }
        }

        // CSRF for the update POST
        $csrf = \App\Support\Csrf::token();


        // Convert duration_ms → MM:SS:FF using the clip's FPS (supports floats like 23.976)
        $fps = (float)($clip['fps'] ?? 0);
        if (!empty($clip['duration_ms']) && $fps > 0) {
            $ms = (int)$clip['duration_ms'];
            $totalSeconds = $ms / 1000;
            $minutes = (int)floor($totalSeconds / 60);
            $seconds = (int)floor(fmod($totalSeconds, 60.0));
            // frame index within the current second; clamp to [0, fps-1]
            $frames  = (int)floor(($totalSeconds - floor($totalSeconds)) * $fps + 1e-6);
            $maxFrame = max(0, (int)floor($fps) - 1);
            if ($frames > $maxFrame) $frames = $maxFrame;

            $clip['duration_pretty'] = sprintf('%02d:%02d:%02d', $minutes, $seconds, $frames);
        } else {
            $clip['duration_pretty'] = '';
        }


        View::render('admin/clips/edit', [
            'project'       => $project,
            'project_uuid'  => $projectUuid,
            'day_uuid'      => $dayUuid,
            'day_label'     => $dayLabel,
            'clip'          => $clip,
            'meta_rows'     => $metaRows,
            '_csrf'         => $csrf,
        ]);
    }

    /**
     * Save posted changes to a clip.
     */
    public function update(string $projectUuid, string $dayUuid, string $clipUuid): void
    {
        if (session_status() === PHP_SESSION_NONE) session_start();
        \App\Support\Csrf::validateOrAbort($_POST['_csrf'] ?? null);

        $account     = $_SESSION['account'] ?? null;
        $personUuid  = $_SESSION['person_uuid'] ?? null;
        $isSuperuser = (int)($account['is_superuser'] ?? 0);

        $pdo = DB::pdo();

        // Permission: Superuser OR project admin
        $isProjectAdmin = 0;
        if ($personUuid) {
            $stmtAdmin = $pdo->prepare("
                SELECT pm.is_project_admin
                FROM project_members pm
                JOIN projects p ON p.id = pm.project_id
                WHERE p.id = UUID_TO_BIN(:p,1)
                  AND pm.person_id = UUID_TO_BIN(:person,1)
                LIMIT 1
            ");
            $stmtAdmin->execute([':p' => $projectUuid, ':person' => $personUuid]);
            $isProjectAdmin = (int)($stmtAdmin->fetchColumn() ?: 0);
        }
        if (!$isSuperuser && !$isProjectAdmin) {
            http_response_code(403);
            echo "Forbidden";
            return;
        }

        // Sanity: ensure clip exists under project+day
        $existsStmt = $pdo->prepare("
            SELECT c.id
            FROM clips c
            WHERE c.id = UUID_TO_BIN(:clip,1)
              AND c.project_id = UUID_TO_BIN(:p,1)
              AND c.day_id     = UUID_TO_BIN(:d,1)
            LIMIT 1
        ");
        $existsStmt->execute([':clip' => $clipUuid, ':p' => $projectUuid, ':d' => $dayUuid]);
        $clipBin = $existsStmt->fetchColumn();
        if (!$clipBin) {
            http_response_code(404);
            echo "Clip not found";
            return;
        }

        // Gather inputs (empty => NULL where appropriate)
        $scene       = trim($_POST['scene']      ?? '');
        $slate       = trim($_POST['slate']      ?? '');
        $take        = trim($_POST['take']       ?? '');
        $camera      = trim($_POST['camera']     ?? '');
        $reel        = trim($_POST['reel']       ?? '');
        $fileName    = trim($_POST['file_name']  ?? '');
        $tcStart     = trim($_POST['tc_start']   ?? '');
        $tcEnd       = trim($_POST['tc_end']     ?? '');
        $durationMs  = trim($_POST['duration_ms'] ?? '');
        $rating      = trim($_POST['rating']     ?? '');
        $isSelect    = isset($_POST['is_select']) ? 1 : 0;
        $ingestState = trim($_POST['ingest_state'] ?? '');

        // take_int: prefer numeric parse of take; null if not numeric
        $takeInt = (ctype_digit($take) ? (int)$take : null);

        // Normalize nullable fields
        $scene      = ($scene      !== '') ? $scene     : null;
        $slate      = ($slate      !== '') ? $slate     : null;
        $take       = ($take       !== '') ? $take      : null;
        $camera     = ($camera     !== '') ? $camera    : null;
        $reel       = ($reel       !== '') ? $reel      : null;
        $fileName   = ($fileName   !== '') ? $fileName  : null;
        $tcStart    = ($tcStart    !== '') ? $tcStart   : null;
        $tcEnd      = ($tcEnd      !== '') ? $tcEnd     : null;
        $durationMs = ($durationMs !== '' && ctype_digit($durationMs)) ? (int)$durationMs : null;
        $rating     = ($rating     !== '' && ctype_digit($rating)) ? (int)$rating : null;
        $ingestState = in_array($ingestState, ['provisional', 'ready', 'locked', 'archived'], true) ? $ingestState : 'provisional';


        // --- Resolve existing duration from clips ---
        $durStmt = $pdo->prepare("
    SELECT duration_ms
    FROM clips
    WHERE id = UUID_TO_BIN(:clip,1)
      AND project_id = UUID_TO_BIN(:p,1)
      AND day_id     = UUID_TO_BIN(:d,1)
    LIMIT 1
");
        $durStmt->execute([':clip' => $clipUuid, ':p' => $projectUuid, ':d' => $dayUuid]);
        $existingMs = ($durStmt->fetchColumn() !== false) ? (int)$durStmt->fetchColumn() : null;

        // --- Resolve FPS from metadata ---
        $fpsKeyStmt = $pdo->prepare("
    SELECT meta_value
    FROM clip_metadata
    WHERE clip_id = UUID_TO_BIN(:clip,1)
      AND meta_key IN ('fps','FPS','Camera FPS','Frame Rate','FrameRate','CameraFPS')
    ORDER BY FIELD(meta_key,'fps','FPS','Camera FPS','Frame Rate','FrameRate','CameraFPS')
    LIMIT 1
");
        $fpsKeyStmt->execute([':clip' => $clipUuid]);
        $fpsRaw = $fpsKeyStmt->fetchColumn();
        $dbFps = 0;
        if ($fpsRaw !== false && $fpsRaw !== null) {
            $norm = str_replace(',', '.', (string)$fpsRaw);
            if (preg_match('/^\d+(\.\d+)?$/', trim($norm))) {
                $dbFps = (float)$norm;
            }
        }


        $durationPretty = trim($_POST['duration_pretty'] ?? '');
        // Default: keep existing if user left it blank or FPS unknown
        $durationMs = $existingMs;

        if ($durationPretty !== '') {
            if ($dbFps > 0) {
                // Parse MM:SS:FF with actual clip FPS
                if (preg_match('/^(\d{1,2}):(\d{1,2}):(\d{1,2})$/', $durationPretty, $m)) {
                    $mm = (int)$m[1];
                    $ss = (int)$m[2];
                    $ff = (int)$m[3];
                    $durationMs = (int)round((($mm * 60) + $ss + ($ff / $dbFps)) * 1000);
                } elseif (ctype_digit($durationPretty)) {
                    // If someone typed raw ms, accept it explicitly
                    $durationMs = (int)$durationPretty;
                } else {
                    // Bad format -> keep existing value
                    // (Optionally you could throw 400 here.)
                }
            } else {
                // FPS unknown -> cannot convert MM:SS:FF; keep existing
                // (Optionally set a flash warning to tell the user FPS is needed.)
            }
        }


        try {
            $pdo->beginTransaction();

            // Unique constraint guard:
            // UNIQUE KEY uq_clip_identity (project_id,day_id,scene,slate,take,camera)
            // If scene/slate/take/camera changed, we might trip it — rely on DB error for now.

            $sql = "
                UPDATE clips
                SET scene = :scene_u,
                    slate = :slate_u,
                    take = :take_u,
                    take_int = :take_int_u,
                    camera = :camera_u,
                    reel = :reel_u,
                    file_name = :file_u,
                    tc_start = :tc_start_u,
                    tc_end = :tc_end_u,
                    duration_ms = :dur_u,
                    rating = :rating_u,
                    is_select = :select_u,
                    ingest_state = :ingest_state_u
                WHERE id = UUID_TO_BIN(:clip_u,1)
                  AND project_id = UUID_TO_BIN(:p_u,1)
                  AND day_id     = UUID_TO_BIN(:d_u,1)
                LIMIT 1
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':scene_u'        => $scene,
                ':slate_u'        => $slate,
                ':take_u'         => $take,
                ':take_int_u'     => $takeInt,
                ':camera_u'       => $camera,
                ':reel_u'         => $reel,
                ':file_u'         => $fileName,
                ':tc_start_u'     => $tcStart,
                ':tc_end_u'       => $tcEnd,
                ':dur_u'          => $durationMs,
                ':rating_u'       => $rating,
                ':select_u'       => $isSelect,
                ':ingest_state_u' => $ingestState,
                ':clip_u'         => $clipUuid,
                ':p_u'            => $projectUuid,
                ':d_u'            => $dayUuid,
            ]);

            // Audit (best effort)
            $actorId = null;
            if ($personUuid) {
                $actorId = $pdo->query("SELECT UUID_TO_BIN('$personUuid',1)")->fetchColumn();
            }
            $pdo->prepare("
                INSERT INTO events_audit (actor_id, project_id, entity, entity_id, action, meta_json)
                VALUES (:actor, UUID_TO_BIN(:p,1), 'clip', UUID_TO_BIN(:clip,1), 'update',
                        JSON_OBJECT('fields', JSON_ARRAY('scene','slate','take','take_int','camera','reel','file_name','tc_start','tc_end','duration_ms','rating','is_select','ingest_state')))
            ")->execute([
                ':actor' => $actorId,
                ':p'     => $projectUuid,
                ':clip'  => $clipUuid,
            ]);

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            http_response_code(400);
            echo "Save failed: " . $e->getMessage();
            return;
        }

        // Back to the clips list
        header("Location: /admin/projects/{$projectUuid}/days/{$dayUuid}/clips?updated=1#clip-{$clipUuid}");
        exit;
    }

    /**
     * Quick update of one simple field (scene|slate|take). JSON in/out.
     * Route: POST /admin/projects/{p}/days/{d}/clips/{clip}/quick
     */
    public function quickField(string $projectUuid, string $dayUuid, string $clipUuid): void
    {
        if (session_status() === PHP_SESSION_NONE) session_start();
        \App\Support\Csrf::validateOrAbort($_POST['_csrf'] ?? null);

        header('Content-Type: application/json; charset=utf-8');

        $account     = $_SESSION['account'] ?? null;
        $personUuid  = $_SESSION['person_uuid'] ?? null;
        $isSuperuser = (int)($account['is_superuser'] ?? 0);

        $pdo = DB::pdo();

        // Permission: Superuser OR project admin (same as full edit)
        $isProjectAdmin = 0;
        if ($personUuid) {
            $stmtAdmin = $pdo->prepare("
            SELECT pm.is_project_admin
            FROM project_members pm
            JOIN projects p ON p.id = pm.project_id
            WHERE p.id = UUID_TO_BIN(:p,1)
              AND pm.person_id = UUID_TO_BIN(:person,1)
            LIMIT 1
        ");
            $stmtAdmin->execute([':p' => $projectUuid, ':person' => $personUuid]);
            $isProjectAdmin = (int)($stmtAdmin->fetchColumn() ?: 0);
        }
        if (!$isSuperuser && !$isProjectAdmin) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Forbidden']);
            return;
        }

        // Ensure clip belongs to project+day
        $existsStmt = $pdo->prepare("
        SELECT c.id, c.take
        FROM clips c
        WHERE c.id = UUID_TO_BIN(:clip,1)
          AND c.project_id = UUID_TO_BIN(:p,1)
          AND c.day_id     = UUID_TO_BIN(:d,1)
        LIMIT 1
    ");
        $existsStmt->execute([':clip' => $clipUuid, ':p' => $projectUuid, ':d' => $dayUuid]);
        $clip = $existsStmt->fetch(\PDO::FETCH_ASSOC);
        if (!$clip) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Clip not found']);
            return;
        }

        $field = trim($_POST['field'] ?? '');
        $value = trim($_POST['value'] ?? '');

        if (!in_array($field, ['scene', 'slate', 'take'], true)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Unsupported field']);
            return;
        }

        // Normalize empty => NULL
        $val = ($value !== '') ? $value : null;

        // If take changes, compute take_int if numeric
        $takeInt = null;
        $params = [
            ':clip' => $clipUuid,
            ':p'    => $projectUuid,
            ':d'    => $dayUuid,
        ];

        $sql = '';
        if ($field === 'take') {
            $takeInt = (ctype_digit((string)$value) ? (int)$value : null);
            $sql = "
            UPDATE clips
               SET take = :v,
                   take_int = :vi
             WHERE id = UUID_TO_BIN(:clip,1)
               AND project_id = UUID_TO_BIN(:p,1)
               AND day_id = UUID_TO_BIN(:d,1)
             LIMIT 1
        ";
            $params[':v']  = $val;
            $params[':vi'] = $takeInt;
        } else {
            $sql = "
            UPDATE clips
               SET {$field} = :v
             WHERE id = UUID_TO_BIN(:clip,1)
               AND project_id = UUID_TO_BIN(:p,1)
               AND day_id = UUID_TO_BIN(:d,1)
             LIMIT 1
        ";
            $params[':v'] = $val;
        }

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            // Display value normalization (for take we prefer numeric if set)
            $display = ($field === 'take')
                ? (($takeInt !== null) ? (string)$takeInt : ($val ?? ''))
                : ($val ?? '');

            echo json_encode(['ok' => true, 'field' => $field, 'display' => $display]);
        } catch (\Throwable $e) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    public function quickSelect(string $projectUuid, string $dayUuid, string $clipUuid): void
    {
        if (session_status() === PHP_SESSION_NONE) session_start();
        \App\Support\Csrf::validateOrAbort($_POST['_csrf'] ?? null);

        header('Content-Type: application/json; charset=utf-8');

        $account     = $_SESSION['account'] ?? null;
        $personUuid  = $_SESSION['person_uuid'] ?? null;
        $isSuperuser = (int)($account['is_superuser'] ?? 0);

        $pdo = DB::pdo();

        // permission: superuser OR project admin
        $isProjectAdmin = 0;
        if ($personUuid) {
            $stmtAdmin = $pdo->prepare("
            SELECT pm.is_project_admin
            FROM project_members pm
            JOIN projects p ON p.id = pm.project_id
            WHERE p.id = UUID_TO_BIN(:p,1)
              AND pm.person_id = UUID_TO_BIN(:person,1)
            LIMIT 1
        ");
            $stmtAdmin->execute([':p' => $projectUuid, ':person' => $personUuid]);
            $isProjectAdmin = (int)($stmtAdmin->fetchColumn() ?: 0);
        }
        if (!$isSuperuser && !$isProjectAdmin) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Forbidden']);
            return;
        }

        // ensure clip belongs to project+day
        $exists = $pdo->prepare("
        SELECT is_select
        FROM clips
        WHERE id = UUID_TO_BIN(:c,1)
          AND project_id = UUID_TO_BIN(:p,1)
          AND day_id     = UUID_TO_BIN(:d,1)
        LIMIT 1
    ");
        $exists->execute([':c' => $clipUuid, ':p' => $projectUuid, ':d' => $dayUuid]);
        $cur = $exists->fetchColumn();
        if ($cur === false) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Clip not found']);
            return;
        }

        // If client sent explicit value (0/1) use it; else toggle.
        $posted = $_POST['value'] ?? null;
        if ($posted === '0' || $posted === '1') {
            $next = (int)$posted;
        } else {
            $next = ((int)$cur) ? 0 : 1;
        }

        try {
            $upd = $pdo->prepare("
            UPDATE clips
            SET is_select = :v
            WHERE id = UUID_TO_BIN(:c,1)
              AND project_id = UUID_TO_BIN(:p,1)
              AND day_id     = UUID_TO_BIN(:d,1)
            LIMIT 1
        ");
            $upd->execute([':v' => $next, ':c' => $clipUuid, ':p' => $projectUuid, ':d' => $dayUuid]);

            echo json_encode(['ok' => true, 'is_select' => $next]);
        } catch (\Throwable $e) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
    }


    public function destroy(string $projectUuid, string $dayUuid, string $clipUuid): void
    {
        // AuthN
        $account     = $_SESSION['account'] ?? null;
        $personUuid  = $_SESSION['person_uuid'] ?? null;
        $isSuperuser = (int)($account['is_superuser'] ?? 0);

        // CSRF
        \App\Support\Csrf::validateOrAbort($_POST['_csrf'] ?? null);

        $pdo = DB::pdo();

        // Permission: allow Superuser OR project admin on this project
        $isProjectAdmin = 0;
        if ($personUuid) {
            $stmtAdmin = $pdo->prepare("
            SELECT pm.is_project_admin
            FROM project_members pm
            JOIN projects p ON p.id = pm.project_id
            WHERE p.id = UUID_TO_BIN(:p,1)
              AND pm.person_id = UUID_TO_BIN(:person,1)
            LIMIT 1
        ");
            $stmtAdmin->execute([':p' => $projectUuid, ':person' => $personUuid]);
            $isProjectAdmin = (int)($stmtAdmin->fetchColumn() ?: 0);
        }
        if (!$isSuperuser && !$isProjectAdmin) {
            http_response_code(403);
            echo "Forbidden";
            return;
        }

        // Resolve clip + sanity check it belongs to {project,day}
        $stmt = $pdo->prepare("
        SELECT c.id AS clip_bin, c.file_name
        FROM clips c
        WHERE c.id = UUID_TO_BIN(:clip,1)
          AND c.project_id = UUID_TO_BIN(:p,1)
          AND c.day_id     = UUID_TO_BIN(:d,1)
        LIMIT 1
    ");
        $stmt->execute([':clip' => $clipUuid, ':p' => $projectUuid, ':d' => $dayUuid]);
        $clipRow = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$clipRow) {
            http_response_code(404);
            echo "Clip not found";
            return;
        }
        $clipBin = $clipRow['clip_bin'];

        // Cancel any pending encode jobs for this clip so proxies won't be recreated
        $stmtCancelJobs = $pdo->prepare("
        UPDATE encode_jobs
           SET state = 'canceled',
               cancel_requested = 1,
               updated_at = NOW()
         WHERE clip_id = :clip
           AND state IN ('queued','running')
    ");
        $stmtCancelJobs->bindParam(':clip', $clipBin, \PDO::PARAM_LOB);
        $stmtCancelJobs->execute();


        // Collect all asset paths for this clip (proxy_web/poster/original/sprite/waveform…)
        $stmtA = $pdo->prepare("
        SELECT asset_type, storage_path
        FROM clip_assets
        WHERE clip_id = :clip
    ");
        $stmtA->bindParam(':clip', $clipBin, \PDO::PARAM_LOB);
        $stmtA->execute();
        $assets = $stmtA->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        // New bases (no legacy support)
        $fsBase     = rtrim(getenv('ZEN_STOR_DIR') ?: '/var/www/html/data/zendailies/uploads', '/');
        $publicBase = rtrim(getenv('ZEN_STOR_PUBLIC_BASE') ?: '/data/zendailies/uploads', '/');

        $errors = [];
        $deleted = [];

        foreach ($assets as $a) {
            $publicPath = (string)($a['storage_path'] ?? '');
            if ($publicPath === '') {
                continue;
            }

            // Must be under our public base
            if (!str_starts_with($publicPath, $publicBase . '/')) {
                $errors[] = "Skip (outside new base): $publicPath";
                continue;
            }

            // Convert /data/zendailies/uploads/... -> /var/www/html/data/zendailies/uploads/...
            $abs = $fsBase . substr($publicPath, strlen($publicBase));
            // Normalize path for safety (realpath on parent, then basename)
            $parent = dirname($abs);
            $parentReal = realpath($parent);
            if ($parentReal === false) {
                $errors[] = "Parent missing: $parent";
                continue;
            }
            $absReal = $parentReal . DIRECTORY_SEPARATOR . basename($abs);

            // Safety: ensure deletion stays inside fsBase
            if (!str_starts_with($absReal, $fsBase . '/')) {
                $errors[] = "Unsafe path: $absReal";
                continue;
            }

            // Delete the file if present
            if (is_file($absReal)) {
                if (@unlink($absReal)) {
                    $deleted[] = $absReal;

                    // Optional: delete common sidecars (waveform/json/md5) with same stem
                    $stem = $parentReal . DIRECTORY_SEPARATOR . pathinfo($absReal, PATHINFO_FILENAME);
                    foreach (glob($stem . '.*') ?: [] as $sidecar) {
                        // Keep original file; remove sidecars like .json, .md5, .wav, .png, etc.
                        if ($sidecar !== $absReal && @is_file($sidecar)) {
                            @unlink($sidecar);
                        }
                    }
                } else {
                    $errors[] = "Failed to delete: $absReal";
                }
            } else {
                // Not a file—ignore silently but record info
                $errors[] = "File not found: $absReal";
            }

            // Best-effort tidy: remove empty dirs up the chain (<...>/<project>/<day>)
            @rmdir($parentReal);
            @rmdir(dirname($parentReal));
            @rmdir(dirname(dirname($parentReal)));
        }

        // Attempt to tidy now-empty dir (project/day). Best-effort: ignore errors
        $dayDir = $fsBase . '/' . $projectUuid . '/' . $dayUuid;
        @rmdir($dayDir); // will only succeed if empty

        try {
            $pdo->beginTransaction();

            // Delete clip row (CASCADE will remove clip_assets, comments, ACL, etc.)
            $stmtDel = $pdo->prepare("DELETE FROM clips WHERE id = :clip LIMIT 1");
            $stmtDel->bindParam(':clip', $clipBin, \PDO::PARAM_LOB);
            $stmtDel->execute();

            // Audit
            $actorId = null;
            if ($personUuid) {
                $actorId = $pdo->query("SELECT UUID_TO_BIN('$personUuid',1)")->fetchColumn();
            }
            $pdo->prepare("
            INSERT INTO events_audit (actor_id, project_id, entity, entity_id, action, meta_json)
            VALUES (:actor, UUID_TO_BIN(:p,1), 'clip', UUID_TO_BIN(:clip,1), 'delete',
                    JSON_OBJECT('errors', :errs))
        ")->execute([
                ':actor' => $actorId,
                ':p'     => $projectUuid,
                ':clip'  => $clipUuid,
                ':errs'  => json_encode($errors, JSON_UNESCAPED_SLASHES),
            ]);

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            http_response_code(500);
            echo "DB error: " . $e->getMessage();
            return;
        }

        // Back to clips index
        header("Location: /admin/projects/{$projectUuid}/days/{$dayUuid}/clips?deleted=1");
        exit;
    }
}
