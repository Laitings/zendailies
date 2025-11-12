<?php

/** @var \App\Support\View $this */
/** @var array $filters */
/** @var array $rows */
/** @var int $total */
/** @var int $page */
/** @var int $per */
/** @var array $cameraOptions */
/** @var string $project_uuid */
/** @var string $day_uuid */
/** @var array|null $day_info */
/** @var string $day_label */
/** @var array $project */

// Expect $project_uuid and $day_uuid from the controller
$projectUuidSafe = htmlspecialchars($project_uuid ?? '');
$dayUuidSafe     = htmlspecialchars($day_uuid ?? '');

$this->extend('layout/main');

$this->start('head'); ?>

<?php
// --- Sort link helper ---
// Uses first key of current sort list; toggles dir on repeated click; preserves other query params.
function zd_sort_link(string $key, string $label, array $filters): string
{
    $current = trim(explode(',', $filters['sort'] ?? '')[0] ?? '');
    $dir = strtoupper($filters['dir'] ?? 'ASC');
    $nextDir = ($current === $key && $dir === 'ASC') ? 'DESC' : 'ASC';

    // Preserve all current GET params but override sort+dir
    $qs = $_GET ?? [];
    $qs['sort'] = $key;
    $qs['dir']  = $nextDir;
    $url = '?' . http_build_query($qs);

    // Arrow only on the active one
    $arrow = '';
    if ($current === $key) {
        $arrow = ($dir === 'ASC') ? ' ▲' : ' ▼';
    }
    return '<a href="' . $url . '" class="zd-sort">' . $label . '<span class="zd-sort-arrow">' . $arrow . '</span></a>';
}
?>

<title>Clips · Zentropa Dailies</title>
<link rel="stylesheet" href="/assets/css/admin.clips.css?v=<?= rawurlencode((string)@filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/admin.clips.css')) ?>">
<script type="module" defer src="/assets/js/admin.clips.js?v=<?= rawurlencode((string)@filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/js/admin.clips.js')) ?>"></script>



<?php $this->end(); ?>

<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$__fb = $_SESSION['import_feedback'] ?? null;
if ($__fb) {
    unset($_SESSION['import_feedback']);
} // show once
?>

<?php $this->start('content'); ?>

