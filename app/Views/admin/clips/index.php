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

// Power-user status
$isSuperuser    = (int)($isSuperuser    ?? 0);
$isProjectAdmin = (int)($isProjectAdmin ?? 0);
$isPowerUser    = ($isSuperuser === 1 || $isProjectAdmin === 1);

// Column count for the table (power users see an extra Proxy/Job column)
$colCount = $isPowerUser ? 12 : 11;

// Expect $project_uuid and $day_uuid from the controller
$projectUuidSafe = htmlspecialchars($project_uuid ?? '');
$dayUuidSafe     = htmlspecialchars($day_uuid ?? '');

// TODO: when we add published_at on days and pass it in day_info,
// this will reflect the real publish state. For now it's always false.
$dayIsPublished = !empty($day_info['published_at'] ?? null);

$this->extend('layout/main');

$this->start('head'); ?>

<?php
// --- Sort link helper ---
// Same visual style as days/index.php, but keeps the clips filters in the query string.
function zd_sort_link(string $key, string $label, array $filters): string
{
    // Current sort key is the first one in the list (e.g. "scene,slate")
    $current = trim(explode(',', $filters['sort'] ?? '')[0] ?? '');
    $dir     = strtoupper($filters['dir'] ?? 'ASC');
    $nextDir = ($current === $key && $dir === 'ASC') ? 'DESC' : 'ASC';

    // Preserve all current GET params but override sort+dir
    $qs = $_GET ?? [];
    $qs['sort'] = $key;
    $qs['dir']  = $nextDir;
    $href = '?' . http_build_query($qs);

    $isCurrent   = ($current === $key);
    $activeClass = $isCurrent ? 'is-active' : '';
    $dirClass    = $isCurrent ? 'sort-' . strtolower($dir) : '';

    $html  = '<a href="' . htmlspecialchars($href) . '" class="zd-sortable-header ' . $activeClass . ' ' . $dirClass . '">';
    $html .= '<span>' . htmlspecialchars($label) . '</span>';
    $html .= '<svg viewBox="0 0 24 24" class="icon zd-sort-icon" aria-hidden="true">';
    $html .= '<path class="zd-arrow-up" d="M12 4l-4 4h8z" />';
    $html .= '<path class="zd-arrow-down" d="M12 20l4-4H8z" />';
    $html .= '</svg>';
    $html .= '</a>';

    return $html;
}
?>


<title>Clips · Zentropa Dailies</title>
<link rel="stylesheet" href="/assets/css/admin.clips.css?v=<?= rawurlencode((string)@filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/admin.clips.css')) ?>">
<script type="module" defer src="/assets/js/admin.clips.js?v=<?= rawurlencode((string)@filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/js/admin.clips.js')) ?>"></script>

