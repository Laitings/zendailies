<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Support\DB;
use App\Support\View;
use App\Services\FFmpegService;
use App\Services\ResolveCsvImportService;


final class ClipUploadController
{
    /**
     * JSON responder: always returns JSON and exits.
     */
    private function respond(int $status, array $payload): void
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
        }
        echo json_encode($payload, JSON_UNESCAPED_SLASHES);
        exit;
    }

    private function ensureProjectContext(string $projectUuid): void
    {
        // Resolve project via PDO instead of a repository method
        if (empty($_SESSION['current_project']['uuid']) || $_SESSION['current_project']['uuid'] !== $projectUuid) {
            $pdo = DB::pdo();
            $stmt = $pdo->prepare("
                SELECT BIN_TO_UUID(id,1) AS uuid, title, code
                FROM projects
                WHERE id = UUID_TO_BIN(:p,1)
                LIMIT 1
            ");
            $stmt->execute([':p' => $projectUuid]);
            $proj = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($proj) {
                $_SESSION['current_project'] = [
                    'uuid'  => $proj['uuid'],
                    'title' => $proj['title'] ?? 'Project',
                    'code'  => $proj['code']  ?? '',
                ];
            }
        }
    }

    public function uploadForm(string $projectUuid, string $dayUuid): void
    {
        $this->ensureProjectContext($projectUuid);

        $pdo = DB::pdo();

        // Project (for header/breadcrumb)
        $projectStmt = $pdo->prepare("
            SELECT BIN_TO_UUID(id,1) AS uuid, title, code
            FROM projects
            WHERE id=UUID_TO_BIN(:p,1)
            LIMIT 1
        ");
        $projectStmt->execute([':p' => $projectUuid]);
        $project = $projectStmt->fetch(\PDO::FETCH_ASSOC)
            ?: ['uuid' => $projectUuid, 'title' => 'Project', 'code' => ''];

        // Day
        $dayStmt = $pdo->prepare("
            SELECT BIN_TO_UUID(d.id,1) AS day_uuid, d.shoot_date, d.title, d.unit
            FROM days d
            WHERE d.id = UUID_TO_BIN(:d,1)
              AND d.project_id = UUID_TO_BIN(:p,1)
            LIMIT 1
        ");
        $dayStmt->execute([':d' => $dayUuid, ':p' => $projectUuid]);
        $day = $dayStmt->fetch(\PDO::FETCH_ASSOC);

        if (!$day) {
            http_response_code(404);
            echo "Day not found.";
            return;
        }

        View::render('admin/clips/upload', [
            'project'       => $project,
            'project_uuid'  => $projectUuid,
            'day'           => $day,
        ]);
    }

    public function handleUpload(string $projectUuid, string $dayUuid): void
    {
        // Force JSON-only output and capture stray echoes/notices
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
        }
        ob_start();

        try {
            // ---------- 1) Validate file ----------
            if (empty($_FILES['file'])) {
                ob_end_clean();
                $this->respond(400, ['ok' => false, 'error' => 'No file received']);
            }
            $file = $_FILES['file'];
            if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
                ob_end_clean();
                $this->respond(400, ['ok' => false, 'error' => 'Upload error code ' . $file['error']]);
            }

            $pdo = DB::pdo();

            // ---------- 2) Validate project/day combo ----------
            $dayRow = $pdo->prepare("
                SELECT d.id AS day_bin, p.id AS project_bin
                FROM days d
                JOIN projects p ON p.id = d.project_id
                WHERE d.id = UUID_TO_BIN(:d,1)
                  AND p.id = UUID_TO_BIN(:p,1)
                LIMIT 1
            ");
            $dayRow->execute([':d' => $dayUuid, ':p' => $projectUuid]);
            $ids = $dayRow->fetch(\PDO::FETCH_ASSOC);
            if (!$ids) {
                ob_end_clean();
                $this->respond(404, ['ok' => false, 'error' => 'Project/Day mismatch']);
            }

            // ---------- 3) Compute storage paths ----------
            $fsBase     = rtrim(getenv('ZEN_STOR_DIR') ?: '/var/www/html/data', '/');
            $publicBase = rtrim(getenv('ZEN_STOR_PUBLIC_BASE') ?: '/data', '/');

            // Inject 'source' into the path to keep originals separate from proxies/posters
            $relSubdir  = "source/{$projectUuid}/{$dayUuid}";
            $absDir     = $fsBase . '/' . $relSubdir;

            // Ensure base and target dirs
            if (!is_dir($fsBase) && !@mkdir($fsBase, 0775, true)) {
                error_log('UPLOAD: cannot create base dir ' . $fsBase . ' err=' . json_encode(error_get_last()));
                ob_end_clean();
                $this->respond(500, ['ok' => false, 'error' => 'Base storage dir unavailable']);
            }
            if (!is_writable($fsBase)) {
                error_log('UPLOAD: base dir not writable ' . $fsBase);
                ob_end_clean();
                $this->respond(500, ['ok' => false, 'error' => 'Base storage dir not writable']);
            }
            if (!is_dir($absDir) && !@mkdir($absDir, 0775, true)) {
                $e = error_get_last();
                error_log('UPLOAD: mkdir failed for ' . $absDir . ' -- ' . (($e['message'] ?? 'unknown')));
                ob_end_clean();
                $this->respond(500, ['ok' => false, 'error' => 'Failed to create storage dir']);
            }

            // ---------- 4) Move file ----------
            $origName = $file['name'];
            $safeName = preg_replace('/[^\w\-.]+/u', '_', $origName);
            $target   = $absDir . '/' . $safeName;

            if (!@move_uploaded_file($file['tmp_name'], $target)) {
                $e = error_get_last();
                error_log('UPLOAD: move_uploaded_file failed to ' . $target . ' -- ' . json_encode($e));
                ob_end_clean();
                $this->respond(500, ['ok' => false, 'error' => 'Failed to store upload']);
            }

            $size = @filesize($target) ?: 0;
            if ($size <= 0) {
                @unlink($target);
                error_log('UPLOAD: stored file has zero size at ' . $target);
                ob_end_clean();
                $this->respond(500, ['ok' => false, 'error' => 'Stored file has zero size']);
            }

            $publicPath = $publicBase . '/' . $relSubdir . '/' . $safeName;

            // [NEW] If the uploaded file is a Resolve CSV, ingest it and return JSON (no clip is created here).
            $ext = strtolower(pathinfo($safeName, PATHINFO_EXTENSION));
            if ($ext === 'csv') {
                $svc = new ResolveCsvImportService();
                try {
                    $pdo->beginTransaction();
                    $ing = $svc->ingestCsv($pdo, $projectUuid, $dayUuid, $target, $origName);
                    $pdo->commit();

                    // INSERT THIS:
                    $feedback = $svc->applyLatestJobToDay($pdo, $projectUuid, $dayUuid);

                    ob_end_clean();
                    $this->respond(200, [
                        'ok'               => true,
                        'csv_imported'     => true,
                        'import_job_uuid'  => $ing['job_uuid'] ?? null,
                        'total_rows'       => $ing['total_rows'] ?? null,
                        // optional: surface feedback
                        'matched'          => $feedback['matched_count'] ?? null,
                        'succeeded'        => $feedback['succeeded'] ?? [],
                        'unmatched'        => $feedback['unmatched'] ?? [],
                        'file'             => $origName,
                        'path'             => $publicPath
                    ]);
                } catch (\Throwable $e) {
                    if ($pdo->inTransaction()) $pdo->rollBack();
                    error_log('[ResolveCSV] ingest failed: ' . $e->getMessage());
                    ob_end_clean();
                    $this->respond(500, ['ok' => false, 'error' => 'CSV import failed']);
                }
            }


            // ---------- 4) Move file ----------
            // ... existing code that sets $publicPath ...

            // ---------- 5) DB inserts (create or replace by filename) ----------
            $pdo->beginTransaction();
            try {
                // 5a) Check if a clip already exists for this project/day + file_name
                $stmtExisting = $pdo->prepare("
                    SELECT id
                    FROM clips
                    WHERE project_id = :proj
                    AND day_id     = :day
                    AND file_name  = :file_name
                    ORDER BY created_at DESC
                    LIMIT 1
                ");
                $stmtExisting->bindParam(':proj', $ids['project_bin'], \PDO::PARAM_LOB);
                $stmtExisting->bindParam(':day',  $ids['day_bin'],     \PDO::PARAM_LOB);
                $stmtExisting->bindValue(':file_name', $origName);
                $stmtExisting->execute();
                $clipBin = $stmtExisting->fetchColumn() ?: null;

                if ($clipBin) {
                    // Existing clip: treat this upload as a replacement of the original media

                    // Remove old original/proxy assets for this clip
                    $stmtDelAssets = $pdo->prepare("
                        DELETE FROM clip_assets
                        WHERE clip_id = :clip
                        AND asset_type IN ('original','proxy_web')
                    ");
                    $stmtDelAssets->bindParam(':clip', $clipBin, \PDO::PARAM_LOB);
                    $stmtDelAssets->execute();

                    // Cancel any pending encode jobs for this clip (they refer to the old file)
                    $stmtCancelJobs = $pdo->prepare("
                        UPDATE jobs_queue
                        SET state = 'canceled',
                            cancel_requested = 1,
                            updated_at = NOW()
                        WHERE clip_id = :clip
                        AND state IN ('queued','running')
                    ");
                    $stmtCancelJobs->bindParam(':clip', $clipBin, \PDO::PARAM_LOB);
                    $stmtCancelJobs->execute();
                } else {
                    // No existing clip with this filename -> create a new provisional clip
                    $stmtClip = $pdo->prepare("
                        INSERT INTO clips (project_id, day_id, file_name, ingest_state)
                        VALUES (:proj, :day, :file_name, 'provisional')
                    ");
                    $stmtClip->bindParam(':proj', $ids['project_bin'], \PDO::PARAM_LOB);
                    $stmtClip->bindParam(':day',  $ids['day_bin'],     \PDO::PARAM_LOB);
                    $stmtClip->bindValue(':file_name', $origName);
                    $stmtClip->execute();

                    // Fetch the inserted clip id (binary)
                    $stmtFind = $pdo->prepare("
                        SELECT id FROM clips
                        WHERE project_id=:proj AND day_id=:day AND file_name=:file_name
                        ORDER BY created_at DESC
                        LIMIT 1
                    ");
                    $stmtFind->bindParam(':proj', $ids['project_bin'], \PDO::PARAM_LOB);
                    $stmtFind->bindParam(':day',  $ids['day_bin'],     \PDO::PARAM_LOB);
                    $stmtFind->bindValue(':file_name', $origName);
                    $stmtFind->execute();
                    $clipBin = $stmtFind->fetchColumn();
                }

                if (!$clipBin) {
                    throw new \RuntimeException('Failed to resolve clip id after upload');
                }

                // Register (new) original asset for this clip
                $stmtAsset = $pdo->prepare("
                    INSERT INTO clip_assets (clip_id, asset_type, storage_path, byte_size)
                    VALUES (:clip, 'original', :path, :size)
                ");
                $stmtAsset->bindParam(':clip', $clipBin, \PDO::PARAM_LOB);
                $stmtAsset->bindValue(':path', $publicPath);
                $stmtAsset->bindValue(':size', $size, \PDO::PARAM_INT);
                $stmtAsset->execute();

                $pdo->commit();
            } catch (\Throwable $e) {
                $pdo->rollBack();
                @unlink($target);
                ob_end_clean();
                $this->respond(500, ['ok' => false, 'error' => 'DB error: ' . $e->getMessage()]);
            }


            // ---------- 6) Resolve UUID + Poster generation (best-effort) ----------
            $sourceAbsPath = $target;

            $stmtUuid = $pdo->prepare("SELECT BIN_TO_UUID(:id,1) AS uuid");
            $stmtUuid->bindParam(':id', $clipBin, \PDO::PARAM_LOB);
            $stmtUuid->execute();
            $clipUuid = $stmtUuid->fetchColumn() ?: null;

            // === ENCODE QUEUE: enqueue a proxy transcode job (queued state) ===
            $srcFs = $sourceAbsPath;
            $baseName = pathinfo($safeName, PATHINFO_FILENAME);
            // Save the proxy in the 'proxies' directory instead of 'source'
            $proxySubdir = "proxies/{$projectUuid}/{$dayUuid}";
            if (!is_dir($fsBase . '/' . $proxySubdir)) {
                @mkdir($fsBase . '/' . $proxySubdir, 0775, true);
            }
            $targetFs = $fsBase . '/' . $proxySubdir . '/' . $baseName . '_web.mp4';

            // [FIX] Added 'queue' column and set value to 'gpu'
            $stJob = $pdo->prepare("
                INSERT INTO jobs_queue
                    (clip_id, queue, source_path, target_path, preset, state, priority, progress_pct, attempts, created_at, updated_at)
                VALUES
                    (uuid_to_bin(:clip_uuid,1), 'gpu', :src, :dst, :preset, 'queued', :prio, 0, 0, NOW(), NOW())
            ");

            $stJob->execute([
                ':clip_uuid' => $clipUuid,
                ':src'       => $srcFs,
                ':dst'       => $targetFs,
                ':preset'    => 'proxy_h264_720p',
                ':prio'      => 100,
            ]);

            try {
                $this->audit($pdo, 'clip', $clipUuid, 'encode_enqueued', [
                    'source' => $srcFs,
                    'target' => $targetFs,
                    'preset' => 'proxy_h264_720p',
                ]);
            } catch (\Throwable $ignore) {
            }


            error_log('[DEBUG] Poster generation starting for clip ' . ($clipUuid ?? 'NULL') . ' using ' . ($sourceAbsPath ?? 'NULL'));

            // --- AUTO POSTER (best-effort, never breaks the request) ---
            try {
                // Build poster paths in the 'posters' directory
                $posterSubdir = "posters/{$projectUuid}/{$dayUuid}";
                if (!is_dir($fsBase . '/' . $posterSubdir)) {
                    @mkdir($fsBase . '/' . $posterSubdir, 0775, true);
                }
                $posterAbsPath = $fsBase . '/' . $posterSubdir . '/' . $baseName . '.jpg';
                if ($posterAbsPath === $sourceAbsPath) {
                    $posterAbsPath = $sourceAbsPath . '.poster.jpg';
                }

                // Public URL/path for DB
                // Public URL/path for DB points to the 'posters' directory
                $posterPublicPath = $publicBase . '/posters/' . $projectUuid . '/' . $dayUuid . '/' . $baseName . '.jpg';
                if ($posterPublicPath === $publicPath) {
                    $posterPublicPath = $publicPath . '.poster.jpg';
                }

                // Use existing FFmpegService::generatePoster(input, output, seekSeconds, width)
                $ff  = new FFmpegService();
                $res = $ff->generatePoster($sourceAbsPath, $posterAbsPath, 10, 640);
                if (!($res['ok'] ?? false)) {
                    error_log('[ClipUpload] generatePoster failed for ' . ($clipUuid ?? 'NULL') . ': ' . ($res['err'] ?? 'unknown'));
                } else {
                    // Stat and register poster in clip_assets
                    $bytes = @filesize($posterAbsPath) ?: null;
                    [$w, $h] = @getimagesize($posterAbsPath) ?: [null, null];

                    $stmtPoster = $pdo->prepare("
                        INSERT INTO clip_assets (clip_id, asset_type, storage_path, byte_size, width, height, codec)
                        VALUES (:clip, 'poster', :path, :size, :w, :h, 'jpg')
                    ");
                    $stmtPoster->bindParam(':clip', $clipBin, \PDO::PARAM_LOB);
                    $stmtPoster->bindValue(':path', $posterPublicPath);
                    $stmtPoster->bindValue(':size', $bytes, \PDO::PARAM_INT);
                    $stmtPoster->bindValue(':w', $w, \PDO::PARAM_INT);
                    $stmtPoster->bindValue(':h', $h, \PDO::PARAM_INT);
                    $stmtPoster->execute();
                }
            } catch (\Throwable $e) {
                error_log('[ClipUpload] Poster block exception: ' . $e->getMessage());
            }
            // --- END AUTO POSTER ---

            // --- AUTO WAVEFORM (best-effort) ---
            try {
                // 1. Prepare directories
                $waveformSubdir = "waveforms/{$projectUuid}/{$dayUuid}";
                $waveformDirAbs = $fsBase . '/' . $waveformSubdir;

                if (!is_dir($waveformDirAbs)) {
                    @mkdir($waveformDirAbs, 0775, true);
                }

                // 2. Define paths
                // We reuse $baseName from the poster block (e.g. "A001_C001")
                $waveformAbsPath    = $waveformDirAbs . '/' . $baseName . '.json';
                $waveformPublicPath = $publicBase . '/' . $waveformSubdir . '/' . $baseName . '.json';

                // 3. Generate using FFmpegService
                // (We create a new instance or reuse one if you defined $ff above)
                $ffWave = new \App\Services\FFmpegService();
                $resWave = $ffWave->generateWaveformJson($sourceAbsPath, $waveformAbsPath);

                if (!($resWave['ok'] ?? false)) {
                    error_log('[ClipUpload] Waveform generation failed for ' . ($clipUuid ?? 'NULL') . ': ' . ($resWave['err'] ?? 'unknown'));
                } else {
                    // 4. Register in clip_assets
                    $wSize = @filesize($waveformAbsPath) ?: 0;

                    $stmtWave = $pdo->prepare("
                        INSERT INTO clip_assets (clip_id, asset_type, storage_path, byte_size, width, height, codec)
                        VALUES (:clip, 'waveform', :path, :size, NULL, NULL, 'json')
                    ");
                    $stmtWave->bindValue(':clip', $clipBin, \PDO::PARAM_LOB);
                    $stmtWave->bindValue(':path', $waveformPublicPath);
                    $stmtWave->bindValue(':size', $wSize, \PDO::PARAM_INT);
                    $stmtWave->execute();
                }
            } catch (\Throwable $e) {
                // Never block the upload if waveform fails
                error_log('[ClipUpload] Waveform block exception: ' . $e->getMessage());
            }
            // --- END AUTO WAVEFORM ---

            // [NEW] Try to apply Resolve CSV metadata to this clip now (if a CSV was ingested earlier)
            try {
                $svc = new ResolveCsvImportService();
                $svc->applyToClipIfMatch($pdo, $projectUuid, $dayUuid, $clipUuid, $origName, $publicPath);
            } catch (\Throwable $e) {
                error_log('[ResolveCSV] apply-to-clip failed for ' . ($clipUuid ?? 'NULL') . ': ' . $e->getMessage());
            }

            // --- Auto pull core metadata on upload (duration_ms, tc_start, fps) ---
            try {
                // 1) Probe the file you just saved
                $core = \App\Services\FFmpegService::probeCoreMetadata($sourceAbsPath);

                // [NEW] Precise FPS into clips (float + rational)
                $fpsInfo = \App\Services\FFmpegService::getFpsInfo($sourceAbsPath);
                if ($fpsInfo) {
                    $stFps = $pdo->prepare("
                    UPDATE clips
                    SET fps = :fps,
                        fps_num = :num,
                        fps_den = :den
                    WHERE id = uuid_to_bin(:c,1)
                    LIMIT 1
                ");
                    $stFps->execute([
                        ':fps' => $fpsInfo['fps'],
                        ':num' => $fpsInfo['fps_num'],
                        ':den' => $fpsInfo['fps_den'],
                        ':c'   => $clipUuid,
                    ]);
                }

                // 2) Update clips table (duration_ms, tc_start)
                if (!empty($core)) {
                    $stUpd = $pdo->prepare("
                    UPDATE clips
                    SET duration_ms = COALESCE(:dur, duration_ms),
                        tc_start    = COALESCE(:tc,  tc_start)
                    WHERE id = uuid_to_bin(:c,1)
                    LIMIT 1
                ");
                    $stUpd->execute([
                        ':dur' => $core['duration_ms'] ?? null,
                        ':tc'  => $core['tc_start']    ?? null,
                        ':c'   => $clipUuid,
                    ]);

                    // 3) Upsert fps into clip_metadata
                    if (isset($core['fps']) && $core['fps'] !== null) {
                        $stMeta = $pdo->prepare("
                        INSERT INTO clip_metadata (clip_id, meta_key, meta_value)
                        VALUES (uuid_to_bin(:c,1), 'fps', :v)
                        ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)
                    ");
                        $stMeta->execute([
                            ':c' => $clipUuid,
                            ':v' => (string)$core['fps'],
                        ]);
                    }

                    // 4) Optional audit (non-fatal)
                    try {
                        $this->audit($pdo, 'clip', $clipUuid, 'pull_metadata@upload', [
                            'source'       => $sourceAbsPath,
                            'duration_ms'  => $core['duration_ms'] ?? null,
                            'tc_start'     => $core['tc_start'] ?? null,
                            'fps'          => $core['fps'] ?? null,
                            'fps_num'       => $fpsInfo['fps_num'] ?? null,
                            'fps_den'       => $fpsInfo['fps_den'] ?? null

                        ]);
                    } catch (\Throwable $ignore) {
                    }
                }
            } catch (\Throwable $e) {
                // Non-fatal: never block the upload if metadata probing fails
                error_log('[upload-metadata] ' . $e->getMessage());
            }



            // ---------- 7) Success JSON ----------
            ob_end_clean();
            $this->respond(200, [
                'ok'        => true,
                'clip_uuid' => $clipUuid,
                'file'      => $origName,
                'size'      => $size,
                'path'      => $publicPath,
            ]);
        } catch (\Throwable $e) {
            $buf = ob_get_clean(); // flush buffered warnings/notices
            error_log('[ClipUpload] Exception: ' . $e->getMessage());
            if ($buf) {
                error_log('[ClipUpload] Buffered output: ' . trim($buf));
            }
            $this->respond(500, [
                'ok'    => false,
                'error' => 'Upload failed',
                'detail' => 'server_error',
            ]);
        }
    }

    /**
     * Write a lightweight audit record (mirrors DayConverterController::audit)
     */
    private function audit(\PDO $pdo, string $entity, string $entityUuid, string $action, array $meta): void
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
            // never fatal -- just skip logging
        }
    }

    /**
     * Ensure an import_source row for Resolve CSV exists for this project.
     * Returns the BINARY(16) id (as raw binary) for FK usage.
     */
    private function upsertResolveImportSource(\PDO $pdo, string $projectUuid): string
    {
        // try find
        $st = $pdo->prepare("
        SELECT id FROM import_sources
        WHERE project_id = UUID_TO_BIN(:p,1) AND source_type='resolve_csv'
        LIMIT 1
    ");
        $st->execute([':p' => $projectUuid]);
        $bin = $st->fetchColumn();
        if ($bin) return $bin;

        // create
        $st = $pdo->prepare("
        INSERT INTO import_sources (id, project_id, source_type, display_name)
        VALUES (UUID_TO_BIN(UUID(),1), UUID_TO_BIN(:p,1), 'resolve_csv', 'Resolve CSV')
    ");
        $st->execute([':p' => $projectUuid]);

        // refetch
        $st = $pdo->prepare("
        SELECT id FROM import_sources
        WHERE project_id = UUID_TO_BIN(:p,1) AND source_type='resolve_csv'
        LIMIT 1
    ");
        $st->execute([':p' => $projectUuid]);
        $bin = $st->fetchColumn();
        if (!$bin) {
            throw new \RuntimeException('Failed to create import_source');
        }
        return $bin;
    }

    /**
     * Parse a Resolve CSV into import_jobs + import_rows.
     * Returns the job UUID (string) for reference.
     */
    private function parseResolveCsvToImportTables(
        \PDO $pdo,
        string $sourceBinId,
        string $csvAbsPath,
        string $inputName,
        string $projectUuid,
        string $dayUuid
    ): string {
        // Create job
        $st = $pdo->prepare("
        INSERT INTO import_jobs (id, import_source_id, status, input_filename, input_mime, input_sha256, total_rows, matched_rows)
        VALUES (UUID_TO_BIN(UUID(),1), :src, 'parsing', :name, 'text/csv', NULL, 0, 0)
    ");
        $st->bindParam(':src', $sourceBinId, \PDO::PARAM_LOB);
        $st->bindValue(':name', $inputName);
        $st->execute();

        // Get job id (binary + uuid text)
        $jobBin = $pdo->query("SELECT LAST_INSERT_ID()")->fetchColumn(); // MySQL won't return for UUID PK, so reselect by filename
        $stj = $pdo->prepare("
        SELECT id, BIN_TO_UUID(id,1) AS job_uuid
        FROM import_jobs
        WHERE import_source_id = :src AND input_filename = :name
        ORDER BY created_at DESC LIMIT 1
    ");
        $stj->bindParam(':src', $sourceBinId, \PDO::PARAM_LOB);
        $stj->bindValue(':name', $inputName);
        $stj->execute();
        $jobRow = $stj->fetch(\PDO::FETCH_ASSOC);
        $jobBin = $jobRow['id'];
        $jobUuid = $jobRow['job_uuid'];

        // Open & parse CSV
        $fh = new \SplFileObject($csvAbsPath, 'r');
        $fh->setFlags(\SplFileObject::READ_CSV | \SplFileObject::SKIP_EMPTY);
        $fh->setCsvControl(','); // DaVinci default; adjust if needed

        $headers = null;
        $rowIndex = 0;
        $total = 0;
        while (!$fh->eof()) {
            $row = $fh->fgetcsv();
            if ($row === [null] || $row === false) continue;

            if ($headers === null) {
                // normalize header keys: "Clip Name" -> "clip_name"
                $headers = array_map(function ($h) {
                    $h = (string)$h;
                    $h = trim($h);
                    $h = strtolower(preg_replace('/\s+/', '_', $h));
                    return $h;
                }, $row);
                continue;
            }

            $data = [];
            foreach ($headers as $i => $key) {
                $data[$key] = isset($row[$i]) ? trim((string)$row[$i]) : null;
            }
            if (!$data) continue;

            // Heuristics for common Resolve CSV columns
            $fileName = $data['clip_name'] ?? $data['file'] ?? $data['source_file'] ?? $data['file_name'] ?? null;
            if ($fileName) {
                // strip any folder components to compare basenames later
                $fileName = basename($fileName);
            }
            $filePath = $data['source_file'] ?? $data['file_path'] ?? null;

            $scene   = $data['scene'] ?? null;
            $slate   = $data['slate'] ?? null;
            $take    = $data['take'] ?? null;
            $camera  = $data['camera'] ?? $data['cam'] ?? null;
            $reel    = $data['reel'] ?? $data['reel_name'] ?? null;

            // TC fields
            $tcStart = $data['start_tc'] ?? $data['start_timecode'] ?? $data['start'] ?? null;
            $tcEnd   = $data['end_tc'] ?? $data['end_timecode'] ?? $data['end'] ?? null;

            // Duration -- accept either "00:00:12:10" (tc) or "12.4" (seconds)
            $fpsRaw  = $data['fps'] ?? $data['frame_rate'] ?? null;
            $fpsNorm = $fpsRaw ? (float)str_replace(',', '.', $fpsRaw) : null;
            $durMs   = null;
            $durationTc = $data['duration'] ?? $data['duration_tc'] ?? null;
            $durationS  = $data['duration_s'] ?? $data['duration_seconds'] ?? null;

            if ($durationS !== null && is_numeric(str_replace(',', '.', $durationS))) {
                $durMs = (int)round((float)str_replace(',', '.', $durationS) * 1000);
            } elseif ($durationTc !== null && $fpsNorm) {
                // parse "MM:SS:FF" or "HH:MM:SS:FF"
                if (preg_match('/^(?:(\d{1,2}):)?(\d{1,2}):(\d{1,2}):(\d{1,2})$/', $durationTc, $m)) {
                    $hh = (int)($m[1] ?? 0);
                    $mm = (int)$m[2];
                    $ss = (int)$m[3];
                    $ff = (int)$m[4];
                    $durMs = (int)round((($hh * 3600) + ($mm * 60) + $ss + ($ff / $fpsNorm)) * 1000);
                }
            }

            // Write row
            $stRow = $pdo->prepare("
            INSERT INTO import_rows (
                id, import_job_id, row_index,
                shoot_date, scene, slate, take, camera, reel,
                file_name, file_path, tc_start, tc_end, duration_ms, raw_json
            )
            VALUES (
                UUID_TO_BIN(UUID(),1), :job, :i,
                NULL, :scene, :slate, :take, :camera, :reel,
                :file_name, :file_path, :tc_start, :tc_end, :dur_ms, :raw
            )
        ");
            $stRow->bindParam(':job', $jobBin, \PDO::PARAM_LOB);
            $stRow->bindValue(':i', $rowIndex++, \PDO::PARAM_INT);
            $stRow->bindValue(':scene', $scene);
            $stRow->bindValue(':slate', $slate);
            $stRow->bindValue(':take', $take);
            $stRow->bindValue(':camera', $camera);
            $stRow->bindValue(':reel', $reel);
            $stRow->bindValue(':file_name', $fileName);
            $stRow->bindValue(':file_path', $filePath);
            $stRow->bindValue(':tc_start', $tcStart);
            $stRow->bindValue(':tc_end', $tcEnd);
            $stRow->bindValue(':dur_ms', $durMs, \PDO::PARAM_INT);
            $stRow->bindValue(':raw', json_encode($data, JSON_UNESCAPED_SLASHES));
            $stRow->execute();

            $total++;
        }

        // finalize job
        $pdo->prepare("
        UPDATE import_jobs
           SET status='committed', total_rows=:t, committed_at=NOW()
         WHERE id = :job
         LIMIT 1
    ")->execute([':t' => $total, ':job' => $jobBin]);

        return $jobUuid;
    }
}
