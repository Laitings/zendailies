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


<style>
    .zd-table th a.zd-sort {
        color: inherit;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 4px;
    }

    .zd-table th a.zd-sort:hover {
        text-decoration: underline;
    }

    .zd-sort-arrow {
        opacity: 0.85;
        font-size: 12px;
    }

    .zd-filters {
        display: grid;
        grid-template-columns: repeat(8, minmax(120px, 1fr));
        gap: 8px;
        margin: 12px 0;
    }

    .zd-table {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0;
    }

    /* cells */
    .zd-table th,
    .zd-table td {
        padding: 8px 10px;
        border-bottom: 1px solid #1f2430;
    }

    /* header (sticky) */
    .zd-table th {
        font-weight: 600;
        color: #e9eef3;
        text-align: left;
        background: #0b0c10;
        position: sticky;
        top: 0;
        z-index: 1;
        /* keep above body rows */
        background-clip: padding-box;
        /* prevents background bleeding past rounded corners */
    }

    /* rounded header corners */
    .zd-table thead th:first-child {
        border-top-left-radius: 8px;
    }

    .zd-table thead th:last-child {
        border-top-right-radius: 8px;
    }

    /* OPTIONAL: rounded bottom corners on the final row */
    .zd-table tbody tr:last-child td:first-child {
        border-bottom-left-radius: 8px;
    }

    .zd-table tbody tr:last-child td:last-child {
        border-bottom-right-radius: 8px;
    }


    .zd-thumb {
        width: 72px;
        height: 40px;
        object-fit: cover;
        border-radius: 6px;
        border: 1px solid #1f2430;
        background: #111318
    }

    .zd-meta {
        color: #9aa7b2;
        font-size: 12px
    }

    .zd-badge {
        display: inline-block;
        padding: 2px 8px;
        border-radius: 999px;
        border: 1px solid #1f2430;
        font-size: 12px
    }

    .zd-pager {
        display: flex;
        gap: 8px;
        align-items: center;
        margin: 14px 0
    }

    .zd-input,
    .zd-select {
        background: #0f1218;
        color: #e9eef3;
        border: 1px solid #1f2430;
        border-radius: 8px;
        padding: 8px
    }

    .zd-btn {
        background: #3aa0ff;
        border: none;
        color: #0b0c10;
        border-radius: 10px;
        padding: 8px 12px;
        cursor: pointer
    }

    .zd-btn:disabled {
        opacity: .5;
        cursor: not-allowed
    }

    /* Force a clean, single-column layout on this page only */
    .zd-clips-page {
        display: grid;
        grid-template-columns: 1fr;
        gap: 12px;

        /* defensive: kill accidental multi-column flow if present */
        column-count: 1;
    }

    /* Make the header span the grid and stay above filters/table */
    .zd-clips-head {
        grid-column: 1 / -1;
        column-span: all;
        /* helps if a parent uses CSS columns */
        margin: 6px 0 8px;
    }

    /* Ensure the rest stacks normally; avoid any legacy floats */
    .zd-stack {
        display: block;
        float: none;
    }

    .zd-table-wrap {
        clear: both;
    }

    .zd-table tbody tr:last-child td:last-child {
        border-bottom-right-radius: 8px;
    }

    /* --- multi-select styling --- */
    .zd-table tbody tr.zd-selected-row {
        background: rgba(58, 160, 255, 0.12);
        /* accent blue [#3aa0ff] but transparent */
    }

    .zd-table tbody tr.zd-selected-row td {
        border-bottom: 1px solid #3aa0ff;
        /* accent border for clarity */
    }

    /* When user is selecting with shift etc, don't let text get highlighted everywhere */
    .zd-table tbody tr {
        user-select: none;
        cursor: pointer;
    }

    /* inline quick-edit */
    .zd-editable {
        cursor: text;
    }

    .zd-editing {
        background: rgba(58, 160, 255, 0.08);
    }

    .zd-inline-input {
        width: fit-content;
        /* shrink to content */
        max-width: 100%;
        box-sizing: border-box;
        border: 1px solid #1f2430;
        /* subtler by default */
        border-radius: 4px;
        /* smaller radius */
        background: #0f1218;
        color: #e9eef3;
        padding: 2px 4px;
        /* less bulky */
        font: inherit;
        font-size: 13px;
        /* slightly smaller text */
        line-height: 1.2;
    }

    .zd-inline-input:focus {
        outline: none;
        border-color: #3aa0ff;
        /* accent only on focus */
    }

    /* per-field comfortable widths */
    td[data-edit="scene"] .zd-inline-input {
        width: 6ch;
    }

    td[data-edit="slate"] .zd-inline-input {
        width: 6ch;
    }

    td[data-edit="take"] .zd-inline-input {
        width: 4ch;
    }
