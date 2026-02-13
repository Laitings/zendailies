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
        $dayAclSql = '';
        if ($viewerParam !== null) {
            $dayAclSql = "
                AND (
                    NOT EXISTS (
                        SELECT 1
                        FROM day_sensitive_acl dsa_chk
                        WHERE dsa_chk.day_id = d.id
                    )
                    OR EXISTS (
                        SELECT 1
                        FROM day_sensitive_acl dsa_access
                        JOIN sensitive_group_members sgm_access ON sgm_access.group_id = dsa_access.group_id
                        WHERE dsa_access.day_id = d.id
                          AND sgm_access.person_id = UUID_TO_BIN(:viewer_uuid_day, 1)
                    )
                )
            ";
        }

        // 1. Prepare the Days Query (includes the security check)
        $allDaysStmt = $pdo->prepare("
            SELECT 
                BIN_TO_UUID(d.id,1) AS day_uuid,
                d.shoot_date,
                d.title
            FROM days d
            WHERE d.project_id = UUID_TO_BIN(:p,1)
            {$dayVisibilitySql}
            {$dayAclSql}
            ORDER BY d.shoot_date DESC, d.created_at DESC
            LIMIT 500
        ");

        // 2. Bind Parameters (include viewer UUID for day-level ACL checks)
        $dayParams = [':p' => $projectUuid];
        if ($viewerParam !== null) {
            $dayParams[':viewer_uuid_day'] = $viewerParam;
        }

        $allDaysStmt->execute($dayParams);
        $rawDays = $allDaysStmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        if (!$rawDays) return [];

        $clipAclSql = '';
        if ($viewerParam !== null) {
            $clipAclSql = "
            AND (
                -- Day Gate
                (
                    NOT EXISTS (SELECT 1 FROM day_sensitive_acl dsa WHERE dsa.day_id = c.day_id)
                    OR EXISTS (
                        SELECT 1 FROM day_sensitive_acl dsa
                        JOIN sensitive_group_members sgm ON sgm.group_id = dsa.group_id
                        WHERE dsa.day_id = c.day_id AND sgm.person_id = UUID_TO_BIN(:viewer_uuid_day, 1)
                    )
                )
                AND
                -- Clip Gate
                (csa.group_id IS NULL OR sgm.person_id = UUID_TO_BIN(:viewer_uuid_clip, 1))
            )
            ";
        }

        /**
         * FIXED QUERY: 
         * Uses nested subquery to first filter authorized clips with GROUP BY,
         * then fetches poster only from those authorized clips.
         */
        $thumbSql = "
        SELECT 
            authorized_clip.clip_id AS clip_bin,
            (
                SELECT a.storage_path
                FROM clip_assets a
                WHERE a.clip_id = authorized_clip.clip_id
                AND a.asset_type = 'poster'
                ORDER BY a.created_at DESC
                LIMIT 1
            ) AS poster_path,
            (
                SELECT CAST(REPLACE(cm.meta_value, ',', '.') AS DECIMAL(10,3))
                FROM clip_metadata cm
                WHERE cm.clip_id = authorized_clip.clip_id
                AND cm.meta_key IN ('fps','FPS','Camera FPS','Frame Rate','FrameRate','CameraFPS')
                ORDER BY FIELD(cm.meta_key,'fps','FPS','Camera FPS','Frame Rate','FrameRate','CameraFPS')
                LIMIT 1
            ) AS clip_fps
        FROM (
            SELECT c.id AS clip_id
            FROM clips c
            LEFT JOIN clip_sensitive_acl csa ON csa.clip_id = c.id
            LEFT JOIN sensitive_group_members sgm ON sgm.group_id = csa.group_id
            WHERE c.project_id = UUID_TO_BIN(:p,1)
            AND c.day_id = UUID_TO_BIN(:d,1)
            {$clipAclSql}
            ORDER BY c.created_at ASC
            LIMIT 1
        ) AS authorized_clip
        ";

        $thumbStmt = $pdo->prepare($thumbSql);

        // Per-day stats (ACL-aware)
        $statsSql = "
        SELECT
            COUNT(*)                          AS cnt,
            COALESCE(SUM(duration_ms), 0)   AS tot_ms
        FROM (
            SELECT c.id, c.duration_ms
            FROM clips c
            LEFT JOIN clip_sensitive_acl csa ON csa.clip_id = c.id
            LEFT JOIN sensitive_group_members sgm ON sgm.group_id = csa.group_id
            WHERE c.project_id = UUID_TO_BIN(:p,1)
            AND c.day_id     = UUID_TO_BIN(:d,1)
            {$clipAclSql}

            GROUP BY c.id
        ) AS c
        ";
        $statsStmt = $pdo->prepare($statsSql);

        $daysOut = [];
        foreach ($rawDays as $rowDay) {
            // Bind Thumb Query
            $thumbStmt->bindValue(':p', $projectUuid);
            $thumbStmt->bindValue(':d', $rowDay['day_uuid']);
            if ($viewerParam !== null) {
                $thumbStmt->bindValue(':viewer_uuid_day', $viewerParam);
                $thumbStmt->bindValue(':viewer_uuid_clip', $viewerParam);
            }
            $thumbStmt->execute();
            $thumbRow = $thumbStmt->fetch(\PDO::FETCH_ASSOC);

            // Bind Stats Query
            $statsStmt->bindValue(':p', $projectUuid);
            $statsStmt->bindValue(':d', $rowDay['day_uuid']);
            if ($viewerParam !== null) {
                $statsStmt->bindValue(':viewer_uuid_day', $viewerParam);
                $statsStmt->bindValue(':viewer_uuid_clip', $viewerParam);
            }
            $statsStmt->execute();
            $stats = $statsStmt->fetch(\PDO::FETCH_ASSOC) ?: ['cnt' => 0, 'tot_ms' => 0];

            $totMs = (int)($stats['tot_ms'] ?? 0);

            // FPS / Duration Logic
            $dayFps = null;
            if (!empty($thumbRow) && isset($thumbRow['clip_fps']) && $thumbRow['clip_fps'] !== null) {
                $dayFps = (float)$thumbRow['clip_fps'];
            }
            if (!$dayFps || $dayFps <= 0) {
                $dayFps = 25.0;
            }

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
                'total_hms'   => $hmsff,
            ];
        }

        return $daysOut;
    }

    /**
     * Validate whether a user has access to view a specific clip
     * 
     * @param \PDO $pdo Database connection
     * @param string $clipUuid UUID of the clip to check
     * @param string|null $personUuid UUID of the person requesting access
     * @param bool $isSuperuser Whether the user is a superuser
     * @param bool $isProjectAdmin Whether the user is a project admin
     * @return bool True if user has access, false otherwise
     */
    /**
     * Validate whether a user has access to view a specific clip
     * Checks both DAY-level and CLIP-level permissions.
     */
    /**
     * Validate whether a user has access to view a specific clip
     */
    private function validateClipAccess(\PDO $pdo, string $clipUuid, ?string $personUuid, bool $isSuperuser, bool $isProjectAdmin): bool
    {
        if ($isSuperuser || $isProjectAdmin) {
            return true;
        }

        $stmt = $pdo->prepare("
            SELECT 
                CASE 
                    -- 1. DAY CHECK: Fail if Day is restricted and user is NOT in the group
                    WHEN EXISTS (SELECT 1 FROM day_sensitive_acl dsa WHERE dsa.day_id = c.day_id)
                         AND NOT EXISTS (
                            SELECT 1 
                            FROM day_sensitive_acl dsa
                            JOIN sensitive_group_members sgm ON sgm.group_id = dsa.group_id
                            WHERE dsa.day_id = c.day_id
                              AND sgm.person_id = UUID_TO_BIN(:viewer_uuid_day, 1)
                         ) THEN 0

                    -- 2. CLIP CHECK: Fail if Clip is restricted and user is NOT in the group
                    WHEN c.is_restricted = 1
                         AND NOT EXISTS (
                            SELECT 1
                            FROM clip_sensitive_acl csa_sub
                            JOIN sensitive_group_members sgm_sub ON sgm_sub.group_id = csa_sub.group_id
                            WHERE csa_sub.clip_id = c.id
                              AND sgm_sub.person_id = UUID_TO_BIN(:viewer_uuid_clip, 1)
                        ) THEN 0
                    
                    -- 3. Otherwise, Access Granted
                    ELSE 1
                END AS has_access
            FROM clips c
            WHERE c.id = UUID_TO_BIN(:c, 1)
            LIMIT 1
        ");

        $stmt->execute([
            ':c' => $clipUuid,
            ':viewer_uuid_day'  => ($personUuid ?? '00000000-0000-0000-0000-000000000000'),
            ':viewer_uuid_clip' => ($personUuid ?? '00000000-0000-0000-0000-000000000000')
        ]);

        return (bool)($stmt->fetchColumn() ?: 0);
    }


    public function redirectToFirst(string $projectUuid, string $dayUuid): void
    {
        if (!$projectUuid || !$dayUuid) {
            http_response_code(400);
            echo "Bad request";
            return;
        }

        if (session_status() === \PHP_SESSION_NONE) session_start();
        $account     = $_SESSION['account'] ?? [];
        $personUuid  = $_SESSION['person_uuid'] ?? null;
        $isSuperuser = !empty($account['is_superuser']); //

        $pdo = DB::pdo();

        // 1. Check if user is a Project Admin (DIT) to determine ACL strictness
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

        // 2. Build ACL Fragment
        $aclSql = '';
        $params = [':p' => $projectUuid, ':d' => $dayUuid];

        if (!$isSuperuser && !$isProjectAdmin) {
            // Only allow clips that aren't restricted OR clips where the user is in the group
            $aclSql = " AND (csa.group_id IS NULL OR sgm.person_id = UUID_TO_BIN(:viewer_person_uuid, 1))";
            $params[':viewer_person_uuid'] = ($personUuid ?? '00000000-0000-0000-0000-000000000000');
        }

        // 3. Fetch the first AUTHORIZED clip
        $stmt = $pdo->prepare("
        SELECT BIN_TO_UUID(c.id, 1)
        FROM clips c
        LEFT JOIN clip_sensitive_acl csa ON csa.clip_id = c.id
        LEFT JOIN sensitive_group_members sgm ON sgm.group_id = csa.group_id
        WHERE c.project_id = UUID_TO_BIN(:p, 1)
          AND c.day_id     = UUID_TO_BIN(:d, 1)
          {$aclSql}
        GROUP BY c.id
        ORDER BY c.created_at ASC
        LIMIT 1
    ");
        $stmt->execute($params);
        $clipUuid = $stmt->fetchColumn();

        if ($clipUuid) {
            header("Location: /admin/projects/$projectUuid/days/$dayUuid/player/$clipUuid");
            exit;
        }

        // If no authorized clips exist for that day, send to the list view (which also filters)
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
        $projectStmt = $pdo->prepare("
            SELECT 
                BIN_TO_UUID(id,1) AS project_uuid, 
                title, 
                code, 
                default_aspect_ratio
            FROM projects 
            WHERE id = UUID_TO_BIN(:p,1) 
            LIMIT 1
        ");
        $projectStmt->execute([':p' => $projectUuid]);
        $project = $projectStmt->fetch(PDO::FETCH_ASSOC);

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

        // Bind ACL placeholder used by $aclSql in this query
        if ($viewerParam !== null) {
            $clipListParams[':viewer_person_uuid'] = $viewerParam;
        }

        $clipListSql = "
            SELECT BIN_TO_UUID(c.id,1) AS clip_uuid, BIN_TO_UUID(c.day_id,1) AS day_uuid,
                c.scene, c.slate, c.take, c.take_int, c.camera, c.file_name,
                CAST(c.is_select AS UNSIGNED) AS is_select,
                (SELECT storage_path FROM clip_assets WHERE clip_id = c.id AND asset_type IN ('proxy_web', 'original') ORDER BY FIELD(asset_type, 'proxy_web', 'original') LIMIT 1) AS proxy_url,
                (SELECT COUNT(*) FROM comments cm WHERE cm.clip_id = c.id) AS comment_count,
                (SELECT storage_path FROM clip_assets a WHERE a.clip_id = c.id AND a.asset_type = 'poster' ORDER BY a.created_at DESC LIMIT 1) AS poster_path,
                CASE WHEN csa.group_id IS NOT NULL THEN 1 ELSE 0 END AS is_restricted
            FROM clips c
            JOIN days d ON d.id = c.day_id
            LEFT JOIN clip_sensitive_acl csa ON csa.clip_id = c.id
            LEFT JOIN sensitive_group_members sgm ON sgm.group_id = csa.group_id
            WHERE c.project_id = UUID_TO_BIN(:p,1) $sceneFilterSql $aclSql $dayVisibilitySql
            GROUP BY c.id, c.day_id, c.scene, c.slate, c.take, c.take_int, c.camera, c.file_name, c.is_select, csa.group_id
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

        // ========== CRITICAL SECURITY CHECK ==========
        // Validate user has access to this clip before showing ANY data
        if (!$this->validateClipAccess($pdo, $clipUuid, $personUuid, $isSuperuser, $isProjectAdmin)) {
            http_response_code(403);
            // Redirect to clips list - user will see clips they CAN access
            $this->redirectToFirst($projectUuid, $dayUuid);
            return;
            exit;
        }
        // ========== END SECURITY CHECK ==========

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

        // --- 1. Validate Poster Existence ---
        $hasValidPoster = false;
        if ($posterUrl) {
            // Map to local file system to check existence
            $fsBase = rtrim(getenv('ZEN_STOR_DIR') ?: '/var/www/html/data', '/');
            $publicBase = rtrim(getenv('ZEN_STOR_PUBLIC_BASE') ?: '/data', '/');

            $checkPath = $posterUrl;
            if (strpos($posterUrl, $publicBase) === 0) {
                $checkPath = $fsBase . substr($posterUrl, strlen($publicBase));
            }

            if (file_exists($checkPath)) {
                $hasValidPoster = true;
            }
        }

        // --- 2. Apply Placeholder if Missing ---
        if (!$hasValidPoster) {
            $posterUrl = '/assets/img/poster_placeholder.svg';
        }

        $metadata = $pdo->prepare("SELECT meta_key, meta_value FROM clip_metadata WHERE clip_id=UUID_TO_BIN(:c,1) ORDER BY meta_key");
        $metadata->execute([':c' => $clipUuid]);

        /**
         * AJAX MODIFICATION FOR ClipPlayerController::show()
         * 
         * Add this code to your existing show() method, right after line 382 
         * (after $metadata->execute([':c' => $clipUuid]);)
         * and BEFORE the View::render() call.
         */

        // ========== START OF AJAX MODIFICATION ==========

        // --- INSERT THIS BEFORE THE $isAjax CHECK ---
        $scene  = trim((string)($clip['scene'] ?? ''));
        $slate  = trim((string)($clip['slate'] ?? ''));
        $take   = trim((string)($clip['take']  ?? ''));
        $camera = trim((string)($clip['camera'] ?? ''));

        $sceneLine = '';
        if ($scene !== '' && $slate !== '') {
            $sceneLine = "Sc. {$scene}/{$slate}";
        } elseif ($scene !== '') {
            $sceneLine = "Sc. {$scene}";
        } elseif ($slate !== '') {
            $sceneLine = "Sc. {$slate}";
        }
        if ($take !== '') {
            $sceneLine .= ($sceneLine ? '-' : 'Sc. ') . $take;
        }
        if ($camera !== '') {
            $sceneLine .= " · Cam {$camera}";
        }
        $fileNameBase = preg_replace('/\.[^.]+$/', '', $clip['file_name'] ?? '');
        // --- END OF INSERTION ---

        // Calculate if current clip is sensitive (needed for both AJAX and regular responses)
        $clipIsSensitive = false;
        if ($isSuperuser || $isProjectAdmin) {
            $checkSens = $pdo->prepare("SELECT COUNT(*) FROM clip_sensitive_acl WHERE clip_id = UUID_TO_BIN(:c, 1)");
            $checkSens->execute([':c' => $clipUuid]);
            $clipIsSensitive = $checkSens->fetchColumn() > 0;
        } elseif ($personUuid) {
            $checkSens = $pdo->prepare("
            SELECT COUNT(*) 
            FROM clip_sensitive_acl csa
            JOIN sensitive_group_members sgm ON sgm.group_id = csa.group_id
            WHERE csa.clip_id = UUID_TO_BIN(:c, 1)
            AND sgm.person_id = UUID_TO_BIN(:p, 1)
        ");
            $checkSens->execute([':c' => $clipUuid, ':p' => $personUuid]);
            $clipIsSensitive = $checkSens->fetchColumn() > 0;
        }

        // Check if this is an Ajax request from desktop player
        $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) &&
            strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

        // If it's an Ajax request and not mobile, return JSON instead of HTML
        if ($isAjax) {
            // TEMPORARY TEST - Check if clip has sensitive ACL
            $testQuery = $pdo->prepare("SELECT COUNT(*) FROM clip_sensitive_acl WHERE clip_id = UUID_TO_BIN(:c, 1)");
            $testQuery->execute([':c' => $clipUuid]);
            $hasSensitiveACL = $testQuery->fetchColumn() > 0;

            // Force test value
            $clipIsSensitive = $hasSensitiveACL;  // This will be true if ANY ACL exists

            header('Content-Type: application/json');

            // Fetch metadata
            $metadataRows = $metadata->fetchAll();

            // Fetch comments
            $comments = $this->loadCommentsThreaded($pdo, $clipUuid);

            // Build metadata arrays similar to what the template does
            $metaMap = [];
            foreach ($metadataRows as $row) {
                $metaMap[$row['meta_key']] = $row['meta_value'];
            }

            // Extract FPS info
            $fpsNum = null;
            $fpsDen = null;
            $fpsVal = null;
            $fpsStr = '';

            if (isset($metaMap['fps_num']) && isset($metaMap['fps_den'])) {
                $fpsNum = (int)$metaMap['fps_num'];
                $fpsDen = (int)$metaMap['fps_den'];
                if ($fpsDen > 0) {
                    $fpsVal = $fpsNum / $fpsDen;
                    $fpsStr = number_format($fpsVal, 3, '.', '');
                }
            } elseif (!empty($clip['fps'])) {
                $fpsStr = str_replace(',', '.', $clip['fps']);
                $fpsVal = (float)$fpsStr;
            }

            // Extract resolution
            $frameSize = null;
            if (!empty($metaMap['Width']) && !empty($metaMap['Height'])) {
                $frameSize = $metaMap['Width'] . 'x' . $metaMap['Height'];
            }

            // Extract codec
            $codec = $metaMap['Format'] ?? $metaMap['Codec'] ?? null;

            // Calculate duration in timecode format
            $duration_tc = '00:00:00:00';
            if (!empty($clip['duration_ms'])) {
                $ms = (int)$clip['duration_ms'];
                $useFps = $fpsVal ?: 25.0;
                $fpsInt = (int)round($useFps);
                $totalFrames = (int)round(($ms / 1000.0) * $useFps);
                $frames = $totalFrames % $fpsInt;
                $totalSeconds = intdiv($totalFrames, $fpsInt);
                $ss = $totalSeconds % 60;
                $totalMinutes = intdiv($totalSeconds, 60);
                $mm = $totalMinutes % 60;
                $hh = intdiv($totalMinutes, 60);
                $duration_tc = sprintf('%02d:%02d:%02d:%02d', $hh, $mm, $ss, $frames);
            }

            // Build basic metadata
            $basicMetadata = [
                'TC In' => $clip['tc_start'] ?? null,
                'Camera' => $clip['camera'] ?? null,
                'TC Out' => $clip['tc_end'] ?? null,
                'Reel' => $clip['reel'] ?? null,
                'Duration' => $duration_tc,
                'Resolution' => $frameSize,
                'FPS' => ($fpsVal !== null ? rtrim(rtrim(number_format($fpsVal, 3, '.', ''), '0'), '.') : null),
                'Codec' => $codec,
            ];

            // Filter out empty values
            $basicPairs = [];
            foreach ($basicMetadata as $k => $v) {
                if ($v !== null && $v !== '') {
                    $basicPairs[] = ['key' => $k, 'value' => $v];
                }
            }

            // Ensure exactly 8 slots
            while (count($basicPairs) < 8) {
                $basicPairs[] = ['key' => '—', 'value' => '—'];
            }

            // Build extended metadata (exclude keys already used in basic)
            $excludeKeys = [
                'fps',
                'FPS',
                'Camera FPS',
                'Frame Rate',
                'FrameRate',
                'CameraFPS',
                'fps_num',
                'fps_den',
                'Width',
                'Height',
                'Format',
                'Codec',
                'Duration',
                'TC In',
                'TC Out',
                'Camera',
                'Reel'
            ];

            $extendedMetadata = [];
            foreach ($metadataRows as $row) {
                $key = $row['meta_key'];
                if (!in_array($key, $excludeKeys, true)) {
                    $extendedMetadata[] = [
                        'key' => $key,
                        'value' => $row['meta_value']
                    ];
                }
            }

            // Add poster URL with cache-busting
            $posterUrlWithVersion = null;
            if (!empty($posterUrl)) {
                $posterUrlWithVersion = $posterUrl
                    . ((strpos($posterUrl, '?') !== false) ? '&' : '?')
                    . 'v=' . time();
            }

            // Calculate if current clip is sensitive for this user
            $clipIsSensitive = false;
            $checkSens = $pdo->prepare("SELECT COUNT(*) FROM clip_sensitive_acl WHERE clip_id = UUID_TO_BIN(:c, 1)");
            $checkSens->execute([':c' => $clipUuid]);
            $hasAnyRestriction = $checkSens->fetchColumn() > 0;

            // 2. Define visibility of the badge
            // Admins see the badge if it's restricted. Regular users only see the badge if they have access to it.
            if ($isSuperuser || $isProjectAdmin) {
                $clipIsSensitive = $hasAnyRestriction;
            } else if ($personUuid && $hasAnyRestriction) {
                // If restricted, check if this specific user has a membership allowing them to see it
                $checkUserAccess = $pdo->prepare("
                SELECT COUNT(*) 
                FROM sensitive_group_members 
                WHERE person_id = UUID_TO_BIN(:p, 1) 
                AND group_id IN (SELECT group_id FROM clip_sensitive_acl WHERE clip_id = UUID_TO_BIN(:c, 1))
            ");
                $checkUserAccess->execute([':c' => $clipUuid, ':p' => $personUuid]);
                $clipIsSensitive = $checkUserAccess->fetchColumn() > 0;
            }

            $response = [
                'success' => true,
                'clip' => [
                    'clip_uuid' => $clip['clip_uuid'],
                    'scene' => $clip['scene'] ?? '',
                    'slate' => $clip['slate'] ?? '',
                    'take' => $clip['take'] ?? '',
                    'file_name' => $clip['file_name'] ?? '',
                    'tc_start' => $clip['tc_start'] ?? '00:00:00:00',
                    'tc_end' => $clip['tc_end'] ?? '00:00:00:00',
                    'camera' => $clip['camera'] ?? '',
                    'reel' => $clip['reel'] ?? '',
                    'fps' => $clip['fps'] ?? '25',
                    'fps_num' => $clip['fps_num'] ?? '',
                    'fps_den' => $clip['fps_den'] ?? '',
                    'is_select' => (int)($clip['is_select'] ?? 0),  // ADD THIS LINE
                    'is_restricted' => $clipIsSensitive,            // ADD THIS LINE (rename from is_sensitive)
                    'is_sensitive' => $clipIsSensitive              // Keep for backwards compatibility
                ],
                'urls' => [
                    'video' => "/admin/projects/{$projectUuid}/clips/{$clipUuid}/stream.mp4",
                    'poster' => $posterUrlWithVersion,
                    'waveform' => $waveformUrl,
                ],
                'metadata' => [
                    'basic' => $basicPairs,
                    'extended' => $extendedMetadata,
                ],
                'comments' => $comments,
                'project' => [
                    'uuid' => $projectUuid,
                    'title' => $project['title'] ?? '',
                    'default_aspect_ratio' => $project['default_aspect_ratio'] ?? 'none',
                ],
                'day' => [
                    'uuid' => $dayUuid,
                    'title' => $day['title'] ?? '',
                    'shoot_date' => $day['shoot_date'] ?? '',
                ],
                // [INSERT AFTER line 486 in ClipPlayerController.php]
                'header' => [
                    'scene_line' => $sceneLine, // You'll need to move your $sceneLine logic above the Ajax check
                    'file_name_base' => preg_replace('/\.[^.]+$/', '', $clip['file_name'] ?? ''),
                ],
            ];

            echo json_encode($response);
            exit;
        }

        // ========== END OF AJAX MODIFICATION ==========

        // Check if video files exist for this clip
        // We fetch the path and verify file_exists to correctly handle "Archived" (DB exists, file gone)
        $mediaCheckSql = "
            SELECT storage_path 
            FROM clip_assets 
            WHERE clip_id = UUID_TO_BIN(:clip_uuid, 1) 
            AND asset_type IN ('proxy_web', 'original')
            ORDER BY FIELD(asset_type, 'proxy_web', 'original')
            LIMIT 1
        ";
        $stmt = $pdo->prepare($mediaCheckSql);
        $stmt->execute([':clip_uuid' => $clipUuid]);
        $mediaPath = $stmt->fetchColumn();

        $hasMedia = false;
        if ($mediaPath) {
            // Map public path to local docker path (Reuse logic from stream method)
            $fsBase = rtrim(getenv('ZEN_STOR_DIR') ?: '/var/www/html/data', '/');
            $publicBase = rtrim(getenv('ZEN_STOR_PUBLIC_BASE') ?: '/data', '/');

            $checkPath = $mediaPath;
            if (strpos($mediaPath, $publicBase) === 0) {
                $checkPath = $fsBase . substr($mediaPath, strlen($publicBase));
            }

            $hasMedia = file_exists($checkPath);
        }

        // --- IDENTIFY SENSITIVE CLIPS (For Chip Display) ---
        // Build list of clip UUIDs that should show the sensitive chip
        // - Superusers and Project Admins: see chip for ALL sensitive clips
        // - Regular users: only see chip if they're in the security group for that clip
        // ... inside show() method ...

        $sensitiveClips = [];
        if ($isSuperuser || $isProjectAdmin) {
            // (Keep existing Admin logic if it's there, or use this block)
            $sensSql = "
                SELECT DISTINCT BIN_TO_UUID(c.id, 1) AS clip_uuid
                FROM clips c
                JOIN clip_sensitive_acl csa ON csa.clip_id = c.id
                WHERE c.project_id = UUID_TO_BIN(:pid, 1)
                " . $sceneFilterSql; // Concatenate safely

            $stmtSens = $pdo->prepare($sensSql);
            $stmtSens->bindValue(':pid', $projectUuid);

            if ($targetScene) {
                $stmtSens->bindValue(':sc', $targetScene);
            } else {
                $stmtSens->bindValue(':d', $dayUuid);
            }
            $stmtSens->execute();
            $sensitiveClips = $stmtSens->fetchAll(\PDO::FETCH_COLUMN);
        } elseif ($personUuid) {
            // Regular users only see the chip if they are IN the specific security group

            // 1. Build Query with Concatenation to ensure $sceneFilterSql is included
            $sensSql = "
                SELECT DISTINCT BIN_TO_UUID(c.id, 1) AS clip_uuid
                FROM clips c
                JOIN clip_sensitive_acl csa ON csa.clip_id = c.id
                JOIN sensitive_group_members sgm ON sgm.group_id = csa.group_id
                WHERE c.project_id = UUID_TO_BIN(:pid, 1)
                AND sgm.person_id = UUID_TO_BIN(:uid, 1)
                " . $sceneFilterSql;

            $stmtSens = $pdo->prepare($sensSql);

            // 2. Bind Params
            $stmtSens->bindValue(':pid', $projectUuid);
            $stmtSens->bindValue(':uid', $personUuid);

            // 3. Bind Conditional Param (Scene or Day)
            if ($targetScene) {
                $stmtSens->bindValue(':sc', $targetScene);
            } else {
                $stmtSens->bindValue(':d', $dayUuid);
            }

            $stmtSens->execute();
            $sensitiveClips = $stmtSens->fetchAll(\PDO::FETCH_COLUMN);
        }

        View::render($isMobile ? 'admin/player/show_mobile' : 'admin/player/show', [
            'layout' => $isMobile ? 'layout/mobile' : 'layout/main',
            'navActive'     => 'player',
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
            'has_media' => $hasMedia,
            'current_day_label' => $currentDayLabel,
            'days' => $this->fetchDaysWithThumbs($pdo, $projectUuid, $dayVisibilitySql, $aclSql, $viewerParam),
            'scenes' => $this->fetchScenesWithThumbs($pdo, $projectUuid, $aclSql, $viewerParam),
            'placeholder_thumb_url' => '/assets/img/empty_day_placeholder.png',
            'sensitiveClips' => $sensitiveClips  // <--- Added for sensitive chip display
        ]);
    }

    public function storeComment(string $projectUuid, string $dayUuid, string $clipUuid): void
    {
        if (session_status() === \PHP_SESSION_NONE) session_start();

        \App\Support\Csrf::validateOrAbort($_POST['_csrf'] ?? null);

        $personUuid = $_SESSION['person_uuid'] ?? null;
        if (!$personUuid) {
            http_response_code(403);
            die("Access Denied: You must be logged in to post comments.");
        }

        $body    = trim($_POST['comment_body'] ?? '');
        $parent  = trim($_POST['parent_comment_uuid'] ?? '');
        $startTc = trim($_POST['start_tc'] ?? '');

        if ($body === '') {
            header("Location: " . $_SERVER['HTTP_REFERER']);
            exit;
        }

        $pdo = DB::pdo();

        // Native UUID generation
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        $commentUuid = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));

        // Simplified SQL to ensure parameter count matches exactly
        $sql = "
            INSERT INTO comments (id, clip_id, author_id, parent_id, body, start_tc, created_at)
            VALUES (
                UUID_TO_BIN(:id, 1),
                UUID_TO_BIN(:clip, 1),
                UUID_TO_BIN(:author, 1),
                UUID_TO_BIN(:parent, 1),
                :body,
                :tc,
                NOW()
            )
        ";

        $stmt = $pdo->prepare($sql);

        // Execute with the exact 6 parameters defined in the SQL string
        $stmt->execute([
            ':id'     => $commentUuid,
            ':clip'   => $clipUuid,
            ':author' => $personUuid,
            ':parent' => ($parent !== '' ? $parent : null),
            ':body'   => $body,
            ':tc'     => ($startTc !== '' ? $startTc : null)
        ]);

        header("Location: " . $_SERVER['HTTP_REFERER']);
        exit;
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

        // --- Project-admin (DIT) check ---
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

        // --- Read JSON body ---
        $raw = file_get_contents('php://input') ?: '';
        $body = json_decode($raw, true) ?: [];
        $seconds = isset($body['seconds']) ? (float)$body['seconds'] : null;

        if ($seconds === null || !is_finite($seconds) || $seconds < 0) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Missing or invalid seconds']);
            return;
        }

        // 1. Get the SOURCE video path (to read from)
        $sourceStmt = $pdo->prepare("
            SELECT ca.storage_path, c.file_name
            FROM clips c
            JOIN clip_assets ca ON ca.clip_id = c.id
            WHERE c.id = UUID_TO_BIN(:clip_uuid,1)
              AND ca.asset_type = 'original'
            ORDER BY ca.created_at DESC
            LIMIT 1
        ");
        $sourceStmt->execute([':clip_uuid' => $clipUuid]);
        $sourceRow = $sourceStmt->fetch(PDO::FETCH_ASSOC);

        if (!$sourceRow || empty($sourceRow['storage_path'])) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Original asset not found']);
            return;
        }

        // 2. Get the EXISTING POSTER path (to overwrite)
        $posterStmt = $pdo->prepare("
            SELECT storage_path
            FROM clip_assets
            WHERE clip_id = UUID_TO_BIN(:clip_uuid,1)
              AND asset_type = 'poster'
            ORDER BY created_at DESC
            LIMIT 1
        ");
        $posterStmt->execute([':clip_uuid' => $clipUuid]);
        $existingPosterPath = $posterStmt->fetchColumn();

        // 3. Define Environment Paths
        // Mapped inside container: /var/www/html/data/
        $fsBase     = rtrim(getenv('ZEN_STOR_DIR') ?: '/var/www/html/data', '/');
        // Public URL base: /data/
        $publicBase = rtrim(getenv('ZEN_STOR_PUBLIC_BASE') ?: '/data', '/');

        // Helper to convert DB path (which might be /data/...) to Absolute FS path
        $toAbsPath = function ($dbPath) use ($fsBase, $publicBase) {
            // If it starts with /data, swap it for /var/www/html/data
            if (strpos($dbPath, $publicBase) === 0) {
                return $fsBase . substr($dbPath, strlen($publicBase));
            }
            // Fallback: assume it's already absolute or relative to root
            return $dbPath;
        };

        $sourceAbs = $toAbsPath($sourceRow['storage_path']);

        // 4. Determine Target Path
        if ($existingPosterPath) {
            // CASE A: Overwrite existing file (Preserves 'h1CP7' hash names)
            $posterPublicPath = $existingPosterPath;
            $posterAbsPath    = $toAbsPath($existingPosterPath);
        } else {
            // CASE B: Create new in /posters/ folder structure
            // Structure: /data/posters/{project_uuid}/{day_uuid}/{filename}.jpg
            $fileNameBase = pathinfo($sourceRow['file_name'], PATHINFO_FILENAME);

            // Build relative path components
            $relPath = "/posters/$projectUuid/$dayUuid/$fileNameBase.jpg";

            $posterPublicPath = $publicBase . $relPath; // /data/posters/...
            $posterAbsPath    = $fsBase . $relPath;     // /var/www/html/data/posters/...

            // Ensure directory exists
            $dir = dirname($posterAbsPath);
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
        }

        // 5. Generate Poster
        $ff = new FFmpegService();
        $res = $ff->generatePoster($sourceAbs, $posterAbsPath, $seconds, 640);

        if (!($res['ok'] ?? false)) {
            http_response_code(500);
            echo json_encode([
                'ok'    => false,
                'error' => $res['err'] ?? 'Failed to generate poster',
            ]);
            return;
        }

        // 6. Update DB with new size info
        $bytes   = @filesize($posterAbsPath) ?: null;
        $width   = null;
        $height  = null;
        $imgInfo = @getimagesize($posterAbsPath);
        if (\is_array($imgInfo)) {
            $width  = isset($imgInfo[0]) ? (int)$imgInfo[0] : null;
            $height = isset($imgInfo[1]) ? (int)$imgInfo[1] : null;
        }

        $up = $pdo->prepare("
            INSERT INTO clip_assets (clip_id, asset_type, storage_path, byte_size, width, height, codec, created_at)
            VALUES (UUID_TO_BIN(:clip_uuid,1), 'poster', :path, :size, :w, :h, 'jpg', NOW())
            ON DUPLICATE KEY UPDATE
                storage_path = VALUES(storage_path),
                byte_size    = VALUES(byte_size),
                width        = VALUES(width),
                height       = VALUES(height),
                created_at   = NOW() 
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
            SELECT 
                BIN_TO_UUID(p.id,1) AS project_uuid, 
                p.title, 
                p.code, 
                p.status,
                p.default_aspect_ratio -- Add this column
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
    public function fetchScenesWithThumbs(\PDO $pdo, string $projectUuid, string $ignoredAclSql, ?string $viewerParam): array
    {
        // 1. Build the Security SQL locally to ensure placeholders match the binds.
        // We ignore the passed $ignoredAclSql to prevent alias errors.
        $localSecuritySql = '';

        if ($viewerParam) {
            $localSecuritySql = "
                AND d.published_at IS NOT NULL
                AND (
                    -- Day Gate
                    (
                        NOT EXISTS (SELECT 1 FROM day_sensitive_acl dsa WHERE dsa.day_id = c.day_id)
                        OR EXISTS (
                            SELECT 1 FROM day_sensitive_acl dsa 
                            JOIN sensitive_group_members sgm_d ON sgm_d.group_id = dsa.group_id
                            WHERE dsa.day_id = c.day_id AND sgm_d.person_id = UUID_TO_BIN(:viewer_uuid_day, 1)
                        )
                    )
                    AND
                    -- Clip Gate
                    (csa.group_id IS NULL OR sgm.person_id = UUID_TO_BIN(:viewer_uuid_clip, 1))
                )
             ";
        }

        // 2. Main Query
        $sql = "
            SELECT 
                scene,
                COUNT(*) as clip_count,
                SUM(duration_ms) as tot_ms,
                (
                    SELECT a.storage_path
                    FROM clip_assets a
                    INNER JOIN clips c3 ON a.clip_id = c3.id
                    WHERE c3.project_id = UUID_TO_BIN(:p_sub, 1)
                      AND c3.scene = scene_clips.scene
                      AND a.asset_type = 'poster'
                    ORDER BY c3.created_at ASC, a.created_at DESC
                    LIMIT 1
                ) AS thumb_url
            FROM (
                SELECT 
                    c.scene,
                    c.id,
                    c.duration_ms
                FROM clips c
                -- JOIN DAYS is critical for the 'd.published_at' check
                JOIN days d ON d.id = c.day_id 
                LEFT JOIN clip_sensitive_acl csa ON csa.clip_id = c.id
                LEFT JOIN sensitive_group_members sgm ON sgm.group_id = csa.group_id
                WHERE c.project_id = UUID_TO_BIN(:p_main, 1)
                  AND c.scene IS NOT NULL AND c.scene != ''
                  $localSecuritySql
                GROUP BY c.scene, c.id, c.duration_ms
            ) AS scene_clips
            GROUP BY scene
            ORDER BY 
                CASE WHEN scene REGEXP '^[0-9]+' THEN 0 ELSE 1 END ASC,
                CAST(scene AS UNSIGNED) ASC,
                CASE WHEN scene REGEXP '^[0-9]+$' THEN 0 ELSE 1 END ASC,
                scene ASC
        ";

        $st = $pdo->prepare($sql);
        $st->bindValue(':p_sub', $projectUuid);
        $st->bindValue(':p_main', $projectUuid);

        // 3. Bind the placeholders if we injected the SQL
        if ($viewerParam) {
            $st->bindValue(':viewer_uuid_day', $viewerParam);
            $st->bindValue(':viewer_uuid_clip', $viewerParam);
        }
        $st->execute();

        $scenes = [];
        foreach ($st->fetchAll(\PDO::FETCH_ASSOC) as $row) {
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

        // 3. Sensitive Groups ACL Logic (Day + Clip)
        $aclSql = '';
        $params = [':clip_uuid' => $clipUuid, ':project_uuid' => $projectUuid];

        if (!$isSuperuser && !$isProjectAdmin) {
            $viewerUuid = ($personUuid ?? '00000000-0000-0000-0000-000000000000');
            $params[':viewer_uuid_day']  = $viewerUuid;
            $params[':viewer_uuid_clip'] = $viewerUuid;

            $aclSql = " 
                -- Day Gate
                AND (
                    NOT EXISTS (SELECT 1 FROM day_sensitive_acl dsa WHERE dsa.day_id = c.day_id)
                    OR EXISTS (
                        SELECT 1 FROM day_sensitive_acl dsa 
                        JOIN sensitive_group_members sgm ON sgm.group_id = dsa.group_id
                        WHERE dsa.day_id = c.day_id AND sgm.person_id = UUID_TO_BIN(:viewer_uuid_day, 1)
                    )
                )
                -- Clip Gate
                AND (csa.group_id IS NULL OR sgm.person_id = UUID_TO_BIN(:viewer_uuid_clip, 1))
            ";
        }

        // 4. Locate the Asset
        $sql = "
                SELECT ca.asset_type, ca.storage_path 
                FROM clip_assets ca
                JOIN clips c ON c.id = ca.clip_id
                LEFT JOIN clip_sensitive_acl csa ON csa.clip_id = c.id
                LEFT JOIN sensitive_group_members sgm ON sgm.group_id = csa.group_id
                WHERE c.id = UUID_TO_BIN(:clip_uuid, 1) 
                AND c.project_id = UUID_TO_BIN(:project_uuid, 1)
                AND ca.asset_type IN ('proxy_web', 'original')
                $aclSql
                GROUP BY ca.id -- Prevents duplicate rows from the joins
                ORDER BY FIELD(ca.asset_type, 'proxy_web', 'original') 
                LIMIT 1";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $asset = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$asset) {
            header("HTTP/1.1 403 Forbidden");
            exit;
        }

        // [INSERT THIS LINE HERE] 
        // We have verified the user has access. Now close the session so we don't 
        // block other requests (like Ajax navigation) while streaming this file.
        session_write_close();

        // 5. Map path to Docker container filesystem
        $fsBase = rtrim(getenv('ZEN_STOR_DIR') ?: '/var/www/html/data/zendailies/uploads', '/');
        $publicBase = rtrim(getenv('ZEN_STOR_PUBLIC_BASE') ?: '/data/zendailies/uploads', '/');
        $filePath = str_replace($publicBase, $fsBase, $asset['storage_path']);


        if (!file_exists($filePath)) {
            // Serve a tiny placeholder video instead of 404 to prevent server bans
            // This allows the poster to display beautifully while avoiding fail2ban triggers
            $this->servePlaceholderVideo();
            return;
        }

        // 6. Byte-Range Streaming (Zengrabber Style)
        $size = filesize($filePath);
        $fp   = fopen($filePath, 'rb');
        $start  = 0;
        $length = $size;

        $sourceType = isset($asset['asset_type']) ? $asset['asset_type'] : 'none_found';
        header('Content-Type: video/mp4');
        header('X-Zendailies-Source: ' . $sourceType);
        header('Content-Disposition: inline; filename="playback.mp4"');
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

    /**
     * JSON endpoint for batch-loading day thumbnails (sidebar).
     * Route: /admin/projects/{projectUuid}/player/days/thumbnails
     */
    public function batchDayThumbnails(string $projectUuid): void
    {
        // 1. Auth & Context
        if (session_status() === \PHP_SESSION_NONE) session_start();
        $account = $_SESSION['account'] ?? null;
        $personUuid = $_SESSION['person_uuid'] ?? null;
        $isSuperuser = (int)($account['is_superuser'] ?? 0);

        // Check Project Admin
        $isProjectAdmin = 0;
        if ($personUuid) {
            $pdo = DB::pdo();
            $stmt = $pdo->prepare("SELECT is_project_admin FROM project_members WHERE project_id = UUID_TO_BIN(:p,1) AND person_id = UUID_TO_BIN(:u,1)");
            $stmt->execute([':p' => $projectUuid, ':u' => $personUuid]);
            $isProjectAdmin = (int)$stmt->fetchColumn();
        }

        // 2. Parse Requested Days
        $dayUuidsRaw = $_GET['days'] ?? '';
        $dayUuids = explode(',', $dayUuidsRaw);
        if (empty($dayUuidsRaw) || empty($dayUuids)) {
            echo json_encode([]);
            return;
        }

        // 3. Build Security SQL
        $visibilitySql = '';
        $params = [':project_uuid' => $projectUuid];

        // Regular users must pass Day Gate + Clip Gate + Published check
        if (!$isSuperuser && !$isProjectAdmin) {
            $viewerUuid = $personUuid ?? '00000000-0000-0000-0000-000000000000';
            $params[':viewer_uuid_day']  = $viewerUuid;
            $params[':viewer_uuid_clip'] = $viewerUuid;

            $visibilitySql = "
                AND d.published_at IS NOT NULL
                
                -- Day Gate
                AND (
                    NOT EXISTS (SELECT 1 FROM day_sensitive_acl dsa WHERE dsa.day_id = c.day_id)
                    OR EXISTS (
                        SELECT 1 FROM day_sensitive_acl dsa 
                        JOIN sensitive_group_members sgm ON sgm.group_id = dsa.group_id
                        WHERE dsa.day_id = c.day_id AND sgm.person_id = UUID_TO_BIN(:viewer_uuid_day, 1)
                    )
                )

                -- Clip Gate
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
        }

        // 4. Build IN clause for days
        // We manually sanitize UUIDs to be safe since we can't bind an array directly
        $validUuids = [];
        foreach ($dayUuids as $du) {
            if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', trim($du))) {
                $validUuids[] = trim($du);
            }
        }
        if (empty($validUuids)) {
            echo json_encode([]);
            return;
        }

        $inClause = [];
        foreach ($validUuids as $i => $uuid) {
            $pkey = ":d_$i";
            $inClause[] = "UUID_TO_BIN($pkey, 1)";
            $params[$pkey] = $uuid;
        }
        $inSql = implode(',', $inClause);

        // 5. Query: Get 1 poster per day (respecting security)
        $sql = "
            SELECT 
                BIN_TO_UUID(c.day_id, 1) as day_uuid,
                (
                    SELECT a.storage_path
                    FROM clip_assets a
                    WHERE a.clip_id = c.id
                      AND a.asset_type = 'poster'
                    ORDER BY a.created_at DESC
                    LIMIT 1
                ) as poster_path
            FROM clips c
            JOIN days d ON d.id = c.day_id
            WHERE c.project_id = UUID_TO_BIN(:project_uuid, 1)
              AND c.day_id IN ($inSql)
              $visibilitySql
            GROUP BY c.day_id
        ";

        $pdo = DB::pdo();
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // 6. Output Map
        $result = [];
        foreach ($rows as $r) {
            if (!empty($r['poster_path'])) {
                $result[$r['day_uuid']] = $r['poster_path'];
            }
        }

        header('Content-Type: application/json');
        echo json_encode($result);
    }


    /**
     * JSON endpoint for batch-loading scene thumbnails (sidebar).
     * Route: /admin/projects/{projectUuid}/player/scenes/thumbnails
     */
    public function batchSceneThumbnails(string $projectUuid): void
    {
        // 1. Auth & Context
        if (session_status() === \PHP_SESSION_NONE) session_start();
        $account = $_SESSION['account'] ?? null;
        $personUuid = $_SESSION['person_uuid'] ?? null;
        $isSuperuser = (int)($account['is_superuser'] ?? 0);

        // Check Project Admin
        $isProjectAdmin = 0;
        if ($personUuid) {
            $pdo = DB::pdo();
            $stmt = $pdo->prepare("SELECT is_project_admin FROM project_members WHERE project_id = UUID_TO_BIN(:p,1) AND person_id = UUID_TO_BIN(:u,1)");
            $stmt->execute([':p' => $projectUuid, ':u' => $personUuid]);
            $isProjectAdmin = (int)$stmt->fetchColumn();
        }

        // 2. Parse Requested Scenes
        $scenesRaw = $_GET['scenes'] ?? '';
        $scenes = explode(',', $scenesRaw);
        if (empty($scenesRaw) || empty($scenes)) {
            echo json_encode([]);
            return;
        }

        // 3. Build Security SQL
        $visibilitySql = '';
        $params = [':project_uuid' => $projectUuid];

        if (!$isSuperuser && !$isProjectAdmin) {
            $viewerUuid = $personUuid ?? '00000000-0000-0000-0000-000000000000';
            $params[':viewer_uuid_day']  = $viewerUuid;
            $params[':viewer_uuid_clip'] = $viewerUuid;

            $visibilitySql = "
                AND d.published_at IS NOT NULL
                
                -- Day Gate
                AND (
                    NOT EXISTS (SELECT 1 FROM day_sensitive_acl dsa WHERE dsa.day_id = c.day_id)
                    OR EXISTS (
                        SELECT 1 FROM day_sensitive_acl dsa 
                        JOIN sensitive_group_members sgm ON sgm.group_id = dsa.group_id
                        WHERE dsa.day_id = c.day_id AND sgm.person_id = UUID_TO_BIN(:viewer_uuid_day, 1)
                    )
                )

                -- Clip Gate
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
        }

        // 4. Build IN clause for scenes (Manual sanitization)
        $validScenes = [];
        foreach ($scenes as $sc) {
            $s = trim($sc);
            if ($s !== '') $validScenes[] = $s;
        }

        if (empty($validScenes)) {
            echo json_encode([]);
            return;
        }

        $inClause = [];
        foreach ($validScenes as $i => $s) {
            $pkey = ":s_$i";
            $inClause[] = $pkey;
            $params[$pkey] = $s;
        }
        $inSql = implode(',', $inClause);

        // 5. Query
        $sql = "
            SELECT 
                c.scene,
                (
                    SELECT a.storage_path
                    FROM clip_assets a
                    WHERE a.clip_id = c.id
                      AND a.asset_type = 'poster'
                    ORDER BY a.created_at DESC
                    LIMIT 1
                ) as poster_path
            FROM clips c
            JOIN days d ON d.id = c.day_id
            WHERE c.project_id = UUID_TO_BIN(:project_uuid, 1)
              AND c.scene IN ($inSql)
              $visibilitySql
            GROUP BY c.scene
        ";

        $pdo = DB::pdo();
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // 6. Output
        $result = [];
        foreach ($rows as $r) {
            if (!empty($r['poster_path'])) {
                $result[$r['scene']] = $r['poster_path'];
            }
        }

        header('Content-Type: application/json');
        echo json_encode($result);
    }

    /**
     * Serve a tiny transparent placeholder video when media files are missing/archived
     * 
     * This prevents 404 errors that can trigger fail2ban, while allowing:
     * - Poster images to display beautifully in the player
     * - Users to browse archived projects without errors
     * - Video controls to work (they just show the poster)
     */
    private function servePlaceholderVideo(): void
    {
        // Minimal valid MP4 (1 black frame, ~1.5KB)
        // This is a real, standards-compliant MP4 video that browsers accept
        $placeholderMp4 = base64_decode(
            'AAAAIGZ0eXBpc29tAAACAGlzb21pc28yYXZjMW1wNDEAAAAIZnJlZQAAAq1tZGF0' .
                'AAACrQYF//+c3EXpvebZSLeWLNgg2SPu73gyNjQgLSBjb3JlIDE1NSByMjkwMSA3' .
                'ZDBmZjIyIC0gSC4yNjQvTVBFRy00IEFWQyBjb2RlYyAtIENvcHlsZWZ0IDIwMDMt' .
                'MjAxOCAtIGh0dHA6Ly93d3cudmlkZW9sYW4ub3JnL3gyNjQuaHRtbCAtIG9wdGlv' .
                'bnM6IGNhYmFjPTEgcmVmPTMgZGVibG9jaz0xOjA6MCBhbmFseXNlPTB4MzoweDEx' .
                'MyBtZT1oZXggc3VibWU9NyBwc3k9MSBwc3lfcmQ9MS4wMDowLjAwIG1peGVkX3Jl' .
                'Zj0xIG1lX3JhbmdlPTE2IGNocm9tYV9tZT0xIHRyZWxsaXM9MSA4eDhkY3Q9MSBj' .
                'cW09MCBkZWFkem9uZT0yMSwxMSBmYXN0X3Bza2lwPTEgY2hyb21hX3FwX29mZnNl' .
                'dD0tMiB0aHJlYWRzPTYgbG9va2FoZWFkX3RocmVhZHM9MSBzbGljZWRfdGhyZWFk' .
                'cz0wIG5yPTAgZGVjaW1hdGU9MSBpbnRlcmxhY2VkPTAgYmx1cmF5X2NvbXBhdD0w' .
                'IGNvbnN0cmFpbmVkX2ludHJhPTAgYmZyYW1lcz0zIGJfcHlyYW1pZD0yIGJfYWRh' .
                'cHQ9MSBiX2JpYXM9MCBkaXJlY3Q9MSB3ZWlnaHRiPTEgb3Blbl9nb3A9MCB3ZWln' .
                'aHRwPTIga2V5aW50PTI1MCBrZXlpbnRfbWluPTI1IHNjZW5lY3V0PTQwIGludHJh' .
                'X3JlZnJlc2g9MCByY19sb29rYWhlYWQ9NDAgcmM9Y3JmIG1idHJlZT0xIGNyZj0y' .
                'My4wIHFjb21wPTAuNjAgcXBtaW49MCBxcG1heD02OSBxcHN0ZXA9NCBpcF9yYXRp' .
                'bz0xLjQwIGFxPTE6MS4wMACAAAAAD2WIhAA3//72rvzLK0cLlS4dWXuzUfLoSAgA' .
                'AAMAAAMAAAMAAAMAAAHgvugkks0RPwAA'
        );

        header('Content-Type: video/mp4');
        header('Content-Length: ' . strlen($placeholderMp4));
        header('Accept-Ranges: bytes');
        header('Cache-Control: public, max-age=31536000'); // Cache for 1 year
        header('X-Media-Status: archived'); // Custom header for debugging/logging

        echo $placeholderMp4;
        exit;
    }
}
