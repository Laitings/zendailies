<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Support\DB;
use PDO;

final class PlayerRedirectController
{

    public function toFirstClip(string $projectUuid, string $dayUuid): void
    {
        $pdo = DB::pdo();

        $stmt = $pdo->prepare("
            SELECT BIN_TO_UUID(c.id,1) AS clip_uuid
            FROM clips c
            WHERE c.project_id = UUID_TO_BIN(:p,1)
              AND c.day_id     = UUID_TO_BIN(:d,1)
            ORDER BY c.created_at ASC, c.file_name ASC
            LIMIT 1
        ");
        $stmt->execute([':p' => $projectUuid, ':d' => $dayUuid]);
        $clip = $stmt->fetch(PDO::FETCH_ASSOC);

        if (empty($clip['clip_uuid'])) {
            header('Location: /admin/projects/' . rawurlencode($projectUuid) .
                '/days/' . rawurlencode($dayUuid) . '/clips', true, 302);
            exit;
        }

        header('Location: /admin/projects/' . rawurlencode($projectUuid) .
            '/days/' . rawurlencode($dayUuid) .
            '/player/' . rawurlencode($clip['clip_uuid']), true, 302);
        exit;
    }
    public function toLatestDay(string $projectUuid): void
    {
        // Lookup the latest Day for the project (by shoot_date, fallback created_at)
        $pdo = \App\Support\DB::pdo();

        $sql = "
        SELECT BIN_TO_UUID(d.id, 1) AS day_uuid
        FROM days d
        WHERE d.project_id = UUID_TO_BIN(?, 1)
        ORDER BY d.shoot_date DESC, d.created_at DESC
        LIMIT 1
    ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$projectUuid]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$row || empty($row['day_uuid'])) {
            // No days yet → send to the Days list for this project
            header('Location: /admin/projects/' . rawurlencode($projectUuid) . '/days');
            exit;
        }

        $dayUuid = $row['day_uuid'];

        // Jump into day-mode player; that page will redirect to first clip if needed
        header('Location: /admin/projects/' . rawurlencode($projectUuid) . '/days/' . rawurlencode($dayUuid) . '/player');
        exit;
    }
}
