<?php

namespace App\Http\Controllers\Admin;

use App\Support\DB;
use App\Support\View;
use App\Support\Csrf;
use PDO;
use Throwable;
use App\Services\ResolveCsvImportService;

final class DayCsvImportController
{
    /**
     * POST /admin/projects/{projectUuid}/days/{dayUuid}/clips/import_csv
     * expects:
     *   - _csrf
     *   - csv_file (uploaded file)
     *
     * After processing, we redirect back to clips list and show:
     *   - how many matched/updated
     *   - which CSV rows didn't match any clip in this day
     */
    public function importResolveCsv(string $projectUuid, string $dayUuid): void
    {
        if (session_status() === PHP_SESSION_NONE) session_start();
        error_log('[DayCsvImport] HIT P=' . $projectUuid . ' D=' . $dayUuid);
        if (!isset($_FILES['csv_file'])) {
            error_log('[DayCsvImport] $_FILES keys: ' . implode(',', array_keys($_FILES)));
        } else {
            error_log('[DayCsvImport] csv_file name=' . ($_FILES['csv_file']['name'] ?? 'NULL') . ', err=' . ($_FILES['csv_file']['error'] ?? 'NA'));
        }

        if (session_status() === PHP_SESSION_NONE) session_start();
        ob_start();

        // --- basic guards ---
        if (!$projectUuid || !$dayUuid) {
            http_response_code(400);
            echo "Bad request (missing ids)";
            return;
        }

        // CSRF
        Csrf::validateOrAbort($_POST['_csrf'] ?? null);
        error_log('[DayCsvImport] CSRF OK');

        // --- OPTIONAL: overwrite existing values ---
        $overwrite = isset($_POST['overwrite']) && $_POST['overwrite'] === '1';

        // --- OPTIONAL selection scope from UI ---
        $limitCsv = trim($_POST['limit_to_uuids'] ?? '');
        $limitUuids = [];
        if ($limitCsv !== '') {
            foreach (explode(',', $limitCsv) as $u) {
                $u = trim($u);
                if ($u !== '' && preg_match('/^[0-9a-fA-F-]{36}$/', $u)) {
                    $limitUuids[] = $u;
                }
            }
            // De-dup
            $limitUuids = array_values(array_unique($limitUuids));
        }

        // File presence
        if (
            !isset($_FILES['csv_file']) ||
            !is_uploaded_file($_FILES['csv_file']['tmp_name'])
        ) {
            http_response_code(400);
            echo "No file uploaded";
            return;
        }

        $tmpPath  = $_FILES['csv_file']['tmp_name'];
        $origName = $_FILES['csv_file']['name'] ?? 'upload.csv';

        // --- Step 1: Resolve project/day -> internal binary IDs we can join on ---
        $pdo = DB::pdo();

        // Look up day_id + project_id from UUIDs so we can't import into wrong project/day
        $stmtDay = $pdo->prepare("
            SELECT
                d.id          AS day_id_bin,
                d.project_id  AS project_id_bin
            FROM days d
            WHERE d.id = UUID_TO_BIN(:dayUuid, 1)
              AND d.project_id = (
                SELECT p.id
                FROM projects p
                WHERE p.id = UUID_TO_BIN(:projUuid, 1)
              )
            LIMIT 1
        ");
        $stmtDay->execute([
            ':dayUuid'  => $dayUuid,
            ':projUuid' => $projectUuid,
        ]);
        $dayRow = $stmtDay->fetch(PDO::FETCH_ASSOC);

        if (!$dayRow) {
            http_response_code(404);
            echo "Day not found or not in this project";
            return;
        }

        $dayIdBin     = $dayRow['day_id_bin'];
        $projectIdBin = $dayRow['project_id_bin'];

        // --- Step 2: load all clips for that day into memory for fast match ---
        // We'll make a lookup map [basename_no_ext_lower => clip info]
        $stmtClips = $pdo->prepare("
            SELECT
                BIN_TO_UUID(c.id,1) AS clip_uuid,
                c.id                AS clip_id_bin,
                c.scene,
                c.slate,
                c.take,
                c.take_int,
                c.camera,
                c.reel,
                c.file_name,
                c.tc_start,
                c.tc_end,
                c.duration_ms
            FROM clips c
            WHERE c.day_id = :dayBin
        ");
        // bindParam with LOB is fine for VARBINARY/BINARY in your build; keep consistent with prior codebase
        $stmtClips->bindParam(':dayBin', $dayIdBin, PDO::PARAM_LOB);
        $stmtClips->execute();
        $clips = $stmtClips->fetchAll(PDO::FETCH_ASSOC);

        $clipIndex = []; // key => clip array
        foreach ($clips as $clip) {
            $base = self::baseNoExt($clip['file_name']);
            if ($base === '') continue;
            $clipIndex[strtolower($base)] = $clip;
        }

        // Use shared service
        $svc = new ResolveCsvImportService();

        try {
            $pdo->beginTransaction();

            // 1) Ingest CSV into import_* tables
            $ing = $svc->ingestCsv($pdo, $projectUuid, $dayUuid, $tmpPath, $origName);

            // DEBUG: what did ingest create?
            try {
                $dbgJob = $pdo->query("
                SELECT BIN_TO_UUID(id,1) AS job_uuid, status, input_filename, total_rows, matched_rows, created_at
                FROM import_jobs
                ORDER BY created_at DESC
                LIMIT 1
            ")->fetch(\PDO::FETCH_ASSOC);
                error_log('[DayCsvImport] after ingestCsv: job=' . json_encode($dbgJob, JSON_UNESCAPED_SLASHES));
            } catch (\Throwable $ex) {
                error_log('[DayCsvImport] after ingestCsv: failed to read import_jobs: ' . $ex->getMessage());
            }


            // 2) Apply latest job’s rows to all clips in the day (builds matched/unmatched)
            $feedback = $svc->applyLatestJobToDay($pdo, $projectUuid, $dayUuid, $limitUuids, $overwrite);

            // DEBUG: what did apply compute?
            error_log('[DayCsvImport] after apply: ' . json_encode([
                'matched_count' => $feedback['matched_count'] ?? null,
                'succ' => isset($feedback['succeeded']) ? count($feedback['succeeded']) : null,
                'unmatched' => isset($feedback['unmatched']) ? count($feedback['unmatched']) : null,
            ], JSON_UNESCAPED_SLASHES));

            $pdo->commit();

            error_log('[DayCsvImport] session payload (success) — matched=' . ($feedback['matched_count'] ?? 0) . ' succ=' . (isset($feedback['succeeded']) ? count($feedback['succeeded']) : 0) . ' miss=' . (isset($feedback['unmatched']) ? count($feedback['unmatched']) : 0));

            $_SESSION['import_feedback'] = [
                'status'        => 'ok',
                'message'       => "Import from $origName complete.",
                'matched_count' => $feedback['matched_count'] ?? 0,
                'unmatched'     => $feedback['unmatched'] ?? [],
                'succeeded'     => $feedback['succeeded'] ?? [],
                'counts'        => [
                    'succeeded' => isset($feedback['succeeded']) ? count($feedback['succeeded']) : 0,
                    'missing'   => isset($feedback['unmatched']) ? count($feedback['unmatched']) : 0,
                ],
            ];
        } catch (Throwable $e) {

            error_log('[DayCsvImport] EXCEPTION: ' . $e->getMessage());

            if ($pdo->inTransaction()) $pdo->rollBack();
            $_SESSION['import_feedback'] = [
                'status'        => 'error',
                'message'       => "Import failed: " . $e->getMessage(),
                'matched_count' => 0,
                'unmatched'     => [],
            ];
        }
        if (ob_get_level()) ob_end_clean();
        header("Location: /admin/projects/$projectUuid/days/$dayUuid/clips");
        exit;
    }

    /**
     * Return file name without extension, safe for null.
     * "A003_C001_0101QF.mov" -> "A003_C001_0101QF"
     */
    private static function baseNoExt(?string $name): string
    {
        if (!$name) return '';
        $base = preg_replace('/\.[^.]+$/', '', $name); // remove last ".ext"
        return $base ?? '';
    }

    /**
     * Read a CSV into an array of associative arrays using first row as header.
     * Resolve often exports UTF-16LE CSV. We convert it to UTF-8 on the fly.
     */
    private static function readCsvAssoc(string $path): array
    {
        $fh = fopen($path, 'rb');
        if (!$fh) return [];

        // --- Detect BOM/encoding ---
        $head = fread($fh, 4) ?: '';
        rewind($fh);
        $applyFilter = null;
        if (substr($head, 0, 2) === "\xFF\xFE") {
            $applyFilter = 'convert.iconv.UTF-16LE/UTF-8';
        } elseif (substr($head, 0, 2) === "\xFE\xFF") {
            $applyFilter = 'convert.iconv.UTF-16BE/UTF-8';
        } elseif (substr($head, 0, 3) === "\xEF\xBB\xBF") {
            // UTF-8 BOM — no filter; we'll strip BOM below
        }

        if ($applyFilter) {
            @stream_filter_append($fh, $applyFilter);
        }

        // Probe first line to decide delimiter (comma vs semicolon)
        $probe = fgets($fh) ?: '';
        $probe = preg_replace('/^\xEF\xBB\xBF/', '', $probe); // strip UTF-8 BOM if present
        $commaCount = substr_count($probe, ',');
        $semiCount  = substr_count($probe, ';');
        $delim = ($semiCount > $commaCount) ? ';' : ',';

        // Rewind and actually parse
        rewind($fh);
        $header = fgetcsv($fh, 0, $delim, '"', '\\');
        if (!$header) {
            fclose($fh);
            return [];
        }

        // Clean headers (trim + strip BOM)
        $cleanHeader = array_map(function ($h) {
            $h = (string)$h;
            $h = preg_replace('/^\xEF\xBB\xBF/', '', $h);
            return trim($h);
        }, $header);

        $rows = [];
        $rowIdx = 0;
        while (($cols = fgetcsv($fh, 0, $delim, '"', '\\')) !== false) {
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


    /**
     * Convert a duration string to milliseconds.
     * Formats supported:
     *  - "HH:MM:SS:FF"  (timecode with frames)
     *  - "MM:SS:FF"
     *  - "SS.FFF"
     *
     * @param string|null $s   Duration string from CSV
     * @param float|int|null $fps Optional FPS for converting FF (frames) to seconds. Defaults to 24 if null.
     */
    private static function durationToMs(?string $s, $fps = null): ?int
    {
        if (!$s) return null;
        $s = trim($s);
        if ($s === '') return null;

        $fps = ($fps && is_numeric($fps) && $fps > 0) ? (float)$fps : 24.0;

        // case 1: HH:MM:SS:FF
        if (preg_match('/^(\d{2}):(\d{2}):(\d{2}):(\d{2})$/', $s, $m)) {
            $hh = (int)$m[1];
            $mm = (int)$m[2];
            $ss = (int)$m[3];
            $ff = (int)$m[4];
            $totalSeconds = ($hh * 3600) + ($mm * 60) + $ss + ($ff / $fps);
            return (int)round($totalSeconds * 1000);
        }

        // case 2: MM:SS:FF
        if (preg_match('/^(\d{2}):(\d{2}):(\d{2})$/', $s, $m)) {
            $mm = (int)$m[1];
            $ss = (int)$m[2];
            $ff = (int)$m[3];
            $totalSeconds = ($mm * 60) + $ss + ($ff / $fps);
            return (int)round($totalSeconds * 1000);
        }

        // case 3: seconds.float
        if (preg_match('/^(\d+)(\.\d+)?$/', $s)) {
            $sec = (float)$s;
            return (int)round($sec * 1000);
        }

        // fallback: can't parse
        return null;
    }

    /**
     * Parse FPS value from CSV ("24", "23.976", "25.000", etc.)
     */
    private static function parseFps($raw): ?float
    {
        if ($raw === null || $raw === '') return null;
        $txt = trim((string)$raw);
        // strip possible " fps" suffix or similar
        $txt = preg_replace('/\s*fps$/i', '', $txt);
        if (is_numeric($txt)) {
            $v = (float)$txt;
            return $v > 0 ? $v : null;
        }
        return null;
    }
}
