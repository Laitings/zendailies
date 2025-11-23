<?php

namespace App\Http\Controllers\Admin;

use App\Support\DB;
use App\Support\View;
use App\Services\FFmpegService;
use App\Services\StoragePaths;
use PDO;
use Throwable;

final class DayConverterController
{
    public function index(string $projectUuid, string $dayUuid): void
    {
        // AuthZ: superuser OR project admin (DIT) for this project
        if (!$this->canManageDay($projectUuid)) {
            http_response_code(403);
            echo "Forbidden";
            return;
        }

        $pdo = DB::pdo();

        // Project + Day info
        $sqlProj = "SELECT title, bin_to_uuid(id,1) AS project_uuid FROM projects WHERE id = uuid_to_bin(:p_uuid,1)";
        $stProj = $pdo->prepare($sqlProj);
        $stProj->execute([':p_uuid' => $projectUuid]);
        $project = $stProj->fetch(PDO::FETCH_ASSOC);
        if (!$project) {
            http_response_code(404);
            echo "Project not found";
            return;
        }

        $sqlDay = "SELECT shoot_date, title, bin_to_uuid(id,1) AS day_uuid
                   FROM days
                   WHERE project_id = uuid_to_bin(:p_uuid,1)
                     AND id = uuid_to_bin(:d_uuid,1)";
        $stDay = $pdo->prepare($sqlDay);
        $stDay->execute([':p_uuid' => $projectUuid, ':d_uuid' => $dayUuid]);
        $day = $stDay->fetch(PDO::FETCH_ASSOC);
        if (!$day) {
            http_response_code(404);
            echo "Day not found";
            return;
        }

        // Clips + asset presence (poster/proxy_web)
        // Clips + asset presence (poster/proxy_web) + latest encode job state
        $sqlClips = "
        SELECT
            BIN_TO_UUID(c.id,1) AS clip_uuid,
            c.scene,
            c.slate,
            c.take,
            c.camera,
            c.file_name,
            c.duration_ms,
            c.ingest_state,
            c.updated_at,

            -- How many proxies exist (0 = none)
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
        WHERE c.project_id = UUID_TO_BIN(:p_uuid,1)
          AND c.day_id     = UUID_TO_BIN(:d_uuid,1)
        ORDER BY c.created_at ASC";

        $stClips = $pdo->prepare($sqlClips);
        $stClips->execute([':p_uuid' => $projectUuid, ':d_uuid' => $dayUuid]);
        $clips = $stClips->fetchAll(PDO::FETCH_ASSOC);

        // Map: clip_uuid => ['poster'=>bool,'poster_path'=>string|null,'proxy_web'=>bool]
        $assets = $this->fetchAssetPresence($pdo, array_column($clips, 'clip_uuid'));

        // CSRF: per-day token bucket
        $token = bin2hex(random_bytes(32));
        $_SESSION['csrf_tokens']['converter'][$dayUuid] = $token;

        View::render('admin/converter/index', [
            'project' => $project,
            'day'     => $day,
            'clips'   => $clips,
            'assets'  => $assets,
            'csrf'    => $token,
        ]);
    }

    public function generatePoster(string $projectUuid, string $dayUuid): void
    {
        $this->jsonHeaders();

        // CSRF (accept per-day, "all" token, or any matching token in the converter bucket)
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $inputToken = $_POST['csrf_token'] ?? '';

        $bucket   = $_SESSION['csrf_tokens']['converter'] ?? [];
        $matched  = false;

        if (is_array($bucket)) {
            // 1) Exact day match
            if (!empty($bucket[$dayUuid]) && hash_equals($bucket[$dayUuid], $inputToken)) {
                $matched = true;
            }
            // 2) Shared "all" token (clips All-days view)
            elseif (!empty($bucket['all']) && hash_equals($bucket['all'], $inputToken)) {
                $matched = true;
            } else {
                // 3) Fallback: accept any value in this bucket that equals the token
                foreach ($bucket as $val) {
                    if (is_string($val) && hash_equals($val, $inputToken)) {
                        $matched = true;
                        break;
                    }
                }
            }
        } elseif (is_string($bucket) && hash_equals($bucket, $inputToken)) {
            // Legacy: single string
            $matched = true;
        }

        if (!$matched) {
            http_response_code(419);
            echo json_encode(['ok' => false, 'error' => 'CSRF token mismatch']);
            return;
        }


        if (!$this->canManageDay($projectUuid)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Forbidden']);
            return;
        }

        $clipUuid = trim($_POST['clip_uuid'] ?? '');
        $force    = (int)($_POST['force'] ?? 0) === 1;

        if (!$clipUuid) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Missing clip_uuid']);
            return;
        }

