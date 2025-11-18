<?php

namespace App\Http\Controllers\Admin;

use App\Support\DB;
use App\Support\View;
use App\Support\Csrf;
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

        if (session_status() === \PHP_SESSION_NONE) session_start();

        // Session context
        $account      = $_SESSION['account'] ?? [];
        $isSuperuser  = !empty($account['is_superuser']);
        $personUuid   = $_SESSION['person_uuid'] ?? null;

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

        // Project
        $projStmt = $pdo->prepare("
            SELECT BIN_TO_UUID(p.id,1) AS project_uuid, p.title, p.code, p.status
            FROM projects p
            WHERE p.id = UUID_TO_BIN(:p,1)
            LIMIT 1
        ");
        $projStmt->execute([':p' => $projectUuid]);
        $project = $projStmt->fetch(PDO::FETCH_ASSOC);
        if (!$project) {
            http_response_code(404);
            echo "Project not found";
            return;
        }

        // Day (ensure belongs to project)
        $dayStmt = $pdo->prepare("
            SELECT BIN_TO_UUID(d.id,1) AS day_uuid, d.shoot_date, d.title, d.unit
            FROM days d
            WHERE d.id = UUID_TO_BIN(:d,1)
              AND d.project_id = UUID_TO_BIN(:p,1)
            LIMIT 1
        ");
        $dayStmt->execute([':d' => $dayUuid, ':p' => $projectUuid]);
        $day = $dayStmt->fetch(PDO::FETCH_ASSOC);
        if (!$day) {
            http_response_code(404);
            echo "Day not found";
            return;
        }

        // >>> ADDED: label shown in "(DAY NAME) / Clips"
        $currentDayLabel = $day['title'] ?: $day['shoot_date'];

        // Determine if current user is a project admin for this project
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


        // We will reuse these fragments in multiple queries below
        $aclSql          = '';
        $dayVisibilitySql = '';
        $viewerParam     = null;

        if (!$isSuperuser && !$isProjectAdmin) {
            // ACL: only see clips not in sensitive group OR explicitly whitelisted
            $aclSql = " AND (csa.group_id IS NULL OR sgm.person_id = UUID_TO_BIN(:viewer_person_uuid,1))";
            $viewerParam = ($personUuid ?? '00000000-0000-0000-0000-000000000000');

            // Visibility: regular users only see published days
            $dayVisibilitySql = " AND d.published_at IS NOT NULL";
        }



        // Day's clip list (with latest poster), respecting sensitive ACL — always load it
        $clipListParams = [
            ':p' => $projectUuid,
            ':d' => $dayUuid,
        ];
        if ($viewerParam !== null) {
            $clipListParams[':viewer_person_uuid'] = $viewerParam;
        }

        // BEGIN: day’s clip list query
        $clipListSql = "
            SELECT
                BIN_TO_UUID(c.id,1)        AS clip_uuid,
                c.scene, c.slate, c.take, c.take_int, c.camera,
                c.file_name,
                CAST(c.is_select AS UNSIGNED) AS is_select,
                (
                    SELECT COUNT(*)
                    FROM comments cm
                    WHERE cm.clip_id = c.id
                ) AS comment_count,
                (
                    SELECT storage_path
                    FROM clip_assets a
                    WHERE a.clip_id = c.id
                    AND a.asset_type = 'poster'
                    ORDER BY a.created_at DESC
                    LIMIT 1
                ) AS poster_path
            FROM clips c
            JOIN days d ON d.id = c.day_id
            LEFT JOIN clip_sensitive_acl csa ON csa.clip_id = c.id
            LEFT JOIN sensitive_group_members sgm ON sgm.group_id = csa.group_id
            WHERE c.project_id = UUID_TO_BIN(:p,1)
            AND c.day_id     = UUID_TO_BIN(:d,1)
            $aclSql
            $dayVisibilitySql
            ORDER BY
                c.scene IS NULL, c.scene,
                c.slate IS NULL, c.slate,
                c.take_int IS NULL, c.take_int,
                c.take IS NULL, c.take,
                c.camera IS NULL, c.camera,
                c.created_at
            LIMIT 500
        ";
        // END: day’s clip list query

        $listStmt = $pdo->prepare($clipListSql);
        foreach ($clipListParams as $k => $v) $listStmt->bindValue($k, $v);
        $listStmt->execute();
        $clipList = $listStmt->fetchAll(PDO::FETCH_ASSOC);

        // Clip + basic fields (single clip being viewed), respecting same ACL
        $clipParams = [
            ':p' => $projectUuid,
            ':d' => $dayUuid,
            ':c' => $clipUuid,
        ];
        if ($viewerParam !== null) {
            $clipParams[':viewer_person_uuid'] = $viewerParam;
        }

        $clipSql = "
            SELECT 
                BIN_TO_UUID(c.id,1)         AS clip_uuid,
                BIN_TO_UUID(c.project_id,1) AS project_uuid,
                BIN_TO_UUID(c.day_id,1)     AS day_uuid,
                c.scene, c.slate, c.take, c.camera, c.reel,
                c.file_name, c.tc_start, c.tc_end, c.duration_ms,
                c.fps, c.fps_num, c.fps_den,
                c.rating, CAST(c.is_select AS UNSIGNED) AS is_select, c.created_at
            FROM clips c
            JOIN days d ON d.id = c.day_id
            LEFT JOIN clip_sensitive_acl csa ON csa.clip_id = c.id
            LEFT JOIN sensitive_group_members sgm ON sgm.group_id = csa.group_id
            WHERE c.id         = UUID_TO_BIN(:c,1)
              AND c.project_id = UUID_TO_BIN(:p,1)
              AND c.day_id     = UUID_TO_BIN(:d,1)
              $aclSql
              $dayVisibilitySql
            LIMIT 1
        ";
        $clipStmt = $pdo->prepare($clipSql);
        foreach ($clipParams as $k => $v) $clipStmt->bindValue($k, $v);
        $clipStmt->execute();
        $clip = $clipStmt->fetch(PDO::FETCH_ASSOC);

        if (!$clip) {
            http_response_code(404);
            echo "Clip not found or not visible";
            return;
        }

        // Handle new comment POST (add or reply)
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['comment_body'])) {
            if (!$personUuid) {
                http_response_code(403);
                echo "You must be logged in to comment on clips.";
                return;
            }

            Csrf::validateOrAbort($_POST['_csrf'] ?? null);

            $body = trim((string)($_POST['comment_body'] ?? ''));
            $startTc = trim((string)($_POST['start_tc'] ?? ''));
            $parentUuid = trim((string)($_POST['parent_comment_uuid'] ?? ''));

            if ($body !== '') {
                // Validate / normalise timecode: allow empty or HH:MM:SS:FF
                if ($startTc === '' || !preg_match('/^\d{2}:\d{2}:\d{2}:\d{2}$/', $startTc)) {
                    $startTc = null;
                }

                // Validate that parent (if any) belongs to this clip
                if ($parentUuid !== '') {
                    $chk = $pdo->prepare("
                        SELECT 1
                        FROM comments
                        WHERE id = UUID_TO_BIN(:parent_uuid,1)
                          AND clip_id = UUID_TO_BIN(:clip_uuid,1)
                        LIMIT 1
                    ");
                    $chk->execute([
                        ':parent_uuid' => $parentUuid,
                        ':clip_uuid'   => $clipUuid,
                    ]);
                    if (!$chk->fetchColumn()) {
                        $parentUuid = null;
                    }
                } else {
                    $parentUuid = null;
                }

                $insert = $pdo->prepare("
                    INSERT INTO comments (clip_id, parent_id, author_id, body, start_tc)
                    VALUES (
                        UUID_TO_BIN(:clip_uuid,1),
                        UUID_TO_BIN(:parent_uuid,1),
                        UUID_TO_BIN(:author_uuid,1),
                        :body,
                        :start_tc
                    )
                ");

                $insert->execute([
                    ':clip_uuid'   => $clipUuid,
                    ':parent_uuid' => $parentUuid,
                    ':author_uuid' => $personUuid,
                    ':body'        => $body,
                    ':start_tc'    => $startTc,
                ]);
            }

            // PRG: redirect after POST to avoid duplicate submissions
            $selfUrl = $_SERVER['REQUEST_URI'] ?? '';
            header('Location: ' . $selfUrl);
            exit;
        }
        // Assets: pick latest proxy_web and poster
        // Assets: pick latest proxy_web or original for playback, plus poster
        $assetStmt = $pdo->prepare("
            SELECT asset_type, storage_path, byte_size, width, height, codec, created_at
            FROM clip_assets
            WHERE clip_id = UUID_TO_BIN(:c,1)
            AND asset_type IN ('proxy_web','original','poster')
            ORDER BY created_at DESC
        ");

        $assetStmt->execute([':c' => $clipUuid]);
        $assets = $assetStmt->fetchAll(PDO::FETCH_ASSOC);

        $proxyUrl  = null;  // “playable” file: proxy_web if exists, else original
        $posterUrl = null;

        foreach ($assets as $a) {
            if ($proxyUrl === null && $a['asset_type'] === 'proxy_web') {
                $proxyUrl = $a['storage_path'];
            } elseif ($proxyUrl === null && $a['asset_type'] === 'original') {
                $proxyUrl = $a['storage_path'];
            }

            if ($posterUrl === null && $a['asset_type'] === 'poster') {
                $posterUrl = $a['storage_path'];
            }

            if ($proxyUrl && $posterUrl) {
                break;
            }
        }


        // Metadata (key/value)
        $metaStmt = $pdo->prepare("
            SELECT meta_key, meta_value
            FROM clip_metadata
            WHERE clip_id = UUID_TO_BIN(:c,1)
            ORDER BY meta_key
        ");
        $metaStmt->execute([':c' => $clipUuid]);
        $metadata = $metaStmt->fetchAll(PDO::FETCH_ASSOC);

        // Comments (threaded: root + replies)
        $comments = $this->loadCommentsThreaded($pdo, $clipUuid);


        $daysOut = $this->fetchDaysWithThumbs($pdo, $projectUuid, $dayVisibilitySql, $aclSql, $viewerParam);


        // >>> ADDED: placeholder thumb path for days with no clips
        $placeholderThumbUrl = '/assets/img/empty_day_placeholder.png';

        // Render
        View::render('admin/player/show', [
            'project'               => $project,
            'day'                   => $day,
            'clip'                  => $clip,
            'proxy_url'             => $proxyUrl,
            'poster_url'            => $posterUrl,
            'metadata'              => $metadata,
            'comments'              => $comments,
            'project_uuid'          => $projectUuid,
            'day_uuid'              => $dayUuid,
            'clip_list'             => $clipList,
            'current_clip'          => $clipUuid,

            // >>> ADDED for the updated show.php
            'current_day_label'     => $currentDayLabel,
            'days'                  => $daysOut,
            'placeholder_thumb_url' => $placeholderThumbUrl,
        ]);
    }

    public function overview(string $projectUuid): void
    {
        if (session_status() === \PHP_SESSION_NONE) session_start();
        $pdo = \App\Support\DB::pdo();

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

        // Build the same days list with thumbs via helper
        $daysOut = $this->fetchDaysWithThumbs($pdo, $projectUuid, $dayVisibilitySql, $aclSql, $viewerParam);


        // Reuse the SAME view; no clip/day selected. JS handles ?pane=days
        \App\Support\View::render('admin/player/show', [
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
            'placeholder_thumb_url' => '/assets/img/empty_day_placeholder.png',
        ]);
    }
}
