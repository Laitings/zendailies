<?php

namespace App\Http\Controllers\Admin;

use App\Support\DB;
use App\Support\View;
use App\Repositories\ClipRepository;

final class ProjectClipsController
{
    /**
     * Project-level clips index (ALL days).
     * Route: /admin/projects/{projectUuid}/clips
     */
    public function indexProject(string $projectUuid): void
    {
        // Use the existing index() but force "all" as the day
        $this->index($projectUuid, 'all');
    }

    public function index(string $projectUuid, string $dayUuid): void
    {
        if (!$projectUuid || !$dayUuid) {
            http_response_code(400);
            echo "Bad request";
            return;
        }

        if (session_status() === \PHP_SESSION_NONE) {
            session_start();
        }

        // Session context
        $account    = $_SESSION['account'] ?? null;
        $personUuid = $_SESSION['person_uuid'] ?? null;
        $isSuperuser = (int)($account['is_superuser'] ?? 0);

        $pdo = DB::pdo();

        // --- FETCH PROJECT MEMBER ROLE & ADMIN STATUS ---
        $isProjectAdmin = 0;
        $userRole       = ''; // We fetch this so the View can allow "Selects" for DOPs/Directors
        if ($personUuid) {
            $stmtMember = $pdo->prepare("
                SELECT pm.is_project_admin, pm.role
                FROM project_members pm
                WHERE pm.project_id = UUID_TO_BIN(:p, 1)
                  AND pm.person_id  = UUID_TO_BIN(:person, 1)
                LIMIT 1
            ");
            $stmtMember->execute([':p' => $projectUuid, ':person' => $personUuid]);
            $member = $stmtMember->fetch(\PDO::FETCH_ASSOC);

            if ($member) {
                $isProjectAdmin = (int)($member['is_project_admin'] ?? 0);
                $userRole       = strtolower($member['role'] ?? '');
            }
        }

        // After $isProjectAdmin is determined...
        $projRepo = new \App\Repositories\ProjectRepository($pdo);
        $availableGroups = $projRepo->listSensitiveGroups($projectUuid);
        // Note: We created listSensitiveGroups in the previous turn.

        // Filters (GET)
        $q = [
            'scene'   => trim($_GET['scene']   ?? ''),
            'slate'   => trim($_GET['slate']   ?? ''),
            'take'    => trim($_GET['take']    ?? ''),
            'camera'  => trim($_GET['camera']  ?? ''),
            'rating'  => trim($_GET['rating']  ?? ''),
            'select'  => trim($_GET['select']  ?? ''), // '', '1', '0'
            'text'    => trim($_GET['text']    ?? ''), // file_name/reel matches
            'sort'    => trim($_GET['sort']    ?? 'file'),
            'dir'     => strtoupper(trim($_GET['dir'] ?? 'ASC')),
            'page'    => max(1, (int)($_GET['page'] ?? 1)),
            'per'     => max(10, min(200, (int)($_GET['per'] ?? 50))),
        ];
        if (!in_array($q['dir'], ['ASC', 'DESC'], true)) {
            $q['dir'] = 'ASC';
        }

        // Pagination
        $offset = ($q['page'] - 1) * $q['per'];

        // === Fetch All Days (for dropdown) ===
        $sqlDays = "
            SELECT BIN_TO_UUID(id,1) as id, shoot_date, title, published_at
            FROM days
            WHERE project_id = UUID_TO_BIN(:p,1)
        ";

        // Regular users: only see published days in the dropdown
        if (!$isSuperuser && !$isProjectAdmin) {
            $sqlDays .= " AND published_at IS NOT NULL";
        }

        $sqlDays .= " ORDER BY shoot_date ASC";

        $daysStmt = $pdo->prepare($sqlDays);
        $daysStmt->execute([':p' => $projectUuid]);
        $allDays = $daysStmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        // === Check Mode: All Days or Single Day ===
        $isAllDays = ($dayUuid === 'all');
        $dayInfo = null;
        $dayLabel = '';

        if ($isAllDays) {
            $dayLabel = 'All days';
        } else {
            // === Guard: regular users cannot access unpublished days ===
            $dayStmt = $pdo->prepare("
                SELECT d.id, d.published_at, d.title, d.shoot_date, d.notes
                FROM days d
                WHERE d.project_id = UUID_TO_BIN(:p, 1)
                  AND d.id         = UUID_TO_BIN(:d, 1)
                LIMIT 1
            ");
            $dayStmt->execute([':p' => $projectUuid, ':d' => $dayUuid]);
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

            $dayInfo = $dayRow;
            $dayLabel = $dayInfo['title'] !== null && $dayInfo['title'] !== ''
                ? $dayInfo['title']
                : ($dayInfo['shoot_date'] ?? '');
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

        // === Filters ===
        $filters = [
            'scene'  => $q['scene'],
            'slate'  => $q['slate'],
            'take'   => $q['take'],
            'camera' => $q['camera'],
            'rating' => $q['rating'],
            'select' => $q['select'],
            'text'   => $q['text'],
        ];

        // === Sensitive-ACL visibility + published-only for regular users ===
        $visibilitySql    = '';
        $visibilityParams = [];

        if (!$isSuperuser && !$isProjectAdmin) {
            // Regular users must satisfy ALL of this:
            // 1) The day is published
            // 2) The DAY is not restricted (or user is in the Day's group)
            // 3) The CLIP is not restricted (or user is in the Clip's group)

            $visibilitySql = "
                AND d.published_at IS NOT NULL
                
                -- DAY GATE (Uses :viewer_uuid_day)
                AND (
                    NOT EXISTS (SELECT 1 FROM day_sensitive_acl dsa_day WHERE dsa_day.day_id = c.day_id)
                    OR EXISTS (
                        SELECT 1 
                        FROM day_sensitive_acl dsa_day
                        JOIN sensitive_group_members sgm_day ON sgm_day.group_id = dsa_day.group_id
                        WHERE dsa_day.day_id = c.day_id 
                          AND sgm_day.person_id = UUID_TO_BIN(:viewer_uuid_day, 1)
                    )
                )

                -- CLIP GATE (Uses :viewer_uuid_clip)
                AND (
                    c.is_restricted = 0
                    OR EXISTS (
                        SELECT 1
                        FROM clip_sensitive_acl csa_sub
                        JOIN sensitive_group_members sgm_sub ON sgm_sub.group_id = csa_sub.group_id
                        WHERE csa_sub.clip_id = c.id
                          AND sgm_sub.person_id = UUID_TO_BIN(:viewer_uuid_clip, 1)
                    )
                )
            ";

            // Bind the same person UUID to BOTH placeholders
            $viewerUuid = $personUuid ?? '00000000-0000-0000-0000-000000000000';
            $visibilityParams[':viewer_uuid_day']  = $viewerUuid;
            $visibilityParams[':viewer_uuid_clip'] = $viewerUuid;
        }

        // === Use ClipRepository ===
        $clipRepo = new ClipRepository($pdo);
        $repoOptions = [
            'visibility_sql'    => $visibilitySql,
            'visibility_params' => $visibilityParams,
        ];
        $listOptions = array_merge($repoOptions, [
            'sort'      => $q['sort'],
            'direction' => $q['dir'],
            'limit'     => $q['per'],
            'offset'    => $offset,
        ]);

        // 1. Unfiltered Stats
        if ($isAllDays) {
            $totalUnfiltered    = $clipRepo->countForProject($projectUuid, [], $repoOptions);
            $durationUnfiltered = $clipRepo->sumDurationForProject($projectUuid, [], $repoOptions);
        } else {
            $totalUnfiltered    = $clipRepo->countForDay($projectUuid, $dayUuid, [], $repoOptions);
            $durationUnfiltered = $clipRepo->sumDurationForDay($projectUuid, $dayUuid, [], $repoOptions);
        }

        // 2. Filtered Stats
        if ($isAllDays) {
            $total              = $clipRepo->countForProject($projectUuid, $filters, $repoOptions);
            $rows               = $clipRepo->listForProject($projectUuid, $filters, $listOptions);
            $dayTotalDurationMs = $clipRepo->sumDurationForProject($projectUuid, $filters, $repoOptions);
        } else {
            $total              = $clipRepo->countForDay($projectUuid, $dayUuid, $filters, $repoOptions);
            $rows               = $clipRepo->listForDay($projectUuid, $dayUuid, $filters, $listOptions);
            $dayTotalDurationMs = $clipRepo->sumDurationForDay($projectUuid, $dayUuid, $filters, $repoOptions);
        }

        // Camera list
        $cameraOptions = $clipRepo->getDistinctCameras($projectUuid, $isAllDays ? null : $dayUuid);

        // CSRF
        $converterToken = bin2hex(random_bytes(32));
        $_SESSION['csrf_tokens']['converter'][$dayUuid] = $converterToken;
        $quickToken = \App\Support\Csrf::token();
        $_SESSION['csrf_tokens']['clip_quick'][$dayUuid] = $quickToken;

        View::render('admin/clips/index', [
            'project'                 => $project,
            'availableGroups'         => $projRepo->listSensitiveGroups($projectUuid),
            'project_uuid'            => $projectUuid,
            'day_uuid'                => $dayUuid,
            'day_info'                => $dayInfo,
            'day_label'               => $dayLabel,
            'all_days'                => $allDays,
            'is_all_days'             => $isAllDays,
            'filters'                 => $q,
            'rows'                    => $rows,
            'total'                   => $total,
            'page'                    => $q['page'],
            'per'                     => $q['per'],
            'cameraOptions'           => $cameraOptions,
            'isSuperuser'             => $isSuperuser,
            'isProjectAdmin'          => $isProjectAdmin,
            'user_role'               => $userRole, // <--- CRITICAL: Pass the role to the view
            'converter_csrf'          => $converterToken,
            'quick_csrf'              => $quickToken,
            'day_total_duration_ms'   => $dayTotalDurationMs,
            'total_unfiltered'        => $totalUnfiltered,
            'duration_unfiltered'     => $durationUnfiltered,
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

        // Added 'camera' to the allowed list
        if (!in_array($field, ['scene', 'slate', 'take', 'camera'], true)) {
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

    /**
     * This function is from ProjectClipsController
     * Toggles the 'is_select' flag for a clip.
     * Route: POST /admin/projects/{projectUuid}/days/{dayUuid}/clips/{clipUuid}/select
     */
    public function quickSelect(string $projectUuid, string $dayUuid, string $clipUuid): void
    {
        // Set JSON header early
        header('Content-Type: application/json');

        if (!$projectUuid || !$dayUuid || !$clipUuid) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Missing parameters']);
            return;
        }

        if (session_status() === \PHP_SESSION_NONE) {
            session_start();
        }

        $account    = $_SESSION['account'] ?? null;
        $personUuid = $_SESSION['person_uuid'] ?? null;
        $isSuperuser = (int)($account['is_superuser'] ?? 0);

        // Early validation
        if (!$account) {
            http_response_code(401);
            echo json_encode(['ok' => false, 'error' => 'Not authenticated']);
            return;
        }

        try {
            $pdo = DB::pdo();

            // --- Permission Check: Superuser OR allowed project roles ---
            $canSelect = false;
            if ($isSuperuser) {
                $canSelect = true;
            } elseif ($personUuid) {
                $stmtPerm = $pdo->prepare("
                SELECT pm.is_project_admin, pm.role
                FROM project_members pm
                WHERE pm.project_id = UUID_TO_BIN(:p,1)
                  AND pm.is_active = 1
                  AND pm.person_id  = UUID_TO_BIN(:person,1)
                LIMIT 1
            ");
                $stmtPerm->execute([':p' => $projectUuid, ':person' => $personUuid]);
                $member = $stmtPerm->fetch(\PDO::FETCH_ASSOC);

                if ($member) {
                    $allowedRoles = ['owner', 'admin', 'dit', 'dop', 'director', 'producer', 'editor'];
                    $userRole = strtolower($member['role'] ?? '');

                    if ((int)($member['is_project_admin'] ?? 0) === 1 || in_array($userRole, $allowedRoles)) {
                        $canSelect = true;
                    }
                }
            }

            if (!$canSelect) {
                http_response_code(403);
                echo json_encode(['ok' => false, 'error' => 'You do not have permission to mark selects.']);
                return;
            }

            // --- Verify the clip exists and belongs to this project/day ---
            $stmtVerify = $pdo->prepare("
            SELECT id, is_select 
            FROM clips 
            WHERE id = UUID_TO_BIN(:c,1)
              AND project_id = UUID_TO_BIN(:p,1)
              AND day_id = UUID_TO_BIN(:d,1)
            LIMIT 1
        ");
            $stmtVerify->execute([
                ':c' => $clipUuid,
                ':p' => $projectUuid,
                ':d' => $dayUuid
            ]);
            $clip = $stmtVerify->fetch(\PDO::FETCH_ASSOC);

            if (!$clip) {
                http_response_code(404);
                echo json_encode(['ok' => false, 'error' => 'Clip not found']);
                return;
            }

            // --- Toggle Logic ---
            $current = (int)$clip['is_select'];
            $newState = ($current === 1) ? 0 : 1;

            // Update database
            $stmtUpdate = $pdo->prepare("
            UPDATE clips 
            SET is_select = :s 
            WHERE id = UUID_TO_BIN(:c,1) 
            LIMIT 1
        ");
            $stmtUpdate->execute([':s' => $newState, ':c' => $clipUuid]);

            echo json_encode([
                'ok' => true,
                'is_select' => $newState
            ]);
        } catch (\PDOException $e) {
            http_response_code(500);
            error_log("Database error in quickSelect: " . $e->getMessage());
            echo json_encode(['ok' => false, 'error' => 'Database error: ' . $e->getMessage()]);
        } catch (\Throwable $e) {
            http_response_code(500);
            error_log("Error in quickSelect: " . $e->getMessage());
            echo json_encode(['ok' => false, 'error' => 'Server error: ' . $e->getMessage()]);
        }
    }

    public function quick_restrict(string $projectUuid, string $dayUuid, string $clipUuid): void
    {
        // 1. JSON and Session Setup
        header('Content-Type: application/json; charset=utf-8');
        if (session_status() === \PHP_SESSION_NONE) session_start();

        // 2. Security Guards (CSRF and Auth)
        \App\Support\Csrf::validateOrAbort($_POST['_csrf'] ?? null);

        $account     = $_SESSION['account'] ?? null;
        $personUuid  = $_SESSION['person_uuid'] ?? null;
        $isSuperuser = (int)($account['is_superuser'] ?? 0);
        $pdo         = DB::pdo();

        // 3. Permission Guard: Superuser OR Project Admin (DIT) only
        $isProjectAdmin = 0;
        if ($personUuid) {
            $stmtAdmin = $pdo->prepare("
                SELECT pm.is_project_admin
                FROM project_members pm
                WHERE pm.project_id = UUID_TO_BIN(:p, 1)
                  AND pm.person_id  = UUID_TO_BIN(:person, 1)
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

        // 4. Input Processing
        // Expecting an array of group UUIDs from the multi-select UI
        $groupUuids = $_POST['groups'] ?? [];
        if (!is_array($groupUuids)) {
            $groupUuids = $groupUuids ? [$groupUuids] : [];
        }

        try {
            $clipRepo = new ClipRepository($pdo);

            // 5. Update many-to-many ACL and restricted flag
            // This method handles the transaction and clearing/setting of groups
            $clipRepo->setClipGroups($clipUuid, $groupUuids);

            echo json_encode([
                'ok' => true,
                'is_restricted' => !empty($groupUuids),
                'assigned_groups' => $groupUuids
            ]);
        } catch (\Throwable $e) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Batch update restrictions for multiple clips.
     * Route: POST /admin/projects/{p}/days/{d}/clips/bulk-restrict
     */
    public function bulkRestrict(string $projectUuid, string $dayUuid): void
    {
        header('Content-Type: application/json; charset=utf-8');
        if (session_status() === \PHP_SESSION_NONE) session_start();
        \App\Support\Csrf::validateOrAbort($_POST['_csrf'] ?? null);

        $account     = $_SESSION['account'] ?? null;
        $personUuid  = $_SESSION['person_uuid'] ?? null;
        $isSuperuser = (int)($account['is_superuser'] ?? 0);
        $pdo         = DB::pdo();

        // Permission Guard
        $isProjectAdmin = 0;
        if ($personUuid) {
            $stmtAdmin = $pdo->prepare("SELECT is_project_admin FROM project_members WHERE project_id = UUID_TO_BIN(:p, 1) AND person_id = UUID_TO_BIN(:person, 1) LIMIT 1");
            $stmtAdmin->execute([':p' => $projectUuid, ':person' => $personUuid]);
            $isProjectAdmin = (int)($stmtAdmin->fetchColumn() ?: 0);
        }

        if (!$isSuperuser && !$isProjectAdmin) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Forbidden']);
            return;
        }

        $clipUuids = explode(',', $_POST['clip_uuids'] ?? '');
        $groupUuids = $_POST['groups'] ?? []; // Array of group UUIDs
        $mode = $_POST['mode'] ?? 'set'; // 'set' (overwrite), 'add', or 'remove'

        if (empty($clipUuids)) {
            echo json_encode(['ok' => false, 'error' => 'No clips selected']);
            return;
        }

        try {
            $clipRepo = new \App\Repositories\ClipRepository($pdo);
            $pdo->beginTransaction();

            foreach ($clipUuids as $clipUuid) {
                $clipUuid = trim($clipUuid);
                if (!$clipUuid) continue;

                if ($mode === 'set') {
                    $clipRepo->setClipGroups($clipUuid, $groupUuids);
                } else {
                    $currentGroups = $clipRepo->getClipGroups($clipUuid);
                    if ($mode === 'add') {
                        $newGroups = array_unique(array_merge($currentGroups, $groupUuids));
                        $clipRepo->setClipGroups($clipUuid, $newGroups);
                    } elseif ($mode === 'remove') {
                        $newGroups = array_diff($currentGroups, $groupUuids);
                        $clipRepo->setClipGroups($clipUuid, $newGroups);
                    }
                }
            }

            $pdo->commit();
            echo json_encode(['ok' => true]);
        } catch (\Throwable $e) {
            // Crucial: check if transaction is active before rolling back
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
    }

    public function destroy(string $projectUuid, string $dayUuid, string $clipUuid): void
    {
        if (session_status() === PHP_SESSION_NONE) session_start();
        \App\Support\Csrf::validateOrAbort($_POST['_csrf'] ?? null);

        // Permission check
        if (!$this->canUserManageClips($projectUuid)) {
            http_response_code(403);
            echo "Forbidden";
            return;
        }

        $this->internalDelete($projectUuid, $dayUuid, $clipUuid);

        header("Location: /admin/projects/{$projectUuid}/days/{$dayUuid}/clips?deleted=1");
        exit;
    }

    /**
     * Route: POST /admin/projects/{p}/days/{d}/clips/bulk_delete
     */
    public function bulkDelete(string $projectUuid, string $dayUuid): void
    {
        if (session_status() === PHP_SESSION_NONE) session_start();
        \App\Support\Csrf::validateOrAbort($_POST['_csrf'] ?? null);

        if (!$this->canUserManageClips($projectUuid)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Forbidden']);
            return;
        }

        $clipUuids = explode(',', $_POST['clip_uuids'] ?? '');
        $clipUuids = array_filter(array_map('trim', $clipUuids));

        if (empty($clipUuids)) {
            header("Location: /admin/projects/{$projectUuid}/days/{$dayUuid}/clips?error=no_selection");
            exit;
        }

        foreach ($clipUuids as $uuid) {
            $this->internalDelete($projectUuid, $dayUuid, $uuid);
        }

        header("Location: /admin/projects/{$projectUuid}/days/{$dayUuid}/clips?deleted_bulk=" . count($clipUuids));
        exit;
    }

    /**
     * Shared logic to scrub files and DB records for a clip
     */
    private function internalDelete(string $projectUuid, string $dayUuid, string $clipUuid): void
    {
        $pdo = \App\Support\DB::pdo();

        // 1. Resolve clip and assets
        $stmt = $pdo->prepare("
        SELECT c.id AS clip_bin
        FROM clips c
        WHERE c.id = UUID_TO_BIN(:clip, 1)
          AND c.project_id = UUID_TO_BIN(:p, 1)
        LIMIT 1
    ");
        $stmt->execute([':clip' => $clipUuid, ':p' => $projectUuid]);
        $clipBin = $stmt->fetchColumn();

        if (!$clipBin) return;

        // 2. Cancel pending jobs
        $pdo->prepare("UPDATE jobs_queue SET state = 'canceled' WHERE clip_id = :clip AND state IN ('queued','running')")
            ->execute([':clip' => $clipBin]);

        // 3. Delete Physical Files (Proxies, Posters, etc.)
        $stmtA = $pdo->prepare("SELECT storage_path FROM clip_assets WHERE clip_id = :clip");
        $stmtA->execute([':clip' => $clipBin]);
        $assets = $stmtA->fetchAll(\PDO::FETCH_ASSOC);

        // [UPDATED] Use robust fallbacks matching ClipPlayerController
        $fsBase     = rtrim(getenv('ZEN_STOR_DIR') ?: '/var/www/html/data', '/');
        $publicBase = rtrim(getenv('ZEN_STOR_PUBLIC_BASE') ?: '/data', '/');

        foreach ($assets as $a) {
            $path = $a['storage_path'];
            // Check if the DB path starts with our public base URL (e.g. /data/...)
            if (str_starts_with($path, $publicBase)) {
                // Remove /data, prepend /var/www/html/data
                $suffix = substr($path, strlen($publicBase));
                $abs    = $fsBase . $suffix;

                if (is_file($abs)) {
                    @unlink($abs);
                }
            }
        }

        // 4. Final DB Scrub
        $pdo->prepare("DELETE FROM clips WHERE id = :clip")->execute([':clip' => $clipBin]);
    }

    /**
     * Helper for clip management permissions
     */
    private function canUserManageClips(string $projectUuid): bool
    {
        $account = $_SESSION['account'] ?? null;
        $personUuid = $_SESSION['person_uuid'] ?? null;
        if (!$account) return false;
        if ((int)($account['is_superuser'] ?? 0)) return true;

        $pdo = \App\Support\DB::pdo();
        $stmt = $pdo->prepare("SELECT is_project_admin FROM project_members WHERE project_id = UUID_TO_BIN(:p, 1) AND person_id = UUID_TO_BIN(:person, 1) LIMIT 1");
        $stmt->execute([':p' => $projectUuid, ':person' => $personUuid]);
        return (bool)$stmt->fetchColumn();
    }

    public function batchThumbnails(string $projectUuid, string $dayUuid = 'all'): void
    {
        header('Content-Type: application/json');

        // Start session and check auth
        if (session_status() === \PHP_SESSION_NONE) {
            session_start();
        }

        $account = $_SESSION['account'] ?? null;
        if (!$account) {
            http_response_code(403);
            echo json_encode(['error' => 'Unauthorized']);
            exit;
        }

        // Get clip UUIDs from query string
        $clipUuidsParam = $_GET['clip_uuids'] ?? '';
        if (empty($clipUuidsParam)) {
            echo json_encode([]);
            exit;
        }

        // Parse comma-separated UUIDs and limit to 200 clips per request
        $clipUuids = array_filter(array_map('trim', explode(',', $clipUuidsParam)));

        if (empty($clipUuids) || count($clipUuids) > 200) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid clip_uuids parameter (max 200)']);
            exit;
        }

        $pdo = DB::pdo();
        $personUuid = $_SESSION['person_uuid'] ?? null;
        $isSuperuser = (int)($account['is_superuser'] ?? 0);

        // Check if user is project admin
        $isProjectAdmin = 0;
        if ($personUuid) {
            $stmtMember = $pdo->prepare("
            SELECT pm.is_project_admin
            FROM project_members pm
            WHERE pm.project_id = UUID_TO_BIN(:p, 1)
              AND pm.person_id  = UUID_TO_BIN(:person, 1)
            LIMIT 1
        ");
            $stmtMember->execute([':p' => $projectUuid, ':person' => $personUuid]);
            $isProjectAdmin = (int)($stmtMember->fetchColumn() ?: 0);
        }

        // Build visibility SQL (same logic as in index method)
        $visibilitySql = '';
        $params = [':project_uuid' => $projectUuid];

        if (!$isSuperuser && !$isProjectAdmin) {
            // Regular users: only see published days + unrestricted clips OR clips they have group access to
            // Using EXISTS to prevent duplication
            $visibilitySql = "
                AND d.published_at IS NOT NULL
                AND (
                    c.is_restricted = 0
                    OR EXISTS (
                        SELECT 1
                        FROM clip_sensitive_acl csa_sub
                        JOIN sensitive_group_members sgm_sub ON sgm_sub.group_id = csa_sub.group_id
                        WHERE csa_sub.clip_id = c.id
                          AND sgm_sub.person_id = UUID_TO_BIN(:viewer_person_uuid, 1)
                    )
                )
            ";

            $params[':viewer_person_uuid'] = $personUuid ?? '00000000-0000-0000-0000-000000000000';
        }

        // Build IN clause for clip UUIDs
        $placeholders = [];
        foreach ($clipUuids as $idx => $uuid) {
            $key = ":clip_uuid_{$idx}";
            $placeholders[] = "UUID_TO_BIN({$key}, 1)";
            $params[$key] = $uuid;
        }
        $inClause = implode(',', $placeholders);

        // Optional: filter by specific day if not "all"
        $dayFilter = '';
        if ($dayUuid !== 'all') {
            $dayFilter = " AND c.day_id = UUID_TO_BIN(:day_uuid, 1)";
            $params[':day_uuid'] = $dayUuid;
        }

        // Fetch thumbnails in a single query
        // Join with days table to enforce visibility rules
        $sql = "
        SELECT 
            BIN_TO_UUID(c.id, 1) AS clip_uuid,
            (
                SELECT a.storage_path
                FROM clip_assets a
                WHERE a.clip_id = c.id
                  AND a.asset_type = 'poster'
                ORDER BY a.created_at DESC
                LIMIT 1
            ) AS poster_path
        FROM clips c
        INNER JOIN days d ON d.id = c.day_id
        WHERE c.id IN ({$inClause})
          AND c.project_id = UUID_TO_BIN(:project_uuid, 1)
          {$dayFilter}
          {$visibilitySql}
        GROUP BY c.id
    ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Build response object
        $result = [];
        foreach ($rows as $row) {
            $result[$row['clip_uuid']] = $row['poster_path'] ?? null;
        }

        // Set cache headers (5 minutes)
        // ETags help with conditional requests
        $etag = md5(json_encode($result));
        header('Cache-Control: public, max-age=300');
        header('ETag: "' . $etag . '"');

        // Check if client has cached version
        $clientEtag = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
        if ($clientEtag === '"' . $etag . '"') {
            http_response_code(304); // Not Modified
            exit;
        }

        echo json_encode($result);
        exit;
    }
}