        $pdo = DB::pdo();
        try {

            // Fetch clip
            $sqlClip = "SELECT
                bin_to_uuid(id,1) AS clip_uuid,
                file_name,
                duration_ms,
                ingest_state,
                scene,
                slate,
                take
            FROM clips
            WHERE id = uuid_to_bin(:c_uuid,1)
              AND project_id = uuid_to_bin(:p_uuid,1)
              AND day_id     = uuid_to_bin(:d_uuid,1)
            FOR UPDATE";
            $stClip = $pdo->prepare($sqlClip);
            $stClip->execute([
                ':c_uuid' => $clipUuid,
                ':p_uuid' => $projectUuid,
                ':d_uuid' => $dayUuid,
            ]);
            $clip = $stClip->fetch(PDO::FETCH_ASSOC);
            if (!$clip) {
                http_response_code(404);
                echo json_encode(['ok' => false, 'error' => 'Clip not found']);
                return;
            }

            // Check if poster exists unless force
            $hasPoster = $this->assetExists($pdo, $clipUuid, 'poster');
            if ($hasPoster && !$force) {
                echo json_encode(['ok' => true, 'skipped' => true, 'reason' => 'exists']);
                return;
            }

            // Resolve source input (prefer proxy_web later; for now, use external ref or original/proxy if you already store it)
            $source = $this->resolveBestInputForPoster($pdo, $clipUuid);
            if (!$source) {
                http_response_code(422);
                echo json_encode(['ok' => false, 'error' => 'Missing source media for poster']);
                return;
            }

            // Build the PUBLIC poster URL exactly as your storage layout uses:
            // /data/zendailies/uploads/{projectUuid}/{dayUuid}/{clipUuid}/poster/clip.jpg
            $storRoot = rtrim((string)getenv('ZEN_STOR_DIR'), '/');          // /var/www/html/data/zendailies/uploads
            $pubBase  = rtrim((string)getenv('ZEN_STOR_PUBLIC_BASE'), '/');  // /data/zendailies/uploads

            if ($storRoot === '' || $pubBase === '') {
                http_response_code(500);
                echo json_encode(['ok' => false, 'error' => 'Storage env not set (ZEN_STOR_DIR / ZEN_STOR_PUBLIC_BASE)']);
                return;
            }

            // PUBLIC href → exactly your desired layout
            $href = $pubBase
                . '/' . $projectUuid
                . '/' . $dayUuid
                . '/' . $clipUuid
                . '/poster/clip.jpg';

            // Map PUBLIC → FS for ffmpeg output
            if (strpos($href, $pubBase . '/') !== 0) {
                http_response_code(500);
                echo json_encode(['ok' => false, 'error' => 'Poster public URL not under base', 'href' => $href, 'base' => $pubBase]);
                return;
            }
            $dest = $storRoot . substr($href, strlen($pubBase));



            // Ensure directory
            if (!is_dir(dirname($dest)) && !@mkdir(dirname($dest), 0770, true)) {
                http_response_code(500);
                echo json_encode(['ok' => false, 'error' => 'Failed to create storage dir']);
                return;
            }

            // Run ffmpeg (CPU)
            $ff = new FFmpegService(getenv('FFMPEG_BIN') ?: 'ffmpeg');
            // Choose a seek time ~10s in, with jitter, and avoid the last used time if force=1
            $lastSeek = null;
            try {
                $stLast = $pdo->prepare("
                    SELECT meta_json
                    FROM events_audit
                    WHERE entity = 'clip'
                    AND entity_id = uuid_to_bin(:c_uuid,1)
                    AND action = 'generate_poster'
                    ORDER BY created_at DESC
                    LIMIT 1
                ");
                $stLast->execute([':c_uuid' => $clipUuid]);
                if ($row = $stLast->fetch(PDO::FETCH_ASSOC)) {
                    $mj = json_decode((string)$row['meta_json'], true);
                    if (is_array($mj) && isset($mj['seek'])) {
                        $lastSeek = (int)$mj['seek'];
                    }
                }
            } catch (\Throwable $ignore) {
            }

            $seekSeconds = $this->pickPosterSecond((int)($clip['duration_ms'] ?? 0), $lastSeek, $force === 1);

            // Gentle clamp: only constrain if we know duration; otherwise keep picker’s randomness
            $durSecKnown = (int)floor((int)($clip['duration_ms'] ?? 0) / 1000);
            if ($durSecKnown > 2) {
                $seekSeconds = max(2, min($durSecKnown - 2, $seekSeconds));
            } else {
                // duration unknown/very short — leave $seekSeconds as chosen by pickPosterSecond()
                $seekSeconds = max(2, $seekSeconds);
            }



            $result = $ff->generatePoster($source, $dest, $seekSeconds, 640);

            if (!$result['ok']) {
                http_response_code(500);
                echo json_encode(['ok' => false, 'error' => 'ffmpeg failed', 'detail' => $result['err'] ?? null]);
                return;
            }

            // 2) Gather file stats (never fatal)
            $size = @filesize($dest) ?: null;
            [$w, $h] = @getimagesize($dest) ?: [null, null];

            // 3) Upsert asset in its own transaction; if it fails, we still return ok with a warning
            $dbWarning = null;
            try {
                $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

                $pdo->beginTransaction();

                // Store the PUBLIC path, like your 'proxy_web' entries
                $this->upsertAsset($pdo, $clipUuid, 'poster', $href, $size, null, $w, $h, 'image/jpeg');

                // Optionally bump ingest_state → 'ready' only when scene/slate/take are all present (DB constraint: clips_chk_1)
                if (($clip['ingest_state'] ?? '') === 'provisional') {
                    $hasIdentity = ($clip['scene'] ?? null) !== null
                        && ($clip['slate'] ?? null) !== null
                        && ($clip['take']  ?? null) !== null;

                    if ($hasIdentity) {
                        $sqlUpd = "UPDATE clips SET ingest_state='ready' WHERE id = uuid_to_bin(:c_uuid2,1)";
                        $stUpd = $pdo->prepare($sqlUpd);
                        $stUpd->execute([':c_uuid2' => $clipUuid]);
                    }
                    // else: keep 'provisional' until metadata is complete (avoids violating clips_chk_1)
                }

                $pdo->commit();

                // ---- Verify row actually exists; if not, record a warning and fall through as ok ----
                $verify = $pdo->prepare("
                    SELECT 1
                    FROM clip_assets
                    WHERE clip_id = uuid_to_bin(?,1) AND asset_type = 'poster'
                    LIMIT 1
                ");
                $verify->execute([$clipUuid]);
                if (!$verify->fetchColumn()) {
                    $dbWarning = ($dbWarning ? $dbWarning . ' | ' : '') . 'poster row missing after commit';
                    // write a simple log to storage for later inspection
                    @file_put_contents(
                        rtrim((string)getenv('ZEN_STOR_DIR'), '/') . '/../_logs/poster_db_fail.log',
                        sprintf("[%s] clip=%s href=%s\n", date('c'), $clipUuid, $href),
                        FILE_APPEND
                    );
                }
            } catch (\Throwable $dbEx) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                // Keep the poster; just surface a non-fatal warning
                $dbWarning = $dbEx->getMessage();
            }

            // 4) Audit is best-effort: never throw
            try {
                $this->audit($pdo, 'clip', $clipUuid, 'generate_poster', [
                    'source' => $source,
                    'dest'   => $href,
                    'seek'   => $seekSeconds,
                    'width'  => $w,
                    'height' => $h,
                    'bytes'  => $size,
                    'db_warning' => $dbWarning,
                ]);
            } catch (\Throwable $ignore) {
            }

            // 5) Always succeed if the image exists
            echo json_encode([
                'ok'   => true,
                'href' => $href,
                'width' => $w,
                'height' => $h,
                'bytes' => $size,
                'used_seek' => $seekSeconds,   // <-- added
                'db_warning' => $dbWarning,
            ]);
            return;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            http_response_code(500);
            echo json_encode([
                'ok'      => false,
                'error'   => 'Exception',
                'message' => $e->getMessage(),
                'trace'   => substr($e->getTraceAsString(), 0, 1500)
            ]);
        }
    }

