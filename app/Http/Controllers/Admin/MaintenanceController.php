<?php

namespace App\Http\Controllers\Admin;

use App\Support\DB;

/**
 * MaintenanceController — admin utilities
 *
 * Route: POST /admin/maintenance/backfill-fps
 * Behavior:
 *   - Finds clips with missing/zero fps.
 *   - Resolves a file path from clip_assets (prefer proxy, fallback original).
 *   - Runs ffprobe to get frame rate.
 *   - Updates clips.fps / fps_num / fps_den.
 * Output: plain text summary.
 */
class MaintenanceController
{
    public function backfillFps(): void
    {
        header('Content-Type: text/plain; charset=utf-8');

        $pdo = DB::pdo();
        if (!$pdo) {
            http_response_code(500);
            echo "FATAL: DB connection failed.\n";
            return;
        }

        // Choose ffprobe binary
        $ffprobe = getenv('FFPROBE_BIN') ?: 'ffprobe';

        // Build list: clips with NULL/0 fps, plus a resolved asset path
        $sql = <<<SQL
SELECT
    c.id,
    c.fps,
    COALESCE(
        (SELECT a.storage_path
           FROM clip_assets a
          WHERE a.clip_id = c.id AND a.asset_type = 'proxy'
          ORDER BY a.created_at DESC
          LIMIT 1),
        (SELECT a.storage_path
           FROM clip_assets a
          WHERE a.clip_id = c.id AND a.asset_type = 'original'
          ORDER BY a.created_at DESC
          LIMIT 1)
    ) AS storage_path
FROM clips c
WHERE c.fps IS NULL OR c.fps = 0
SQL;

        $rows = $pdo->query($sql)->fetchAll(\PDO::FETCH_ASSOC);

        $update = $pdo->prepare(
            "UPDATE clips SET fps = :fps, fps_num = :n, fps_den = :d WHERE id = :id"
        );

        $baseWebRoot = '/var/www/html';          // container FS root
        $updated = 0;
        $kept = 0;       // clips that already had fps (should be 0 with the WHERE)
        $missing = 0;    // no asset path or file missing
        $errors = 0;     // ffprobe/parse errors

        foreach ($rows as $r) {
            $path = $r['storage_path'] ?? null;
            if (!$path) {
                $missing++;
                continue;
            }

            // storage_path is like "/data/zendailies/uploads/...."
            // That is web path. Convert to filesystem path.
            $abs = $baseWebRoot . $path;

            if (!is_file($abs)) {
                $missing++;
                continue;
            }

            // Probe FPS
            $cmd = sprintf(
                '%s -v error -select_streams v:0 -show_entries stream=r_frame_rate,avg_frame_rate -of json %s',
                escapeshellcmd($ffprobe),
                escapeshellarg($abs)
            );
            $out = shell_exec($cmd);

            if (!$out) {
                $errors++;
                continue;
            }

            $j = json_decode($out, true);
            $stream = $j['streams'][0] ?? null;
            if (!$stream) {
                $errors++;
                continue;
            }

            $rate = $stream['r_frame_rate'] ?? ($stream['avg_frame_rate'] ?? null);
            if (!$rate || $rate === '0/0') {
                $errors++;
                continue;
            }

            if (!preg_match('#^(\d+)\s*/\s*(\d+)$#', $rate, $m)) {
                $errors++;
                continue;
            }

            $num = (int)$m[1];
            $den = (int)$m[2];
            if ($num <= 0 || $den <= 0) {
                $errors++;
                continue;
            }
            $dec = round($num / $den, 3);

            // Update
            $ok = $update->execute([
                ':fps' => $dec,
                ':n'   => $num,
                ':d'   => $den,
                ':id'  => $r['id'], // binary(16)
            ]);

            if ($ok) {
                $updated++;
            } else {
                $errors++;
            }
        }

        // Also count total clips for context
        $total = (int)$pdo->query("SELECT COUNT(*) FROM clips")->fetchColumn();

        echo "Backfill FPS complete\n";
        echo "Clips total:  {$total}\n";
        echo "Updated:      {$updated}\n";
        echo "Kept:         {$kept}\n";
        echo "Missing file: {$missing}\n";
        echo "Probe errors: {$errors}\n";
    }
}