<style>
    /* Publish day modal (dark admin look) */
    /* --- Table layout borrowed from days (users-style) --- */
    .zd-clips-table-wrap {
        margin-top: 16px;
        border-radius: 12px;
        overflow: visible;
        background: var(--panel, #111318);
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.35);
    }

    table.zd-clips-table {
        width: 100%;
        border-collapse: collapse;
        table-layout: fixed;
        font-size: 0.9rem;
    }

    .zd-clips-table thead th {
        text-align: left;
        padding: 10px 16px;
        background: #171922;
        font-size: 0.8rem;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        color: var(--muted, #9aa7b2);
        border-bottom: 1px solid var(--border, #1f2430);
        white-space: nowrap;
    }

    .zd-clips-table tbody td {
        padding: 10px 16px;
        border-top: 1px solid var(--border, #1f2430);
        color: var(--text, #e9eef3);
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    /* Allow dropdown menu to escape the cell */
    .zd-clips-table tbody td.col-actions {
        overflow: visible;
        text-overflow: initial;
        white-space: nowrap;
        position: relative;
        /* safety, in case we ever move the menu here */
    }


    .zd-clips-table tbody tr:hover {
        background: rgba(58, 160, 255, 0.05);
    }

    .zd-clips-table tbody tr {
        cursor: pointer;
    }

    /* Right-align “Actions” like on days */
    .col-actions {
        text-align: right;
        width: 140px;
    }

    /* Stronger blue highlight for selected rows, keeps keyboard/selection feel */
    .zd-clips-table tbody tr.zd-selected-row {
        background: rgba(58, 160, 255, 0.20);
    }

    .zd-publish-backdrop {
        position: fixed;
        inset: 0;
        background: rgba(0, 0, 0, 0.6);
        display: flex;
    }



    /* Thumb cell – hide any text dots under the image */
    .zd-clips-table td.thumb-cell {
        text-overflow: clip;
        white-space: nowrap;
        font-size: 0;
        /* hides any stray text like "..." */
    }



    .zd-publish-backdrop[hidden] {
        display: none;
    }

    .zd-publish-modal {
        background: #111318;
        border: 1px solid #1f2430;
        border-radius: 12px;
        padding: 16px 18px;
        width: min(440px, 92vw);
        box-shadow: 0 10px 40px rgba(0, 0, 0, 0.7);
    }

    .zd-publish-head h2 {
        margin: 0 0 8px;
        font-size: 18px;
        color: #e9eef3;
    }

    .zd-publish-body {
        font-size: 14px;
        color: #e9eef3;
    }

    .zd-publish-checkbox {
        display: flex;
        align-items: center;
        gap: 8px;
        margin-top: 16px;
        font-size: 14px;
        color: #e9eef3;
    }

    .zd-publish-note {
        margin-top: 8px;
        font-size: 12px;
        color: #9aa7b2;
    }

    .zd-publish-footer {
        margin-top: 16px;
        display: flex;
        justify-content: flex-end;
        gap: 8px;
    }

    /* --- Sortable headers (same as days/index.php) --- */
    .zd-sortable-header {
        display: inline-flex;
        align-items: center;
        gap: 2px;
        color: inherit;
        text-decoration: none;
        transition: color 0.15s ease;
    }

    .zd-sortable-header:hover,
    .zd-sortable-header.is-active {
        color: var(--text);
    }

    .zd-sort-icon {
        display: block;
        flex-shrink: 0;
        transition: fill 0.15s ease, opacity 0.15s ease;
    }

    .zd-sort-icon path {
        fill: var(--muted);
        opacity: 0.4;
        transition: fill 0.15s ease, opacity 0.15s ease;
    }

    .zd-sortable-header:hover .zd-sort-icon path {
        fill: var(--text);
        opacity: 0.6;
    }

    .zd-sortable-header.is-active.sort-asc .zd-sort-icon .zd-arrow-up,
    .zd-sortable-header.is-active.sort-desc .zd-sort-icon .zd-arrow-down {
        fill: var(--text);
        opacity: 1;
    }

    /* --- Actions dropdown (⋯) --- */
    .zd-actions-wrap {
        position: relative;
        display: inline-flex;
        justify-content: flex-end;
    }

    .zd-actions-btn {
        width: 32px;
        height: 28px;
        border-radius: 6px;
        border: 1px solid var(--border, #1f2430);
        background: #181b24;
        color: #e9eef3;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        font-size: 18px;
        line-height: 1;
        padding: 0;
    }

    .zd-actions-btn:hover {
        background: #222633;
    }

    .zd-actions-menu {
        position: absolute;
        right: 0;
        top: 34px;
        background: #111318;
        border: 1px solid #1f2430;
        border-radius: 8px;
        padding: 6px 0;
        min-width: 160px;
        box-shadow: 0 8px 24px rgba(0, 0, 0, 0.6);
        display: none;
        /* hide by default */
        z-index: 999;
    }

    .zd-actions-wrap.open .zd-actions-menu {
        display: block;
    }

    .zd-actions-menu a,
    .zd-actions-menu button.zd-actions-item {
        display: block;
        width: 100%;
        padding: 6px 12px;
        color: var(--text, #e9eef3);
        text-decoration: none;
        font-size: 13px;
        white-space: nowrap;
        text-align: left;
        background: none;
        border: none;
        cursor: pointer;
    }

    .zd-actions-menu a:hover,
    .zd-actions-menu button.zd-actions-item:hover {
        background: rgba(255, 255, 255, 0.08);
    }


    .zd-actions-head {
        display: inline-flex;
        align-items: center;
        justify-content: flex-end;
        gap: 6px;
        width: 100%;
    }

    /* When near bottom of viewport: open the row menu upwards instead */
    .zd-actions-wrap.drop-up .zd-actions-menu {
        top: auto;
        bottom: 34px;
        /* same offset as top, but from bottom */
        box-shadow: 0 -8px 24px rgba(0, 0, 0, 0.6);
    }

    /* --- Sleeker, tighter input fields for filters --- */

    .zd-filters .zd-input,
    .zd-filters .zd-select {
        background: #0f1218;
        border: 1px solid #1f2430;
        color: #e9eef3;
        padding: 4px 8px;
        /* was something like 8-12, now tighter */
        font-size: 13px;
        /* slightly smaller */
        border-radius: 6px;
        /* subtle radius */
        height: 28px;
        /* uniform compact height */
    }

    /* Make text inputs match dropdown height/padding exactly */
    .zd-filters input.zd-input[type="text"],
    .zd-filters input.zd-input {
        padding: 4px 8px;
        font-size: 13px;
        height: 28px;
        box-sizing: border-box;
    }

    /* Make all blue buttons match filter height and center text */
    .zd-clips-page .zd-btn,
    .zd-clips-page .zd-btn-primary {
        min-height: 28px;
        height: 28px;
        padding: 0 14px;
        /* horizontal padding only */
        display: inline-flex;
        /* enables centering */
        align-items: center;
        /* vertical centering */
        justify-content: center;
        /* horizontal centering */
        line-height: 1;
        font-size: 13px;
        border-radius: 6px;
        /* same feel as filters */
        box-sizing: border-box;
    }

    /* Normalize ALL button variants on the clips page */
    .zd-clips-page .zd-btn,
    .zd-clips-page .zd-btn-primary,
    .zd-clips-page .zd-btn-secondary,
    .zd-clips-page .zd-btn-danger,
    .zd-clips-page .btn,
    .zd-clips-page button {
        height: 28px;
        min-height: 28px;
        padding: 0 14px;
        font-size: 13px !important;
        /* make sure they match */
        display: inline-flex;
        align-items: center;
        justify-content: center;
        line-height: 1;
        border-radius: 6px;
        box-sizing: border-box;
    }


    .zd-filters .zd-input::placeholder {
        color: #6c7685;
    }

    .zd-filters .zd-select {
        padding-right: 24px;
        /* tighter select arrow spacing */
    }

    /* Buttons inside filters */
    .zd-filters .zd-btn {
        height: 28px;
        padding: 0 12px;
        font-size: 13px;
        border-radius: 6px;
    }

    /* Compact layout for filter row */
    .zd-filters {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        margin-bottom: 10px;
        align-items: center;
    }

    .zd-filters>* {
        flex-shrink: 0;
    }



    .zd-filters input.zd-input[type="text"]::placeholder {
        font-size: 11px;
        opacity: 0.65;
    }

    /* Limit width of Scene / Slate / Take fields */
    .zd-filters input[name="scene"],
    .zd-filters input[name="slate"],
    .zd-filters input[name="take"] {
        max-width: 55px;
        /* fits ~4 characters */
        width: 100%;
        justify-self: start;
        /* prevents stretching */
    }

    /* Selected (star) column */
    .col-selected {
        width: 5%;
        text-align: center;
        white-space: nowrap;
    }

    /* Make sure stars keep a solid size */
    .col-selected .star {
        width: 14px;
        height: 14px;
        display: inline-block;
    }


    .col-thumb {
        width: 6%;
    }

    .col-scene {
        width: 5%;
    }

    .col-slate {
        width: 5%;
    }

    .col-take {
        width: 4%;
    }

    .col-camera {
        width: 4%;
    }

    .col-selected {
        width: 6%;
    }

    .col-reel {
        width: 8%;
    }

    .col-file {
        width: 21%;
    }

    .col-tc {
        width: 8%;
    }

    .col-duration {
        width: 7%;
    }

    .col-proxy {
        width: 10%;
    }

    /* admin only */
    .col-actions {
        width: 3%;
    }

    /* Equal vertical spacing for the clips header meta rows */
    .zd-clips-head .zd-meta>div {
        margin-top: 6px;
    }

    .zd-clips-head .zd-meta>div:first-child {
        margin-top: 0;
    }
</style>


<?php $this->end(); ?>

<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$__fb = $_SESSION['import_feedback'] ?? null;
if ($__fb) {
    unset($_SESSION['import_feedback']);
} // show once
$__publish_fb = $_SESSION['publish_feedback'] ?? null;
if ($__publish_fb) {
    unset($_SESSION['publish_feedback']);
}

?>

<?php $this->start('content'); ?>

<div class="zd-clips-page"
    data-project="<?= htmlspecialchars($project_uuid) ?>"
    data-day="<?= htmlspecialchars($day_uuid) ?>"
    data-converter-csrf="<?= htmlspecialchars($converter_csrf ?? '') ?>"
    data-quick-csrf="<?= htmlspecialchars($quick_csrf ?? '') ?>"
    data-can-edit="<?= $isPowerUser ? '1' : '0' ?>">



    <?php
    // $dayLabel logic is now largely handled in controller, but we fallback safely
    $displayLabel = $is_all_days ? 'All days' : ($day_label ?? 'Day');
    ?>
    <div class="zd-clips-head">
        <h1>
            Clips - <?= htmlspecialchars($is_all_days ? 'All days' : ($day_label ?? 'Day')) ?>
            <?php if (!$is_all_days && ($dayIsPublished ?? false)): ?>
                <span class="zd-chip zd-chip-ok" style="margin-left: 8px; font-size: 0.5em; vertical-align: middle;">
                    Public
                </span>
            <?php endif; ?>
        </h1>

        <div class="zd-meta" style="line-height: 1.6;">
            <div style="color: #e9eef3; font-weight: 600;">
                Total: <?= (int)$total_unfiltered ?> clip<?= ($total_unfiltered == 1 ? '' : 's') ?>



                · Runtime:
                <span id="zd-unfiltered-runtime" data-ms="<?= (int)($duration_unfiltered ?? 0) ?>">
                    00:00:00:00
                </span>
            </div>

            <div id="zd-runtime-block"
                data-day-total-ms="<?= isset($day_total_duration_ms) ? (int)$day_total_duration_ms : 0 ?>"
                style="color: #9aa7b2;">
                Filtered: <?= (int)$total ?> clip<?= ($total == 1 ? '' : 's') ?>
                ·
                Runtime: <span id="zd-total-runtime">00:00:00:00</span>
            </div>
            <div class="zd-meta zd-meta-selected">
                <span id="zd-selected-count">0 selected</span>
                ·
                Selected runtime:
                <span id="zd-selected-runtime">00:00:00:00</span>
            </div>

        </div>
    </div>
    <?php if ($isPowerUser): ?>
        <div class="zd-actions" style="display:flex; gap:12px; align-items:center">

            <!-- Add clips -->
            <a class="zd-btn zd-btn-primary"
                href="/admin/projects/<?= htmlspecialchars($project_uuid) ?>/days/<?= htmlspecialchars($day_uuid) ?>/clips/upload">
                + Add clips
            </a>

            <!-- Publish / Unpublish -->
            <?php if (!$dayIsPublished): ?>
                <button type="button"
                    class="zd-btn zd-btn-primary"
                    id="zd-publish-open">
                    Publish
                </button>
            <?php else: ?>
                <button type="button"
                    class="zd-btn"
                    id="zd-unpublish-open">
                    Unpublish
                </button>
            <?php endif; ?>

            <!-- Delete -->
            <a class="zd-btn zd-btn-danger"
                href="/admin/projects/<?= htmlspecialchars($project['project_uuid'] ?? $project_uuid) ?>/days/<?= htmlspecialchars($day_uuid) ?>/delete">
                Delete day
            </a>

        </div>
    <?php endif; ?>

    <?php if (!empty($__publish_fb['published'])): ?>
        <div class="zd-flash-ok">
            Day published<?= !empty($__publish_fb['send_email']) ? ' (Email planned)' : '' ?>.
        </div>
    <?php endif; ?>


    <?php if ($isPowerUser && !$is_all_days): ?>
        <div class="zd-bulk-actions" style="display:flex; flex-wrap:wrap; gap:12px; align-items:center; font-size:13px; color:#9aa7b2;">

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
    <?php endif; ?>

    <div class="zd-stack">
        <form method="get" class="zd-filters">
            <select class="zd-select" id="zd-day-select">
                <option value="/admin/projects/<?= htmlspecialchars($project_uuid) ?>/days/all/clips"
                    <?= $is_all_days ? 'selected' : '' ?>>
                    All days
                </option>
                <?php foreach ($all_days ?? [] as $d):
                    $dLabel = $d['title'] ?: $d['shoot_date'];
                    $dUrl = "/admin/projects/" . htmlspecialchars($project_uuid) . "/days/" . htmlspecialchars($d['id']) . "/clips";
                    $isSelected = !$is_all_days && ($day_uuid === $d['id']);
                ?>
                    <option value="<?= $dUrl ?>" <?= $isSelected ? 'selected' : '' ?>>
                        <?= htmlspecialchars($dLabel) ?>
                    </option>
                <?php endforeach; ?>
            </select>

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


        <div class="zd-table-wrap zd-clips-table-wrap">
            <table class="zd-table zd-clips-table">
                <thead>
                    <tr>
                        <th class="col-thumb zd-resizable">
                            <div class="th-inner">Thumb</div>
                            <div class="col-resize-handle"></div>
                        </th>

                        <th class="col-scene zd-resizable">
                            <div class="th-inner">
                                <?= zd_sort_link('scene', 'Scene', $filters) ?>
                            </div>
                            <div class="col-resize-handle"></div>
                        </th>

                        <th class="col-slate zd-resizable">
                            <div class="th-inner">
                                <?= zd_sort_link('slate', 'Slate', $filters) ?>
                            </div>
                            <div class="col-resize-handle"></div>
                        </th>

                        <th class="col-take zd-resizable">
                            <div class="th-inner">
                                <?= zd_sort_link('take', 'Take', $filters) ?>
                            </div>
                            <div class="col-resize-handle"></div>
                        </th>

                        <th class="col-camera zd-resizable">
                            <div class="th-inner">
                                <?= zd_sort_link('camera', 'Cam', $filters) ?>
                            </div>
                            <div class="col-resize-handle"></div>
                        </th>

                        <th class="col-selected zd-resizable">
                            <div class="th-inner">
                                <?= zd_sort_link('select', 'Selected', $filters) ?>
                            </div>
                            <div class="col-resize-handle"></div>
                        </th>

                        <th class="col-reel zd-resizable">
                            <div class="th-inner">
                                <?= zd_sort_link('reel', 'Reel', $filters) ?>
                            </div>
                            <div class="col-resize-handle"></div>
                        </th>

                        <th class="col-file zd-resizable">
                            <div class="th-inner">
                                <?= zd_sort_link('file', 'File', $filters) ?>
                            </div>
                            <div class="col-resize-handle"></div>
                        </th>

                        <th class="col-tc zd-resizable">
                            <div class="th-inner">
                                <?= zd_sort_link('tc_start', 'TC In', $filters) ?>
                            </div>
                            <div class="col-resize-handle"></div>
                        </th>

                        <th class="col-duration zd-resizable">
                            <div class="th-inner">
                                <?= zd_sort_link('duration', 'Dur', $filters) ?>
                            </div>
                            <div class="col-resize-handle"></div>
                        </th>

                        <?php if ($isPowerUser): ?>
                            <th class="col-proxy zd-resizable">
                                <div class="th-inner">Proxy / Job</div>
                                <div class="col-resize-handle"></div>
                            </th>
                        <?php endif; ?>

                        <th class="col-actions">
                            <div class="zd-actions-head">
                                <span>Actions</span>

                                <?php if ($isPowerUser): ?>
                                    <div class="zd-actions-wrap zd-actions-wrap-head">
                                        <button class="zd-actions-btn" type="button">⋯</button>
                                        <div class="zd-actions-menu">
                                            <button type="button" class="zd-actions-item" data-bulk-import>
                                                Import metadata for selected (overwrite)
                                            </button>
                                            <button type="button" class="zd-actions-item" data-bulk-poster>
                                                Generate posters for selected
                                            </button>
                                            <button type="button" class="zd-actions-item" data-bulk-delete>
                                                Delete selected
                                            </button>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </th>
                    </tr>
                </thead>



                <tbody>
                    <?php if (!$rows): ?>
                        <tr>
                            <td colspan="<?= $colCount ?>" class="zd-meta">No clips found.</td>
                        </tr>
                        <?php else: foreach ($rows as $idx => $r): ?>
                            <?php
                            $isSelected = (int)($r['selected'] ?? 0) === 1;
                            $thumbUrl   = $r['poster_path'] ?? $placeholder_thumb_url;

                            // NEW: per-row day UUID (for All days vs single day)
                            $rowDayUuid      = $r['day_uuid'] ?? $day_uuid;
                            $rowDayUuidSafe  = htmlspecialchars($rowDayUuid, ENT_QUOTES, 'UTF-8');

                            // NEW: proxy / job labels, based on ClipRepository fields
                            $hasProxy        = !empty($r['proxy_path']);
                            $proxyCount      = (int)($r['proxy_count'] ?? 0);
                            $jobState        = (string)($r['job_state'] ?? '');
                            $jobStateTsNice  = (string)($r['job_state_ts_nice'] ?? '');

                            if ($hasProxy) {
                                // At least one web proxy exists
                                $proxyLabel = 'Proxy ready';
                                if ($proxyCount > 1) {
                                    $proxyLabel .= ' (' . $proxyCount . ' files)';
                                }
                            } elseif ($proxyCount > 0) {
                                // Some proxy assets but not the main one
                                $proxyLabel = 'Proxy files: ' . $proxyCount;
                            } else {
                                $proxyLabel = 'No proxy';
                            }

                            if ($jobState !== '') {
                                $jobLabel = ucfirst($jobState);
                                if ($jobStateTsNice !== '') {
                                    $jobLabel .= ' · ' . $jobStateTsNice;
                                }
                            } else {
                                $jobLabel = 'No jobs';
                            }
                            ?>

                            <tr id="clip-<?= htmlspecialchars($r['clip_uuid']) ?>"
                                class="zd-table-row"
                                data-clip-uuid="<?= htmlspecialchars($r['clip_uuid']) ?>"
                                data-row-index="<?= $idx ?>"
                                data-day-uuid="<?= $rowDayUuidSafe ?>">


                                <!-- Thumb -->
                                <td class="col-thumb thumb-cell" data-field="thumb">
                                    <?php if (!empty($r['poster_path'])): ?>
                                        <img class="zd-thumb" src="<?= htmlspecialchars($r['poster_path']) ?>" alt="">
                                    <?php else: ?>
                                        <div class="zd-thumb"></div>
                                    <?php endif; ?>
                                </td>

                                <!-- Scene -->
                                <td class="col-scene <?php if ($isPowerUser): ?>zd-editable<?php endif; ?>"
                                    <?php if ($isPowerUser): ?>data-edit="scene" <?php endif; ?>>
                                    <span><?= htmlspecialchars($r['scene'] ?? '') ?></span>
                                </td>

                                <!-- Slate -->
                                <td class="col-slate <?php if ($isPowerUser): ?>zd-editable<?php endif; ?>"
                                    <?php if ($isPowerUser): ?>data-edit="slate" <?php endif; ?>>
                                    <span><?= htmlspecialchars($r['slate'] ?? '') ?></span>
                                </td>

                                <!-- Take -->
                                <td class="col-take <?php if ($isPowerUser): ?>zd-editable<?php endif; ?>"
                                    <?php if ($isPowerUser): ?>data-edit="take" <?php endif; ?>>
                                    <span><?= htmlspecialchars(($r['take_int'] ?? null) !== null ? (string)$r['take_int'] : ($r['take'] ?? '')) ?></span>
                                </td>

                                <!-- Camera -->
                                <td class="col-camera"><?= htmlspecialchars($r['camera'] ?? '') ?></td>

                                <!-- Selected -->
                                <!-- Selected -->
                                <td class="col-selected zd-select-td" data-field="select">
                                    <?php $isSelected = (int)($r['is_select'] ?? 0) === 1; ?>

                                    <?php if ($isPowerUser): ?>
                                        <button class="star-toggle"
                                            type="button"
                                            data-clip="<?= htmlspecialchars($r['clip_uuid']) ?>"
                                            data-selected="<?= $isSelected ? 1 : 0 ?>"
                                            aria-label="Toggle selected"
                                            title="Toggle selected">
                                            <?php if ($isSelected): ?>
                                                <svg
                                                    viewBox="0 0 24 24"
                                                    class="star on"
                                                    width="18"
                                                    height="18"
                                                    aria-hidden="true">
                                                    <path d="M12 .587l3.668 7.431 8.2 1.192-5.934 5.788 1.402 8.168L12 18.896l-7.336 3.87 1.402-8.168L.132 9.21l8.2-1.192z" />
                                                </svg>
                                            <?php else: ?>
                                                <svg
                                                    viewBox="0 0 24 24"
                                                    class="star off"
                                                    width="18"
                                                    height="18"
                                                    aria-hidden="true">
                                                    <path d="M12 .587l3.668 7.431 8.2 1.192-5.934 5.788 1.402 8.168L12 18.896l-7.336 3.87 1.402-8.168L.132 9.21l8.2-1.192z" />
                                                </svg>
                                            <?php endif; ?>
                                        </button>
                                    <?php else: ?>
                                        <span class="star-readonly">
                                            <?php if ($isSelected): ?>
                                                <svg
                                                    viewBox="0 0 24 24"
                                                    class="star on"
                                                    width="18"
                                                    height="18"
                                                    aria-hidden="true">
                                                    <path d="M12 .587l3.668 7.431 8.2 1.192-5.934 5.788 1.402 8.168L12 18.896l-7.336 3.87 1.402-8.168L.132 9.21l8.2-1.192z" />
                                                </svg>
                                            <?php else: ?>
                                                <svg
                                                    viewBox="0 0 24 24"
                                                    class="star off"
                                                    width="18"
                                                    height="18"
                                                    aria-hidden="true">
                                                    <path d="M12 .587l3.668 7.431 8.2 1.192-5.934 5.788 1.402 8.168L12 18.896l-7.336 3.87 1.402-8.168L.132 9.21l8.2-1.192z" />
                                                </svg>
                                            <?php endif; ?>
                                        </span>
                                    <?php endif; ?>
                                </td>


                                <!-- Reel -->
                                <td class="col-reel"><?= htmlspecialchars($r['reel'] ?? '') ?></td>

                                <!-- File -->
                                <td class="col-file">
                                    <?php
                                    $fileNameFull = $r['file_name'] ?? '';
                                    $fileNameWithoutExt = pathinfo($fileNameFull, PATHINFO_FILENAME);
                                    ?>
                                    <div class="filename" title="<?= htmlspecialchars($fileNameFull) ?>"><?= htmlspecialchars($fileNameWithoutExt) ?></div>
                                </td>

                                <!-- TC In -->
                                <td class="col-tc" data-field="tc_start"><?= htmlspecialchars($r['tc_start'] ?? '') ?></td>

                                <!-- Duration -->
                                <td class="col-duration"
                                    data-field="duration"
                                    data-duration-ms="<?= $r['duration_ms'] !== null ? (int)$r['duration_ms'] : '' ?>"
                                    data-fps="<?= isset($r['fps']) && $r['fps'] !== null ? (int)$r['fps'] : '' ?>">
                                </td>

                                <?php if ($isPowerUser): ?>
                                    <!-- Proxy / Job -->
                                    <td class="col-proxy" data-field="proxy_job">
                                        <span class="zd-meta">
                                            <?= htmlspecialchars($proxyLabel, ENT_QUOTES, 'UTF-8') ?> ·
                                            <?= htmlspecialchars($jobLabel, ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                    </td>
                                <?php endif; ?>

                                <!-- Actions -->
                                <td class="col-actions">
                                    <div class="zd-actions-head">
                                        <a href="/admin/projects/<?= $projectUuidSafe ?>/days/<?= $rowDayUuidSafe ?>/player/<?= htmlspecialchars($r['clip_uuid']) ?>"
                                            class="zd-actions-btn zd-play-btn" title="Play">
                                            <img src="/assets/icons/play.svg" class="icon" alt="Play">
                                        </a>

                                        <?php if ($isPowerUser): ?>
                                            <div class="zd-actions-wrap">
                                                <button class="zd-actions-btn" type="button">⋯</button>
                                                <div class="zd-actions-menu">
                                                    <a href="/admin/projects/<?= $project_uuid ?>/days/<?= $rowDayUuid ?>/clips/<?= $r['clip_uuid'] ?>/edit">Edit metadata</a>
                                                    <a href="#" data-clip-import="<?= htmlspecialchars($r['clip_uuid']) ?>">Import metadata</a>
                                                    <a href="/admin/projects/<?= $project_uuid ?>/days/<?= $rowDayUuid ?>/clips/<?= $r['clip_uuid'] ?>/poster">Generate poster</a>
                                                    <a href="/admin/projects/<?= $project_uuid ?>/days/<?= $rowDayUuid ?>/clips/<?= $r['clip_uuid'] ?>/delete" style="color:#d62828;">Delete</a>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                    </div>
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

<!-- Publish day modal -->
<div class="zd-publish-backdrop" id="zd-publish-backdrop" hidden>
    <div class="zd-publish-modal">
        <div class="zd-publish-head">
            <h2 id="zd-modal-title">Publish day</h2>
        </div>

        <div class="zd-publish-body">
            <p id="zd-modal-message">Are you sure you want to publish this day?</p>

            <div id="zd-modal-email-block">
                <label class="zd-publish-checkbox">
                    <input type="checkbox" id="zd-publish-send-email" value="1">
                    <span>Send email to users with link to this day</span>
                </label>
            </div>

            <div id="zd-modal-publish-extra">
                <p style="margin-top: 0.5rem;">
                    When published, this day becomes visible to regular users on the project.
                </p>
                <p style="margin-top: 0.75rem; font-size: 0.85rem; opacity: 0.7;">
                    (Email sending will be implemented later – this checkbox is just stored for now.)
                </p>
            </div>
        </div>


        <div class="zd-publish-footer">
            <button type="button"
                class="zd-btn"
                id="zd-publish-cancel">
                Cancel
            </button>
            <button type="button"
                class="zd-btn"
                id="zd-publish-confirm">
                Publish
            </button>
        </div>
    </div>
</div>

<!-- Hidden forms for real publish and unpublish POST  -->
<form id="zd-publish-form"
    method="post"
    action="/admin/projects/<?= htmlspecialchars($project_uuid) ?>/days/<?= htmlspecialchars($day_uuid) ?>/publish"
    style="display:none;">
    <input type="hidden" name="_csrf" value="<?= \App\Support\Csrf::token() ?>">
    <input type="hidden" name="send_email" id="zd-publish-send-email-field" value="0">
</form>

<form id="zd-unpublish-form"
    method="post"
    action="/admin/projects/<?= htmlspecialchars($project_uuid) ?>/days/<?= htmlspecialchars($day_uuid) ?>/unpublish"
    style="display:none;">
    <input type="hidden" name="_csrf" value="<?= \App\Support\Csrf::token() ?>">
</form>

<form id="zd-bulk-delete-form"
    method="post"
    action="/admin/projects/<?= htmlspecialchars($project_uuid) ?>/days/<?= htmlspecialchars($day_uuid) ?>/clips/bulk_delete"
    style="display:none;">
    <input type="hidden" name="_csrf" value="<?= \App\Support\Csrf::token() ?>">
    <input type="hidden" name="clip_uuids" id="zd-bulk-delete-uuids" value="">
</form>




<?php $this->end(); /* end of content section */ ?>

<?php $this->start('scripts'); ?>
<?= $this->includeWithin('partials/import_csv_modal', ['feedback' => $__fb]) ?>

<script>
    document.addEventListener("DOMContentLoaded", function() {
        const backdrop = document.getElementById("zd-publish-backdrop");

        const btnOpenPublish = document.getElementById("zd-publish-open");
        const btnOpenUnpublish = document.getElementById("zd-unpublish-open");

        const btnCancel = document.getElementById("zd-publish-cancel");
        const btnConfirm = document.getElementById("zd-publish-confirm");

        const sendEmailBlock = document.getElementById("zd-modal-email-block");
        const sendEmailInput = document.getElementById("zd-publish-send-email");
        const titleEl = document.getElementById("zd-modal-title");
        const messageEl = document.getElementById("zd-modal-message");
        const publishExtra = document.getElementById("zd-modal-publish-extra");


        const publishForm = document.getElementById("zd-publish-form");
        const unpublishForm = document.getElementById("zd-unpublish-form");
        const sendField = document.getElementById("zd-publish-send-email-field");

        let currentMode = "publish";

        function openModal(mode) {
            currentMode = mode;

            if (mode === "publish") {
                titleEl.textContent = "Publish day";
                messageEl.textContent = "Are you sure you want to publish this day?";
                btnConfirm.textContent = "Publish";

                sendEmailBlock.style.display = "block";
                publishExtra.style.display = "block";

            } else {
                titleEl.textContent = "Unpublish day";
                messageEl.textContent = "Unpublish this day? It will no longer be visible to regular users.";
                btnConfirm.textContent = "Unpublish";

                sendEmailBlock.style.display = "none";
                publishExtra.style.display = "none";
            }


            backdrop.hidden = false;
        }

        function closeModal() {
            backdrop.hidden = true;
        }

        if (btnOpenPublish) {
            btnOpenPublish.addEventListener("click", () => openModal("publish"));
        }
        if (btnOpenUnpublish) {
            btnOpenUnpublish.addEventListener("click", () => openModal("unpublish"));
        }

        if (btnCancel) {
            btnCancel.addEventListener("click", closeModal);
        }

        backdrop.addEventListener("click", (e) => {
            if (e.target === backdrop) closeModal();
        });

        if (btnConfirm) {
            btnConfirm.addEventListener("click", () => {
                if (currentMode === "publish") {
                    sendField.value = sendEmailInput.checked ? "1" : "0";
                    publishForm.submit();
                } else {
                    unpublishForm.submit();
                }
            });
        }
    });
</script>

<script>
    document.addEventListener('click', (e) => {
        const btn = e.target.closest('.zd-actions-btn');
        const wrap = btn?.closest('.zd-actions-wrap');

        // Shared refs
        const bulkPosterBtn = document.getElementById('zd-bulk-poster');
        const importFileInput = document.getElementById('zd-import-file');
        const importOverwrite = document.getElementById('zd-import-overwrite');
        const importUuids = document.getElementById('zd-import-uuids');
        const bulkDeleteForm = document.getElementById('zd-bulk-delete-form');
        const bulkDeleteUuids = document.getElementById('zd-bulk-delete-uuids');

        function getSelectedClipUuids() {
            return Array.from(document.querySelectorAll('.zd-table tbody tr.zd-selected-row'))
                .map(tr => tr.dataset.clipUuid)
                .filter(Boolean);
        }

        function closeAllMenus() {
            document.querySelectorAll('.zd-actions-wrap.open')
                .forEach(w => {
                    w.classList.remove('open');
                    w.classList.remove('drop-up');
                });
        }

        // === Open/close any ⋯ menu ===
        if (btn && wrap) {
            const wasOpen = wrap.classList.contains('open');

            // Close everything first so we never have two open
            closeAllMenus();

            if (!wasOpen) {
                wrap.classList.remove('drop-up');

                // Auto-direction (skip header)
                if (!wrap.classList.contains('zd-actions-wrap-head')) {
                    const rect = wrap.getBoundingClientRect();
                    const menuHeight = 160; // estimated dropdown height
                    const spaceBelow = window.innerHeight - rect.bottom;
                    const spaceAbove = rect.top;

                    // Not enough room below → fold up
                    if (spaceBelow < menuHeight && spaceAbove > menuHeight) {
                        wrap.classList.add('drop-up');
                    }
                }

                wrap.classList.add('open');
            }

            e.stopPropagation();
            return;
        }



        // === Header bulk: Import metadata for selected (overwrite) ===
        const bulkImportBtn = e.target.closest('[data-bulk-import]');
        if (bulkImportBtn) {
            e.preventDefault();
            const uuids = getSelectedClipUuids();
            if (!uuids.length) {
                alert('No clips selected.');
                closeAllMenus();
                return;
            }
            if (!importFileInput || !importOverwrite || !importUuids) return;

            importOverwrite.checked = true;
            importUuids.value = uuids.join(',');
            importFileInput.click(); // open file picker
            closeAllMenus();
            return;
        }

        // === Header bulk: Generate posters for selected ===
        const bulkPosterMenu = e.target.closest('[data-bulk-poster]');
        if (bulkPosterMenu) {
            e.preventDefault();
            if (bulkPosterBtn) bulkPosterBtn.click();
            closeAllMenus();
            return;
        }

        // === Header bulk: Delete selected ===
        const bulkDeleteBtn = e.target.closest('[data-bulk-delete]');
        if (bulkDeleteBtn) {
            e.preventDefault();
            const uuids = getSelectedClipUuids();
            if (!uuids.length) {
                alert('No clips selected.');
                closeAllMenus();
                return;
            }
            if (!bulkDeleteForm || !bulkDeleteUuids) return;

            if (!confirm('Delete ' + uuids.length + ' selected clip(s)?')) {
                closeAllMenus();
                return;
            }
            bulkDeleteUuids.value = uuids.join(',');
            bulkDeleteForm.submit();
            return;
        }

        // === Per-clip: Import metadata for THIS clip only ===
        const clipImportLink = e.target.closest('[data-clip-import]');
        if (clipImportLink) {
            e.preventDefault();
            const clipUuid = clipImportLink.getAttribute('data-clip-import');
            if (!clipUuid || !importFileInput || !importOverwrite || !importUuids) return;

            importOverwrite.checked = true;
            importUuids.value = clipUuid;
            importFileInput.click(); // same CSV form, limited to single clip
            closeAllMenus();
            return;
        }

        // === Click elsewhere: close any open menus ===
        closeAllMenus();
    });
</script>






<?php $this->end(); ?>