<div class="zd-clips-page"
    data-project="<?= htmlspecialchars($project_uuid) ?>"
    data-day="<?= htmlspecialchars($day_uuid) ?>"
    data-converter-csrf="<?= htmlspecialchars($converter_csrf ?? '') ?>"
    data-quick-csrf="<?= htmlspecialchars($quick_csrf ?? '') ?>">


    <?php
    $dayLabel = !empty($dayInfo['title']) ? $dayInfo['title']
        : (!empty($dayInfo['shoot_date']) ? 'Day ' . $dayInfo['shoot_date'] : '');
    ?>
    <div class="zd-clips-head">
        <h1>
            Clips - <?= htmlspecialchars($day_label ?? ($day_uuid ?? '')) ?>
            <span class="muted">
                - <?= (int)$total ?> clip<?= ($total == 1 ? '' : 's') ?>
            </span>
        </h1>

        <p class="zd-meta"
            id="zd-runtime-block"
            data-day-total-ms="<?= isset($day_total_duration_ms) ? (int)$day_total_duration_ms : 0 ?>">
            Project: <?= htmlspecialchars($project['title'] ?? 'Project') ?><br>
            <?= (int)$total ?> clip<?= ($total == 1 ? '' : 's') ?>
            · Total runtime:
            <span id="zd-total-runtime">00:00:00:00</span>
        </p>

    </div>
    <div class="zd-actions" style="display:flex; gap:8px; align-items:center">

        <a class="za-btn za-btn-primary" href="/admin/projects/<?= htmlspecialchars($project_uuid) ?>/days/<?= htmlspecialchars($day_uuid) ?>/clips/upload">
            + Add clips
        </a>

        <a class="za-btn za-btn-danger" href="/admin/projects/<?= htmlspecialchars($project['project_uuid'] ?? $project_uuid) ?>/days/<?= htmlspecialchars($day_uuid) ?>/delete" style="margin-left:12px">
            Delete day
        </a>
    </div>
    <div class="zd-bulk-actions" style="display:flex; flex-wrap:wrap; gap:12px; align-items:center; font-size:13px; color:#9aa7b2;">
        <div style="display:flex; flex-direction:column; line-height:1.3; min-width:140px;">
            <div id="zd-selected-count">0 selected</div>
            <div>
                Selected runtime:
                <span id="zd-selected-runtime">00:00:00:00</span>
            </div>
        </div>

        <button id="zd-bulk-poster"
            class="zd-btn"
            style="background:#3aa0ff;color:#0b0c10;"
            disabled>
            Generate poster for selected
        </button>

        <div style="margin-left:auto; display:flex; align-items:center;">
            <form id="zd-import-form"
                method="post"
                action="/admin/projects/<?= htmlspecialchars($project_uuid) ?>/days/<?= htmlspecialchars($day_uuid) ?>/clips/import_csv"
                enctype="multipart/form-data"
                style="display:flex; gap:8px; align-items:center; background:#111318; border:1px solid #1f2430; border-radius:10px; padding:6px 10px; font-size:13px; color:#9aa7b2;">
                <input type="hidden" name="_csrf" value="<?= \App\Support\Csrf::token() ?>">
                <!-- NEW: populated by JS with comma-separated clip UUIDs when selection > 0 -->
                <input type="hidden" id="zd-import-uuids" name="limit_to_uuids" value="">

                <label style="display:flex; flex-direction:column; cursor:pointer;">
                    <span style="font-size:11px; color:#9aa7b2;">Resolve CSV</span>
                    <input id="zd-import-file"
                        type="file"
                        name="csv_file"
                        accept=".csv,text/csv"
                        style="font-size:12px; color:#e9eef3; background:#0f1218; border:1px solid #1f2430; border-radius:6px; padding:4px 6px; min-width:180px;">
                </label>
                <label style="display:flex; align-items:center; gap:6px; margin-left:8px;">
                    <input type="checkbox" name="overwrite" id="zd-import-overwrite" value="1" />
                    <span>Overwrite existing values</span>
                </label>
                <button id="zd-import-btn"
                    type="submit"
                    class="zd-btn"
                    title="Import metadata"
                    style="background:#3aa0ff;color:#0b0c10;">
                    Import metadata
                </button>
            </form>
        </div>
    </div>

    <div class="zd-stack">
        <form method="get" class="zd-filters">
            <input class="zd-input" name="scene" value="<?= htmlspecialchars($filters['scene']) ?>" placeholder="Scene">
            <input class="zd-input" name="slate" value="<?= htmlspecialchars($filters['slate']) ?>" placeholder="Slate">
            <input class="zd-input" name="take" value="<?= htmlspecialchars($filters['take']) ?>" placeholder="Take">
            <select class="zd-select" name="camera">
                <option value="">Camera</option>
                <?php foreach ($cameraOptions as $c): ?>
                    <option value="<?= htmlspecialchars($c) ?>" <?= $filters['camera'] === $c ? 'selected' : '' ?>><?= htmlspecialchars($c) ?></option>
                <?php endforeach; ?>
            </select>
            <select class="zd-select" name="rating">
                <option value="">Rating</option>
                <?php for ($i = 1; $i <= 5; $i++): ?>
                    <option value="<?= $i ?>" <?= $filters['rating'] === (string)$i ? 'selected' : '' ?>><?= $i ?>★</option>
                <?php endfor; ?>
            </select>
            <select class="zd-select" name="select">
                <option value="">Select?</option>
                <option value="1" <?= $filters['select'] === '1' ? 'selected' : '' ?>>Yes</option>
                <option value="0" <?= $filters['select'] === '0' ? 'selected' : '' ?>>No</option>
            </select>
            <input class="zd-input" name="text" value="<?= htmlspecialchars($filters['text']) ?>" placeholder="Search file/reel">
            <div style="display:flex;gap:8px">
                <button class="zd-btn" type="submit">Apply</button>
                <a class="zd-btn" href="?">Reset</a>
            </div>
        </form>


        <div class="zd-table-wrap">
            <table class="zd-table">
                <thead>
                    <tr>
                        <th>Thumb</th>
                        <th><?= zd_sort_link('scene',    'Scene',  $filters) ?></th>
                        <th><?= zd_sort_link('slate',    'Slate',  $filters) ?></th>
                        <th><?= zd_sort_link('take',     'Take',   $filters) ?></th>
                        <th><?= zd_sort_link('camera',   'Cam',    $filters) ?></th>
                        <th><?= zd_sort_link('select', 'Selected', $filters) ?></th>
                        <th><?= zd_sort_link('reel',     'Reel',   $filters) ?></th>
                        <th><?= zd_sort_link('file',     'File',   $filters) ?></th>
                        <th><?= zd_sort_link('tc_start', 'TC In',  $filters) ?></th>
                        <th><?= zd_sort_link('duration', 'Dur',    $filters) ?></th>
                        <th>Actions</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if (!$rows): ?>
                        <tr>
                            <td colspan="10" class="zd-meta">No clips found.</td>
                        </tr>
                        <?php else: foreach ($rows as $idx => $r): ?>
                            <tr id="clip-<?= htmlspecialchars($r['clip_uuid']) ?>"
                                data-clip-uuid="<?= htmlspecialchars($r['clip_uuid']) ?>"
                                data-row-index="<?= (int)$idx ?>">
                                <!-- Thumb (live-updated after poster gen) -->
                                <td data-field="thumb">
                                    <?php if (!empty($r['poster_path'])): ?>
                                        <img class="zd-thumb" src="<?= htmlspecialchars($r['poster_path']) ?>" alt="">
                                    <?php else: ?>
                                        <div class="zd-thumb"></div>
                                    <?php endif; ?>
                                </td>

                                <!-- Scene / Slate / Take / Cam / Reel -->
                                <td class="zd-editable" data-edit="scene"><span><?= htmlspecialchars($r['scene'] ?? '') ?></span></td>
                                <td class="zd-editable" data-edit="slate"><span><?= htmlspecialchars($r['slate'] ?? '') ?></span></td>
                                <td class="zd-editable" data-edit="take"><span><?= htmlspecialchars(($r['take_int'] ?? null) !== null ? (string)$r['take_int'] : ($r['take'] ?? '')) ?></span></td>
                                <td><?= htmlspecialchars($r['camera'] ?? '') ?></td>
                                <td class="zd-select-td" data-field="select">
                                    <button class="star-toggle"
                                        type="button"
                                        data-clip="<?= htmlspecialchars($r['clip_uuid']) ?>"
                                        data-selected="<?= (int)($r['is_select'] ?? 0) ?>"
                                        aria-label="Toggle selected"
                                        title="Toggle selected">
                                        <?php if ((int)($r['is_select'] ?? 0) === 1): ?>
                                            <svg viewBox="0 0 24 24" class="star on" aria-hidden="true">
                                                <path d="M12 17.3l-5.47 3.22 1.45-6.17-4.78-4.1 6.3-.54L12 3l2.5 6.7 6.3.54-4.78 4.1 1.45 6.17z" />
                                            </svg>
                                        <?php else: ?>
                                            <svg viewBox="0 0 24 24" class="star off" aria-hidden="true">
                                                <path d="M12 17.3l-5.47 3.22 1.45-6.17-4.78-4.1 6.3-.54L12 3l2.5 6.7 6.3.54-4.78 4.1 1.45 6.17z" />
                                            </svg>
                                        <?php endif; ?>
                                    </button>
                                </td>

                                <td><?= htmlspecialchars($r['reel'] ?? '') ?></td>

                                <!-- File name + UUID -->
                                <td class="col-file">
                                    <div class="filename" title="<?= htmlspecialchars($r['file_name'] ?? '') ?>">
                                        <?= htmlspecialchars($r['file_name'] ?? '') ?>
                                    </div>
                                    <div class="zd-meta uuid">#<?= htmlspecialchars($r['clip_uuid']) ?></div>
                                </td>

                                <!-- TC In -->
                                <td data-field="tc_start"><?= htmlspecialchars($r['tc_start'] ?? '') ?></td>

                                <!-- Duration (live-updated after metadata pull) -->
                                <td data-field="duration"
                                    data-duration-ms="<?= $r['duration_ms'] !== null ? (int)$r['duration_ms'] : '' ?>"
                                    data-fps="<?= isset($r['fps']) && $r['fps'] !== null ? (int)$r['fps'] : '' ?>">
                                </td>

                                <!-- Actions -->
                                <td style="white-space:nowrap">
                                    <!-- Play -->
                                    <a href="/admin/projects/<?= $projectUuidSafe ?>/days/<?= $dayUuidSafe ?>/player/<?= htmlspecialchars($r['clip_uuid']) ?>"
                                        class="icon-btn"
                                        title="Play">
                                        <img src="/assets/icons/play.svg" alt="Play" class="icon">
                                    </a>

                                    <!-- Edit (NEW) -->
                                    <a href="/admin/projects/<?= $projectUuidSafe ?>/days/<?= $dayUuidSafe ?>/clips/<?= htmlspecialchars($r['clip_uuid']) ?>/edit"
                                        class="icon-btn"
                                        title="Edit clip">
                                        <img src="/assets/icons/pencil.svg" alt="Edit" class="icon">
                                    </a>

                                    <!-- Generate poster (AJAX) -->
                                    <button class="icon-btn"
                                        type="button"
                                        title="Generate poster"
                                        data-clip="<?= htmlspecialchars($r['clip_uuid']) ?>"
                                        data-action="poster">
                                        <img src="/assets/icons/thumbnail.svg"
                                            alt="Generate poster"
                                            class="icon icon--light">
                                    </button>

                                    <!-- Delete -->
                                    <form method="post"
                                        action="/admin/projects/<?= $projectUuidSafe ?>/days/<?= $dayUuidSafe ?>/clips/<?= htmlspecialchars($r['clip_uuid']) ?>/delete"
                                        onsubmit="return confirm('Delete this clip and all its files? This cannot be undone.');"
                                        style="display:inline;">
                                        <input type="hidden" name="_csrf" value="<?= \App\Support\Csrf::token() ?>">
                                        <button class="icon-btn danger" type="submit" title="Delete">
                                            <img src="/assets/icons/trash.svg" alt="Delete" class="icon">
                                        </button>
                                    </form>
                                </td>
                            </tr>
                    <?php endforeach;
                    endif; ?>
                </tbody>
            </table>
        </div>

        <?php
        $totalPages = (int)ceil($total / $per);
        ?>
        <div class="zd-pager">
            <form method="get" style="display:flex;gap:8px;align-items:center">
                <?php foreach (['scene', 'slate', 'take', 'camera', 'rating', 'select', 'text', 'sort', 'dir'] as $k): ?>
                    <input type="hidden" name="<?= $k ?>" value="<?= htmlspecialchars($filters[$k]) ?>">
                <?php endforeach; ?>
                <button class="zd-btn" name="page" value="<?= max(1, $page - 1) ?>" <?= $page <= 1 ? 'disabled' : '' ?>>Prev</button>
                <span class="zd-meta">Page <?= $page ?> / <?= $totalPages ?: 1 ?></span>
                <button class="zd-btn" name="page" value="<?= min($totalPages ?: 1, $page + 1) ?>" <?= ($page >= $totalPages) ? 'disabled' : '' ?>>Next</button>
                <select class="zd-select" name="per" onchange="this.form.submit()">
                    <?php foreach ([25, 50, 100, 150, 200] as $opt): ?>
                        <option value="<?= $opt ?>" <?= $per === $opt ? 'selected' : '' ?>><?= $opt ?>/page</option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>
    </div>
</div>


<?php $this->end(); /* end of content section */ ?>

<?php $this->start('scripts'); ?>
<?= $this->includeWithin('partials/import_csv_modal', ['feedback' => $__fb]) ?>
<?php $this->end(); ?>