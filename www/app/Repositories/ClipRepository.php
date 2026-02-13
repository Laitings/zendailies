<?php

namespace App\Repositories;

use PDO;

/**
 * Central data-access layer for clips and clip-related data.
 *
 * This pulls together the patterns currently used in:
 * - ProjectClipsController (list, filters, runtime)
 * - ClipPlayerController (single-clip lookup + assets)
 * - jobs_queue / clip_assets (proxy + job state)
 */
final class ClipRepository
{
    public function __construct(private PDO $pdo) {}

    /**
     * Scene filter that supports both:
     * - prefix matching (e.g. "24" -> 24, 24A, 240)
     * - token matching inside composite scenes (e.g. "24" -> 23+24, 24/25)
     */
    private function applySceneFilter(array &$where, array &$params, string $scene): void
    {
        if ($scene === '') {
            return;
        }

        $where[] = '(c.scene LIKE :scene_prefix OR c.scene REGEXP :scene_token)';
        $params[':scene_prefix'] = $scene . '%';
        $params[':scene_token'] = '(^|[^0-9A-Za-z])' . preg_quote($scene, '/') . '([^0-9A-Za-z]|$)';
    }

    /**
     * List clips for a given project/day, with filters and ordering,
     * including poster/proxy paths, proxy_count and latest encode job state.
     *
     * This is essentially the "clips index" query moved into one place.
     *
     * @param string $projectUuid  BIN_TO_UUID(project_id,1)
     * @param string $dayUuid      BIN_TO_UUID(day_id,1)
     * @param array  $filters      [
     *   'scene'   => string|null,
     *   'slate'   => string|null,
     *   'take'    => string|null,
     *   'camera'  => string|null,
     *   'rating'  => int|string|null,
     *   'select'  => int|string|null,
     *   'text'    => string|null   (search in file_name/reel)
     * ]
     * @param array  $options      [
     *   'sort'            => string (e.g. "scene, slate, take, camera"),
     *   'direction'       => 'asc'|'desc' (global fallback),
     *   'limit'           => int,
     *   'offset'          => int,
     *   'visibility_sql'  => string (e.g. " AND (csa.group_id IS NULL OR sgm.person_id = UUID_TO_BIN(:viewer_person_uuid,1))"),
     *   'visibility_params' => array (e.g. [':viewer_person_uuid' => '...']),
     * ]
     */
    public function listForDay(
        string $projectUuid,
        string $dayUuid,
        array $filters = [],
        array $options = []
    ): array {
        $where = [
            'c.project_id = UUID_TO_BIN(:project_uuid, 1)',
            'c.day_id     = UUID_TO_BIN(:day_uuid, 1)',
        ];

        $params = [
            ':project_uuid' => $projectUuid,
            ':day_uuid'     => $dayUuid,
        ];

        // --- Filters (mirror current ProjectClipsController behaviour) ---

        $scene  = trim((string)($filters['scene'] ?? ''));
        $slate  = trim((string)($filters['slate'] ?? ''));
        $take   = trim((string)($filters['take']  ?? ''));
        $camera = trim((string)($filters['camera'] ?? ''));
        $rating = (string)($filters['rating'] ?? '');
        $select = (string)($filters['select'] ?? '');
        $text   = trim((string)($filters['text'] ?? ''));

        // Scene: prefix + token match
        $this->applySceneFilter($where, $params, $scene);
        // Slate: prefix match
        if ($slate !== '') {
            $where[] = 'c.slate LIKE :slate_prefix';
            $params[':slate_prefix'] = $slate . '%';
        }
        // Take: prefix match with numeric branch
        if ($take !== '') {
            if (ctype_digit($take)) {
                $where[] = 'CAST(c.take_int AS CHAR) LIKE :take_prefix_int';
                $params[':take_prefix_int'] = $take . '%';
            } else {
                $where[] = 'c.take LIKE :take_prefix';
                $params[':take_prefix'] = $take . '%';
            }
        }
        if ($camera !== '') {
            $where[] = 'c.camera = :camera';
            $params[':camera'] = $camera;
        }
        if ($rating !== '') {
            $where[] = 'c.rating = :rating';
            $params[':rating'] = (int)$rating;
        }
        if ($select !== '') {
            $where[] = 'c.is_select = :is_select';
            $params[':is_select'] = (int)$select;
        }
        if ($text !== '') {
            $where[] = '(c.file_name LIKE :t1 OR c.reel LIKE :t2)';
            $params[':t1'] = '%' . $text . '%';
            $params[':t2'] = '%' . $text . '%';
        }


        // --- Sensitive-ACL visibility hook (clip_sensitive_acl + sensitive_group_members) ---

        $visibilitySql    = (string)($options['visibility_sql'] ?? '');
        $visibilityParams = (array)($options['visibility_params'] ?? []);

        foreach ($visibilityParams as $k => $v) {
            $params[$k] = $v;
        }

        // --- Sort logic (whitelist like in ProjectClipsController) ---

        $sortWhitelist = [
            'created_at' => 'c.created_at',
            'scene'      => 'c.scene',
            'slate'      => 'c.slate',
            'take'       => 'c.take_int',
            'take_int'   => 'c.take_int',   // <— add this line
            'camera'     => 'c.camera',
            'reel'       => 'c.reel',
            'file'       => 'c.file_name',
            'rating'     => 'c.rating',
            'select'     => 'c.is_select',
            'tc_start'   => 'c.tc_start',
            'tc_end'     => 'c.tc_end',
            'duration'   => 'c.duration_ms',
        ];


        $orderParts = [];
        $sortSpec   = (string)($options['sort'] ?? '');
        $globalDir  = strtolower((string)($options['direction'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';

        if ($sortSpec !== '') {
            foreach (explode(',', $sortSpec) as $part) {
                $k = trim($part);
                if ($k === '') continue;

                $dir = $globalDir;

                // Simple prefix handling: "-scene" → scene DESC
                if ($k[0] === '-') {
                    $k   = substr($k, 1);
                    $dir = 'DESC';
                }

                if (isset($sortWhitelist[$k])) {
                    $orderParts[] = $sortWhitelist[$k] . ' ' . $dir;
                }
            }
        }

        // Default sort: scene/ slate / take / camera
        if (!$orderParts) {
            $orderParts[] = 'c.scene ASC';
            $orderParts[] = 'c.slate ASC';
            $orderParts[] = 'c.take_int ASC';
            $orderParts[] = 'c.camera ASC';
        }

        $limit  = max(1, (int)($options['limit'] ?? 50));
        $offset = max(0, (int)($options['offset'] ?? 0));

        // FIX: Remove the LEFT JOIN to clip_sensitive_acl and sensitive_group_members
        // These joins create duplicate rows when a clip belongs to multiple groups
        // The visibility check is already handled properly via EXISTS subquery in the controller
        $sql = "
            SELECT
                BIN_TO_UUID(c.id,1)              AS clip_uuid,
                BIN_TO_UUID(c.day_id,1)          AS day_uuid,
                c.scene,
                c.slate,
                c.take,
                c.take_int,
                c.camera,
                c.rating,
                c.is_select,
                c.is_restricted,
                c.created_at,
                c.reel,
                c.file_name,
                c.tc_start,
                c.duration_ms,
                c.fps,

                -- Poster / proxy path (latest by created_at)
                MAX(CASE WHEN a.asset_type = 'poster'    THEN a.storage_path END) AS poster_path,
                MAX(CASE WHEN a.asset_type = 'proxy_web' THEN a.storage_path END) AS proxy_path,

                -- How many proxies exist for this clip
                (
                    SELECT COUNT(*)
                    FROM clip_assets a2
                    WHERE a2.clip_id = c.id
                    AND a2.asset_type = 'proxy_web'
                ) AS proxy_count,

                -- Latest encode job state for this clip, if any
                (
                    SELECT ej.state
                    FROM jobs_queue ej
                    WHERE ej.clip_id = c.id
                    ORDER BY ej.id DESC
                    LIMIT 1
                ) AS job_state,

                -- Assigned group UUIDs for the restriction checkboxes
                (
                    SELECT GROUP_CONCAT(BIN_TO_UUID(group_id, 1))
                    FROM clip_sensitive_acl
                    WHERE clip_id = c.id
                ) AS assigned_group_uuids

            FROM clips c
            JOIN days d ON d.id = c.day_id
            LEFT JOIN clip_assets a ON a.clip_id = c.id
            WHERE " . implode(' AND ', $where) . $visibilitySql . "
            GROUP BY c.id
            ORDER BY " . implode(', ', $orderParts) . "
            LIMIT :limit OFFSET :offset
        ";
        $stmt = $this->pdo->prepare($sql);

        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit',  $limit,  PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Same as listForDay, but for ALL days (project-level) -> uses the 'all' logic
     */
    public function listForProject(
        string $projectUuid,
        array $filters = [],
        array $options = []
    ): array {
        // Same logic, just skip the day_id filter
        $where = [
            'c.project_id = UUID_TO_BIN(:project_uuid, 1)',
        ];

        $params = [
            ':project_uuid' => $projectUuid,
        ];

        // Filter logic (mirrors listForDay)
        $scene  = trim((string)($filters['scene'] ?? ''));
        $slate  = trim((string)($filters['slate'] ?? ''));
        $take   = trim((string)($filters['take']  ?? ''));
        $camera = trim((string)($filters['camera'] ?? ''));
        $rating = (string)($filters['rating'] ?? '');
        $select = (string)($filters['select'] ?? '');
        $text   = trim((string)($filters['text'] ?? ''));

        $this->applySceneFilter($where, $params, $scene);
        if ($slate !== '') {
            $where[] = 'c.slate LIKE :slate_prefix';
            $params[':slate_prefix'] = $slate . '%';
        }
        if ($take !== '') {
            if (ctype_digit($take)) {
                $where[] = 'CAST(c.take_int AS CHAR) LIKE :take_prefix_int';
                $params[':take_prefix_int'] = $take . '%';
            } else {
                $where[] = 'c.take LIKE :take_prefix';
                $params[':take_prefix'] = $take . '%';
            }
        }
        if ($camera !== '') {
            $where[] = 'c.camera = :camera';
            $params[':camera'] = $camera;
        }
        if ($rating !== '') {
            $where[] = 'c.rating = :rating';
            $params[':rating'] = (int)$rating;
        }
        if ($select !== '') {
            $where[] = 'c.is_select = :is_select';
            $params[':is_select'] = (int)$select;
        }
        if ($text !== '') {
            $where[] = '(c.file_name LIKE :t1 OR c.reel LIKE :t2)';
            $params[':t1'] = '%' . $text . '%';
            $params[':t2'] = '%' . $text . '%';
        }

        // Visibility
        $visibilitySql    = (string)($options['visibility_sql'] ?? '');
        $visibilityParams = (array)($options['visibility_params'] ?? []);
        foreach ($visibilityParams as $k => $v) {
            $params[$k] = $v;
        }

        // Sort
        $sortWhitelist = [
            'created_at' => 'c.created_at',
            'scene'      => 'c.scene',
            'slate'      => 'c.slate',
            'take'       => 'c.take_int',
            'take_int'   => 'c.take_int',
            'camera'     => 'c.camera',
            'reel'       => 'c.reel',
            'file'       => 'c.file_name',
            'rating'     => 'c.rating',
            'select'     => 'c.is_select',
            'tc_start'   => 'c.tc_start',
            'tc_end'     => 'c.tc_end',
            'duration'   => 'c.duration_ms',
        ];

        $orderParts = [];
        $sortSpec   = (string)($options['sort'] ?? '');
        $globalDir  = strtolower((string)($options['direction'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';

        if ($sortSpec !== '') {
            foreach (explode(',', $sortSpec) as $part) {
                $k = trim($part);
                if ($k === '') continue;

                $dir = $globalDir;
                if ($k[0] === '-') {
                    $k   = substr($k, 1);
                    $dir = 'DESC';
                }

                if (isset($sortWhitelist[$k])) {
                    $orderParts[] = $sortWhitelist[$k] . ' ' . $dir;
                }
            }
        }

        if (!$orderParts) {
            $orderParts[] = 'c.scene ASC';
            $orderParts[] = 'c.slate ASC';
            $orderParts[] = 'c.take_int ASC';
            $orderParts[] = 'c.camera ASC';
        }

        $limit  = max(1, (int)($options['limit'] ?? 50));
        $offset = max(0, (int)($options['offset'] ?? 0));

        // FIX: Remove the LEFT JOIN to clip_sensitive_acl and sensitive_group_members
        $sql = "
            SELECT
                BIN_TO_UUID(c.id,1)              AS clip_uuid,
                BIN_TO_UUID(c.day_id,1)          AS day_uuid,
                c.scene,
                c.slate,
                c.take,
                c.take_int,
                c.camera,
                c.rating,
                c.is_select,
                c.is_restricted,
                c.created_at,
                c.reel,
                c.file_name,
                c.tc_start,
                c.duration_ms,
                c.fps,

                MAX(CASE WHEN a.asset_type = 'poster'    THEN a.storage_path END) AS poster_path,
                MAX(CASE WHEN a.asset_type = 'proxy_web' THEN a.storage_path END) AS proxy_path,

                (
                    SELECT COUNT(*)
                    FROM clip_assets a2
                    WHERE a2.clip_id = c.id
                    AND a2.asset_type = 'proxy_web'
                ) AS proxy_count,

                (
                    SELECT ej.state
                    FROM jobs_queue ej
                    WHERE ej.clip_id = c.id
                    ORDER BY ej.id DESC
                    LIMIT 1
                ) AS job_state,

                (
                    SELECT GROUP_CONCAT(BIN_TO_UUID(group_id, 1))
                    FROM clip_sensitive_acl
                    WHERE clip_id = c.id
                ) AS assigned_group_uuids

            FROM clips c
            JOIN days d ON d.id = c.day_id
            LEFT JOIN clip_assets a ON a.clip_id = c.id
            WHERE " . implode(' AND ', $where) . $visibilitySql . "
            GROUP BY c.id
            ORDER BY " . implode(', ', $orderParts) . "
            LIMIT :limit OFFSET :offset
        ";

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue(':limit',  $limit,  PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Count clips (unfiltered or filtered).
     */
    public function countForDay(
        string $projectUuid,
        string $dayUuid,
        array $filters = [],
        array $options = []
    ): int {
        $where = [
            'c.project_id = UUID_TO_BIN(:project_uuid, 1)',
            'c.day_id     = UUID_TO_BIN(:day_uuid, 1)',
        ];
        $params = [
            ':project_uuid' => $projectUuid,
            ':day_uuid'     => $dayUuid,
        ];

        // Apply filters
        $scene  = trim((string)($filters['scene'] ?? ''));
        $slate  = trim((string)($filters['slate'] ?? ''));
        $take   = trim((string)($filters['take']  ?? ''));
        $camera = trim((string)($filters['camera'] ?? ''));
        $rating = (string)($filters['rating'] ?? '');
        $select = (string)($filters['select'] ?? '');
        $text   = trim((string)($filters['text'] ?? ''));

        $this->applySceneFilter($where, $params, $scene);
        if ($slate !== '') {
            $where[] = 'c.slate LIKE :slate_prefix';
            $params[':slate_prefix'] = $slate . '%';
        }
        if ($take !== '') {
            if (ctype_digit($take)) {
                $where[] = 'CAST(c.take_int AS CHAR) LIKE :take_prefix_int';
                $params[':take_prefix_int'] = $take . '%';
            } else {
                $where[] = 'c.take LIKE :take_prefix';
                $params[':take_prefix'] = $take . '%';
            }
        }
        if ($camera !== '') {
            $where[] = 'c.camera = :camera';
            $params[':camera'] = $camera;
        }
        if ($rating !== '') {
            $where[] = 'c.rating = :rating';
            $params[':rating'] = (int)$rating;
        }
        if ($select !== '') {
            $where[] = 'c.is_select = :is_select';
            $params[':is_select'] = (int)$select;
        }
        if ($text !== '') {
            $where[] = '(c.file_name LIKE :t1 OR c.reel LIKE :t2)';
            $params[':t1'] = '%' . $text . '%';
            $params[':t2'] = '%' . $text . '%';
        }

        // Visibility
        $visibilitySql    = (string)($options['visibility_sql'] ?? '');
        $visibilityParams = (array)($options['visibility_params'] ?? []);
        foreach ($visibilityParams as $k => $v) {
            $params[$k] = $v;
        }

        // FIX: Use DISTINCT to avoid counting duplicate rows and remove the problematic JOINs
        $sql = "
            SELECT COUNT(DISTINCT c.id) AS cnt
            FROM clips c
            JOIN days d ON d.id = c.day_id
            WHERE " . implode(' AND ', $where) . $visibilitySql . "
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return (int)($row['cnt'] ?? 0);
    }

    public function countForProject(
        string $projectUuid,
        array $filters = [],
        array $options = []
    ): int {
        $where = [
            'c.project_id = UUID_TO_BIN(:project_uuid, 1)',
        ];
        $params = [
            ':project_uuid' => $projectUuid,
        ];

        // Filters
        $scene  = trim((string)($filters['scene'] ?? ''));
        $slate  = trim((string)($filters['slate'] ?? ''));
        $take   = trim((string)($filters['take']  ?? ''));
        $camera = trim((string)($filters['camera'] ?? ''));
        $rating = (string)($filters['rating'] ?? '');
        $select = (string)($filters['select'] ?? '');
        $text   = trim((string)($filters['text'] ?? ''));

        $this->applySceneFilter($where, $params, $scene);
        if ($slate !== '') {
            $where[] = 'c.slate LIKE :slate_prefix';
            $params[':slate_prefix'] = $slate . '%';
        }
        if ($take !== '') {
            if (ctype_digit($take)) {
                $where[] = 'CAST(c.take_int AS CHAR) LIKE :take_prefix_int';
                $params[':take_prefix_int'] = $take . '%';
            } else {
                $where[] = 'c.take LIKE :take_prefix';
                $params[':take_prefix'] = $take . '%';
            }
        }
        if ($camera !== '') {
            $where[] = 'c.camera = :camera';
            $params[':camera'] = $camera;
        }
        if ($rating !== '') {
            $where[] = 'c.rating = :rating';
            $params[':rating'] = (int)$rating;
        }
        if ($select !== '') {
            $where[] = 'c.is_select = :is_select';
            $params[':is_select'] = (int)$select;
        }
        if ($text !== '') {
            $where[] = '(c.file_name LIKE :t1 OR c.reel LIKE :t2)';
            $params[':t1'] = '%' . $text . '%';
            $params[':t2'] = '%' . $text . '%';
        }

        // Visibility
        $visibilitySql    = (string)($options['visibility_sql'] ?? '');
        $visibilityParams = (array)($options['visibility_params'] ?? []);
        foreach ($visibilityParams as $k => $v) {
            $params[$k] = $v;
        }

        // FIX: Use DISTINCT and remove problematic JOINs
        $sql = "
            SELECT COUNT(DISTINCT c.id) AS cnt
            FROM clips c
            JOIN days d ON d.id = c.day_id
            WHERE " . implode(' AND ', $where) . $visibilitySql . "
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return (int)($row['cnt'] ?? 0);
    }

    /**
     * Sum durations (for total runtime).
     */
    public function sumDurationForDay(
        string $projectUuid,
        string $dayUuid,
        array $filters = [],
        array $options = []
    ): int {
        $where = [
            'c.project_id = UUID_TO_BIN(:project_uuid, 1)',
            'c.day_id     = UUID_TO_BIN(:day_uuid, 1)',
        ];
        $params = [
            ':project_uuid' => $projectUuid,
            ':day_uuid'     => $dayUuid,
        ];

        // Filters
        $scene  = trim((string)($filters['scene'] ?? ''));
        $slate  = trim((string)($filters['slate'] ?? ''));
        $take   = trim((string)($filters['take']  ?? ''));
        $camera = trim((string)($filters['camera'] ?? ''));
        $rating = (string)($filters['rating'] ?? '');
        $select = (string)($filters['select'] ?? '');
        $text   = trim((string)($filters['text'] ?? ''));

        $this->applySceneFilter($where, $params, $scene);
        if ($slate !== '') {
            $where[] = 'c.slate LIKE :slate_prefix';
            $params[':slate_prefix'] = $slate . '%';
        }
        if ($take !== '') {
            if (ctype_digit($take)) {
                $where[] = 'CAST(c.take_int AS CHAR) LIKE :take_prefix_int';
                $params[':take_prefix_int'] = $take . '%';
            } else {
                $where[] = 'c.take LIKE :take_prefix';
                $params[':take_prefix'] = $take . '%';
            }
        }
        if ($camera !== '') {
            $where[] = 'c.camera = :camera';
            $params[':camera'] = $camera;
        }
        if ($rating !== '') {
            $where[] = 'c.rating = :rating';
            $params[':rating'] = (int)$rating;
        }
        if ($select !== '') {
            $where[] = 'c.is_select = :is_select';
            $params[':is_select'] = (int)$select;
        }
        if ($text !== '') {
            $where[] = '(c.file_name LIKE :t1 OR c.reel LIKE :t2)';
            $params[':t1'] = '%' . $text . '%';
            $params[':t2'] = '%' . $text . '%';
        }

        // Visibility
        $visibilitySql    = (string)($options['visibility_sql'] ?? '');
        $visibilityParams = (array)($options['visibility_params'] ?? []);
        foreach ($visibilityParams as $k => $v) {
            $params[$k] = $v;
        }

        // FIX: Use subquery with DISTINCT to avoid summing duplication from JOINs
        $sql = "
            SELECT COALESCE(SUM(sq.duration_ms), 0) AS total_ms
            FROM (
                SELECT DISTINCT c.id, c.duration_ms
                FROM clips c
                JOIN days d ON d.id = c.day_id
                WHERE " . implode(' AND ', $where) . $visibilitySql . "
            ) AS sq
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return (int)($row['total_ms'] ?? 0);
    }

    public function sumDurationForProject(
        string $projectUuid,
        array $filters = [],
        array $options = []
    ): int {
        $where = [
            'c.project_id = UUID_TO_BIN(:project_uuid, 1)',
        ];
        $params = [
            ':project_uuid' => $projectUuid,
        ];

        // Filters
        $scene  = trim((string)($filters['scene'] ?? ''));
        $slate  = trim((string)($filters['slate'] ?? ''));
        $take   = trim((string)($filters['take']  ?? ''));
        $camera = trim((string)($filters['camera'] ?? ''));
        $rating = (string)($filters['rating'] ?? '');
        $select = (string)($filters['select'] ?? '');
        $text   = trim((string)($filters['text'] ?? ''));

        $this->applySceneFilter($where, $params, $scene);
        if ($slate !== '') {
            $where[] = 'c.slate LIKE :slate_prefix';
            $params[':slate_prefix'] = $slate . '%';
        }
        if ($take !== '') {
            if (ctype_digit($take)) {
                $where[] = 'CAST(c.take_int AS CHAR) LIKE :take_prefix_int';
                $params[':take_prefix_int'] = $take . '%';
            } else {
                $where[] = 'c.take LIKE :take_prefix';
                $params[':take_prefix'] = $take . '%';
            }
        }
        if ($camera !== '') {
            $where[] = 'c.camera = :camera';
            $params[':camera'] = $camera;
        }
        if ($rating !== '') {
            $where[] = 'c.rating = :rating';
            $params[':rating'] = (int)$rating;
        }
        if ($select !== '') {
            $where[] = 'c.is_select = :is_select';
            $params[':is_select'] = (int)$select;
        }
        if ($text !== '') {
            $where[] = '(c.file_name LIKE :t1 OR c.reel LIKE :t2)';
            $params[':t1'] = '%' . $text . '%';
            $params[':t2'] = '%' . $text . '%';
        }

        // Visibility
        $visibilitySql    = (string)($options['visibility_sql'] ?? '');
        $visibilityParams = (array)($options['visibility_params'] ?? []);
        foreach ($visibilityParams as $k => $v) {
            $params[$k] = $v;
        }

        $sql = "
            SELECT COALESCE(SUM(sq.duration_ms), 0) AS total_ms
            FROM (
                SELECT DISTINCT c.id, c.duration_ms
                FROM clips c
                JOIN days d ON d.id = c.day_id
                WHERE " . implode(' AND ', $where) . $visibilitySql . "
            ) AS sq
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return (int)($row['total_ms'] ?? 0);
    }

    /**
     * Fetch a single clip row for a given project/day/clip UUID triple.
     * This matches the "base clip row" queries used in ClipPlayerController.
     */
    public function findForDay(
        string $projectUuid,
        string $dayUuid,
        string $clipUuid
    ): ?array {
        $sql = "
            SELECT 
                BIN_TO_UUID(c.id,1) AS clip_uuid,
                c.project_id,
                c.day_id,
                c.scene,
                c.slate,
                c.take,
                c.take_int,
                c.camera,
                c.reel,
                c.file_name,
                c.tc_start,
                c.tc_end,
                c.duration_ms,
                c.fps,
                c.rating,
                c.is_select,
                c.created_at
            FROM clips c
            WHERE c.id = UUID_TO_BIN(:clip,1)
              AND c.project_id = UUID_TO_BIN(:p,1)
              AND c.day_id     = UUID_TO_BIN(:d,1)
            LIMIT 1
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':clip' => $clipUuid,
            ':p'    => $projectUuid,
            ':d'    => $dayUuid,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * Fetch all assets for a clip, grouped by asset_type.
     *
     * Typical usage: the player chooses proxy_web vs original, posters, etc.
     */
    public function getAssetsForClip(string $clipUuid): array
    {
        $sql = "
            SELECT 
                asset_type,
                storage_path,
                byte_size,
                width,
                height,
                codec,
                created_at
            FROM clip_assets
            WHERE clip_id = UUID_TO_BIN(:c,1)
            ORDER BY created_at DESC
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':c' => $clipUuid]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        // Optional: index by asset_type for convenience
        $byType = [];
        foreach ($rows as $row) {
            $type = $row['asset_type'] ?? 'unknown';
            $byType[$type][] = $row;
        }

        return $byType;
    }

    /**
     * Update or toggle the is_select flag for a given clip in a project/day.
     * This matches the quickSelect behaviour in ProjectClipsController.
     */
    public function updateSelectFlag(
        string $projectUuid,
        string $dayUuid,
        string $clipUuid,
        ?int $explicitValue = null
    ): int {
        // First read current value
        $stmt = $this->pdo->prepare("
            SELECT is_select
            FROM clips
            WHERE id = UUID_TO_BIN(:c,1)
              AND project_id = UUID_TO_BIN(:p,1)
              AND day_id     = UUID_TO_BIN(:d,1)
            LIMIT 1
        ");
        $stmt->execute([
            ':c' => $clipUuid,
            ':p' => $projectUuid,
            ':d' => $dayUuid,
        ]);
        $cur = $stmt->fetchColumn();

        if ($cur === false) {
            throw new \RuntimeException('Clip not found for select toggle');
        }

        // If explicit 0/1 given, use that; else toggle
        if ($explicitValue === 0 || $explicitValue === 1) {
            $next = $explicitValue;
        } else {
            $next = ((int)$cur) ? 0 : 1;
        }

        $upd = $this->pdo->prepare("
            UPDATE clips
            SET is_select = :v
            WHERE id = UUID_TO_BIN(:c,1)
              AND project_id = UUID_TO_BIN(:p,1)
              AND day_id     = UUID_TO_BIN(:d,1)
            LIMIT 1
        ");
        $upd->execute([
            ':v' => $next,
            ':c' => $clipUuid,
            ':p' => $projectUuid,
            ':d' => $dayUuid,
        ]);

        return $next;
    }

    /**
     * Get all distinct camera names for a project (and optionally a specific day).
     */
    public function getDistinctCameras(string $projectUuid, ?string $dayUuid = null): array
    {
        $sql = "SELECT DISTINCT camera FROM clips WHERE project_id = UUID_TO_BIN(:p, 1)";
        $params = [':p' => $projectUuid];

        if ($dayUuid !== null && $dayUuid !== 'all') {
            $sql .= " AND day_id = UUID_TO_BIN(:d, 1)";
            $params[':d'] = $dayUuid;
        }

        $sql .= " AND camera IS NOT NULL AND camera != '' ORDER BY camera ASC";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(\PDO::FETCH_COLUMN) ?: [];
    }

    public function getClipGroups(string $clipUuid): array
    {
        $sql = "SELECT BIN_TO_UUID(group_id, 1) as group_uuid 
            FROM clip_sensitive_acl 
            WHERE clip_id = UUID_TO_BIN(:clip, 1)";
        $st = $this->pdo->prepare($sql);
        $st->execute([':clip' => $clipUuid]);
        return $st->fetchAll(\PDO::FETCH_COLUMN);
    }

    public function setClipGroups(string $clipUuid, array $groupUuids): void
    {
        // Check if we are already in a transaction from a bulk operation
        $managed = false;
        if (!$this->pdo->inTransaction()) {
            $this->pdo->beginTransaction();
            $managed = true;
        }

        try {
            // 1. Clear existing
            $st = $this->pdo->prepare("DELETE FROM clip_sensitive_acl WHERE clip_id = UUID_TO_BIN(:clip, 1)");
            $st->execute([':clip' => $clipUuid]);

            // 2. Insert new ones
            if (!empty($groupUuids)) {
                $ins = $this->pdo->prepare("INSERT INTO clip_sensitive_acl (clip_id, group_id) VALUES (UUID_TO_BIN(:clip, 1), UUID_TO_BIN(:gid, 1))");
                foreach ($groupUuids as $gid) {
                    $ins->execute([':clip' => $clipUuid, ':gid' => $gid]);
                }
                // 3. Ensure the clip is marked as restricted
                $this->pdo->prepare("UPDATE clips SET is_restricted = 1 WHERE id = UUID_TO_BIN(:clip, 1)")->execute([':clip' => $clipUuid]);
            } else {
                // 4. If no groups, it's no longer restricted
                $this->pdo->prepare("UPDATE clips SET is_restricted = 0 WHERE id = UUID_TO_BIN(:clip, 1)")->execute([':clip' => $clipUuid]);
            }

            // Only commit if we started the transaction here
            if ($managed) {
                $this->pdo->commit();
            }
        } catch (\Exception $e) {
            // Only roll back if we started the transaction here
            if ($managed && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}
