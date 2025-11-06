<?php

namespace App\Http\Controllers\Admin;

use App\Support\DB;
use App\Support\View;
use PDO;

final class ClipPlayerController
{
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

    public function show(string $projectUuid, string $dayUuid, string $clipUuid): void
    {
        if (!$projectUuid || !$dayUuid || !$clipUuid) {
            http_response_code(400);
            echo "Bad request";
            return;
        }

        if (session_status() === \PHP_SESSION_NONE) session_start();

        $pdo = DB::pdo();

        // Session context
        $account      = $_SESSION['account'] ?? [];
        $isSuperuser  = !empty($account['is_superuser']);
        $personUuid   = $_SESSION['person_uuid'] ?? null;

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

        // We will reuse this ACL fragment in multiple queries below
        $visibilitySql = '';
        $viewerParam   = null;

        if (!$isSuperuser && !$isProjectAdmin) {
            $visibilitySql = " AND (csa.group_id IS NULL OR sgm.person_id = UUID_TO_BIN(:viewer_person_uuid,1))";
            $viewerParam   = ($personUuid ?? '00000000-0000-0000-0000-000000000000');
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
                c.file_name, CAST(c.is_select AS UNSIGNED) AS is_select,
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
            $visibilitySql
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
                c.rating, CAST(c.is_select AS UNSIGNED) AS is_select, c.created_at
            FROM clips c
            JOIN days d ON d.id = c.day_id
            LEFT JOIN clip_sensitive_acl csa ON csa.clip_id = c.id
            LEFT JOIN sensitive_group_members sgm ON sgm.group_id = csa.group_id
            WHERE c.id         = UUID_TO_BIN(:c,1)
              AND c.project_id = UUID_TO_BIN(:p,1)
              AND c.day_id     = UUID_TO_BIN(:d,1)
              $visibilitySql
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

        // Assets: pick latest proxy and poster
        $assetStmt = $pdo->prepare("
            SELECT asset_type, storage_path, byte_size, width, height, codec, created_at
            FROM clip_assets
            WHERE clip_id = UUID_TO_BIN(:c,1)
              AND asset_type IN ('proxy','poster')
            ORDER BY created_at DESC
        ");
        $assetStmt->execute([':c' => $clipUuid]);
        $assets = $assetStmt->fetchAll(PDO::FETCH_ASSOC);

        $proxyUrl  = null;
        $posterUrl = null;
        foreach ($assets as $a) {
            if ($a['asset_type'] === 'proxy'  && $proxyUrl  === null) $proxyUrl  = $a['storage_path'];
            if ($a['asset_type'] === 'poster' && $posterUrl === null) $posterUrl = $a['storage_path'];
            if ($proxyUrl && $posterUrl) break;
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

        // Comments (latest first)
        $cmtStmt = $pdo->prepare("
            SELECT 
                BIN_TO_UUID(cm.id,1) AS id,
                cm.body, cm.start_tc, cm.end_tc, cm.created_at
            FROM comments cm
            WHERE cm.clip_id = UUID_TO_BIN(:c,1)
            ORDER BY cm.created_at DESC
            LIMIT 200
        ");
        $cmtStmt->execute([':c' => $clipUuid]);
        $comments = $cmtStmt->fetchAll(PDO::FETCH_ASSOC);

        // >>> ADDED: fetch ALL days in this project for the Day Picker
        // We'll later attach a thumb to each.
        $allDaysStmt = $pdo->prepare("
            SELECT 
                BIN_TO_UUID(d.id,1) AS day_uuid,
                d.shoot_date,
                d.title
            FROM days d
            WHERE d.project_id = UUID_TO_BIN(:p,1)
            ORDER BY d.shoot_date ASC, d.created_at ASC
            LIMIT 500
        ");
        $allDaysStmt->execute([':p' => $projectUuid]);
        $rawDays = $allDaysStmt->fetchAll(PDO::FETCH_ASSOC);

        $daysOut = [];
        if ($rawDays) {

            // Prepare a statement to get ONE poster thumb per day,
            // respecting the same ACL (visibilitySql).
            // Strategy: pick the first visible clip in that day, then its newest poster.
            $thumbSql = "
                SELECT 
                    (
                        SELECT a.storage_path
                        FROM clip_assets a
                        WHERE a.clip_id = c.id
                          AND a.asset_type = 'poster'
                        ORDER BY a.created_at DESC
                        LIMIT 1
                    ) AS poster_path
                FROM clips c
                LEFT JOIN clip_sensitive_acl csa ON csa.clip_id = c.id
                LEFT JOIN sensitive_group_members sgm ON sgm.group_id = csa.group_id
                WHERE c.project_id = UUID_TO_BIN(:p,1)
                  AND c.day_id     = UUID_TO_BIN(:d,1)
                  $visibilitySql
                ORDER BY c.created_at ASC
                LIMIT 1
            ";
            $thumbStmt = $pdo->prepare($thumbSql);

            foreach ($rawDays as $rowDay) {
                $thumbStmt->bindValue(':p', $projectUuid);
                $thumbStmt->bindValue(':d', $rowDay['day_uuid']);
                if ($viewerParam !== null) {
                    $thumbStmt->bindValue(':viewer_person_uuid', $viewerParam);
                }
                $thumbStmt->execute();
                $thumbRow = $thumbStmt->fetch(PDO::FETCH_ASSOC);

                $daysOut[] = [
                    'day_uuid'   => $rowDay['day_uuid'],
                    'title'      => $rowDay['title'] ?: $rowDay['shoot_date'],
                    'shoot_date' => $rowDay['shoot_date'],
                    'thumb_url'  => $thumbRow['poster_path'] ?? null,
                    // you can also flag current day if you want later, e.g.:
                    // 'is_current' => ($rowDay['day_uuid'] === $dayUuid),
                ];
            }
        }

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
}
