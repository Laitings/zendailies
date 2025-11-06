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
}