</style>
<title>Clips · Zentropa Dailies</title>

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

<div class="zd-clips-page">

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
                                <td><?= htmlspecialchars($r['reel'] ?? '') ?></td>

                                <!-- File name + UUID -->
                                <td>
                                    <div><?= htmlspecialchars($r['file_name'] ?? '') ?></div>
                                    <div class="zd-meta">#<?= htmlspecialchars($r['clip_uuid']) ?></div>
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
<script>
    /**
     * Convert duration (ms) + fps -> "MM:SS:FF"
     * - MM  = zero-padded minutes
     * - SS  = zero-padded seconds (0-59)
     * - FF  = frame number within current second, 0-based, zero-padded to 2 digits
     */
    function formatDurationMsToTimecode(ms, fpsFallback, fpsFromServer) {
        if (!ms || ms <= 0) return "";

        const fps = fpsFromServer || fpsFallback || 24; // safety fallback

        const totalSecondsFloat = ms / 1000;
        const totalSecondsInt = Math.floor(totalSecondsFloat);

        const minutes = Math.floor(totalSecondsInt / 60);
        const seconds = totalSecondsInt % 60;

        // fractional part of the second -> frame number
        const fractional = totalSecondsFloat - totalSecondsInt;
        let frame = Math.floor(fractional * fps);

        // pad segments
        const mm = String(minutes).padStart(2, "0");
        const ss = String(seconds).padStart(2, "0");
        const ff = String(frame).padStart(2, "0");

        return `${mm}:${ss}:${ff}`;
    }
    // Format each row's duration cell using its data-duration-ms/data-fps.
    // Returns the sum (ms) of durations on THIS PAGE ONLY.
    function initDurationsOnPage() {
        const durCells = document.querySelectorAll('td[data-field="duration"]');
        let pageMsTotal = 0;

        durCells.forEach(cell => {
            const msAttr = cell.getAttribute('data-duration-ms');
            if (!msAttr) return;

            const ms = parseInt(msAttr, 10);
            if (isNaN(ms) || ms <= 0) return;

            const fpsAttr = cell.getAttribute('data-fps');
            const fpsNum = fpsAttr ? parseInt(fpsAttr, 10) : null;

            // Per-row visible text (MM:SS:FF)
            const pretty = formatDurationMsToTimecode(
                ms,
                24, // fallback fps
                fpsNum // per clip fps if known
            );
            cell.textContent = pretty;

            pageMsTotal += ms;
        });

        return pageMsTotal;
    }

    // Read the total ms for the whole day from the DOM attribute,
    // convert to HH:MM:SS:FF, and display it.
    function renderTotalRuntimeFromDayAttribute() {
        const blockEl = document.getElementById('zd-runtime-block');
        const outEl = document.getElementById('zd-total-runtime');
        if (!blockEl || !outEl) return;

        const totalAttr = blockEl.getAttribute('data-day-total-ms');
        const totalMs = parseInt(totalAttr || "0", 10);

        const prettyTotal = formatTotalMsToHHMMSSFF(totalMs, 24);
        outEl.textContent = prettyTotal;
    }


    // Convert totalMs -> HH:MM:SS:FF (note: FF is the frame number of the LAST second's fractional, using fallback fps 24)
    function formatTotalMsToHHMMSSFF(totalMs, fps = 24) {
        if (!totalMs || totalMs <= 0) return "00:00:00:00";

        // total seconds float
        const totalSecondsFloat = totalMs / 1000;
        const totalSecondsInt = Math.floor(totalSecondsFloat);

        const hours = Math.floor(totalSecondsInt / 3600);
        const minutes = Math.floor((totalSecondsInt % 3600) / 60);
        const seconds = totalSecondsInt % 60;

        const fractional = totalSecondsFloat - totalSecondsInt;
        let frame = Math.floor(fractional * fps);

        const hh = String(hours).padStart(2, "0");
        const mm = String(minutes).padStart(2, "0");
        const ss = String(seconds).padStart(2, "0");
        const ff = String(frame).padStart(2, "0");

        return `${hh}:${mm}:${ss}:${ff}`;
    }

    function renderSelectedRuntime() {
        const selRuntimeEl = document.getElementById('zd-selected-runtime');
        if (!selRuntimeEl) return;

        let totalSelMs = 0;

        // Loop through each selected clip UUID and add its duration_ms
        selectedRows.forEach(clipUuid => {
            const rowEl = document.querySelector(`#clip-${clipUuid}`);
            if (!rowEl) return;

            const durCell = rowEl.querySelector('td[data-field="duration"]');
            if (!durCell) return;

            const msAttr = durCell.getAttribute('data-duration-ms');
            if (!msAttr) return;

            const ms = parseInt(msAttr, 10);
            if (!isNaN(ms) && ms > 0) {
                totalSelMs += ms;
            }
        });

        const prettySel = formatTotalMsToHHMMSSFF(totalSelMs, 24);
        selRuntimeEl.textContent = prettySel;
    }

    // --- Multi-select state ---
    const tbodyEl = document.querySelector('.zd-table tbody');
    const bulkPosterBtn = document.getElementById('zd-bulk-poster');
    const importForm = document.getElementById('zd-import-form');
    const importBtn = document.getElementById('zd-import-btn');
    const importUids = document.getElementById('zd-import-uuids');
    const selCountEl = document.getElementById('zd-selected-count');

    let selectedRows = new Set(); // holds clip_uuid strings
    let lastClickedIndex = null; // number (data-row-index)

    function updateBulkUI() {
        const count = selectedRows.size;
        selCountEl.textContent = count + ' selected';

        const enable = count > 0;
        if (bulkPosterBtn) bulkPosterBtn.disabled = !enable;

        // after any selection change, refresh "Selected runtime"
        renderSelectedRuntime();

        // Update import button label based on selection
        if (importBtn) {
            const count = selectedRows.size;
            if (count > 0) {
                importBtn.textContent = 'Import metadata for selected';
                importBtn.title = 'Only apply CSV rows to selected clips';
            } else {
                importBtn.textContent = 'Import metadata';
                importBtn.title = 'Apply CSV rows to matching clips in this day';
            }
        }

    }


    function toggleSingleRow(trEl, forceOn = null) {
        if (!trEl) return;
        const clipUuid = trEl.getAttribute('data-clip-uuid');
        if (!clipUuid) return;

        let nowOn;
        if (forceOn === true) {
            nowOn = true;
        } else if (forceOn === false) {
            nowOn = false;
        } else {
            nowOn = !selectedRows.has(clipUuid);
        }

        if (nowOn) {
            selectedRows.add(clipUuid);
            trEl.classList.add('zd-selected-row');
        } else {
            selectedRows.delete(clipUuid);
            trEl.classList.remove('zd-selected-row');
        }
    }

    function clearAllSelection() {
        selectedRows.clear();
        tbodyEl.querySelectorAll('tr.zd-selected-row').forEach(tr => {
            tr.classList.remove('zd-selected-row');
        });
    }

    function selectRange(idxA, idxB) {
        const start = Math.min(idxA, idxB);
        const end = Math.max(idxA, idxB);
        for (let i = start; i <= end; i++) {
            const trEl = tbodyEl.querySelector('tr[data-row-index="' + i + '"]');
            toggleSingleRow(trEl, true);
        }
    }

    // Row click handler with modifier keys
    tbodyEl.addEventListener('click', (ev) => {
        // Don't steal clicks from actual action buttons/links inside the row.
        // If user clicks a .icon-btn or <a>, let normal behavior run.
        const isActionClick = ev.target.closest('button.icon-btn, a.icon-btn, a[href], button[data-action]');
        if (isActionClick) {
            return; // let the other handler deal with it
        }

        const trEl = ev.target.closest('tr[data-clip-uuid]');
        if (!trEl) return;

        const thisIdx = parseInt(trEl.getAttribute('data-row-index'), 10);

        const ctrl = ev.ctrlKey || ev.metaKey; // metaKey so Mac cmd+click also works
        const shift = ev.shiftKey;

        if (shift && lastClickedIndex !== null) {
            // Shift+click = range select from lastClickedIndex to thisIdx
            // 1) If ctrl is NOT held, clear first
            if (!ctrl) {
                clearAllSelection();
            }
            selectRange(lastClickedIndex, thisIdx);
        } else if (ctrl) {
            // Ctrl+click = toggle this row only
            toggleSingleRow(trEl, null);
            lastClickedIndex = thisIdx;
        } else {
            // Plain click = select only this row
            clearAllSelection();
            toggleSingleRow(trEl, true);
            lastClickedIndex = thisIdx;
        }

        updateBulkUI();
    });

    // --- Double-click row to open Edit ---
    tbodyEl.addEventListener('dblclick', (ev) => {
        const trEl = ev.target.closest('tr[data-clip-uuid]');
        if (!trEl) return;
        const clipUuid = trEl.getAttribute('data-clip-uuid');
        if (!clipUuid) return;
        window.location.href = `/admin/projects/<?= $projectUuidSafe ?>/days/<?= $dayUuidSafe ?>/clips/${clipUuid}/edit`;
    });

    // Ctrl+A (Select All)
    document.addEventListener('keydown', (ev) => {
        // Windows/Linux: Ctrl+A, macOS: Cmd+A
        const isSelectAllShortcut =
            (ev.key === 'a' || ev.key === 'A') &&
            (ev.ctrlKey || ev.metaKey);

        if (!isSelectAllShortcut) return;

        // prevent the browser "select all text" behavior
        ev.preventDefault();

        clearAllSelection();
        tbodyEl.querySelectorAll('tr[data-clip-uuid]').forEach(tr => {
            toggleSingleRow(tr, true);
        });
        updateBulkUI();
    });

    // 1) Format per-row durations and grab page sum (we may use it later)
    initDurationsOnPage();

    // 2) Update the "Total runtime" header from server-provided full-day ms
    renderTotalRuntimeFromDayAttribute();

    // 3) Also compute selected runtime in case something starts pre-selected (usually 0)
    renderSelectedRuntime();

    document.addEventListener('click', async (ev) => {
        const btn = ev.target.closest('button.icon-btn[data-action="poster"]');
        if (!btn) return;

        const clipUuid = btn.getAttribute('data-clip');
        const csrf = "<?= htmlspecialchars($converter_csrf ?? '') ?>";
        const baseUrl = "/admin/projects/<?= $projectUuidSafe ?>/days/<?= $dayUuidSafe ?>/converter/";
        const endpoint = baseUrl + "poster";
        const rowEl = document.querySelector(`#clip-${clipUuid}`);

        btn.disabled = true;
        const originalTitle = btn.title;
        btn.title = "Working…";

        try {
            const body = new URLSearchParams({
                csrf_token: csrf,
                clip_uuid: clipUuid,
                force: '1'
            });

            const resp = await fetch(endpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body
            });

            const json = await resp.json();

            if (resp.ok && json.ok) {
                btn.title = 'Poster OK';

                // Update thumb (same logic as before)
                if (json.href) {
                    const thumbCellImg = rowEl?.querySelector('[data-field="thumb"] img');
                    if (thumbCellImg) {
                        thumbCellImg.src = json.href + '?v=' + Date.now();
                    } else {
                        const thumbDiv = rowEl?.querySelector('[data-field="thumb"] .zd-thumb');
                        if (thumbDiv) {
                            thumbDiv.outerHTML = `<img class="zd-thumb" src="${json.href}?v=${Date.now()}" alt="">`;
                        }
                    }
                }

                // Recompute durations & totals (no change)
                initDurationsOnPage();
                renderTotalRuntimeFromDayAttribute();
                renderSelectedRuntime();
            } else {
                btn.title = 'Poster ERR';
                alert(json.error || json.message || "Failed poster");
            }
        } catch (err) {
            console.error(err);
            btn.title = 'Poster ERR';
            alert("Network/JS error during poster");
        } finally {
            btn.disabled = false;
            setTimeout(() => {
                btn.title = originalTitle;
            }, 2000);
        }
    });


    // --- Bulk buttons ---
    async function runBulkPosterAction() {
        const csrf = "<?= htmlspecialchars($converter_csrf ?? '') ?>";
        const baseUrl = "/admin/projects/<?= $projectUuidSafe ?>/days/<?= $dayUuidSafe ?>/converter/";
        const endpoint = baseUrl + "poster";

        for (const clipUuid of selectedRows) {
            const rowEl = document.querySelector(`#clip-${clipUuid}`);
            try {
                const body = new URLSearchParams({
                    csrf_token: csrf,
                    clip_uuid: clipUuid,
                    force: '1'
                });

                const resp = await fetch(endpoint, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body
                });
                const json = await resp.json();

                if (resp.ok && json.ok) {
                    if (json.href) {
                        const thumbCellImg = rowEl?.querySelector('[data-field="thumb"] img');
                        if (thumbCellImg) {
                            thumbCellImg.src = json.href + '?v=' + Date.now();
                        } else {
                            const thumbDiv = rowEl?.querySelector('[data-field="thumb"] .zd-thumb');
                            if (thumbDiv) {
                                thumbDiv.outerHTML = `<img class="zd-thumb" src="${json.href}?v=${Date.now()}" alt="">`;
                            }
                        }
                    }
                } else {
                    console.error("Bulk poster failed for", clipUuid, json);
                    alert("Problem with poster on " + clipUuid + ": " + (json.error || json.message || "unknown error"));
                }
            } catch (err) {
                console.error("Bulk poster error for", clipUuid, err);
                alert("Network/JS error during bulk poster on " + clipUuid);
            }
        }

        initDurationsOnPage();
        renderTotalRuntimeFromDayAttribute();
        renderSelectedRuntime();
    }

    if (importForm) {
        importForm.addEventListener('submit', (ev) => {
            // Pack selection as comma-separated UUIDs (or leave empty string to mean "no limit")
            if (importUids) {
                importUids.value = (selectedRows.size > 0) ?
                    Array.from(selectedRows).join(',') :
                    '';
            }
            // Optional: block submit if no file chosen
            const file = document.getElementById('zd-import-file');
            if (!file || !file.files || file.files.length === 0) {
                ev.preventDefault();
                alert('Please choose a CSV file first.');
            }
        });
    }

    if (bulkPosterBtn) {
        bulkPosterBtn.addEventListener('click', async () => {
            await runBulkPosterAction();
        });
    }

    // --- Quick edit (scene/slate/take) ---
    const quickCsrf = "<?= htmlspecialchars($quick_csrf ?? '') ?>";

    function beginQuickEdit(td) {
        if (!td || td.classList.contains('zd-editing')) return;
        const field = td.getAttribute('data-edit');
        if (!field) return;

        const tr = td.closest('tr[data-clip-uuid]');
        const clipUuid = tr?.getAttribute('data-clip-uuid');
        if (!clipUuid) return;

        const span = td.querySelector('span');
        const oldVal = span ? span.textContent : '';

        td.classList.add('zd-editing');
        const input = document.createElement('input');
        input.type = 'text';
        input.className = 'zd-inline-input';
        input.value = oldVal;
        td.innerHTML = '';
        td.appendChild(input);
        input.focus();
        input.select();

        const cancel = () => {
            td.classList.remove('zd-editing');
            td.innerHTML = `<span>${oldVal}</span>`;
        };

        const commit = async (newVal) => {
            // no change → just restore
            if (newVal === oldVal) {
                cancel();
                return;
            }

            try {
                const body = new URLSearchParams({
                    _csrf: quickCsrf,
                    field: field,
                    value: newVal
                });

                const resp = await fetch(`/admin/projects/<?= $projectUuidSafe ?>/days/<?= $dayUuidSafe ?>/clips/${clipUuid}/quick`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body
                });

                const json = await resp.json();
                if (resp.ok && json.ok) {
                    // server may normalize/coerce (e.g., take_int)
                    const show = (json.display ?? newVal ?? '');
                    td.classList.remove('zd-editing');
                    td.innerHTML = `<span>${show}</span>`;

                    // If server updated anything duration-related etc., you could refresh here later.
                } else {
                    alert(json.error || 'Save failed');
                    cancel();
                }
            } catch (e) {
                console.error(e);
                alert('Network error');
                cancel();
            }
        };

        input.addEventListener('keydown', (ev) => {
            if (ev.key === 'Enter') {
                ev.preventDefault();
                commit(input.value.trim());
            } else if (ev.key === 'Escape') {
                ev.preventDefault();
                cancel();
            }
        });

        input.addEventListener('blur', () => {
            commit(input.value.trim());
        });
    }

    // Click on cell to start quick-edit (only if it isn't an action click)
    tbodyEl.addEventListener('click', (ev) => {
        const td = ev.target.closest('td.zd-editable');
        if (!td) return;

        // Avoid hijacking link/button clicks inside (defensive)
        const isAction = ev.target.closest('a,button');
        if (isAction) return;

        beginQuickEdit(td);
    });
</script>

<?php $this->end(); /* end of content section */ ?>

<?php $this->start('scripts'); ?>
<?= $this->includeWithin('partials/import_csv_modal', ['feedback' => $__fb]) ?>
<?php $this->end(); ?>