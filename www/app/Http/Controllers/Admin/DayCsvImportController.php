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
        // --- CSRF ---
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
            $limitUuids = array_values(array_unique($limitUuids));
        }

        // --- Uploaded file ---
        if (!isset($_FILES['csv_file']) || !is_uploaded_file($_FILES['csv_file']['tmp_name'])) {
            http_response_code(400);
            echo 'No file uploaded';
            return;
        }

        $tmpPath  = (string)$_FILES['csv_file']['tmp_name'];
        $origName = (string)($_FILES['csv_file']['name'] ?? 'resolve.csv');

        $pdo = DB::pdo();

        try {
            $pdo->beginTransaction();

            // Ensure day belongs to project
            $stmtDay = $pdo->prepare("
            SELECT d.id
            FROM days d
            WHERE d.id = UUID_TO_BIN(:dayUuid, 1)
              AND d.project_id = UUID_TO_BIN(:projUuid, 1)
            LIMIT 1
        ");
            $stmtDay->execute([
                ':dayUuid'  => $dayUuid,
                ':projUuid' => $projectUuid,
            ]);
            $dayIdBin = $stmtDay->fetchColumn();
            if (!$dayIdBin) {
                throw new \RuntimeException('Day not found for project');
            }

            $svc = new ResolveCsvImportService();

            // 1) Ingest CSV → import_jobs/import_rows
            $svc->ingestCsv($pdo, $projectUuid, $dayUuid, $tmpPath, $origName);

            // 2) Apply latest job to this day's clips
            $feedback = $svc->applyLatestJobToDay($pdo, $projectUuid, $dayUuid, $limitUuids, $overwrite);

            $pdo->commit();

            $succeeded = $feedback['succeeded'] ?? [];
            $unmatched = $feedback['unmatched'] ?? [];

            $_SESSION['import_feedback'] = [
                'status'        => 'ok',
                'message'       => 'CSV imported.',
                'matched_count' => (int)($feedback['matched_count'] ?? count($succeeded)),
                'succeeded'     => $succeeded,
                'unmatched'     => $unmatched,
                'counts'        => [
                    // keep BOTH spellings so old/new UI code works
                    'applied'   => count($succeeded),
                    'succeeded' => count($succeeded),
                    'missing'   => count($unmatched),
                ],
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();

            $_SESSION['import_feedback'] = [
                'status'        => 'error',
                'message'       => 'Import failed: ' . $e->getMessage(),
                'matched_count' => 0,
                'unmatched'     => [],
            ];
        }

        if (ob_get_level()) ob_end_clean();
        header("Location: /admin/projects/$projectUuid/days/$dayUuid/clips");
        exit;
    }
}
