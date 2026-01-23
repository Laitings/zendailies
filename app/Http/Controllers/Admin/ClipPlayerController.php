<?php

namespace App\Http\Controllers\Admin;

use App\Support\DB;
use App\Support\View;
use App\Support\Csrf;
use App\Services\StoragePaths;
use App\Services\FFmpegService;
use PDO;



final class ClipPlayerController
{
    private function fetchDaysWithThumbs(\PDO $pdo, string $projectUuid, string $dayVisibilitySql, string $aclSql, ?string $viewerParam): array
    {
        // All days for project (order newest first)
        $allDaysStmt = $pdo->prepare("
        SELECT 
            BIN_TO_UUID(d.id,1) AS day_uuid,
            d.shoot_date,
            d.title
        FROM days d
        WHERE d.project_id = UUID_TO_BIN(:p,1)
            {$dayVisibilitySql}
            ORDER BY d.shoot_date DESC, d.created_at DESC
        LIMIT 500
    ");

        $allDaysStmt->execute([':p' => $projectUuid]);
        $rawDays = $allDaysStmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        if (!$rawDays) return [];

        // For each day, pick first visible clip in that day, then newest poster
        $thumbSql = "
            SELECT 
                c.id AS clip_bin,
                (
                    SELECT a.storage_path
                    FROM clip_assets a
                    WHERE a.clip_id = c.id
                    AND a.asset_type = 'poster'
                    ORDER BY a.created_at DESC
                    LIMIT 1
                ) AS poster_path,
                (
                    SELECT CAST(REPLACE(cm.meta_value, ',', '.') AS DECIMAL(10,3))
                    FROM clip_metadata cm
                    WHERE cm.clip_id = c.id
                    AND cm.meta_key IN ('fps','FPS','Camera FPS','Frame Rate','FrameRate','CameraFPS')
                    ORDER BY FIELD(cm.meta_key,'fps','FPS','Camera FPS','Frame Rate','FrameRate','CameraFPS')
                    LIMIT 1
                ) AS clip_fps
            FROM clips c
            LEFT JOIN clip_sensitive_acl csa ON csa.clip_id = c.id
            LEFT JOIN sensitive_group_members sgm ON sgm.group_id = csa.group_id
            WHERE c.project_id = UUID_TO_BIN(:p,1)
            AND c.day_id     = UUID_TO_BIN(:d,1)
            $aclSql
            ORDER BY c.created_at ASC
            LIMIT 1
        ";

        $thumbStmt = $pdo->prepare($thumbSql);

        // Per-day stats (ACL-aware): clip count + total duration (ms)
        $statsSql = "
            SELECT
                COUNT(*)                          AS cnt,
                COALESCE(SUM(c.duration_ms), 0)   AS tot_ms
            FROM clips c
            LEFT JOIN clip_sensitive_acl csa ON csa.clip_id = c.id
            LEFT JOIN sensitive_group_members sgm ON sgm.group_id = csa.group_id
            WHERE c.project_id = UUID_TO_BIN(:p,1)
            AND c.day_id     = UUID_TO_BIN(:d,1)
            $aclSql
        ";
        $statsStmt = $pdo->prepare($statsSql);


        $daysOut = [];
        foreach ($rawDays as $rowDay) {
            $thumbStmt->bindValue(':p', $projectUuid);
            $thumbStmt->bindValue(':d', $rowDay['day_uuid']);
            if ($viewerParam !== null) {
                $thumbStmt->bindValue(':viewer_person_uuid', $viewerParam);
            }
            $thumbStmt->execute();
            $thumbRow = $thumbStmt->fetch(\PDO::FETCH_ASSOC);

            $statsStmt->bindValue(':p', $projectUuid);
            $statsStmt->bindValue(':d', $rowDay['day_uuid']);
            if ($viewerParam !== null) {
                $statsStmt->bindValue(':viewer_person_uuid', $viewerParam);
            }
            $statsStmt->execute();
            $stats = $statsStmt->fetch(\PDO::FETCH_ASSOC) ?: ['cnt' => 0, 'tot_ms' => 0];

            $totMs = (int)($stats['tot_ms'] ?? 0);

            // choose FPS for the day: first visible clip's FPS, else 25
            $dayFps = null;
            if (!empty($thumbRow) && isset($thumbRow['clip_fps']) && $thumbRow['clip_fps'] !== null) {
                $dayFps = (float)$thumbRow['clip_fps'];
            }
            if (!$dayFps || $dayFps <= 0) {
                $dayFps = 25.0;
            }

            // Convert totMs to frames using the chosen FPS.
            // Frame index FF is 0..(fpsInt-1), fpsInt is the nominal integer (23.976→24, 29.97→30).
            $fpsInt = (int)round($dayFps);
            if ($fpsInt < 1) $fpsInt = 25;

            $totalFrames   = (int)round(($totMs / 1000.0) * $dayFps);
            $frames        = $totalFrames % $fpsInt;
            $totalSeconds  = intdiv($totalFrames, $fpsInt);
            $ss            = $totalSeconds % 60;
            $totalMinutes  = intdiv($totalSeconds, 60);
            $mm            = $totalMinutes % 60;
            $hh            = intdiv($totalMinutes, 60);

            $hmsff = sprintf('%02d:%02d:%02d:%02d', $hh, $mm, $ss, $frames);



            $daysOut[] = [
                'day_uuid'    => $rowDay['day_uuid'],
                'title'       => $rowDay['title'] ?: $rowDay['shoot_date'],
                'shoot_date'  => $rowDay['shoot_date'],
                'thumb_url'   => $thumbRow['poster_path'] ?? null,
                'clip_count'  => (int)($stats['cnt'] ?? 0),
                'total_hms'   => $hmsff,            // now HH:MM:SS:FF
                // optionally expose which FPS we used, for debugging or future badges
                // 'day_fps_used' => $dayFps,
            ];
        }

        return $daysOut;
    }


    public function redirectToFirst(string $projectUuid, string $dayUuid): void
    {
        if (!$projectUuid || !$dayUuid) {
            http_response_code(400);
            echo "Bad request";
            return;
        }

        $pdo = DB::pdo();

        $stmt = $pdo->prepare("
        SELECT BIN_TO_UUID(c.id,1)
        FROM clips c
        WHERE c.project_id = UUID_TO_BIN(:p,1)
          AND c.day_id     = UUID_TO_BIN(:d,1)
        ORDER BY c.created_at ASC
        LIMIT 1
    ");
        $stmt->execute([':p' => $projectUuid, ':d' => $dayUuid]);
        $clipUuid = $stmt->fetchColumn();

        if ($clipUuid) {
            header("Location: /admin/projects/$projectUuid/days/$dayUuid/player/$clipUuid");
            exit;
        }

        // If no clips exist for that day
        header("Location: /admin/projects/$projectUuid/days/$dayUuid/clips");
        exit;
    }

    /**
     * Load comments for a clip and return them in a flattened tree order:
     * root comment, its replies, next root, etc.
     *
     * @return array<int,array<string,mixed>>
     */
    private function loadCommentsThreaded(PDO $pdo, string $clipUuid): array
    {
        $sql = "
            SELECT 
                BIN_TO_UUID(cm.id,1) AS comment_uuid,
                CASE 
                    WHEN cm.parent_id IS NULL THEN NULL 
                    ELSE BIN_TO_UUID(cm.parent_id,1) 
                END AS parent_uuid,
                cm.body,
                cm.start_tc,
                cm.created_at,
                BIN_TO_UUID(p.id,1) AS author_uuid,
                CONCAT(
                    COALESCE(p.first_name, ''),
                    CASE 
                        WHEN p.first_name IS NOT NULL AND p.last_name IS NOT NULL 
                        THEN ' ' 
                        ELSE '' 
                    END,
                    COALESCE(p.last_name, '')
                ) AS author_name
            FROM comments cm
            LEFT JOIN persons p ON p.id = cm.author_id
            WHERE cm.clip_id = UUID_TO_BIN(:c,1)
            ORDER BY cm.created_at ASC
            LIMIT 500
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([':c' => $clipUuid]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        if (!$rows) {
            return [];
        }

        $rootKey = '__root__';
        $childrenOf = [];

        foreach ($rows as $row) {
            $parentKey = $row['parent_uuid'] ?: $rootKey;
            if (!isset($childrenOf[$parentKey])) {
                $childrenOf[$parentKey] = [];
            }
            $childrenOf[$parentKey][] = $row;
        }

        $ordered = [];
        $this->flattenCommentsTree($childrenOf, $rootKey, 0, $ordered);

        return $ordered;
    }

    /**
     * Recursive helper to flatten a comment tree.
     *
     * @param array<string,array<int,array<string,mixed>>> $childrenOf
     * @param array<int,array<string,mixed>>               $out
     */
    private function flattenCommentsTree(array $childrenOf, string $parentKey, int $depth, array &$out): void
    {
        if (empty($childrenOf[$parentKey])) {
            return;
        }

        foreach ($childrenOf[$parentKey] as $row) {
            $row['depth'] = $depth;
            $row['is_reply'] = $depth > 0;
            $out[] = $row;
            $childKey = $row['comment_uuid'] ?? '';
            if ($childKey !== '') {
                $this->flattenCommentsTree($childrenOf, $childKey, $depth + 1, $out);
            }
        }
    }

    public function show(string $projectUuid, string $dayUuid, string $clipUuid): void
    {
        if (!$projectUuid || !$dayUuid || !$clipUuid) {
            http_response_code(400);
            echo "Bad request";
            return;
        }

        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $isMobile = preg_match('/(android|iphone|ipad|mobile)/i', $ua);
        if (session_status() === \PHP_SESSION_NONE) session_start();

        $account      = $_SESSION['account'] ?? [];
        $isSuperuser  = !empty($account['is_superuser']);
        $personUuid   = $_SESSION['person_uuid'] ?? null;
        $pdo = DB::pdo();

        // Guard: Day access
        $dayStmt = $pdo->prepare("SELECT id, published_at FROM days WHERE project_id = UUID_TO_BIN(:p, 1) AND id = UUID_TO_BIN(:d, 1) LIMIT 1");
        $dayStmt->execute([':p' => $projectUuid, ':d' => $dayUuid]);
        $dayRow = $dayStmt->fetch(\PDO::FETCH_ASSOC);
        if (!$dayRow) {
            http_response_code(404);
            echo 'Day not found';
            return;
        }

        // Project & Day Context
        $project = $pdo->prepare("SELECT BIN_TO_UUID(id,1) AS project_uuid, title, code FROM projects WHERE id = UUID_TO_BIN(:p,1) LIMIT 1");
        $project->execute([':p' => $projectUuid]);
        $project = $project->fetch(PDO::FETCH_ASSOC);

        $dayContext = $pdo->prepare("SELECT BIN_TO_UUID(id,1) AS day_uuid, shoot_date, title FROM days WHERE id = UUID_TO_BIN(:d,1) LIMIT 1");
        $dayContext->execute([':d' => $dayUuid]);
        $day = $dayContext->fetch(PDO::FETCH_ASSOC);

        // ACL Logic
        $isProjectAdmin = 0;
        if (!$isSuperuser && $personUuid) {
            $admStmt = $pdo->prepare("SELECT is_project_admin FROM project_members WHERE project_id = UUID_TO_BIN(:p,1) AND person_id = UUID_TO_BIN(:person,1) LIMIT 1");
            $admStmt->execute([':p' => $projectUuid, ':person' => $personUuid]);
            $isProjectAdmin = (int)($admStmt->fetchColumn() ?: 0);
        }

        $aclSql = '';
        $dayVisibilitySql = '';
        $viewerParam = null;
        if (!$isSuperuser && !$isProjectAdmin) {
            $aclSql = " AND (csa.group_id IS NULL OR sgm.person_id = UUID_TO_BIN(:viewer_person_uuid,1))";
            $viewerParam = ($personUuid ?? '00000000-0000-0000-0000-000000000000');
            $dayVisibilitySql = " AND d.published_at IS NOT NULL";
        }

        // --- GLOBAL SCENE SEARCH ---
        $targetScene = $_GET['scene'] ?? null;
        $clipListParams = [':p' => $projectUuid];
        $currentDayLabel = ($targetScene) ? "Scene " . $targetScene : ($day['title'] ?: $day['shoot_date']);

        $sceneFilterSql = $targetScene ? " AND c.scene = :sc " : " AND c.day_id = UUID_TO_BIN(:d,1) ";
        if ($targetScene) $clipListParams[':sc'] = $targetScene;
        else $clipListParams[':d'] = $dayUuid;
        if ($viewerParam !== null) $clipListParams[':viewer_person_uuid'] = $viewerParam;

        $clipListSql = "
            SELECT BIN_TO_UUID(c.id,1) AS clip_uuid, BIN_TO_UUID(c.day_id,1) AS day_uuid,
                c.scene, c.slate, c.take, c.take_int, c.camera, c.file_name,
                CAST(c.is_select AS UNSIGNED) AS is_select,
                (SELECT storage_path FROM clip_assets WHERE clip_id = c.id AND asset_type IN ('proxy_web', 'original') ORDER BY FIELD(asset_type, 'proxy_web', 'original') LIMIT 1) AS proxy_url,
                (SELECT COUNT(*) FROM comments cm WHERE cm.clip_id = c.id) AS comment_count,
                (SELECT storage_path FROM clip_assets a WHERE a.clip_id = c.id AND a.asset_type = 'poster' ORDER BY a.created_at DESC LIMIT 1) AS poster_path
            FROM clips c
            JOIN days d ON d.id = c.day_id
            LEFT JOIN clip_sensitive_acl csa ON csa.clip_id = c.id
            LEFT JOIN sensitive_group_members sgm ON sgm.group_id = csa.group_id
            WHERE c.project_id = UUID_TO_BIN(:p,1) $sceneFilterSql $aclSql $dayVisibilitySql
            ORDER BY c.scene, c.slate, c.take_int, c.camera LIMIT 500";

        $listStmt = $pdo->prepare($clipListSql);
        foreach ($clipListParams as $k => $v) $listStmt->bindValue($k, $v);
        $listStmt->execute();
        $clipList = $listStmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch Main Clip (Relaxed day constraint for scene browsing)
        $clipStmt = $pdo->prepare("SELECT BIN_TO_UUID(id,1) AS clip_uuid, BIN_TO_UUID(day_id,1) AS day_uuid, scene, slate, take, camera, reel, file_name, tc_start, tc_end, duration_ms, fps, rating, is_select FROM clips WHERE id = UUID_TO_BIN(:c,1) AND project_id = UUID_TO_BIN(:p,1) LIMIT 1");
        $clipStmt->execute([':p' => $projectUuid, ':c' => $clipUuid]);
        $clip = $clipStmt->fetch(PDO::FETCH_ASSOC);

        if (!$clip) {
            http_response_code(404);
            echo "Clip not found";
            return;
        }

        // Added 'waveform' to the asset type list
        $assets = $pdo->prepare("SELECT asset_type, storage_path FROM clip_assets WHERE clip_id=UUID_TO_BIN(:c,1) AND asset_type IN ('proxy_web','original','poster','waveform') ORDER BY created_at DESC");
        $assets->execute([':c' => $clipUuid]);
        $proxyUrl = null;
        $posterUrl = null;
        $waveformUrl = null;
        foreach ($assets->fetchAll() as $a) {
            if (!$proxyUrl && ($a['asset_type'] === 'proxy_web' || $a['asset_type'] === 'original')) $proxyUrl = $a['storage_path'];
            if (!$posterUrl && $a['asset_type'] === 'poster') $posterUrl = $a['storage_path'];
            if (!$waveformUrl && $a['asset_type'] === 'waveform') $waveformUrl = $a['storage_path'];
        }

        $metadata = $pdo->prepare("SELECT meta_key, meta_value FROM clip_metadata WHERE clip_id=UUID_TO_BIN(:c,1) ORDER BY meta_key");
        $metadata->execute([':c' => $clipUuid]);

        View::render($isMobile ? 'admin/player/show_mobile' : 'admin/player/show', [
            'layout' => $isMobile ? 'layout/mobile' : 'layout/main',
            'project' => $project,
            'day' => $day,
            'clip' => $clip,
            'proxy_url' => $proxyUrl,
            'poster_url' => $posterUrl,
            'waveform_url' => $waveformUrl,
            'metadata' => $metadata->fetchAll(),
            'comments' => $this->loadCommentsThreaded($pdo, $clipUuid),
            'project_uuid' => $projectUuid,
            'day_uuid' => $dayUuid,
            'clip_list' => $clipList,
            'current_clip' => $clipUuid,
            'current_day_label' => $currentDayLabel,
            'days' => $this->fetchDaysWithThumbs($pdo, $projectUuid, $dayVisibilitySql, $aclSql, $viewerParam),
            'scenes' => $this->fetchScenesWithThumbs($pdo, $projectUuid, $aclSql, $viewerParam),
            'placeholder_thumb_url' => '/assets/img/empty_day_placeholder.png'
        ]);
    }

    /**
     * Admin / DIT-only endpoint:
     * Grab a new poster from the exact time the player is paused at.
     *
     * Expects JSON body: { "seconds": 12.08 }
     * Returns JSON: { ok: true, poster_url: "..." } or error.
     */
    public function posterFromTime(string $projectUuid, string $dayUuid, string $clipUuid): void
    {
        if (session_status() === \PHP_SESSION_NONE) {
            session_start();
        }

        $account     = $_SESSION['account'] ?? [];
        $personUuid  = $_SESSION['person_uuid'] ?? null;
        $isSuperuser = !empty($account['is_superuser']);

        $pdo = DB::pdo();


        // --- Project-admin (DIT) check: same logic as elsewhere ---
        $isProjectAdmin = 0;
        if (!$isSuperuser && $personUuid) {
            $adminCheck = $pdo->prepare("
                SELECT COUNT(*) 
                FROM project_members pm
                JOIN projects p ON p.id = pm.project_id
                WHERE pm.person_id = UUID_TO_BIN(:person_uuid,1)
                  AND p.id         = UUID_TO_BIN(:proj_uuid,1)
                  AND pm.role IN ('owner','admin','dit')
                  AND pm.is_active = 1
            ");
            $adminCheck->execute([
                ':person_uuid' => $personUuid,
                ':proj_uuid'   => $projectUuid,
            ]);
            $isProjectAdmin = (int)$adminCheck->fetchColumn();
        }

        if (!$isSuperuser && !$isProjectAdmin) {
            http_response_code(403);
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=utf-8');
            }
            echo json_encode(['ok' => false, 'error' => 'Forbidden']);
            return;
        }

        // --- Read JSON body: { "seconds": 12.08 } ---
        $raw = file_get_contents('php://input') ?: '';
        $body = json_decode($raw, true) ?: [];
        $seconds = isset($body['seconds']) ? (float)$body['seconds'] : null;

        if ($seconds === null || !is_finite($seconds) || $seconds < 0) {
            http_response_code(400);
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=utf-8');
            }
            echo json_encode(['ok' => false, 'error' => 'Missing or invalid seconds']);
            return;
        }

        // --- Find the original asset for this exact project/day/clip ---
        $assetStmt = $pdo->prepare("
            SELECT ca.storage_path
            FROM clips c
            JOIN clip_assets ca ON ca.clip_id = c.id
            WHERE c.id         = UUID_TO_BIN(:clip_uuid,1)
              AND c.project_id = UUID_TO_BIN(:proj_uuid,1)
              AND c.day_id     = UUID_TO_BIN(:day_uuid,1)
              AND ca.asset_type = 'original'
            ORDER BY ca.created_at DESC
            LIMIT 1
        ");
        $assetStmt->execute([
            ':clip_uuid' => $clipUuid,
            ':proj_uuid' => $projectUuid,
            ':day_uuid'  => $dayUuid,
        ]);
        $asset = $assetStmt->fetch(PDO::FETCH_ASSOC);

        if (!$asset || empty($asset['storage_path'])) {
            http_response_code(404);
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=utf-8');
            }
            echo json_encode(['ok' => false, 'error' => 'Original asset not found']);
            return;
        }

        $publicBase = rtrim(getenv('ZEN_STOR_PUBLIC_BASE') ?: '/data/zendailies/uploads', '/');
        $fsBase     = rtrim(getenv('ZEN_STOR_DIR') ?: '/var/www/html/data/zendailies/uploads', '/');

        $sourcePublic = $asset['storage_path']; // e.g. /data/zendailies/uploads/...
        $sourceAbs    = $sourcePublic;

        // If storage_path is a public URL, map it back to a filesystem path
        if (strpos($sourcePublic, $publicBase . '/') === 0) {
            $suffix    = substr($sourcePublic, strlen($publicBase));
            $sourceAbs = $fsBase . $suffix;
        }

        // --- Derive poster paths next to the original (same pattern as ClipUploadController) ---
        $posterAbsPath = preg_replace('/\.(mp4|mov|mxf)$/i', '', $sourceAbs) . '.poster.jpg';
        if ($posterAbsPath === $sourceAbs) {
            $posterAbsPath = $sourceAbs . '.poster.jpg';
        }

        $posterPublicPath = preg_replace('/\.(mp4|mov|mxf)$/i', '', $sourcePublic) . '.poster.jpg';
        if ($posterPublicPath === $sourcePublic) {
            $posterPublicPath = $sourcePublic . '.poster.jpg';
        }

        // --- Generate poster at EXACT time ---
        $ff = new FFmpegService();

        $res = $ff->generatePoster($sourceAbs, $posterAbsPath, $seconds, 640);

        if (!($res['ok'] ?? false)) {
            http_response_code(500);
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=utf-8');
            }
            echo json_encode([
                'ok'    => false,
                'error' => $res['err'] ?? 'Failed to generate poster',
            ]);
            return;
        }

        // --- Stat the new poster (size + dimensions) ---
        $bytes   = @filesize($posterAbsPath) ?: null;
        $width   = null;
        $height  = null;
        $imgInfo = @getimagesize($posterAbsPath);
        if (\is_array($imgInfo)) {
            $width  = isset($imgInfo[0]) ? (int)$imgInfo[0] : null;
            $height = isset($imgInfo[1]) ? (int)$imgInfo[1] : null;
        }

        // --- Upsert into clip_assets as asset_type='poster' ---
        $up = $pdo->prepare("
            INSERT INTO clip_assets (clip_id, asset_type, storage_path, byte_size, width, height, codec)
            VALUES (UUID_TO_BIN(:clip_uuid,1), 'poster', :path, :size, :w, :h, 'jpg')
            ON DUPLICATE KEY UPDATE
                storage_path = VALUES(storage_path),
                byte_size    = VALUES(byte_size),
                width        = VALUES(width),
                height       = VALUES(height),
                codec        = VALUES(codec)
        ");

        $up->execute([
            ':clip_uuid' => $clipUuid,
            ':path'      => $posterPublicPath,
            ':size'      => $bytes,
            ':w'         => $width,
            ':h'         => $height,
        ]);

        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }

        echo json_encode([
            'ok'         => true,
            'poster_url' => $posterPublicPath,
        ]);
    }



    public function overview(string $projectUuid): void
    {
        if (session_status() === \PHP_SESSION_NONE) session_start();
        $pdo = \App\Support\DB::pdo();

        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $isMobile = (bool)preg_match('/(android|iphone|ipad|mobile)/i', $ua);

        // Session context (same as show)
        $account     = $_SESSION['account'] ?? [];
        $isSuperuser = !empty($account['is_superuser']);
        $personUuid  = $_SESSION['person_uuid'] ?? null;

        // Project
        $projStmt = $pdo->prepare("
        SELECT BIN_TO_UUID(p.id,1) AS project_uuid, p.title, p.code, p.status
        FROM projects p
        WHERE p.id = UUID_TO_BIN(:p,1)
        LIMIT 1
    ");
        $projStmt->execute([':p' => $projectUuid]);
        $project = $projStmt->fetch(\PDO::FETCH_ASSOC);
        if (!$project) {
            http_response_code(404);
            echo 'Project not found';
            return;
        }

        // Project-admin check (same pattern as show)
        $isProjectAdmin = 0;
        if (!$isSuperuser && $personUuid) {
            $admStmt = $pdo->prepare("
            SELECT pm.is_project_admin
            FROM project_members pm
            WHERE pm.project_id = UUID_TO_BIN(:p,1)
              AND pm.person_id  = UUID_TO_BIN(:person,1)
            LIMIT 1
        ");
            $admStmt->execute([':p' => $projectUuid, ':person' => $personUuid]);
            $isProjectAdmin = (int)($admStmt->fetchColumn() ?: 0);
        }

        // ACL + visibility fragments (same idea as show)
        $aclSql           = '';
        $dayVisibilitySql = '';
        $viewerParam      = null;

        if (!$isSuperuser && !$isProjectAdmin) {
            $aclSql = " AND (csa.group_id IS NULL OR sgm.person_id = UUID_TO_BIN(:viewer_person_uuid,1))";
            $viewerParam = ($personUuid ?? '00000000-0000-0000-0000-000000000000');

            // Regular users should only see published days in overview too
            $dayVisibilitySql = " AND d.published_at IS NOT NULL";
        }

        // --- If scene=... is present on overview, jump into the real player route (day+clip) ---
        $targetScene = isset($_GET['scene']) ? trim((string)$_GET['scene']) : '';
        if ($targetScene !== '') {

            // Respect the same visibility rules as lists (published-only for regular users)
            // and the same sensitive ACL rules.
            $sceneJumpSql = "
                SELECT
                    BIN_TO_UUID(c.id,1)     AS clip_uuid,
                    BIN_TO_UUID(c.day_id,1) AS day_uuid
                FROM clips c
                JOIN days d ON d.id = c.day_id
                LEFT JOIN clip_sensitive_acl csa ON csa.clip_id = c.id
                LEFT JOIN sensitive_group_members sgm ON sgm.group_id = csa.group_id
                WHERE c.project_id = UUID_TO_BIN(:p,1)
                AND c.scene = :sc
                $aclSql
                $dayVisibilitySql
                ORDER BY c.created_at ASC
                LIMIT 1
            ";

            $st = $pdo->prepare($sceneJumpSql);
            $st->bindValue(':p', $projectUuid);
            $st->bindValue(':sc', $targetScene);
            if ($viewerParam !== null) {
                $st->bindValue(':viewer_person_uuid', $viewerParam);
            }
            $st->execute();
            $hit = $st->fetch(\PDO::FETCH_ASSOC);

            if ($hit && !empty($hit['day_uuid']) && !empty($hit['clip_uuid'])) {
                $dest = "/admin/projects/" . rawurlencode($projectUuid)
                    . "/days/" . rawurlencode($hit['day_uuid'])
                    . "/player/" . rawurlencode($hit['clip_uuid'])
                    . "?scene=" . rawurlencode($targetScene)
                    . "&pane=clips";



                header("Location: " . $dest, true, 302);
                exit;
            }

            // If the scene exists but user can't see any clips in it, fall through to overview render
            // (you can optionally set a flash message here later).
        }


        // Build the same days list with thumbs via helper
        $daysOut = $this->fetchDaysWithThumbs($pdo, $projectUuid, $dayVisibilitySql, $aclSql, $viewerParam);

        $scenesOut = $this->fetchScenesWithThumbs($pdo, $projectUuid, $aclSql, $viewerParam);

        // Detect mobile environment
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $isMobile = preg_match('/(android|iphone|ipad|mobile)/i', $ua);

        $viewName = $isMobile ? 'admin/days/index_mobile' : 'admin/player/show';
        $layout   = $isMobile ? 'layout/mobile' : 'layout/main';

        \App\Support\View::render($viewName, [
            'layout'                => $layout,
            'project'               => $project,
            'day'                   => null,
            'clip'                  => null,
            'proxy_url'             => null,
            'poster_url'            => null,
            'metadata'              => [],
            'comments'              => [],
            'project_uuid'          => $projectUuid,
            'day_uuid'              => null,
            'clip_list'             => [],
            'current_clip'          => null,
            'current_day_label'     => 'Days',
            'days'                  => $daysOut,
            'scenes'                => $scenesOut,
            'placeholder_thumb_url' => '/assets/img/empty_day_placeholder.png',
        ]);
    }

    // Insert after fetchDaysWithThumbs around line 133
    public function fetchScenesWithThumbs(PDO $pdo, string $projectUuid, string $aclSql, ?string $viewerParam): array
    {
        $sql = "
            SELECT 
                c.scene,
                COUNT(*) as clip_count,
                SUM(c.duration_ms) as tot_ms,
                (
                    SELECT a.storage_path
                    FROM clip_assets a
                    INNER JOIN clips c3 ON a.clip_id = c3.id
                    WHERE c3.project_id = UUID_TO_BIN(:p_sub, 1)
                      AND c3.scene = c.scene
                      AND a.asset_type = 'poster'
                    ORDER BY c3.created_at ASC, a.created_at DESC
                    LIMIT 1
                ) AS thumb_url
            FROM clips c
            LEFT JOIN clip_sensitive_acl csa ON csa.clip_id = c.id
            LEFT JOIN sensitive_group_members sgm ON sgm.group_id = csa.group_id
            WHERE c.project_id = UUID_TO_BIN(:p_main, 1)
              AND c.scene IS NOT NULL AND c.scene != ''
              $aclSql
            GROUP BY c.scene
            ORDER BY 
                CASE WHEN c.scene REGEXP '^[0-9]+$' THEN CAST(c.scene AS UNSIGNED) ELSE 999999 END ASC, 
                c.scene ASC
        ";

        $st = $pdo->prepare($sql);
        $st->bindValue(':p_sub', $projectUuid);
        $st->bindValue(':p_main', $projectUuid);
        if ($viewerParam) {
            $st->bindValue(':viewer_person_uuid', $viewerParam);
        }
        $st->execute();

        $scenes = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $ms = (int)$row['tot_ms'];
            $ss = intdiv($ms, 1000);
            $mm = intdiv($ss, 60);
            $hh = intdiv($mm, 60);
            $hms = sprintf('%02d:%02d:%02d', $hh, $mm % 60, $ss % 60);

            $scenes[] = [
                'scene'      => $row['scene'],
                'thumb_url'  => $row['thumb_url'],
                'clip_count' => (int)$row['clip_count'],
                'total_hms'  => $hms
            ];
        }
        return $scenes;
    }

    /**
     * Securely streams a video file to the player without exposing the .mp4 path.
     * Uses byte-range support for seeking and mirrors the proven Zengrabber logic.
     */

    public function stream(string $projectUuid, string $clipUuid): void
    {
        // Use your app's official session starter instead of raw session_start()
        \App\Support\Auth::startSession();

        // Use your app's Auth::check() to be consistent with AuthGuard
        if (!\App\Support\Auth::check()) {
            header("HTTP/1.1 403 Forbidden");
            exit;
        }

        // 1. Authentication Guard (match the rest of the app's session shape)
        $account = $_SESSION['account'] ?? null;
        $accountId = is_array($account) ? ($account['id'] ?? null) : null;

        if (!$accountId) {
            header("HTTP/1.1 403 Forbidden");
            exit;
        }

        $pdo = \App\Support\DB::pdo();
        $personUuid = $_SESSION['person_uuid'] ?? null;
        $account = $_SESSION['account'] ?? [];
        $isSuperuser = !empty($account['is_superuser']);

        // 2. Fetch Project Admin status
        $isProjectAdmin = 0;
        if (!$isSuperuser && $personUuid) {
            $admStmt = $pdo->prepare("
                SELECT is_project_admin 
                FROM project_members 
                WHERE project_id = UUID_TO_BIN(:p, 1) 
                  AND person_id = UUID_TO_BIN(:person, 1) 
                LIMIT 1
            ");
            $admStmt->execute([':p' => $projectUuid, ':person' => $personUuid]);
            $isProjectAdmin = (int)($admStmt->fetchColumn() ?: 0);
        }

        // 3. Sensitive Groups ACL Logic
        $aclSql = '';
        $params = [':clip_uuid' => $clipUuid, ':project_uuid' => $projectUuid];

        if (!$isSuperuser && !$isProjectAdmin) {
            $aclSql = " AND (csa.group_id IS NULL OR sgm.person_id = UUID_TO_BIN(:viewer_person_uuid, 1))";
            $params[':viewer_person_uuid'] = ($personUuid ?? '00000000-0000-0000-0000-000000000000');
        }

        // 4. Locate the Asset
        $sql = "
            SELECT ca.storage_path 
            FROM clip_assets ca
            JOIN clips c ON c.id = ca.clip_id
            LEFT JOIN clip_sensitive_acl csa ON csa.clip_id = c.id
            LEFT JOIN sensitive_group_members sgm ON sgm.group_id = csa.group_id
            WHERE c.id = UUID_TO_BIN(:clip_uuid, 1) 
              AND c.project_id = UUID_TO_BIN(:project_uuid, 1)
              AND ca.asset_type IN ('proxy_web', 'original')
              $aclSql
            ORDER BY FIELD(ca.asset_type, 'proxy_web', 'original') 
            LIMIT 1";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $asset = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$asset) {
            header("HTTP/1.1 403 Forbidden");
            exit;
        }

        // 5. Map path to Docker container filesystem
        $fsBase = rtrim(getenv('ZEN_STOR_DIR') ?: '/var/www/html/data/zendailies/uploads', '/');
        $publicBase = rtrim(getenv('ZEN_STOR_PUBLIC_BASE') ?: '/data/zendailies/uploads', '/');
        $filePath = str_replace($publicBase, $fsBase, $asset['storage_path']);


        if (!file_exists($filePath)) {
            header("HTTP/1.1 404 Not Found");
            exit;
        }

        // 6. Byte-Range Streaming (Zengrabber Style)
        $size = filesize($filePath);
        $fp   = fopen($filePath, 'rb');
        $start  = 0;
        $length = $size;

        header('Content-Type: video/mp4');
        header('Accept-Ranges: bytes');
        header('X-Content-Type-Options: nosniff');

        if (isset($_SERVER['HTTP_RANGE'])) {
            if (preg_match('/bytes=(\d*)-(\d*)/', $_SERVER['HTTP_RANGE'], $m)) {
                $rangeStart = $m[1] === '' ? 0 : (int)$m[1];
                $rangeEnd   = $m[2] === '' ? $size - 1 : (int)$m[2];

                if ($rangeStart > $rangeEnd || $rangeStart >= $size) {
                    header('HTTP/1.1 416 Range Not Satisfiable');
                    header('Content-Range: bytes */' . $size);
                    fclose($fp);
                    exit;
                }
                if ($rangeEnd >= $size) $rangeEnd = $size - 1;

                $start  = $rangeStart;
                $length = $rangeEnd - $rangeStart + 1;

                header('HTTP/1.1 206 Partial Content');
                header("Content-Range: bytes $rangeStart-$rangeEnd/$size");
            }
        }

        header('Content-Length: ' . $length);
        fseek($fp, $start);
        $chunkSize = 8192;

        while (!feof($fp) && $length > 0) {
            $readSize = ($length > $chunkSize) ? $chunkSize : $length;
            $buffer   = fread($fp, $readSize);
            echo $buffer;
            flush();
            $length -= $readSize;
        }

        fclose($fp);
        exit;
    }
}
