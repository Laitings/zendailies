<?php

namespace App\Services;

use App\Support\DB;
use PDO;

final class PosterService
{
    /**
     * Extract a poster frame from a video and register it in clip_assets.
     *
     * @param string $clipUuid   UUID (string form, hyphenated)
     * @param string $videoPath  Absolute path to source video (proxy is fine)
     * @param string $posterPath Absolute path where poster (jpg) should be written
     * @param int    $seekSec    Fallback seek seconds if you don't have per-clip metadata yet
     * @return array{ok:bool, path?:string, error?:string}
     */
    public function makePosterAndRegister(string $clipUuid, string $videoPath, string $posterPath, int $seekSec = 10): array
    {
        try {
            // 1) Ensure folder exists
            $dir = dirname($posterPath);
            if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
                return ['ok' => false, 'error' => "Failed to create poster dir: $dir"];
            }

            // 2) Use FFmpegService to extract a frame
            $ff = new FFmpegService();
            // NOTE: Reuse your existing thumbnail logic if present; this is a minimal, robust call:
            $ok = $ff->extractFrame($videoPath, $posterPath, $seekSec); // implement if you don’t have it yet
            if (!$ok || !is_file($posterPath)) {
                return ['ok' => false, 'error' => 'FFmpeg failed to create poster'];
            }

            // 3) Stat image
            $size = filesize($posterPath) ?: null;
            [$w, $h] = @getimagesize($posterPath) ?: [null, null];

            // 4) Insert into clip_assets as 'poster'
            $pdo = DB::pdo();
            $sql = "INSERT INTO clip_assets (clip_id, asset_type, storage_path, byte_size, width, height, codec)
                    VALUES (uuid_to_bin(:clip_uuid, 1), 'poster', :path, :bytes, :w, :h, 'jpg')";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':clip_uuid' => $clipUuid,
                ':path'      => $posterPath,   // stored as absolute since you already expose /data; adjust if needed
                ':bytes'     => $size,
                ':w'         => $w,
                ':h'         => $h,
            ]);

            return ['ok' => true, 'path' => $posterPath];
        } catch (\Throwable $e) {
            error_log('[PosterService] ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Exception: ' . $e->getMessage()];
        }
    }
}
