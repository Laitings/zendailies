<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

final class ResolveCsvImportService
{
    /** Normalize DaVinci "Good Take" field to bool (1/0). Accepts 1/0/true/false strings. */
    private function parseGoodTake(mixed $val): bool
    {
        if ($val === null) return false;
        $s = strtolower(trim((string)$val));
        // DaVinci tends to use "1" or "FALSE"
        if ($s === '1' || $s === 'true' || $s === 'yes') return true;
        if ($s === '0' || $s === 'false' || $s === 'no') return false;
        // Fallback: any non-empty that isn't an explicit false → false by default
        return false;
    }

    /** Ingest a Resolve CSV: create/locate import_source → create import_job → parse → write import_rows. Returns ['job_uuid'=>string,'total_rows'=>int]. */
    public function ingestCsv(PDO $pdo, string $projectUuid, string $dayUuid, string $csvAbsPath, string $origName): array
    {
        $sourceBin = $this->upsertResolveImportSource($pdo, $projectUuid);

        // Make a job row
        $pdo->prepare("
            INSERT INTO import_jobs (id, import_source_id, status, input_filename, input_mime, total_rows, matched_rows)
            VALUES (UUID_TO_BIN(UUID(),1), :src, 'parsing', :name, 'text/csv', 0, 0)
        ")->execute([
            ':src'  => $sourceBin,
            ':name' => $origName,
        ]);