    public function generatePostersBulk(string $projectUuid, string $dayUuid): void
    {
        $this->jsonHeaders();

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // CSRF (same rules as generatePoster)
        $inputToken = $_POST['csrf_token'] ?? '';

        $bucket   = $_SESSION['csrf_tokens']['converter'] ?? [];
        $matched  = false;

        if (is_array($bucket)) {
            if (!empty($bucket[$dayUuid]) && hash_equals($bucket[$dayUuid], $inputToken)) {
                $matched = true;
            } elseif (!empty($bucket['all']) && hash_equals($bucket['all'], $inputToken)) {
                $matched = true;
            } else {
                foreach ($bucket as $val) {
                    if (is_string($val) && hash_equals($val, $inputToken)) {
                        $matched = true;
                        break;
                    }
                }
            }
        } elseif (is_string($bucket) && hash_equals($bucket, $inputToken)) {
            $matched = true;
        }

        if (!$matched) {
            http_response_code(419);
            echo json_encode(['ok' => false, 'error' => 'CSRF token mismatch']);
            return;
        }



        if (!$this->canManageDay($projectUuid)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Forbidden']);
            return;
        }

        $pdo = DB::pdo();

        // Global "force" flag for this bulk call (0 or 1)
        $forceGlobal = ((int)($_POST['force'] ?? 0) === 1);

        // --- 1) Check if we got an explicit selection from clips page ---
        $clipUuidsRaw = trim($_POST['clip_uuids'] ?? '');
        $clipUuidList = array_values(array_filter(array_map('trim', explode(',', $clipUuidsRaw))));
        $rows = [];

        if (!empty($clipUuidList)) {
            // Use the *selected* clips
            foreach ($clipUuidList as $uuid) {
                if ($uuid !== '') {
                    $rows[] = ['clip_uuid' => $uuid];
                }
            }
        } else {
            // Fallback: old behaviour – all clips on this day that are missing a poster
            $sql = "
            SELECT bin_to_uuid(c.id,1) AS clip_uuid, c.duration_ms
            FROM clips c
            WHERE c.project_id = uuid_to_bin(:p_uuid,1)
              AND c.day_id     = uuid_to_bin(:d_uuid,1)
              AND NOT EXISTS (
                SELECT 1 FROM clip_assets a
                WHERE a.clip_id = c.id AND a.asset_type = 'poster'
              )
            ORDER BY c.created_at ASC
        ";
            $st = $pdo->prepare($sql);
            $st->execute([':p_uuid' => $projectUuid, ':d_uuid' => $dayUuid]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        }

        $ok = 0;
        $fail = 0;
        $skipped = 0;

        if (empty($rows)) {
            echo json_encode([
                'ok'      => true,
                'done'    => 0,
                'failed'  => 0,
                'skipped' => 0,
            ]);
            return;
        }

        // --- 2) Loop rows and reuse generatePoster() internally ---
        foreach ($rows as $r) {
            // Prepare per-call POST payload
            $_POST['clip_uuid']  = $r['clip_uuid'];
            $_POST['force']      = $forceGlobal ? 1 : 0;
            $_POST['csrf_token'] = $inputToken; // keep the same validated token

            ob_start();
            $this->generatePoster($projectUuid, $dayUuid);
            $respJson = ob_get_clean();
            $resp = json_decode($respJson ?: '{}', true);

            if (!is_array($resp)) {
                $fail++;
                continue;
            }

            if (!empty($resp['skipped'])) {
                $skipped++;
            } elseif (!empty($resp['ok'])) {
                $ok++;
            } else {
                $fail++;
            }
        }

        echo json_encode([
            'ok'      => true,
            'done'    => $ok,
            'failed'  => $fail,
            'skipped' => $skipped,
        ]);
    }


    /**
     * Pull metadata (duration_ms, fps, tc_start) for a clip and write it to DB.
     * Expects ?clip_uuid=... in the request or matches however generatePoster() finds the clip.
     */
    /**
     * Pull metadata (duration_ms, fps, tc_start) for a clip and write it to DB.
     * POST only. Expects:
     *  - projectUuid, dayUuid in route params (same as generatePoster)
     *  - clip_uuid in POST
     *  - csrf_token same bucket as converter
     */
    public function pullMetadata(string $projectUuid, string $dayUuid): void
    {
        $this->jsonHeaders();

        if (session_status() === PHP_SESSION_NONE) session_start();

        // CSRF (reuse same token bucket as generatePoster / index)
        $inputToken = $_POST['csrf_token'] ?? '';

        $bucket   = $_SESSION['csrf_tokens']['converter'] ?? [];
        $expected = '';

        if (is_array($bucket)) {
            if (!empty($bucket[$dayUuid])) {
                $expected = $bucket[$dayUuid];
            } elseif (!empty($bucket['all'])) {
                $expected = $bucket['all'];
            }
        } elseif (is_string($bucket)) {
            $expected = $bucket;
        }

        if (!$expected || !hash_equals($expected, $inputToken)) {
            http_response_code(419);
            echo json_encode(['ok' => false, 'error' => 'CSRF token mismatch']);
            return;
        }



        // Auth guard: same rule as poster
        if (!$this->canManageDay($projectUuid)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Forbidden']);
            return;
        }

        $clipUuid = trim($_POST['clip_uuid'] ?? '');
        if ($clipUuid === '') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Missing clip_uuid']);
            return;
        }

        $pdo = DB::pdo();

        try {
            // --- 1. Load clip row (FOR UPDATE so we don't race state changes)
            $sqlClip = "SELECT
                bin_to_uuid(id,1) AS clip_uuid,
                id                           AS clip_bin,       -- raw binary PK
                file_name,
                duration_ms,
                ingest_state,
                scene,
                slate,
                take
            FROM clips
            WHERE id = uuid_to_bin(:c_uuid,1)
              AND project_id = uuid_to_bin(:p_uuid,1)
              AND day_id     = uuid_to_bin(:d_uuid,1)
            FOR UPDATE";
            $stClip = $pdo->prepare($sqlClip);
            $stClip->execute([
                ':c_uuid' => $clipUuid,
                ':p_uuid' => $projectUuid,
                ':d_uuid' => $dayUuid,
            ]);
            $clip = $stClip->fetch(PDO::FETCH_ASSOC);
            if (!$clip) {
                http_response_code(404);
                echo json_encode(['ok' => false, 'error' => 'Clip not found']);
                return;
            }

            // We'll need the binary id for updates later
            $clipIdBin = $clip['clip_bin'];

            // --- 2. Resolve source video path on disk (same logic as poster)
            $sourceFs = $this->resolveBestInputForPoster($pdo, $clipUuid);
            if (!$sourceFs || !is_file($sourceFs)) {
                http_response_code(422);
                echo json_encode(['ok' => false, 'error' => 'Missing source media for metadata probe']);
                return;
            }

            // --- 3. Run ffprobe (core metadata only)
            $core = FFmpegService::probeCoreMetadata($sourceFs);

            // $core = [
            //   'duration_ms' => int|null,
            //   'fps'         => float|null,
            //   'tc_start'    => string|null,
            // ]

            // --- 4. Update clips.duration_ms and clips.tc_start
            $upd = $pdo->prepare("
                UPDATE clips
                   SET duration_ms = COALESCE(:dur, duration_ms),
                       tc_start    = COALESCE(:tc,  tc_start)
                 WHERE id = :id
                 LIMIT 1
            ");
            $upd->execute([
                ':dur' => $core['duration_ms'] ?? null,
                ':tc'  => $core['tc_start']    ?? null,
                ':id'  => $clipIdBin,
            ]);

            // --- 5. Upsert fps into clip_metadata
            if (isset($core['fps']) && $core['fps'] !== null) {
                $insMeta = $pdo->prepare("
                    INSERT INTO clip_metadata (clip_id, meta_key, meta_value)
                    VALUES (:cid, 'fps', :val)
                    ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)
                ");
                $insMeta->execute([
                    ':cid' => $clipIdBin,
                    ':val' => (string)$core['fps'],
                ]);
            }

            // --- 6. Audit (optional but keeps history consistent with poster)
            try {
                $this->audit($pdo, 'clip', $clipUuid, 'pull_metadata', [
                    'duration_ms' => $core['duration_ms'] ?? null,
                    'tc_start'    => $core['tc_start']    ?? null,
                    'fps'         => $core['fps']         ?? null,
                    'source'      => $sourceFs,
                ]);
            } catch (\Throwable $ignore) {
            }

            // --- 7. Respond
            echo json_encode([
                'ok'          => true,
                'duration_ms' => $core['duration_ms'] ?? null,
                'tc_start'    => $core['tc_start']    ?? null,
                'fps'         => $core['fps']         ?? null,
            ]);
            return;
        } catch (Throwable $e) {
            http_response_code(500);
            echo json_encode([
                'ok'      => false,
                'error'   => 'Exception',
                'message' => $e->getMessage(),
                'trace'   => substr($e->getTraceAsString(), 0, 800),
            ]);
            return;
        }
    }


    // --- helpers ---

    private function canManageDay(string $projectUuid): bool
    {
        $isSuper = (int)($_SESSION['account']['is_superuser'] ?? 0) === 1;
        if ($isSuper) return true;

        $personUuid = $_SESSION['person_uuid'] ?? null;
        if (!$personUuid) return false;

        $pdo = DB::pdo();
        // Is this person a project admin (DIT) on the project?
        $sql = "
            SELECT 1
            FROM project_members pm
            JOIN persons p ON p.id = pm.person_id
            WHERE pm.project_id = uuid_to_bin(:p_uuid,1)
              AND p.id = uuid_to_bin(:person,1)
              AND pm.is_project_admin = 1
            LIMIT 1
        ";
        $st = $pdo->prepare($sql);
        $st->execute([':p_uuid' => $projectUuid, ':person' => $personUuid]);
        return (bool)$st->fetchColumn();
    }

    private function fetchAssetPresence(PDO $pdo, array $clipUuids): array
    {
        if (empty($clipUuids)) return [];
        // Build positional placeholders to avoid named re-use
        $marks = implode(',', array_fill(0, count($clipUuids), 'uuid_to_bin(?,1)'));

        $sql = "
            SELECT
              BIN_TO_UUID(a.clip_id,1) AS clip_uuid,
              a.asset_type,
              a.storage_path
            FROM clip_assets a
            WHERE a.clip_id IN ($marks)
              AND a.asset_type IN ('poster','proxy_web')
        ";
        $st = $pdo->prepare($sql);
        $st->execute($clipUuids);

        $out = [];
        while ($row = $st->fetch(\PDO::FETCH_ASSOC)) {
            $cu   = $row['clip_uuid'];
            $type = $row['asset_type'];

            if (!isset($out[$cu])) {
                $out[$cu] = [
                    'poster'      => false,
                    'poster_path' => null,
                    'proxy_web'       => false, // this means "has proxy_web"
                ];
            }

            if ($type === 'poster') {
                $out[$cu]['poster']      = true;
                $out[$cu]['poster_path'] = $row['storage_path'];
            } elseif ($type === 'proxy_web') {
                $out[$cu]['proxy_web'] = true;
            }
        }

        return $out;
    }


    private function assetExists(PDO $pdo, string $clipUuid, string $type): bool
    {
        $sql = "SELECT 1 FROM clip_assets WHERE clip_id = uuid_to_bin(:c_uuid,1) AND asset_type = :t LIMIT 1";
        $st = $pdo->prepare($sql);
        $st->execute([':c_uuid' => $clipUuid, ':t' => $type]);
        return (bool)$st->fetchColumn();
    }

    private function resolveBestInputForPoster(PDO $pdo, string $clipUuid): ?string
    {
        $pubBase  = rtrim((string)getenv('ZEN_STOR_PUBLIC_BASE'), '/'); // e.g., /data/zendailies/uploads
        $storBase = rtrim((string)getenv('ZEN_STOR_DIR'), '/');         // e.g., /var/www/html/data/zendailies/uploads

        // 1) Prefer worker-generated web proxy, then original upload
        $preferredTypes = ['proxy_web', 'original'];

        foreach ($preferredTypes as $type) {
            $sql = "
                SELECT storage_path
                FROM clip_assets
                WHERE clip_id = UUID_TO_BIN(:c_uuid,1)
                  AND asset_type = :t
                ORDER BY created_at DESC
                LIMIT 1
            ";
            $st = $pdo->prepare($sql);
            $st->execute([
                ':c_uuid' => $clipUuid,
                ':t'      => $type,
            ]);
            $row = $st->fetch(\PDO::FETCH_ASSOC);
            if (!$row || empty($row['storage_path'])) {
                continue;
            }

            $public = (string)$row['storage_path'];

            // If storage_path is already an absolute file path and exists, use it directly
            if ($public !== '' && @is_file($public)) {
                return $public;
            }

            // If it's a public path under ZEN_STOR_PUBLIC_BASE, map to filesystem under ZEN_STOR_DIR
            if ($public !== '' && $pubBase !== '' && strpos($public, $pubBase . '/') === 0) {
                $fs = $storBase . substr($public, strlen($pubBase));
                if (@is_file($fs)) {
                    return $fs;
                }
            }
        }

        // 2) Fallback: external refs (e.g. camera drive / NAS paths)
        $sqlExt = "
            SELECT file_path
            FROM clip_external_refs
            WHERE clip_id = UUID_TO_BIN(:c_uuid,1)
            ORDER BY created_at DESC
            LIMIT 1
        ";
        $stE = $pdo->prepare($sqlExt);
        $stE->execute([':c_uuid' => $clipUuid]);
        $e = $stE->fetch(\PDO::FETCH_ASSOC);
        if ($e && is_string($e['file_path']) && $e['file_path'] !== '' && @file_exists($e['file_path'])) {
            return $e['file_path'];
        }

        // 3) Nothing usable found
        return null;
    }


    private function midpointSeconds(int $durationMs): int
    {
        if ($durationMs > 0) return max(1, (int)floor(($durationMs / 1000) / 2));
        return 1; // default
    }

    private function pickPosterSecond(int $durationMs, ?int $lastSeek, bool $force): int
    {
        // Estimate duration in seconds; assume ~30 s if unknown
        $dur = (int)floor($durationMs / 1000);
        if ($dur <= 0) $dur = 30;

        // Helper clamp
        $clamp = static fn(int $v, int $lo, int $hi): int => max($lo, min($hi, $v));

        // Define 3 time-bands within clip (fractions of dur)
        $bands = [
            [0.10, 0.25], // A  early safe zone
            [0.35, 0.55], // B  mid zone
            [0.65, 0.85], // C  late zone
        ];

        // Determine last band if we know last seek
        $lastBandIdx = null;
        if ($lastSeek !== null && $dur > 0) {
            $rel = $lastSeek / $dur;
            if ($rel >= 0.10 && $rel <= 0.25) $lastBandIdx = 0;
            elseif ($rel >= 0.35 && $rel <= 0.55) $lastBandIdx = 1;
            elseif ($rel >= 0.65 && $rel <= 0.85) $lastBandIdx = 2;
        }

        // Pick band
        $bandIdx = 0; // default A
        if ($force) {
            if ($lastBandIdx === null) {
                // unknown last → random of B or C
                $bandIdx = random_int(1, 2);
            } else {
                // rotate A→B→C→A
                $bandIdx = ($lastBandIdx + 1) % 3;
            }
        }

        // Convert chosen band to absolute seconds
        [$fLo, $fHi] = $bands[$bandIdx];
        $lo = $clamp((int)floor($dur * $fLo), 2, max(2, $dur - 2));
        $hi = $clamp((int)ceil($dur * $fHi),  2, max(2, $dur - 2));
        if ($hi <= $lo) $hi = $clamp($lo + 2, 2, max(2, $dur - 1));

        // Random inside band
        $seek = random_int($lo, $hi);

        // If force and we hit same time, nudge
        if ($force && $lastSeek !== null && $seek === $lastSeek) {
            $seek = $clamp($seek + random_int(2, 5), 2, max(2, $dur - 2));
        }

        // Final clamp
        return $clamp($seek, 2, max(2, $dur - 2));
    }



    private function upsertAsset(
        \PDO $pdo,
        string $clipUuid,
        string $type,           // 'poster' | 'proxy_web' | etc
        string $relPath,        // storage_path relative to public base (e.g. /data/zendailies/uploads/.../poster.jpg)
        ?int $byteSize,
        ?string $checksum,      // sha1 (or null)
        ?int $w,
        ?int $h,
        ?string $codec          // 'image/jpeg' (or null)
    ): void {
        // Idempotent upsert (simple approach): delete any existing asset of this type, then insert
        $del = $pdo->prepare("DELETE FROM clip_assets WHERE clip_id = uuid_to_bin(:c,1) AND asset_type = :t");
        $del->execute([':c' => $clipUuid, ':t' => $type]);

        // IMPORTANT: the column is 'storage_path' (not mangled)
        $sql = "
        INSERT INTO clip_assets (
            id, clip_id, asset_type, storage_path, byte_size, checksum, width, height, codec, created_at
        ) VALUES (
            uuid_to_bin(uuid(),1),
            uuid_to_bin(:c,1),
            :t,
            :p,
            :sz,
            :chk,
            :w,
            :h,
            :codec,
            NOW()
        )
    ";
        $ins = $pdo->prepare($sql);
        $ins->execute([
            ':c'     => $clipUuid,
            ':t'     => $type,
            ':p'     => $relPath,
            ':sz'    => $byteSize,
            ':chk'   => $checksum,
            ':w'     => $w,
            ':h'     => $h,
            ':codec' => $codec,
        ]);
    }



    private function audit(PDO $pdo, string $entity, string $entityUuid, string $action, array $meta): void
    {
        try {
            $actor = $_SESSION['person_uuid'] ?? null;
            $sql = "
                INSERT INTO events_audit (id, actor_id, project_id, entity, entity_id, action, meta_json, created_at)
                VALUES (
                  uuid_to_bin(uuid(),1),
                  " . ($actor ? "uuid_to_bin(:actor,1)" : "NULL") . ",
                  NULL,
                  :entity,
                  uuid_to_bin(:entity_id,1),
                  :action,
                  :meta,
                  NOW()
                )
            ";
            $st = $pdo->prepare($sql);
            $params = [
                ':entity'    => $entity,
                ':entity_id' => $entityUuid,
                ':action'    => $action,
                ':meta'      => json_encode($meta, JSON_UNESCAPED_SLASHES),
            ];
            if ($actor) $params[':actor'] = $actor;
            $st->execute($params);
        } catch (\Throwable $ignore) {
        }
    }

    private function jsonHeaders(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
    }
}