        // Re-select job id/uuid (most recent for this source+filename)
        $stj = $pdo->prepare("
            SELECT id, BIN_TO_UUID(id,1) AS job_uuid
            FROM import_jobs
            WHERE import_source_id = :src AND input_filename = :name
            ORDER BY created_at DESC LIMIT 1
        ");
        $stj->bindParam(':src', $sourceBin, PDO::PARAM_LOB);
        $stj->bindValue(':name', $origName);
        $stj->execute();
        $job = $stj->fetch(PDO::FETCH_ASSOC);
        if (!$job) {
            throw new \RuntimeException('Failed to create import_job');
        }
        $jobBin  = $job['id'];
        $jobUuid = $job['job_uuid'];

        // Parse Resolve CSV (often UTF-16LE) → associative rows
        $rows = $this->readCsvAssoc($csvAbsPath);
        $total = 0;

        foreach ($rows as $rowIdx => $csvRow) {
            // Identify filename (use best-known headers)
            $csvFileNameRaw = $csvRow['File Name']
                ?? $csvRow['Filename']
                ?? $csvRow['Source File']
                ?? $csvRow['Clip Name']
                ?? null;

            $fileName = $csvFileNameRaw ? basename((string)$csvFileNameRaw) : null;
            $filePath = $csvRow['Source File'] ?? $csvRow['Clip Directory'] ?? null;

            $scene    = $csvRow['Scene'] ?? null;
            $take     = $csvRow['Take'] ?? null;
            $slate    = null;

            // Scene may contain "009-003" → scene/slate split
            if ($scene && strpos($scene, '-') !== false) {
                [$s1, $s2] = explode('-', $scene, 2);
                $scene = trim($s1);
                $slate = trim($s2 ?: '');
            }
            if (($slate === null || $slate === '') && !empty($csvRow['Scene/Slate/Take'])) {
                $sst = (string)$csvRow['Scene/Slate/Take'];
                if (strpos($sst, '-') !== false) {
                    [$sc, $rest] = explode('-', $sst, 2);
                    $scene = $scene ?: trim($sc);
                    $slateGuess = trim(preg_split('~[ /]~', $rest, 2)[0] ?? '');
                    if ($slateGuess !== '') $slate = $slateGuess;
                }
            }

            $camera   = $csvRow['Camera #'] ?? $csvRow['Camera'] ?? null;
            if ($camera !== null) $camera = rtrim((string)$camera, '_');

            $reel     = $csvRow['Reel'] ?? $csvRow['Reel Name'] ?? null;

            $tcStart  = $csvRow['Start TC'] ?? null;
            $tcEnd    = $csvRow['End TC']   ?? null;

            // --- Normalize key-ish fields to strip control chars & bad whitespace ---
            $fileName = $this->normalizeKeyField($fileName, 255);
            $filePath = $this->normalizeKeyField($filePath, 1024);

            $scene    = $this->normalizeKeyField($scene, 32);
            $slate    = $this->normalizeKeyField($slate, 32);
            $take     = $this->normalizeKeyField($take, 16);

            $camera   = $this->normalizeKeyField($camera, 8);
            $reel     = $this->normalizeKeyField($reel, 64);

            $tcStart  = $this->normalizeKeyField($tcStart, 16);
            $tcEnd    = $this->normalizeKeyField($tcEnd, 16);
            // -----------------------------------------------------------------------


            $fpsRaw   = $csvRow['FPS'] ?? ($csvRow['Shot Frame Rate'] ?? null);
            $fps      = $this->parseFps($fpsRaw);

            $durText  = $csvRow['Duration'] ?? $csvRow['Duration TC'] ?? null;
            $durMs    = $this->durationToMs($durText, $fps);

            $rawJson  = json_encode(
                $csvRow,
                JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
            );


            try {
                // Write import_rows (normal row)
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
                $stRow->bindParam(':job', $jobBin, PDO::PARAM_LOB);
                $stRow->bindValue(':i', $rowIdx, PDO::PARAM_INT);
                $stRow->bindValue(':scene', $scene);
                $stRow->bindValue(':slate', $slate);
                $stRow->bindValue(':take', $take);
                $stRow->bindValue(':camera', $camera);
                $stRow->bindValue(':reel', $reel);
                $stRow->bindValue(':file_name', $fileName);
                $stRow->bindValue(':file_path', $filePath);
                $stRow->bindValue(':tc_start', $tcStart);
                $stRow->bindValue(':tc_end', $tcEnd);
                $stRow->bindValue(':dur_ms', $durMs, PDO::PARAM_INT);
                $stRow->bindValue(':raw', $rawJson);
                $stRow->execute();

                $total++;
            } catch (\Throwable $e) {
                // If *anything* goes wrong for this row, store it as an error-row
                $errMsg = mb_substr($e->getMessage(), 0, 1000);

                $stErr = $pdo->prepare("
                    INSERT INTO import_rows (
                        id, import_job_id, row_index,
                        shoot_date, scene, slate, take, camera, reel,
                        file_name, file_path, tc_start, tc_end, duration_ms, raw_json,
                        status, error_text
                    )
                    VALUES (
                        UUID_TO_BIN(UUID(),1), :job, :i,
                        NULL, :scene, :slate, :take, :camera, :reel,
                        :file_name, :file_path, :tc_start, :tc_end, :dur_ms, :raw,
                        'error', :err
                    )
                ");
                $stErr->bindParam(':job', $jobBin, PDO::PARAM_LOB);
                $stErr->bindValue(':i', $rowIdx, PDO::PARAM_INT);
                $stErr->bindValue(':scene', $scene);
                $stErr->bindValue(':slate', $slate);
                $stErr->bindValue(':take', $take);
                $stErr->bindValue(':camera', $camera);
                $stErr->bindValue(':reel', $reel);
                $stErr->bindValue(':file_name', $fileName);
                $stErr->bindValue(':file_path', $filePath);
                $stErr->bindValue(':tc_start', $tcStart);
                $stErr->bindValue(':tc_end', $tcEnd);
                $stErr->bindValue(':dur_ms', $durMs, PDO::PARAM_INT);
                $stErr->bindValue(':raw', $rawJson);
                $stErr->bindValue(':err', $errMsg);
                $stErr->execute();

                // Do NOT rethrow – we want the rest of the rows to continue importing
                continue;
            }
        }

        // finalize job
        $pdo->prepare("
            UPDATE import_jobs
               SET status='committed', total_rows=:t, committed_at=NOW()
             WHERE id = :job
             LIMIT 1
        ")->execute([':t' => $total, ':job' => $jobBin]);

        return ['job_uuid' => $jobUuid, 'total_rows' => $total];
    }

    /** Try to match and apply a CSV row to a single clip (called during video upload). */
    public function applyToClipIfMatch(
        PDO $pdo,
        string $projectUuid,
        string $dayUuid,
        string $clipUuid,
        string $uploadedFileName,
        string $uploadedPublicPath,
        bool $overwrite = false,
        $jobFilterBin = null   // <— NEW (raw BINARY id of import_jobs.id)
    ): bool {
        $base = basename($uploadedFileName);

        // Accept exact filename, or base-name-without-ext, or file_path suffix match
        $clipBaseNoExt = strtolower(preg_replace('/\.[^.]+$/', '', $base));

        $jobFilterSql = $jobFilterBin ? " AND ir.import_job_id = :job " : "";

        $sql = "
            SELECT ir.id AS row_bin, BIN_TO_UUID(ir.id,1) AS row_uuid, ir.*
            FROM import_rows ir
            JOIN import_jobs ij ON ij.id = ir.import_job_id
            JOIN import_sources isrc ON isrc.id = ij.import_source_id
            WHERE isrc.project_id = UUID_TO_BIN(:p,1)
            $jobFilterSql
            AND (
                    ir.file_name = :fn1
                OR LOWER(REPLACE(SUBSTRING_INDEX(ir.file_name, '.', 1), '\\\\', '')) = :fn_base
                OR (
                    ir.file_path IS NOT NULL
                AND RIGHT(ir.file_path, LENGTH(:fn2_len)) = :fn2_val
                )
            )
            ORDER BY ir.row_index ASC
            LIMIT 1
        ";

        $st = $pdo->prepare($sql);
        $st->bindValue(':p', $projectUuid);
        if ($jobFilterBin) {
            // pass raw binary for VARBINARY id
            $st->bindParam(':job', $jobFilterBin, PDO::PARAM_LOB);
        }
        $st->bindValue(':fn1', $base);
        $st->bindValue(':fn_base', $clipBaseNoExt);
        $st->bindValue(':fn2_len', $base);
        $st->bindValue(':fn2_val', $base);
        try {
            $st->execute();
        } catch (\Throwable $e) {
            error_log(sprintf(
                "[CSV-MATCH] SQL ERROR clip_file=%s fn1=%s fn_base=%s msg=%s",
                $uploadedFileName,
                $base,
                $clipBaseNoExt,
                $e->getMessage()
            ));
            return false;
        }

        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            error_log(sprintf(
                "[CSV-MATCH] NO ROW FOUND for clip_file=%s base=%s jobFilter=%s",
                $uploadedFileName,
                $clipBaseNoExt,
                $jobFilterBin ? 'yes' : 'no'
            ));
            return false;
        }


        $take = $row['take'] ?? null;
        $takeInt = (ctype_digit((string)$take) ? (int)$take : null);

        // --- Normalize CSV values: trim strings; convert "" (and "   ") to NULL ---
        // This makes non-destructive mode ignore blanks, and overwrite mode actually clear fields.
        $norm = function ($v) {
            if (is_string($v)) {
                // Remove control chars from DB-side values as well
                $v = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $v);
                $v = preg_replace('/\s+/u', ' ', $v);
                $v = trim($v);
            }
            return ($v === '' ? null : $v);
        };


        $scene   = $norm($row['scene']    ?? null);
        $slate   = $norm($row['slate']    ?? null);
        $takeVal = $norm($take);                // normalized take (string or NULL)
        $camera  = $norm($row['camera']   ?? null);
        $reel    = $norm($row['reel']     ?? null);
        $tcStart = $norm($row['tc_start'] ?? null);
        $tcEnd   = $norm($row['tc_end']   ?? null);
        $durMs   = isset($row['duration_ms']) ? (int)$row['duration_ms'] : null;
        // --- Good Take → is_select ---------------------------
        // raw_json contains the full CSV row; pull "Good Take" and normalize
        $rawAssoc = json_decode((string)$row['raw_json'], true) ?: [];
        $goodTake = $this->parseGoodTake($rawAssoc['Good Take'] ?? null);
        $isSelect = $goodTake ? 1 : 0;
        // ------------------------------------------------------


        // If take becomes NULL (blank in CSV), keep take_int NULL too; else recompute from normalized take
        $takeInt = ($takeVal !== null && ctype_digit((string)$takeVal)) ? (int)$takeVal : null;


        if ($overwrite) {
            // HARD OVERWRITE: set directly (including NULLs), i.e., CSV empties will clear values
            $pdo->prepare("
        UPDATE clips
           SET scene       = :scene_set,
               slate       = :slate_set,
               take        = :take_set,
               take_int    = :take_int,
               camera      = :camera,
               reel        = :reel,
               tc_start    = :tc_start,
               tc_end      = :tc_end,
               duration_ms = :dur_ms,
               is_select   = :is_select,
               ingest_state= CASE
                               WHEN ((:scene_case IS NOT NULL) AND (:slate_case IS NOT NULL) AND (:take_case IS NOT NULL))
                               THEN 'ready'
                               ELSE ingest_state
                             END
         WHERE id = UUID_TO_BIN(:clip,1)
           AND project_id = UUID_TO_BIN(:p,1)
           AND day_id     = UUID_TO_BIN(:d,1)
         LIMIT 1
    ")->execute([
                ':scene_set'  => $scene,
                ':slate_set'  => $slate,
                ':take_set'   => $takeVal,
                ':take_int'   => $takeInt,
                ':camera'     => $camera,
                ':reel'       => $reel,
                ':tc_start'   => $tcStart,
                ':tc_end'     => $tcEnd,
                ':dur_ms'     => $durMs,
                ':is_select'  => $isSelect,
                // CASE probes
                ':scene_case' => $scene,
                ':slate_case' => $slate,
                ':take_case'  => $takeVal,
                // where
                ':clip'       => $clipUuid,
                ':p'          => $projectUuid,
                ':d'          => $dayUuid,
            ]);
        } else {
            // NON-DESTRUCTIVE (existing behavior): only fill if CSV has a value
            $pdo->prepare("
        UPDATE clips
           SET scene       = COALESCE(:scene_set, scene),
               slate       = COALESCE(:slate_set, slate),
               take        = COALESCE(:take_set,  take),
               take_int    = COALESCE(:take_int,  take_int),
               camera      = COALESCE(:camera,    camera),
               reel        = COALESCE(:reel,      reel),
               tc_start    = COALESCE(:tc_start,  tc_start),
               tc_end      = COALESCE(:tc_end,    tc_end),
               duration_ms = COALESCE(:dur_ms,    duration_ms),
               is_select   = COALESCE(:is_select, is_select),
               ingest_state= CASE
                               WHEN (COALESCE(:scene_case, scene) IS NOT NULL
                                 AND COALESCE(:slate_case, slate) IS NOT NULL
                                 AND COALESCE(:take_case,  take)  IS NOT NULL)
                               THEN 'ready'
                               ELSE ingest_state
                             END
         WHERE id = UUID_TO_BIN(:clip,1)
           AND project_id = UUID_TO_BIN(:p,1)
           AND day_id     = UUID_TO_BIN(:d,1)
         LIMIT 1
    ")->execute([
                ':scene_set'  => $scene,
                ':slate_set'  => $slate,
                ':take_set'   => $takeVal,
                ':take_int'   => $takeInt,
                ':camera'     => $camera,
                ':reel'       => $reel,
                ':tc_start'   => $tcStart,
                ':tc_end'     => $tcEnd,
                ':dur_ms'     => $durMs,
                ':is_select'  => $isSelect,
                // CASE probes still use normalized (so blanks don't incorrectly mark 'ready')
                ':scene_case' => $scene,
                ':slate_case' => $slate,
                ':take_case'  => $takeVal,
                // where
                ':clip'       => $clipUuid,
                ':p'          => $projectUuid,
                ':d'          => $dayUuid,
            ]);
        }


        // link external ref
        $pdo->prepare("
            INSERT INTO clip_external_refs (id, clip_id, source_type, source_row_id, file_path)
            VALUES (UUID_TO_BIN(UUID(),1), UUID_TO_BIN(:clip,1), 'resolve_csv', :row_uuid, :path)
        ")->execute([
            ':clip'     => $clipUuid,
            ':row_uuid' => $row['row_uuid'],
            ':path'     => $uploadedPublicPath,
        ]);

        // explode raw_json into clip_metadata (skip identifiers we already mapped)
        $raw = $rawAssoc; // reuse the decoded CSV row we already parsed above
        $skip = [
            'File Name',
            'Filename',
            'Source File',
            'Clip Name',
            'Clip Directory',
            'Scene',
            'Take',
            'Good Take',
            'Circle Take',
            'Camera #',
            'Camera',
            'Reel',
            'Reel Name',
            'Start TC',
            'End TC',
            'Duration',
            'Duration TC',
            'Shoot Date',
            'Date Recorded',
            'FPS',
            'Shot Frame Rate',
            'Scene/Slate/Take',
            'Scene Number',
            'Day / Roll',
            'File Number',
            'File Name Suffix',
            'Display Name',
            'Scene Label',
            'Scene Info',
        ];
        foreach ($raw as $k => $v) {
            if ($v === null || $v === '' || in_array($k, $skip, true)) continue;
            $pdo->prepare("
                INSERT INTO clip_metadata (clip_id, meta_key, meta_value)
                VALUES (UUID_TO_BIN(:clip,1), :k, :v)
                ON DUPLICATE KEY UPDATE meta_value=VALUES(meta_value)
            ")->execute([
                ':clip' => $clipUuid,
                ':k'    => $k,
                ':v'    => (string)$v,
            ]);
        }

        return true;
    }

    /** After ingest, apply the most recent job’s rows to all clips in the day and return feedback arrays. */
    public function applyLatestJobToDay(PDO $pdo, string $projectUuid, string $dayUuid, array $limitUuids = [], bool $overwrite = false): array

    {
        // Build clip index: basename_no_ext_lower → clip info
        $stmtClips = $pdo->prepare("
            SELECT BIN_TO_UUID(c.id,1) AS clip_uuid, c.id AS clip_id_bin, c.file_name
            FROM clips c
            WHERE c.day_id = (
                SELECT d.id FROM days d WHERE d.id = UUID_TO_BIN(:d,1) AND d.project_id = UUID_TO_BIN(:p,1) LIMIT 1
            )
        ");
        $stmtClips->execute([':d' => $dayUuid, ':p' => $projectUuid]);
        $clips = $stmtClips->fetchAll(PDO::FETCH_ASSOC);

        $clipIndex = [];
        foreach ($clips as $clip) {
            // Clean up any control chars / weird whitespace in DB-side filenames
            $cleanName = $this->normalizeKeyField($clip['file_name'], 255);
            if ($cleanName === null || $cleanName === '') {
                continue;
            }

            $base = $this->baseNoExt($cleanName);
            if ($base === '' || $base === null) {
                continue;
            }

            $clipIndex[strtolower($base)] = $clip;
        }

        // Get latest committed job for this project
        $stj = $pdo->prepare("
            SELECT ij.id
            FROM import_jobs ij
            JOIN import_sources isrc ON isrc.id = ij.import_source_id
            WHERE isrc.project_id = UUID_TO_BIN(:p,1) AND ij.status='committed'
            ORDER BY ij.committed_at DESC, ij.created_at DESC
            LIMIT 1
        ");
        $stj->execute([':p' => $projectUuid]);
        $jobBin = $stj->fetchColumn();
        if (!$jobBin) {
            return ['matched_count' => 0, 'unmatched' => [], 'succeeded' => []];
        }

        // Iterate rows for this job and attempt match
        $rows = $pdo->prepare("
            SELECT id AS row_bin, row_index, file_name, file_path
            FROM import_rows
            WHERE import_job_id = :job
            ORDER BY row_index ASC
        ");
        $rows->bindParam(':job', $jobBin, PDO::PARAM_LOB);
        $rows->execute();

        $matchedCount = 0;
        $unmatched = [];
        $succeeded = [];

        while ($r = $rows->fetch(PDO::FETCH_ASSOC)) {
            $csvFileNameRaw = $r['file_name'] ?? $r['file_path'] ?? null;
            $csvBase = strtolower($this->baseNoExt($csvFileNameRaw));
            if ($csvBase === '' || !isset($clipIndex[$csvBase])) {
                $unmatched[] = ['row' => (int)$r['row_index'], 'reason' => 'no clip match in this day', 'csv_name' => $csvFileNameRaw];
                continue;
            }
            $clip = $clipIndex[$csvBase];
            // Limit to user-selected clips if provided
            if (!empty($limitUuids) && !in_array($clip['clip_uuid'], $limitUuids, true)) {
                continue;
            }
            // We need full application → re-use single-clip method (needs uploadedPublicPath; pass empty)
            try {
                $ok = $this->applyToClipIfMatch(
                    $pdo,
                    $projectUuid,
                    $dayUuid,
                    $clip['clip_uuid'],
                    $clip['file_name'],
                    '',
                    $overwrite,
                    $jobBin
                );
            } catch (\Throwable $e) {
                error_log(sprintf(
                    "[CSV-APPLY] EXCEPTION row_index=%d csv_name=%s clip_uuid=%s file_name=%s msg=%s",
                    (int)$r['row_index'],
                    (string)$csvFileNameRaw,
                    $clip['clip_uuid'],
                    $clip['file_name'],
                    $e->getMessage()
                ));
                $unmatched[] = [
                    'row'    => (int)$r['row_index'],
                    'reason' => 'exception: ' . $e->getMessage(),
                    'csv_name' => $csvFileNameRaw,
                ];
                continue;
            }

            if ($ok) {
                $matchedCount++;
                $succeeded[] = ['csv_name' => $csvFileNameRaw, 'clip_uuid' => $clip['clip_uuid']];
            } else {
                $unmatched[] = ['row' => (int)$r['row_index'], 'reason' => 'row not applied after match', 'csv_name' => $csvFileNameRaw];
            }
        }

        return ['matched_count' => $matchedCount, 'unmatched' => $unmatched, 'succeeded' => $succeeded];
    }

    /**
     * Normalize key-ish string fields coming from Resolve:
     * - cast to string
     * - remove control characters (including stray newlines)
     * - collapse whitespace
     * - trim
     * - turn empty into NULL
     * - optionally truncate to a safe max length
     */
    private function normalizeKeyField(mixed $value, int $maxLen = 0): ?string
    {
        if ($value === null) {
            return null;
        }

        $s = (string)$value;

        // Remove control chars (0x00–0x1F, 0x7F), incl. \r, \n, \t
        $s = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $s);

        // Collapse multiple whitespace into a single space
        $s = preg_replace('/\s+/u', ' ', $s);

        // Trim ends
        $s = trim($s);

        if ($s === '') {
            return null;
        }

        if ($maxLen > 0) {
            // Safe truncate in UTF-8
            $s = mb_substr($s, 0, $maxLen);
        }

        return $s;
    }



    // ----------------- helpers (ported from your controller) -----------------

    private function baseNoExt(?string $name): string
    {
        if (!$name) return '';
        $base = preg_replace('/\.[^.]+$/', '', $name);
        return $base ?? '';
    }

    /** Resolve often exports UTF-16LE CSV; convert on the fly to UTF-8. */
    private function readCsvAssoc(string $path): array
    {
        $fh = fopen($path, 'r');
        if (!$fh) return [];

        @stream_filter_append($fh, 'convert.iconv.UTF-16LE/UTF-8');

        $header = fgetcsv($fh, 0, ',', '"', '\\');
        if (!$header) {
            fclose($fh);
            return [];
        }

        $cleanHeader = array_map(function ($h) {
            $h = (string)$h;
            $h = preg_replace('/^\xEF\xBB\xBF/', '', $h); // strip BOM
            return trim($h);
        }, $header);

        $rows = [];
        $rowIdx = 0;
        while (($cols = fgetcsv($fh, 0, ',', '"', '\\')) !== false) {
            if (count($cols) === 1 && ($cols[0] === null || $cols[0] === '')) continue;
            $assoc = [];
            foreach ($cleanHeader as $i => $colName) {
                $assoc[$colName] = $cols[$i] ?? null;
            }
            $rows[$rowIdx++] = $assoc;
        }

        fclose($fh);
        return $rows;
    }

    /** Convert "HH:MM:SS:FF" | "MM:SS:FF" | "SS.FFF" → ms. */
    private function durationToMs(?string $s, $fps = null): ?int
    {
        if (!$s) return null;
        $s = trim($s);
        if ($s === '') return null;

        $fps = ($fps && is_numeric($fps) && $fps > 0) ? (float)$fps : 24.0;

        if (preg_match('/^(\d{2}):(\d{2}):(\d{2}):(\d{2})$/', $s, $m)) {
            [$hh, $mm, $ss, $ff] = [(int)$m[1], (int)$m[2], (int)$m[3], (int)$m[4]];
            return (int)round((($hh * 3600) + ($mm * 60) + $ss + ($ff / $fps)) * 1000);
        }
        if (preg_match('/^(\d{2}):(\d{2}):(\d{2})$/', $s, $m)) {
            [$mm, $ss, $ff] = [(int)$m[1], (int)$m[2], (int)$m[3]];
            return (int)round((($mm * 60) + $ss + ($ff / $fps)) * 1000);
        }
        if (preg_match('/^(\d+)(\.\d+)?$/', $s)) {
            return (int)round(((float)$s) * 1000);
        }
        return null;
    }

    /** Parse FPS like "24", "23.976", "25.000", with optional " fps" suffix. */
    private function parseFps($raw): ?float
    {
        if ($raw === null || $raw === '') return null;
        $txt = trim((string)$raw);
        $txt = preg_replace('/\s*fps$/i', '', $txt);
        if (is_numeric($txt)) {
            $v = (float)$txt;
            return $v > 0 ? $v : null;
        }
        return null;
    }

    /** Ensure an import_sources row exists; returns raw binary id suitable for LOB binds. */
    private function upsertResolveImportSource(PDO $pdo, string $projectUuid)
    {
        $st = $pdo->prepare("
            SELECT id FROM import_sources
            WHERE project_id = UUID_TO_BIN(:p,1) AND source_type='resolve_csv'
            LIMIT 1
        ");
        $st->execute([':p' => $projectUuid]);
        $bin = $st->fetchColumn();
        if ($bin) return $bin;

        $pdo->prepare("
            INSERT INTO import_sources (id, project_id, source_type, display_name)
            VALUES (UUID_TO_BIN(UUID(),1), UUID_TO_BIN(:p,1), 'resolve_csv', 'Resolve CSV')
        ")->execute([':p' => $projectUuid]);

        $st->execute([':p' => $projectUuid]);
        $bin = $st->fetchColumn();
        if (!$bin) throw new \RuntimeException('Failed to create import_source');
        return $bin;
    }
}
