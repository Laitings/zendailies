<?php

/** @var \App\Support\View $this */
/** @var array $project */
/** @var array $day */
/** @var array $clip */
/** @var string|null $proxy_url */
/** @var string|null $poster_url */
/** @var array $metadata */
/** @var array $comments */
/** @var string $project_uuid */
/** @var string $day_uuid */
/** @var array $clip_list */
/** @var string|null $current_clip */
/** @var string $current_day_label */
/** @var array $days  Array of all days for this project:
 * [
 * [
 * 'day_uuid'   => '...',
 * 'title'      => 'DAY 03' or '2025-10-30' etc,
 * 'shoot_date' => '2025-10-30', // fallback
 * 'thumb_url'  => '/data/.../poster.jpg' or null,
 * ],
 * ...
 * ]
 */
/** @var string $placeholder_thumb_url  URL for generic day thumb */

$this->extend('layout/main');
$navActive = 'player';
?>

<?php $this->start('title'); ?>
<?= htmlspecialchars($project['title']) ?> · Player
<?php $this->end(); ?>

<?php $this->start('head'); ?>
<link rel="stylesheet" href="/assets/css/player.css">

<style>
    #sortByWrap.is-hidden {
        display: none !important;
    }

    /* Keep Scenes overview aligned with Days/Clips (avoid scrollbar width shifting + ensure identical padding) */
    .sceneScrollOuter {
        max-height: 72vh;
        position: relative;
        overflow-y: auto;
        overflow-x: hidden;
        padding: 0;
        margin: 0;
    }

    #sceneScrollInner {
        scrollbar-gutter: stable;
        padding: 10px !important;
        /* match .dayScrollInner */
        margin: 0 !important;
        box-sizing: border-box;
        position: relative;
    }


    .day-title-line {
        font-weight: 600;
        color: var(--text);
    }

    /* Compact sort select in the sidebar header */
    .zd-sort-select {
        font-size: 12px;
        padding: 4px 8px;
        border-radius: 8px;
        border: 1px solid var(--border);
        background: var(--bg);
        color: var(--text);
        max-width: 140px;
    }

    /* Meta below title in list mode */
    .day-rowmeta {
        margin-top: 2px;
        font-size: 12px;
        color: var(--muted);
    }

    /* Meta on thumb in grid mode (centered) */
    .day-overlay-meta {
        position: absolute;
        left: 50%;
        bottom: 10px;
        transform: translateX(-50%);
        font-size: 13px;
        color: var(--text);
        opacity: 0.9;
        text-align: center;
        text-shadow: 0 1px 2px rgba(0, 0, 0, .6);
        white-space: nowrap;
    }

    .zd-scene-line {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
        margin-bottom: 8px;
        color: var(--text);
        font-weight: 600;
    }

    .zd-scene-left {
        display: flex;
        align-items: center;
        gap: 10px;
        flex-wrap: nowrap;
        min-width: 0;
    }

    .zd-clip-filename {
        font-weight: 400;
        color: var(--muted);
        font-size: 13px;
        opacity: 0.8;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .zd-meta-grid {
        display: grid;
        grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
        gap: 10px 24px;
        /* rows x columns */
        margin-top: 6px;
    }

    .zd-meta-cell {
        display: grid;
        grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
        /* both columns scale equally */
        align-items: center;
        gap: 8px;
        padding: 6px 8px;
        border: 1px solid var(--border);
        border-radius: 8px;
        background: rgba(255, 255, 255, 0.02);
        min-width: 0;
        /* allow shrinking properly */
    }

    .zd-meta-k {
        font-size: 12px;
        color: var(--muted);
        white-space: nowrap;
        min-width: 0;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .zd-meta-v {
        font-size: 13px;
        color: var(--text);
        overflow: hidden;
        min-width: 0;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    /* tables: make columns shrink, clip long values */
    .zd-meta-table {
        table-layout: fixed;
        width: 100%;
    }

    .zd-meta-table td:last-child {
        overflow: hidden;
        text-overflow: ellipsis;
        word-break: break-word;
        /* allow breaking long tokens/paths */
        max-width: 1px;
        /* enables ellipsis inside fixed-layout tables */
    }


    /* ====== Base (3 columns: sidebar | resizer | player) ====== */
    .player-layout {
        display: grid;
        grid-template-columns: var(--clipcol-width, 360px) 10px 1fr;
        grid-template-areas: "side res main";
        column-gap: 8px;
        align-items: start;
    }

    .player-layout>section,
    .player-layout>aside {
        min-width: 0;
    }

    /* the meta card itself must be allowed to shrink */
    .player-layout .player-meta {
        min-width: 0;
    }

    /* the wrapper and player frame should also be shrinkable */
    .player-layout .zd-player-wrap,
    .player-layout .zd-player-frame {
        min-width: 0;
    }

    .player-layout>aside {
        grid-area: side;
    }

    #sidebarResizer {
        grid-area: res;
    }

    .player-layout>section {
        grid-area: main;
    }

    /* Player width limits in normal mode */
    .zd-player-wrap {
        width: 100%;
        max-width: var(--player-maxw, 1000px);
        margin: 0 auto;
    }

    /* Hide inner resizer in normal mode */
    #innerResizer {
        display: none;
    }

    /* ====== Theater mode ====== */
    .player-layout.is-theater {
        /* Two rows:
           Row 1: player spans full width
           Row 2: sidebar | resizer | metadata */
        grid-template-columns: 1fr 6px var(--meta-width, 420px);
        grid-template-rows: auto 1fr;
        grid-template-areas:
            "main main main"
            "side res meta";
        row-gap: 12px;
        column-gap: 8px;
    }

    /* In theater, sidebar stays on left */
    .player-layout.is-theater>aside {
        grid-area: side;
    }

    /* Show outer resizer in theater (between sidebar and metadata) */
    .player-layout.is-theater #sidebarResizer {
        grid-area: res;
        display: block;
    }

    /* Section takes both "main" (row 1) and "meta" (row 2 right side) */
    .player-layout.is-theater>section.player-main {
        grid-area: main;
        display: contents;
        /* This allows children to participate in parent grid */
    }

    /* Player goes to row 1, spans all columns */
    .player-layout.is-theater .zd-player-wrap {
        grid-column: 1 / -1;
        grid-row: 1;
        max-width: none;
    }

    /* Metadata goes to row 2, right column (meta area) */
    .player-layout.is-theater .player-meta {
        grid-column: 3;
        grid-row: 2;
    }

    .player-layout.is-theater .zd-player-frame {
        max-height: 96vh;
    }

    /* Side lists expand naturally below; no fixed max-heights */
    .player-layout.is-theater .clipScrollOuter,
    .player-layout.is-theater .dayScrollOuter,
    .player-layout.is-theater .clipScrollInner,
    .player-layout.is-theater .dayScrollInner {
        max-height: none;
    }

    .clipScrollOuter,
    .dayScrollOuter {
        max-height: 72vh;
        position: relative;

        overflow-y: auto;
        overflow-x: hidden;
        /* ← CRITICAL: prevent overlap over resizer */

        padding: 0;
        margin: 0;
    }

    .clipScrollInner,
    .dayScrollInner {
        overflow-y: auto;
        overflow-x: hidden;
        /* ← also critical */
        max-height: 72vh;
        padding: 10px;
        margin: 0;
        box-sizing: border-box;
        position: relative;
    }


    /* Divider styling */
    .sidebar-resizer {
        cursor: col-resize;
        background: var(--border);
        border-radius: 2px;
        transition: background 0.2s;
    }

    .sidebar-resizer:hover {
        background: var(--accent);
    }

    /* theater icons via CSS mask, inherit button color */
    #btnTheater .theater-icon {
        display: inline-block;
        width: 18px;
        height: 18px;
        background-color: var(--text);
        /* color of the icon */
        vertical-align: middle;

        /* mask for modern + webkit */
        -webkit-mask-repeat: no-repeat;
        -webkit-mask-position: center;
        -webkit-mask-size: 18px 18px;
        mask-repeat: no-repeat;
        mask-position: center;
        mask-size: 18px 18px;

        /* default (enter) icon file */
        -webkit-mask-image: url("/assets/icons/theater.svg");
        mask-image: url("/assets/icons/theater.svg");
    }

    /* exit variant uses your second file */
    #btnTheater .theater-icon.theater-exit {
        -webkit-mask-image: url("/assets/icons/theater-exit.svg");
        mask-image: url("/assets/icons/theater-exit.svg");
    }

    /* FIX: Prevent sidebar header from expanding the sidebar beyond its grid column */
    .zd-header-wrap {
        min-width: 0;
        /* allow shrinking */
        overflow: hidden;
        /* prevent content from breaking resizer */
    }

    /* FIX: Prevent inner header rows from forcing sidebar wider */
    .hdr-row-1,
    .hdr-row-2 {
        min-width: 0;
    }

    /* FIX: Sidebar must not use overflow:visible for width calculations */
    .player-layout>aside {
        overflow-x: hidden !important;
    }

    #sidebarResizer {
        position: relative;

        /* sits ABOVE overflowing elements */
        pointer-events: auto !important;
    }

    /* Styling for "Basic Metadata" and "Extended Metadata" headers */
    .zd-metadata-summary {
        font-family: 'Inter', sans-serif;
        font-size: 11px;
        /* Small and crisp */
        text-transform: uppercase;
        /* All caps */
        letter-spacing: 0.08em;
        /* Wide tracking */
        font-weight: 600;
        /* Semi-bold */
        color: #9ca3af;
        /* The "Pro" Muted Gray */
        cursor: pointer;
        margin-bottom: 8px;
        /* Spacing between header and data */
        user-select: none;
        /* Prevents text selection when clicking */

        /* Fixes thickness on Mac screens */
        -webkit-font-smoothing: antialiased;
        -moz-osx-font-smoothing: grayscale;

        /* Flex alignment to ensure the arrow lines up with text */
        display: flex;
        align-items: center;
        gap: 4px;
        transition: color 0.2s ease;
    }

    /* Hover state - Lightens up */
    .zd-metadata-summary:hover {
        color: #e5e7eb;
    }

    /* Optional: If you want to fix the color of the little arrow triangle */
    .zd-metadata-summary::marker,
    .zd-metadata-summary::-webkit-details-marker {
        color: #9ca3af;
    }

    .zd-section-header {
        font-family: 'Inter', sans-serif;
        font-size: 11px;
        /* Match metadata headers */
        text-transform: uppercase;
        /* All caps */
        letter-spacing: 0.08em;
        /* Wide tracking */
        font-weight: 600;
        /* Semi-bold */
        color: #9ca3af;
        /* Muted Gray */

        /* Fix alignment since it's inside a flex container */
        line-height: 1;

        /* Crispness */
        -webkit-font-smoothing: antialiased;
        -moz-osx-font-smoothing: grayscale;
    }

    /* Disable complex UI elements during resizing for 60fps performance */
    body.zd-resizing * {
        pointer-events: none !important;
        transition: none !important;
        user-select: none !important;
    }

    /* Ensure the video doesn't attempt to re-render in high quality during drag */
    body.zd-resizing video {
        will-change: transform;
    }

    /* Find where #selBlanking is defined or add this to the bottom */

    #selBlanking.zd-select {
        height: 32px;
        /* Matches .zd-btn-icon height */
        font-size: 11px;
        /* Smaller, crisper font */
        padding: 2px 8px;
        /* Reduced vertical padding */
        border-radius: 8px;
        background-color: var(--panel);
        border: 1px solid var(--border);
        color: var(--text);
        cursor: pointer;
        text-transform: uppercase;
        letter-spacing: 0.03em;
        outline: none;
        appearance: none;
        /* Remove default browser arrow if desired */
        -webkit-appearance: none;
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='8' height='8' viewBox='0 0 24 24' fill='none' stroke='%239ca3af' stroke-width='3' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E");
        background-repeat: no-repeat;
        background-position: right 8px center;
        padding-right: 24px;
        /* Space for the custom arrow */
    }

    #selBlanking.zd-select:hover {
        filter: brightness(1.1);
        border-color: var(--accent);
    }
</style>
<script>
    // Anti-flicker: Apply saved sidebar width BEFORE the browser paints the UI
    (function() {
        const keyMode = "clipViewMode";
        const savedMode = sessionStorage.getItem(keyMode) === "grid" ? "grid" : "list";
        const savedWidth = sessionStorage.getItem(`clipcolWidth:${savedMode}`);

        if (savedWidth) {
            document.documentElement.style.setProperty('--clipcol-width', savedWidth + 'px');
        }
    })();
</script>

<?php $this->end(); ?>

<?php $this->start('content'); ?>

<div class="mobile-nav-helper" style="display:none; background:var(--bg); padding:10px; border-bottom:1px solid var(--border); position:sticky; top:0; z-index:100;">
    <div style="display:flex; justify-content: space-around;">
        <button onclick="document.getElementById('playerFrame').scrollIntoView({behavior:'smooth'})" style="all:unset; color:var(--accent); font-size:12px; font-weight:700; text-transform:uppercase;">Video</button>
        <button onclick="document.querySelector('aside').scrollIntoView({behavior:'smooth'})" style="all:unset; color:var(--accent); font-size:12px; font-weight:700; text-transform:uppercase;">Clips</button>
        <button onclick="document.querySelector('.player-meta').scrollIntoView({behavior:'smooth'})" style="all:unset; color:var(--accent); font-size:12px; font-weight:700; text-transform:uppercase;">Info</button>
    </div>
</div>

<style>
    @media (max-width: 900px) {
        .mobile-nav-helper {
            display: block !important;
        }
    }
</style>

<?php $clip_count = is_array($clip_list ?? null) ? count($clip_list) : 0; ?>

<?php
// Near the top of the file, ensure it's initialized
$restoredMode = $restoredMode ?? 'days';
$initialMode  = $initialMode  ?? 'days';
?>

<?php
$hasSelected = false;
$hasComments = false;

if (!empty($clip_list) && is_array($clip_list)) {
    foreach ($clip_list as $it) {
        if (!empty($it['is_select'])) {
            $hasSelected = true;
        }
        if (!empty($it['comment_count'])) {
            $hasComments = true;
        }
    }
}
?>

<?php
// Admin / superuser guard for poster-grab button
$account = $_SESSION['account'] ?? null;
$isSuperuser = (int)($account['is_superuser'] ?? 0);
$isAdmin     = (int)($account['is_admin'] ?? 0); // if not set, this stays 0

$canGrabPoster = ($isSuperuser === 1 || $isAdmin === 1);

// Current clip UUID for JS (fallbacks to list's current_clip if needed)
$currentClipUuid = $clip['clip_uuid'] ?? ($current_clip ?? '');
?>
<?php
$activeScene = $_GET['scene'] ?? null;
$isSceneMode = !empty($activeScene);
$displayLabel = $isSceneMode ? "Scene " . htmlspecialchars($activeScene) : $current_day_label;
?>

<div class="zd-bleed">

    <div class="player-layout"
        data-project-uuid="<?= htmlspecialchars($project_uuid, ENT_QUOTES, 'UTF-8') ?>"
        data-day-uuid="<?= htmlspecialchars($day_uuid, ENT_QUOTES, 'UTF-8') ?>"
        data-clip-uuid="<?= htmlspecialchars($currentClipUuid, ENT_QUOTES, 'UTF-8') ?>"
        data-can-grab-poster="<?= $canGrabPoster ? '1' : '0' ?>"
        data-initial-mode="<?= htmlspecialchars($initialMode, ENT_QUOTES, 'UTF-8') ?>">



        <aside style="background:var(--panel);border:1px solid var(--border);border-radius:12px;padding:12px;overflow-y:visible;overflow-x:hidden;position:relative;">


            <div class="zd-header-wrap">

                <div class="hdr-row-1" style="justify-content: space-between; width: 100%;">
                    <div style="display: flex; align-items: center; gap: 6px;">
                        <?php if ($isSceneMode): ?>
                            <button id="breadcrumbParentScenes" class="hdr-day-btn" style="opacity: 0.6; font-weight: 400;">Scenes</button>
                            <span class="hdr-slash">/</span>
                            <span id="zd-current-day-label" style="font-weight: 600; color: var(--text);">
                                <?= htmlspecialchars($displayLabel) ?>
                            </span>
                        <?php else: ?>
                            <button id="breadcrumbParentDays" class="hdr-day-btn" style="opacity: 0.6; font-weight: 400;">Days</button>
                            <span class="hdr-slash">/</span>
                            <span id="zd-current-day-label" style="font-weight: 600; color: var(--text);">
                                <?= htmlspecialchars($displayLabel) ?>
                            </span>
                        <?php endif; ?>

                        <span class="hdr-slash">/</span>
                        <span class="hdr-count"><?= (int)$clip_count ?> Clips</span>
                    </div>

                    <div class="view-mode-toggle" style="display: flex; background: var(--bg); border-radius: 6px; padding: 2px;">
                        <button id="switchToDays" class="mode-tab <?= $isSceneMode ? '' : 'active' ?>" style="all:unset; font-size:10px; padding:4px 8px; cursor:pointer; border-radius:4px;">DAYS</button>
                        <button id="switchToScenes" class="mode-tab <?= $isSceneMode ? 'active' : '' ?>" style="all:unset; font-size:10px; padding:4px 8px; cursor:pointer; border-radius:4px;">SCENES</button>
                    </div>
                </div>

                <!-- Row 2: Sort (left) + View/Back (right) -->
                <div class="hdr-row-2">
                    <div class="hdr-left">
                        <div id="sortByWrap" class="hdr-sortby-wrap">
                            <?php if (!empty($day_uuid)): ?>
                                <span class="hdr-sort-label">Sort by</span>

                                <select id="clipSortMode" class="zd-sort-select" title="Sort clips">
                                    <option value="scene">Scene</option>
                                    <option value="name">Clip name</option>

                                    <?php if ($hasSelected): ?>
                                        <option value="select">Selected clips</option>
                                    <?php endif; ?>

                                    <?php if ($hasComments): ?>
                                        <option value="comments">Comments</option>
                                    <?php endif; ?>
                                </select>
                            <?php endif; ?>
                        </div>

                        <button id="sortDirBtn"
                            class="icon-btn"
                            type="button"
                            title="Toggle sort direction"
                            aria-label="Toggle sort direction"
                            data-dir="asc">
                            <svg viewBox="0 0 24 24" class="icon zd-sort-icon" aria-hidden="true">
                                <path d="M12 4l-4 4h8z" />
                                <path d="M12 20l4-4H8z" />
                            </svg>
                        </button>
                    </div>


                    <div class="hdr-right">
                        <button id="viewToggleBtn" class="icon-btn" title="Switch view" aria-label="Switch view">
                            <img id="viewToggleIcon" src="/assets/icons/grid.svg" alt="" class="icon">
                        </button>

                        <?php if (!empty($day_uuid)): ?>
                            <a href="/admin/projects/<?= htmlspecialchars($project_uuid) ?>/days/<?= htmlspecialchars($day_uuid) ?>/clips" class="hdr-back">
                                ← back
                            </a>
                        <?php else: ?>
                            <a href="/admin/projects/<?= htmlspecialchars($project_uuid) ?>/overview" class="hdr-back">
                                ← back
                            </a>
                        <?php endif; ?>
                    </div>

                </div>

            </div>




            <?php
            // expects $clip_list and $current_clip
            ?>
            <div class="clipScrollOuter" style="margin-top:12px;max-height:72vh;position:relative;overflow:visible;">

                <div id="clipScrollInner" class="clipScrollInner" style="overflow-y:auto;overflow-x:hidden;max-height:72vh;padding-inline-end:10px;box-sizing:border-box;position:relative;">

                    <div id="clipListContainer" class="list-view" style="position:relative;overflow:visible;">


                        <?php if (empty($clip_list)) : ?>
                            <div class="zd-meta">
                                <?= $activeScene ? "No clips in this scene." : "No clips on this day." ?>
                            </div>
                        <?php else : ?>
                            <?php foreach ($clip_list as $it) :
                                $isActive = ($it['clip_uuid'] === ($current_clip ?? ''));
                                $href = "/admin/projects/" . htmlspecialchars($project_uuid)
                                    . "/days/" . htmlspecialchars($it['day_uuid'])
                                    . "/player/" . htmlspecialchars($it['clip_uuid'])
                                    . ($activeScene ? "?scene=" . urlencode($activeScene) : "");

                                // --- CALCULATE LABEL FIRST ---
                                $scene = trim((string)($it['scene'] ?? ''));
                                $slate = trim((string)($it['slate'] ?? ''));
                                $take  = trim((string)($it['take']  ?? ''));
                                $titleParts = [];
                                if ($scene !== '' && $slate !== '') {
                                    $titleParts[] = $scene . ' / ' . $slate;
                                } elseif ($scene !== '') {
                                    $titleParts[] = $scene;
                                } elseif ($slate !== '') {
                                    $titleParts[] = $slate;
                                }
                                if ($take !== '') {
                                    if (!empty($titleParts)) {
                                        $titleParts[0] .= ' - ' . $take;
                                    } else {
                                        $titleParts[] = $take;
                                    }
                                }
                                $clipLabel = $titleParts[0] ?? '';
                                // ----------------------------
                            ?>
                                <a class="clip-item <?= $isActive ? 'is-active' : '' ?>"
                                    href="<?= $href ?>"
                                    onclick="sessionStorage.setItem('clipListScroll:clips:<?= $restoredMode ?>:' + location.pathname, document.getElementById('clipScrollInner').scrollTop);"
                                    data-is-select="<?= (int)($it['is_select'] ?? 0) ?>"
                                    data-scene="<?= htmlspecialchars($scene, ENT_QUOTES, 'UTF-8') ?>"
                                    data-label="<?= htmlspecialchars($clipLabel, ENT_QUOTES, 'UTF-8') ?>"
                                    data-filename="<?= htmlspecialchars($it['file_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                    data-comment-count="<?= (int)($it['comment_count'] ?? 0) ?>">


                                    <?php
                                    $scene = trim((string)($it['scene'] ?? ''));
                                    $slate = trim((string)($it['slate'] ?? ''));
                                    $take  = trim((string)($it['take']  ?? ''));

                                    // Build: "scene / slate - take" with smart separators
                                    $titleParts = [];
                                    if ($scene !== '' && $slate !== '') {
                                        $titleParts[] = $scene . ' / ' . $slate;
                                    } elseif ($scene !== '') {
                                        $titleParts[] = $scene;
                                    } elseif ($slate !== '') {
                                        $titleParts[] = $slate;
                                    }
                                    if ($take !== '') {
                                        if (!empty($titleParts)) {
                                            $titleParts[0] .= ' - ' . $take;
                                        } else {
                                            $titleParts[] = $take;
                                        }
                                    }
                                    $clipLabel = $titleParts[0] ?? '';

                                    // Base filename (no extension)
                                    $fileNameFull = trim((string)($it['file_name'] ?? ''));
                                    $fileNameBase = $fileNameFull !== '' ? preg_replace('/\.[^.]+$/', '', $fileNameFull) : '';

                                    // Do we have any scene/slate/take info?
                                    $hasSceneInfo = ($scene !== '' || $slate !== '' || $take !== '');
                                    ?>

                                    <?php
                                    $commentCount = (int)($it['comment_count'] ?? 0);
                                    $isSelect = (int)($it['is_select'] ?? 0) === 1;
                                    ?>

                                    <?php if ($commentCount > 0 || $isSelect): ?>
                                        <div class="zd-flag-strip">
                                            <?php if ($commentCount > 0): ?>
                                                <div class="zd-flag-comment" aria-label="<?= $commentCount ?> comment<?= $commentCount === 1 ? '' : 's' ?>">
                                                    <svg viewBox="0 0 24 24" aria-hidden="true">
                                                        <path d="M5 4h14a3 3 0 0 1 3 3v7a3 3 0 0 1-3 3h-6.5L9 21l0-4H5a3 3 0 0 1-3-3V7a3 3 0 0 1 3-3z" fill="#ffffff" stroke="#000000" stroke-width="1.8" stroke-linejoin="round" />
                                                    </svg>
                                                </div>
                                            <?php endif; ?>
                                            <?php if ($isSelect): ?>
                                                <div class="zd-flag-star" aria-label="Good take">
                                                    <svg viewBox="0 0 24 24" role="img" focusable="false" width="16" height="16">
                                                        <path d="M12 17.3l-5.47 3.22 1.45-6.17-4.78-4.1 6.3-.54L12 3l2.5 6.7 6.3.54-4.78 4.1 1.45 6.17z" />
                                                    </svg>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>


                                    <?php
                                    $commentCount = (int)($it['comment_count'] ?? 0);
                                    ?>
                                    <div class="thumb-wrap">
                                        <?php if (!empty($it['poster_path'])) : ?>
                                            <?php
                                            $thumbUrl = (string)$it['poster_path'];
                                            $thumbUrl .= (strpos($thumbUrl, '?') !== false ? '&' : '?') . 'v=' . time();
                                            ?>
                                            <img src="<?= htmlspecialchars($thumbUrl, ENT_QUOTES, 'UTF-8') ?>"
                                                alt="Clip thumbnail"
                                                onerror="this.onerror=null; this.src='/assets/img/poster_placeholder.svg';">
                                        <?php else : ?>
                                            <div class="no-thumb"></div>
                                        <?php endif; ?>

                                        <?php if ((int)($it['is_restricted'] ?? 0) === 1): ?>
                                            <div class="zd-sensitive-chip">RESTRICTED</div>
                                        <?php endif; ?>
                                    </div>


                                    <div class="clip-text">
                                        <?php
                                        // Row title: prefer scene/slate/take label, otherwise file name (without extension)
                                        $rowTitleRaw  = $clipLabel !== '' ? $clipLabel : $fileNameBase;
                                        $rowTitle     = trim((string)$rowTitleRaw);
                                        $commentCount = (int)($it['comment_count'] ?? 0);
                                        ?>
                                        <div class="clip-title clip-title-right">
                                            <span><?= htmlspecialchars($rowTitle, ENT_QUOTES, 'UTF-8') ?></span>
                                        </div>

                                        <?php if ($hasSceneInfo && $fileNameBase !== ''): ?>
                                            <div class="zd-meta">
                                                <div class="zd-filename"><?= htmlspecialchars($fileNameBase, ENT_QUOTES, 'UTF-8') ?></div>
                                            </div>
                                        <?php endif; ?>

                                    </div>
                                </a>
                            <?php endforeach; ?>
                        <?php endif; ?>

                    </div>
                </div>
            </div>
            <div class="dayScrollOuter" style="display:none;margin-top:12px;max-height:72vh;position:relative;overflow:visible;">

                <div id="dayScrollInner" class="dayScrollInner" style="overflow-y:auto;overflow-x:hidden;max-height:72vh;padding-inline-end:10px;box-sizing:border-box;position:relative;">

                    <div id="dayListContainer" class="grid-view" style="position:relative;overflow:visible;">

                        <?php
                        $days = $days ?? [];
                        $placeholder_thumb_url = $placeholder_thumb_url ?? '/assets/img/empty_day_placeholder.png';
                        ?>


                        <?php if (empty($days)) : ?>
                            <div class="zd-meta">No days found.</div>
                        <?php else : ?>
                            <?php foreach ($days as $d) :
                                $thumb = $d['thumb_url'] ?? $placeholder_thumb_url;
                                $title = $d['title'] ?? ($d['shoot_date'] ?? 'Untitled Day');

                                // Cache-bust only real posters (not the generic placeholder)
                                if (!empty($d['thumb_url'])) {
                                    $thumb .= (strpos($thumb, '?') !== false ? '&' : '?') . 'v=' . time();
                                }

                            ?>
                                <button class="day-item" data-day-uuid="<?= htmlspecialchars($d['day_uuid']) ?>" data-day-label="<?= htmlspecialchars($title) ?>">

                                    <div class="day-thumb">
                                        <div class="day-thumb-inner">
                                            <img src="<?= htmlspecialchars($thumb, ENT_QUOTES, 'UTF-8') ?>"
                                                alt=""
                                                onerror="this.onerror=null; this.src='/assets/img/poster_placeholder.svg';">

                                            <div class="day-overlay-label">
                                                <?= htmlspecialchars($title) ?>
                                            </div>
                                            <!-- GRID-VIEW meta (shown on thumb in grid) -->
                                            <div class="day-overlay-meta">
                                                <?= (int)($d['clip_count'] ?? 0) ?> clips · Dur: <?= htmlspecialchars($d['total_hms'] ?? '00:00:00') ?>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="day-rowtext">
                                        <div class="day-title-line">
                                            <?= htmlspecialchars($title) ?>
                                        </div>
                                        <div class="day-rowmeta">
                                            <?= (int)($d['clip_count'] ?? 0) ?> clips · Dur: <?= htmlspecialchars($d['total_hms'] ?? '00:00:00') ?>
                                        </div>
                                    </div>

                                </button>

                            <?php endforeach; ?>
                        <?php endif; ?>

                    </div>
                </div>
            </div>

            <div class="sceneScrollOuter" style="display:none; margin-top:12px; max-height:72vh; position:relative; overflow:visible;">
                <div id="sceneScrollInner" class="dayScrollInner" style="overflow-y:auto; overflow-x:hidden; max-height:72vh; padding-inline-end:10px; box-sizing:border-box; position:relative;">
                    <div id="sceneListContainer" class="grid-view" style="position:relative; overflow:visible;">
                        <?php foreach ($scenes as $s): ?>
                            <button class="day-item scene-item" data-scene="<?= htmlspecialchars($s['scene']) ?>">
                                <div class="day-thumb">
                                    <div class="day-thumb-inner">
                                        <img src="<?= htmlspecialchars($s['thumb_url'] ?: $placeholder_thumb_url) ?>"
                                            onerror="this.onerror=null; this.src='/assets/img/poster_placeholder.svg';">
                                        <div class="day-overlay-label">Sc. <?= htmlspecialchars($s['scene']) ?></div>
                                        <div class="day-overlay-meta"><?= $s['clip_count'] ?> clips · Dur: <?= $s['total_hms'] ?></div>
                                    </div>
                                </div>

                                <div class="day-rowtext">
                                    <div class="day-title-line">
                                        Sc. <?= htmlspecialchars($s['scene']) ?>
                                    </div>
                                    <div class="day-rowmeta">
                                        <?= (int)$s['clip_count'] ?> clips · Dur: <?= htmlspecialchars($s['total_hms']) ?>
                                    </div>
                                </div>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

        </aside>
        <div id="sidebarResizer" class="sidebar-resizer"></div>
        <section class="player-main">
            <div class="zd-player-wrap">

                <div style="background:var(--panel);border:1px solid var(--border);border-radius:12px;padding:12px;margin-bottom:12px;">
                    <?php
                    $scene  = trim((string)($clip['scene'] ?? ''));
                    $slate  = trim((string)($clip['slate'] ?? ''));
                    $take   = trim((string)($clip['take']  ?? ''));
                    $camera = trim((string)($clip['camera'] ?? ''));

                    $sceneLine = '';
                    if ($scene !== '' && $slate !== '') {
                        $sceneLine = "Sc. {$scene} / {$slate}";
                    } elseif ($scene !== '') {
                        $sceneLine = "Sc. {$scene}";
                    } elseif ($slate !== '') {
                        $sceneLine = "Sc. {$slate}";
                    }
                    if ($take !== '') {
                        $sceneLine .= ($sceneLine ? ' - ' : 'Sc. ') . $take;
                    }
                    if ($camera !== '') {
                        $sceneLine .= ($sceneLine ? ' · ' : '') . "Cam {$camera}";
                    }

                    // Base filename for header (no extension)
                    $fileNameFull = trim((string)($clip['file_name'] ?? ''));
                    $fileNameBase = $fileNameFull !== '' ? preg_replace('/\.[^.]+$/', '', $fileNameFull) : '';
                    ?>

                    <div class="zd-scene-line" style="display: flex; justify-content: space-between; align-items: center;">
                        <div class="zd-scene-left" style="display: flex; align-items: center; gap: 10px; min-width: 0;">
                            <span><?= htmlspecialchars($sceneLine) ?></span>
                            <?php if ($fileNameBase !== ''): ?>
                                <span class="zd-clip-filename">
                                    <?= $sceneLine !== '' ? ' - ' : '' ?>
                                    <?= htmlspecialchars($fileNameBase, ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            <?php endif; ?>
                        </div>

                        <div class="zd-scene-right" style="display: flex; align-items: center; gap: 12px; flex-shrink: 0;">
                            <?php if ((int)($clip['is_restricted'] ?? 0) === 1): ?>
                                <div class="zd-sensitive-chip" style="position: static !important;">RESTRICTED</div>
                            <?php endif; ?>

                            <?php if (!empty($clip['is_select']) && (int)$clip['is_select'] === 1): ?>
                                <span class="zd-inline-star" title="Good take">
                                    <svg viewBox="0 0 24 24" width="18" height="18" style="fill: #ffd54a; display: block;">
                                        <path d="M12 17.3l-5.47 3.22 1.45-6.17-4.78-4.1 6.3-.54L12 3l2.5 6.7 6.3.54-4.78 4.1 1.45 6.17z" />
                                    </svg>
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>



                    <?php // Render player if we have a proxy OR if we are in archived state (no media but have clip)
                    ?>
                    <?php if ($proxy_url || ($clip && !$has_media)) : ?>
                        <div class="zd-player-frame" id="playerFrame">
                            <?php
                            // Prefer exact rational from DB, then decimal, then metadata, finally 25
                            $fpsNum = isset($clip['fps_num']) ? (int)$clip['fps_num'] : 0;
                            $fpsDen = isset($clip['fps_den']) ? (int)$clip['fps_den'] : 0;

                            if ($fpsNum > 0 && $fpsDen > 0) {
                                $fpsStr = rtrim(rtrim(number_format($fpsNum / $fpsDen, 3, '.', ''), '0'), '.'); // e.g. "23.976"
                            } else {
                                $fpsRaw = $clip['fps'] ?? ($metadata['video']['fps'] ?? $metadata['fps'] ?? null);
                                $fpsStr = $fpsRaw !== null ? str_replace(',', '.', (string)$fpsRaw) : '25';
                                // If DB had decimal but no num/den, try infer common fractions
                                if ($fpsStr === '23.976') {
                                    $fpsNum = 24000;
                                    $fpsDen = 1001;
                                } elseif ($fpsStr === '29.97') {
                                    $fpsNum = 30000;
                                    $fpsDen = 1001;
                                } elseif ($fpsStr === '59.94') {
                                    $fpsNum = 60000;
                                    $fpsDen = 1001;
                                }
                            }

                            // Debug (View Source only)
                            echo "\n<!-- DEBUG fps: {$fpsStr} (num={$fpsNum}, den={$fpsDen}) -->\n";

                            ?>

                            <?php
                            // Cache-bust poster so we never see a stale frame
                            $posterAttr = '';
                            if (!empty($poster_url)) {
                                $posterWithVer = $poster_url
                                    . ((strpos($poster_url, '?') !== false) ? '&' : '?')
                                    . 'v=' . time();
                                $posterAttr = 'poster="' . htmlspecialchars($posterWithVer, ENT_QUOTES, 'UTF-8') . '"';
                            }
                            ?>


                            <video
                                id="zdVideo"
                                crossorigin="use-credentials"
                                data-fps="<?= htmlspecialchars($fpsStr) ?>"
                                data-fpsnum="<?= $fpsNum ?: '' ?>"
                                data-fpsden="<?= $fpsDen ?: '' ?>"
                                data-tc-start="<?= htmlspecialchars($clip['tc_start'] ?? '00:00:00:00') ?>"
                                <?= $posterAttr ?>
                                controlsList="nodownload"
                                oncontextmenu="return false;">
                                <source src="/admin/projects/<?= $project_uuid ?>/clips/<?= $currentClipUuid ?>/stream.mp4" type="video/mp4">
                                Your browser does not support the video tag.
                            </video>


                            <?php if (!$has_media): ?>
                                <div style="
                                    position: absolute; 
                                    top: 20px; 
                                    right: 20px; 
                                    background: rgba(22, 86, 145, 0.65); 
                                    border: 1px solid #3aa0ff; 
                                    color: #eef1f5; 
                                    backdrop-filter: blur(6px);
                                    padding: 8px 16px; 
                                    border-radius: 6px; 
                                    font-size: 12px; 
                                    font-weight: 700; 
                                    text-transform: uppercase;
                                    letter-spacing: 0.05em;
                                    z-index: 1000; 
                                    box-shadow: 0 4px 20px rgba(0,0,0,0.4); 
                                    display: flex; 
                                    align-items: center; 
                                    gap: 8px;">
                                    <span style="text-shadow: 0 2px 4px rgba(0,0,0,0.5);">Media Archived</span>
                                </div>
                            <?php endif; ?>

                            <div id="zdFlash" style="position:absolute; inset:0; background:white; opacity:0; pointer-events:none; z-index:10; transition:none;"></div>

                            <?php if ($isSuperuser || $isAdmin): ?>
                                <div style="position: absolute; top: 10px; left: 10px; background: rgba(0,0,0,0.7); color: #3aa0ff; padding: 2px 6px; border-radius: 4px; font-size: 10px; z-index: 1000; font-family: monospace; border: 1px solid var(--border);">
                                    SRC: <?= (!empty($proxy_url) && (strpos($proxy_url, 'proxies') !== false || strpos($proxy_url, 'proxy_web') !== false)) ? 'PROXY' : 'ORIGINAL' ?>
                                </div>
                            <?php endif; ?>

                            <div class="zd-controls" role="group" aria-label="Player controls">
                                <div class="zd-controls-inner">
                                    <div class="zd-controls-top">
                                        <input type="range"
                                            id="scrubGlobal"
                                            class="zd-scrub-global"
                                            min="0" max="1000" step="1" value="0"
                                            aria-label="Timeline" title="Timeline">
                                    </div>
                                    <div class="zd-controls-bottom">
                                        <!-- Left group: transport + TC -->
                                        <button class="zd-btn" id="btnPlayPause" title="Play/Pause" aria-label="Play/Pause">
                                            <span data-state="play">▶</span><span data-state="pause" style="display:none;">⏸</span>
                                        </button>
                                        <button class="zd-btn" id="btnStepBack" title="Step 1 frame back" aria-label="Step back 1 frame">◀ 1f</button>
                                        <button class="zd-btn" id="btnStepFwd" title="Step 1 frame forward" aria-label="Step forward 1 frame">1f ▶</button>
                                        <button id="tcChip" class="zd-tc-chip" type="button" title="Click to enter TC">00:00:00:00</button>

                                        <!-- Right group: aligned to right -->
                                        <div class="zd-controls-right">
                                            <?php if (!empty($canGrabPoster)): ?>
                                                <button
                                                    class="zd-btn zd-btn-icon"
                                                    id="btnGrabPoster"
                                                    title="Set poster from current frame"
                                                    aria-label="Set poster from current frame">
                                                    <img
                                                        src="/assets/icons/poster.svg"
                                                        class="zd-icon-poster"
                                                        alt="">
                                                </button>
                                            <?php endif; ?>

                                            <div class="zd-blanking-wrap" style="position: relative; display: flex; align-items: center;">
                                                <select id="selBlanking" class="zd-select font-mono" data-default="<?= htmlspecialchars($project['default_aspect_ratio'] ?? 'none') ?>">
                                                    <option value="none">Off (1.78)</option>
                                                    <option value="2.39">2.39 (Scope)</option>
                                                    <option value="2.00">2.00 (Univisium)</option>
                                                    <option value="1.85">1.85 (Flat)</option>
                                                    <option value="1.66">1.66 (Pillar)</option>
                                                    <option value="1.33">1.33 (4:3)</option>
                                                </select>
                                            </div>

                                            <div class="zd-vol">
                                                <button class="zd-btn" id="btnMute" title="Mute" aria-label="Mute">🔊</button>
                                                <div class="zd-vol-popup">
                                                    <input
                                                        type="range"
                                                        id="vol"
                                                        min="0"
                                                        max="1"
                                                        step="0.01"
                                                        value="1"
                                                        aria-label="Volume">
                                                </div>
                                            </div>

                                            <button class="zd-btn" id="btnTheater" title="Theater mode" aria-label="Theater mode">
                                                <span class="theater-icon" data-state="enter"></span>
                                                <span class="theater-icon theater-exit" data-state="exit" style="display:none;"></span>
                                            </button>

                                            <button class="zd-btn" id="btnFS" title="Fullscreen" aria-label="Fullscreen">⛶</button>
                                        </div>
                                    </div> <!-- end .zd-controls-bottom -->
                                </div> <!-- end .zd-controls-inner -->

                            </div> <!-- end .zd-controls -->
                            <canvas id="lutCanvas" class="lut-canvas"></canvas>
                        </div>
                    <?php else : ?>
                        <?php if ($clip === null): ?>
                            <div style="padding:24px;color:var(--muted);">
                                Select a day to view clips.
                            </div>
                        <?php else: ?>
                            <div style="padding:24px;color:#d62828;">
                                No proxy available for this clip yet.
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>



                </div> <!-- end of the player card -->
            </div> <!-- /.zd-player-wrap -->

            <div id="innerResizer" class="sidebar-resizer" aria-hidden="true"></div>


            <?php
            // --- Prepare Metadata ---


            // Helpers for metadata lookup + timecode formatting
            $metaLookup = function (array $rows, array $keys) {
                foreach ($keys as $k) {
                    foreach ($rows as $r) {
                        if (isset($r['meta_key']) && strcasecmp($r['meta_key'], $k) === 0) {
                            $v = trim((string)$r['meta_value']);
                            if ($v !== '') return $v;
                        }
                    }
                }
                return null;
            };

            // Resolve FPS from metadata (accepts 23.976, "23,976", "24.000", etc.)
            $fpsRaw = $metaLookup($metadata, ['fps', 'FPS', 'Camera FPS', 'Frame Rate', 'FrameRate', 'CameraFPS']);
            $fpsVal = null;
            if ($fpsRaw !== null) {
                $norm = str_replace(',', '.', $fpsRaw);
                if (preg_match('/^\d+(\.\d+)?$/', $norm)) $fpsVal = (float)$norm;
            }

            // Duration → HH:MM:SS:FF using clip FPS (fallback 25 if unknown)
            $durMs  = isset($clip['duration_ms']) ? (int)$clip['duration_ms'] : 0;
            $fpsForDur = ($fpsVal && $fpsVal > 0) ? $fpsVal : 25.0;
            $fpsInt    = max(1, (int)round($fpsForDur));
            $totalFrames  = (int)round(($durMs / 1000.0) * $fpsForDur);
            $FF = $totalFrames % $fpsInt;
            $totalSeconds = intdiv($totalFrames, $fpsInt);
            $SS = $totalSeconds % 60;
            $totalMinutes = intdiv($totalSeconds, 60);
            $MM = $totalMinutes % 60;
            $HH = intdiv($totalMinutes, 60);
            $duration_tc = sprintf('%02d:%02d:%02d:%02d', $HH, $MM, $SS, $FF);

            // Resolution and Codec from metadata (best-effort)
            $width  = $metaLookup($metadata, ['Width', 'Video Width', 'Frame Width']);
            $height = $metaLookup($metadata, ['Height', 'Video Height', 'Frame Height']);
            $frameSize = $metaLookup($metadata, ['Frame Size', 'Video Size', 'Resolution']); // e.g. "1920x1080"
            if (!$frameSize) {
                if ($width && $height) $frameSize = $width . 'x' . $height;
            }

            $codec = $metaLookup($metadata, ['Codec Name', 'Video Codec', 'Codec', 'VideoCodec']);


            // We sort the data from $clip and $metadata into two buckets for the UI.
            $basicMeta = [
                'Clip Name' => $clip['file_name'] ?? null,
                'TC In'     => $clip['tc_start'] ?? null,
                'TC Out'    => $clip['tc_end'] ?? null,
                'FPS'       => null, // We'll try to find this in the $metadata array
            ];
            $extendedMeta = [
                'Reel' => $clip['reel'] ?? null, // Start with reel
            ];

            $basicKeysToFind = ['fps' => 'FPS', 'FPS' => 'FPS', 'Frame Rate' => 'FPS'];
            $remainingMetadata = [];

            foreach ($metadata as $m) {
                $key = $m['meta_key'];
                $val = (string)$m['meta_value'];
                if (isset($basicKeysToFind[$key])) {
                    $basicMeta[$basicKeysToFind[$key]] = $val; // Add to basic
                } else {
                    // Add all others to the extended list
                    $remainingMetadata[$key] = $val;
                }
            }
            ?>
            <div class="player-meta">
                <div style="background:var(--panel);border:1px solid var(--border);border-radius:12px;padding:12px;margin-bottom:12px;">

                    <details class="zd-metadata-group" data-meta-section="basic">
                        <summary class="zd-metadata-summary">Basic Metadata</summary>
                        <div class="zd-metadata-content">
                            <?php
                            // Build the 8 basic fields in desired order
                            $basic = [
                                'TC In'    => $clip['tc_start'] ?? null,
                                'Camera'     => $clip['camera'] ?? null,
                                'TC Out'   => $clip['tc_end']   ?? null,
                                'Reel'       => $clip['reel']   ?? null,
                                'Duration' => $duration_tc,
                                'Resolution' => $frameSize,
                                'FPS'      => ($fpsVal !== null ? rtrim(rtrim(number_format($fpsVal, 3, '.', ''), '0'), '.') : null),

                                'Codec'      => $codec,


                            ];

                            // Filter out empties to avoid blank cells
                            $pairs = [];
                            foreach ($basic as $k => $v) {
                                if ($v !== null && $v !== '') $pairs[] = [$k, $v];
                            }

                            // Ensure exactly 8 slots: fill with dashes if missing
                            while (count($pairs) < 8) $pairs[] = ['—', '—'];
                            ?>
                            <div class="zd-meta-grid">
                                <?php foreach ($pairs as $i => $row): ?>
                                    <div class="zd-meta-cell">
                                        <div class="zd-meta-k"><?= htmlspecialchars($row[0]) ?></div>
                                        <div class="zd-meta-v"><?= htmlspecialchars($row[1]) ?></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </details>

                    <details class="zd-metadata-group" data-meta-section="audio" style="margin-top: 8px;">
                        <summary class="zd-metadata-summary">Audio Waveform</summary>
                        <div class="zd-metadata-content">
                            <div id="zd-waveform-container"
                                style="width:100%; height:80px; background:rgba(0,0,0,0.3); border-radius:8px; position:relative; overflow:hidden;"
                                data-waveform-url="<?= htmlspecialchars($waveform_url ?? '') ?>">
                                <div id="zd-waveform-progress" style="position:absolute; inset:0; border-right:2px solid var(--accent); width:0%; pointer-events:none; z-index:2;"></div>
                                <canvas id="zdWaveformCanvas" style="width:100%; height:100%; display:block;"></canvas>
                            </div>
                        </div>
                    </details>

                    <details class="zd-metadata-group" data-meta-section="extended" style="margin-top: 8px;">

                        <summary class="zd-metadata-summary">Extended Metadata</summary>
                        <div class="zd-metadata-content">
                            <table class="zd-meta-table">
                                <?php $hasExtended = false; ?>
                                <?php // First, add the hard-coded 'Reel' from $clip 
                                ?>
                                <?php if (!empty($extendedMeta['Reel'])) : $hasExtended = true; ?>
                                    <tr>
                                        <td>Reel</td>
                                        <td><?= htmlspecialchars($extendedMeta['Reel']) ?></td>
                                    </tr>
                                <?php endif; ?>

                                <?php // Now, loop over the rest from the $metadata array 
                                ?>
                                <?php if (!empty($remainingMetadata)) : ?>
                                    <?php foreach ($remainingMetadata as $key => $val) : $hasExtended = true; ?>
                                        <tr>
                                            <td><?= htmlspecialchars($key) ?></td>
                                            <td><?= htmlspecialchars($val) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>

                                <?php if (!$hasExtended) : ?>
                                    <tr>
                                        <td colspan="2" style="color:var(--muted);">
                                            No extended metadata.
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </table>
                        </div>
                    </details>
                </div>


                <div style="background:var(--panel);border:1px solid var(--border);border-radius:12px;padding:12px;">
                    <?php
                    $commentCount = is_array($comments) ? count($comments) : 0;
                    $commentLabel = ($commentCount > 0) ? " ({$commentCount})" : "";
                    ?>

                    <details class="zd-metadata-group" data-meta-section="comments">
                        <summary class="zd-metadata-summary">
                            Comments<?= htmlspecialchars($commentLabel) ?>
                        </summary>

                        <div class="zd-metadata-content" style="padding-left: 0; margin-top: 12px;">
                            <form method="post" class="zd-comment-form" style="margin-bottom:12px;display:flex;flex-direction:column;gap:8px;">
                                <input type="hidden" name="_csrf" value="<?= htmlspecialchars(\App\Support\Csrf::token(), ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="parent_comment_uuid" id="comment_parent_uuid" value="">

                                <div style="display:flex;gap:8px;align-items:center;">
                                    <label for="comment_start_tc" style="font-size:11px;color:var(--muted);min-width:88px;">Timecode (opt.)</label>
                                    <input
                                        type="text"
                                        name="start_tc"
                                        id="comment_start_tc"
                                        placeholder="HH:MM:SS:FF"
                                        maxlength="11"
                                        pattern="^\d{2}:\d{2}:\d{2}:\d{2}$"
                                        autocomplete="off"
                                        style="flex:0 0 130px;font-size:12px;padding:4px 6px;border-radius:4px;border:1px solid var(--border);background:var(--bg);color:var(--text);" />
                                    <button type="button" id="btnCommentUseTc" style="font-size:11px;padding:4px 8px;border-radius:4px;border:1px solid var(--border);background:var(--subtle);color:var(--text);cursor:pointer;">
                                        Use current
                                    </button>
                                </div>

                                <div>
                                    <textarea
                                        name="comment_body"
                                        id="comment_body"
                                        rows="2"
                                        placeholder="Add a note for this clip…"
                                        required
                                        style="width:100%;resize:vertical;font-size:13px;padding:6px 8px;border-radius:4px;border:1px solid var(--border);background:var(--bg);color:var(--text);"></textarea>
                                </div>

                                <div style="display:flex;justify-content:flex-end;">
                                    <button type="submit" style="font-size:12px;font-weight:500;padding:6px 12px;border-radius:4px;border:1px solid var(--accent);background:var(--accent);color:#000;cursor:pointer;">
                                        Add comment
                                    </button>
                                </div>
                            </form>

                            <?php if (!empty($comments)) : ?>
                                <div style="display:flex;flex-direction:column;gap:6px;">
                                    <?php foreach ($comments as $c) : ?>
                                        <?php
                                        $isReply = !empty($c['is_reply']);
                                        $depth   = (int)($c['depth'] ?? 0);
                                        $indent  = $isReply ? min(24 + $depth * 12, 80) : 0;
                                        ?>
                                        <div
                                            class="zd-comment-item"
                                            data-comment-uuid="<?= htmlspecialchars($c['comment_uuid'] ?? ($c['id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                            style="border:1px solid var(--border);border-radius:8px;padding:8px;margin-left:<?= $indent ?>px;background:<?= $isReply ? 'rgba(255,255,255,0.02)' : 'transparent' ?>;">
                                            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;font-size:11px;color:var(--muted);">
                                                <div>
                                                    <?php if (!empty($c['start_tc'])) : ?>
                                                        <button
                                                            type="button"
                                                            class="zd-comment-tc"
                                                            data-tc="<?= htmlspecialchars($c['start_tc'], ENT_QUOTES, 'UTF-8') ?>"
                                                            style="font-family:monospace;font-size:11px;padding:2px 6px;border-radius:4px;border:1px solid var(--border);background:var(--bg);color:var(--accent);cursor:pointer;margin-right:6px;">
                                                            <?= htmlspecialchars($c['start_tc'], ENT_QUOTES, 'UTF-8') ?>
                                                        </button>
                                                    <?php endif; ?>

                                                    <span style="font-weight:500;color:var(--text);">
                                                        <?= htmlspecialchars($c['author_name'] ?? 'Unknown', ENT_QUOTES, 'UTF-8') ?>
                                                    </span>

                                                    <?php if (!empty($c['created_at'])) : ?>
                                                        <span style="margin-left:6px;">
                                                            <?= htmlspecialchars(date('Y-m-d H:i', strtotime($c['created_at'])), ENT_QUOTES, 'UTF-8') ?>
                                                        </span>
                                                    <?php endif; ?>

                                                    <?php if ($isReply && !empty($c['parent_uuid'])) : ?>
                                                        <span style="margin-left:6px;font-style:italic;color:var(--muted);">
                                                            ↳ Reply
                                                        </span>
                                                    <?php endif; ?>
                                                </div>

                                                <div>
                                                    <button
                                                        type="button"
                                                        class="zd-comment-reply-btn"
                                                        data-comment-uuid="<?= htmlspecialchars($c['comment_uuid'] ?? ($c['id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                                        data-author-name="<?= htmlspecialchars($c['author_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                                        style="font-size:11px;border:none;background:none;color:var(--accent);cursor:pointer;padding:2px 4px;">
                                                        Reply
                                                    </button>
                                                </div>
                                            </div>

                                            <div style="font-size:13px;color:var(--text);white-space:pre-wrap;">
                                                <?= nl2br(htmlspecialchars($c['body'] ?? '', ENT_QUOTES, 'UTF-8')) ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else : ?>
                                <div style="font-size:12px;color:var(--muted);">No comments yet.</div>
                            <?php endif; ?>
                        </div>
                    </details>
                </div>

            </div>

        </section>
    </div>
</div> <?php $this->end(); ?>

<?php $this->start('scripts'); ?>
<script>
    // Security: Prevent right-click on the video element
    document.getElementById('zdVideo').addEventListener('contextmenu', function(e) {
        e.preventDefault();
    });
</script>
<script src="/assets/js/player.js"></script>
<script type="module" src="/assets/js/player-lut-init.js"></script>
<script type="module" src="/assets/js/player-ui.js"></script>
<script type="module" src="/assets/js/player-batch-thumbnails.js?v=<?= time() ?>"></script>
<script>
    (function() {
        const sortByWrap = document.getElementById('sortByWrap'); // label + select
        const dirBtn = document.getElementById('sortDirBtn'); // always available
        const clipOuter = document.querySelector('.clipScrollOuter');
        const dayOuter = document.querySelector('.dayScrollOuter');
        const sceneOuter = document.querySelector('.sceneScrollOuter');

        if (!sortByWrap || !dirBtn || !clipOuter) return;

        const isVisible = (el) => !!el && window.getComputedStyle(el).display !== 'none';

        const update = () => {
            // Show Sort-by only when viewing an actual clip list (inside a day or selected scene).
            // Hide it when Days overview or Scenes overview is active.
            const showSortBy = isVisible(clipOuter) && !isVisible(dayOuter) && !isVisible(sceneOuter);
            sortByWrap.classList.toggle('is-hidden', !showSortBy);
        };

        const reverseChildren = (container) => {
            if (!container) return;
            const kids = Array.from(container.children).filter(n => n.nodeType === 1);
            for (let i = kids.length - 1; i >= 0; i--) container.appendChild(kids[i]);
        };

        // If we're in Days/Scenes overview, use the same dir button to reverse those lists.
        dirBtn.addEventListener('click', (e) => {
            if (!sortByWrap.classList.contains('is-hidden')) return; // normal clip sorting handled elsewhere

            e.preventDefault();
            e.stopPropagation();

            const cur = (dirBtn.getAttribute('data-dir') || 'asc').toLowerCase();
            const next = cur === 'asc' ? 'desc' : 'asc';
            dirBtn.setAttribute('data-dir', next);

            if (isVisible(dayOuter)) {
                reverseChildren(document.getElementById('dayListContainer'));
            } else if (isVisible(sceneOuter)) {
                reverseChildren(document.getElementById('sceneListContainer'));
            }
        }, true);

        update();

        // Watch the three view containers for display/class changes so the sort-by UI reacts instantly.
        const obs = new MutationObserver(update);
        [clipOuter, dayOuter, sceneOuter].forEach((el) => {
            if (!el) return;
            obs.observe(el, {
                attributes: true,
                attributeFilter: ['style', 'class']
            });
        });

        // Safety: after clicking the mode tabs, the UI toggles quickly; re-check after the event loop.
        document.addEventListener('click', (e) => {
            const t = e.target;
            if (t && (t.id === 'switchToDays' || t.id === 'switchToScenes' || t.closest('.mode-tab'))) {
                setTimeout(update, 0);
            }
        });
    })();

    /**
     * AJAX CLIP NAVIGATION FOR DESKTOP PLAYER
     * 
     * This script intercepts clip navigation and loads content via Ajax
     * instead of full page reloads, providing a smoother user experience.
     */

    document.addEventListener("DOMContentLoaded", function() {
        const vid = document.getElementById("zdVideo");
        if (!vid) return;

        // Track if we're currently loading
        let isLoading = false;
        let currentRequest = null;

        /**
         * Load a clip via Ajax
         */
        async function loadClipAjax(url, clipElement) {
            if (isLoading) {
                console.log("Already loading, ignoring click");
                return;
            }
            isLoading = true;

            // Show loading state
            const loadingOverlay = createLoadingOverlay();
            document.body.appendChild(loadingOverlay);

            // Pause video immediately (but don't clear src yet)
            vid.pause();

            try {
                // Abort any pending request
                if (currentRequest) {
                    currentRequest.abort();
                }

                // Create new AbortController for this request
                const controller = new AbortController();
                currentRequest = controller;

                // Fetch clip data via Ajax
                const response = await fetch(url, {
                    headers: {
                        "X-Requested-With": "XMLHttpRequest",
                    },
                    signal: controller.signal
                });

                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }

                const data = await response.json();

                if (!data.success) {
                    throw new Error("Server returned unsuccessful response");
                }

                // NOW clear the old video (after we have the new data)
                vid.src = "";
                vid.load();

                // Update the page with new data
                updateVideoPlayer(data);
                updateMetadata(data);
                updateComments(data);
                updateActiveClip(clipElement);
                updateBrowserHistory(url, data);

                // Re-initialize player UI for the new clip
                reinitializePlayerUI(data);

                // Scroll the active clip into view
                setTimeout(() => {
                    if (clipElement) {
                        clipElement.scrollIntoView({
                            behavior: "smooth",
                            block: "nearest", // Only scrolls the sidebar, keeps the main page still
                            inline: "nearest"
                        });
                    }
                }, 100);
            } catch (error) {
                // Ignore abort errors (they're intentional)
                if (error.name === 'AbortError') {
                    console.log('Request aborted');
                    return;
                }

                console.error("Ajax navigation failed:", error);
                // Fall back to regular navigation
                window.location.href = url;
                return;
            } finally {
                isLoading = false;
                currentRequest = null;
                loadingOverlay.remove();

                // Autoplay the video once loaded
                vid.play().catch(e => console.log("Auto-play prevented by browser:", e));
            }
        }

        /**
         * Create a loading overlay
         */
        function createLoadingOverlay() {
            const overlay = document.createElement("div");
            overlay.className = "zd-ajax-loader";

            // Transparent full-screen container (no darkening, no blur)
            overlay.style.cssText = `
                position: fixed;
                inset: 0;
                display: flex;
                align-items: center;
                justify-content: center;
                z-index: 10000;
                background: transparent;
                pointer-events: none; /* don't block the UI */
            `;

            // Small centered card
            const card = document.createElement("div");
            card.style.cssText = `
                display: inline-flex;
                align-items: center;
                gap: 12px;
                padding: 14px 16px;
                border-radius: 12px;
                background: rgba(17, 19, 24, 0.96);
                border: 1px solid rgba(255, 255, 255, 0.10);
                box-shadow: 0 12px 40px rgba(0,0,0,0.35);
                color: rgba(255,255,255,0.90);
                font: 500 14px/1.2 system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif;
            `;

            const spinner = document.createElement("div");
            spinner.style.cssText = `
                width: 18px;
                height: 18px;
                border: 3px solid rgba(255, 255, 255, 0.25);
                border-top-color: var(--accent, #3aa0ff);
                border-radius: 50%;
                animation: zdSpin 0.8s linear infinite;
            `;

            const label = document.createElement("div");
            label.textContent = "Loading…";

            // Keyframes (only once)
            if (!document.getElementById("zd-ajax-loader-keyframes")) {
                const style = document.createElement("style");
                style.id = "zd-ajax-loader-keyframes";
                style.textContent = `
                    @keyframes zdSpin { to { transform: rotate(360deg); } }
                    `;
                document.head.appendChild(style);
            }

            card.appendChild(spinner);
            card.appendChild(label);
            overlay.appendChild(card);
            return overlay;
        }


        function updateVideoPlayer(data) {
            const {
                clip,
                urls,
                header
            } = data;

            // 1. Core Data & Video Update
            vid.dataset.fps = clip.fps || "25";
            vid.dataset.fpsnum = clip.fps_num || "";
            vid.dataset.fpsden = clip.fps_den || "";
            vid.dataset.tcStart = clip.tc_start || "00:00:00:00";

            const sceneSpan = document.querySelector('.zd-scene-left span:first-child');
            const filenameSpan = document.querySelector('.zd-clip-filename');

            if (sceneSpan && header) sceneSpan.textContent = header.scene_line;
            if (filenameSpan && header) {
                filenameSpan.textContent = (header.scene_line !== '' ? ' - ' : '') + header.file_name_base;
            }

            // 2. Indicators (Badge & Star) - The Fix
            const sceneRight = document.querySelector('.zd-scene-right');
            if (sceneRight) {
                // Clear current indicators to avoid ghosting or disappearing
                sceneRight.innerHTML = '';

                // Add Restricted Chip if applicable
                if (clip.is_restricted == 1) { // CHANGE: Remove is_sensitive check, use only is_restricted
                    const badge = document.createElement('div');
                    badge.className = 'zd-sensitive-chip';
                    badge.style.cssText = "position: static !important;";
                    badge.textContent = 'RESTRICTED';
                    sceneRight.appendChild(badge);
                }

                // Add Selected Star if applicable
                if (clip.is_select == 1) {
                    const star = document.createElement('span');
                    star.className = 'zd-inline-star';
                    star.title = 'Good take';
                    star.innerHTML = `
                <svg viewBox="0 0 24 24" width="18" height="18" style="fill: #ffd54a; display: block;">
                    <path d="M12 17.3l-5.47 3.22 1.45-6.17-4.78-4.1 6.3-.54L12 3l2.5 6.7 6.3.54-4.78 4.1 1.45 6.17z" />
                </svg>`;
                    sceneRight.appendChild(star);
                }
            }

            // 3. Finalize Media Load
            if (urls.poster) vid.poster = urls.poster;
            else vid.removeAttribute("poster");

            vid.src = urls.video;
            vid.load();

            const layout = document.querySelector(".player-layout");
            if (layout) {
                layout.dataset.projectUuid = data.project.uuid;
                layout.dataset.dayUuid = data.day.uuid;
                layout.dataset.clipUuid = clip.clip_uuid;
            }
        }

        /**
         * Update metadata sections
         */
        function updateMetadata(data) {
            // Update basic metadata
            const basicContent = document.querySelector(
                '[data-meta-section="basic"] .zd-metadata-content'
            );
            if (basicContent) {
                const grid = basicContent.querySelector(".zd-meta-grid");
                if (grid && data.metadata.basic) {
                    grid.innerHTML = data.metadata.basic
                        .map(
                            (pair) => `
                <div class="zd-meta-cell">
                    <div class="zd-meta-k">${escapeHtml(pair.key)}</div>
                    <div class="zd-meta-v">${escapeHtml(pair.value)}</div>
                </div>
                `
                        )
                        .join("");
                }
            }

            // Update waveform
            const waveformContainer = document.getElementById("zd-waveform-container");
            if (waveformContainer) {
                waveformContainer.dataset.waveformUrl = data.urls.waveform || "";
                // Trigger waveform reload if you have a function for that
                if (window.loadWaveform) {
                    window.loadWaveform(data.urls.waveform);
                }
            }

            // Update extended metadata
            const extendedContent = document.querySelector(
                '[data-meta-section="extended"] .zd-metadata-content'
            );
            if (extendedContent) {
                const table = extendedContent.querySelector(".zd-meta-table");
                if (table && data.metadata.extended) {
                    if (data.metadata.extended.length > 0) {
                        table.innerHTML = data.metadata.extended
                            .map(
                                (pair) => `
            <tr>
              <td>${escapeHtml(pair.key)}</td>
              <td>${escapeHtml(pair.value)}</td>
            </tr>
          `
                            )
                            .join("");
                    } else {
                        table.innerHTML = `
            <tr>
              <td colspan="2" style="color:var(--muted);">
                No extended metadata.
              </td>
            </tr>
          `;
                    }
                }
            }
        }

        /**
         * Update comments section
         */
        function updateComments(data) {
            const commentsSection = document.querySelector('[data-meta-section="comments"]');
            if (!commentsSection) return;

            // Update the summary count
            const summary = commentsSection.querySelector(".zd-metadata-summary");
            if (summary) {
                const count = data.comments ? data.comments.length : 0;
                const countLabel = count > 0 ? ` (${count})` : "";
                summary.innerHTML = `Comments${escapeHtml(countLabel)}`;
            }

            // Find the comments container (after the form)
            const form = commentsSection.querySelector(".zd-comment-form");
            const commentsContainer = form ? form.nextElementSibling : null;

            if (!commentsContainer) return;

            // Clear and rebuild comments
            if (!data.comments || data.comments.length === 0) {
                commentsContainer.innerHTML = `
        <div style="font-size:12px;color:var(--muted);">No comments yet.</div>
      `;
                return;
            }

            commentsContainer.innerHTML = `
      <div style="display:flex;flex-direction:column;gap:6px;">
        ${data.comments.map((c) => renderComment(c)).join("")}
      </div>
    `;

            // Re-attach comment event listeners
            attachCommentListeners();
        }

        /**
         * Render a single comment
         */
        function renderComment(c) {
            const isReply = !!c.is_reply;
            const depth = parseInt(c.depth || 0, 10);
            const indent = isReply ? Math.min(24 + depth * 12, 80) : 0;

            const tcButton = c.start_tc ?
                `
      <button
        type="button"
        class="zd-comment-tc"
        data-tc="${escapeHtml(c.start_tc)}"
        style="font-family:monospace;font-size:11px;padding:2px 6px;border-radius:4px;border:1px solid var(--border);background:var(--bg);color:var(--accent);cursor:pointer;margin-right:6px;">
        ${escapeHtml(c.start_tc)}
      </button>
    ` :
                "";

            const createdAt = c.created_at ?
                `<span style="margin-left:6px;">${escapeHtml(formatDate(c.created_at))}</span>` :
                "";

            const replyLabel =
                isReply && c.parent_uuid ?
                `<span style="margin-left:6px;font-style:italic;color:var(--muted);">↳ Reply</span>` :
                "";

            return `
      <div
        class="zd-comment-item"
        data-comment-uuid="${escapeHtml(c.comment_uuid || c.id || '')}"
        style="border:1px solid var(--border);border-radius:8px;padding:8px;margin-left:${indent}px;background:${isReply ? 'rgba(255,255,255,0.02)' : 'transparent'};">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;font-size:11px;color:var(--muted);">
          <div>
            ${tcButton}
            <span style="font-weight:500;color:var(--text);">
              ${escapeHtml(c.author_name || "Unknown")}
            </span>
            ${createdAt}
            ${replyLabel}
          </div>
          <div>
            <button
              type="button"
              class="zd-comment-reply-btn"
              data-comment-uuid="${escapeHtml(c.comment_uuid || c.id || '')}"
              data-author-name="${escapeHtml(c.author_name || '')}"
              style="font-size:11px;border:none;background:none;color:var(--accent);cursor:pointer;padding:2px 4px;">
              Reply
            </button>
          </div>
        </div>
        <div style="font-size:13px;color:var(--text);white-space:pre-wrap;">
          ${escapeHtml(c.body || "").replace(/\n/g, "<br>")}
        </div>
      </div>
    `;
        }

        /**
         * Re-attach event listeners for comment interactions
         */
        function attachCommentListeners() {
            // TC jump buttons
            document.querySelectorAll(".zd-comment-tc").forEach((btn) => {
                btn.addEventListener("click", () => {
                    const tc = btn.dataset.tc;
                    if (tc && window.commentTcToSeconds) {
                        const seconds = window.commentTcToSeconds(tc);
                        vid.currentTime = seconds;
                        vid.pause();
                    }
                });
            });

            // Reply buttons
            document.querySelectorAll(".zd-comment-reply-btn").forEach((btn) => {
                btn.addEventListener("click", () => {
                    const uuid = btn.dataset.commentUuid || "";
                    const author = btn.dataset.authorName || "";
                    if (window.enterReplyMode) {
                        window.enterReplyMode(uuid, author);
                    }
                });
            });
        }

        /**
         * Update which clip is marked as active
         */
        function updateActiveClip(newActiveElement) {
            // Remove active class from all clips
            document.querySelectorAll(".clip-item.is-active").forEach((el) => {
                el.classList.remove("is-active");
            });

            // Add active class to new clip
            if (newActiveElement) {
                newActiveElement.classList.add("is-active");
            }
        }

        /**
         * Update browser history for back button support
         */
        function updateBrowserHistory(url, data) {
            const state = {
                clipUuid: data.clip.clip_uuid,
                dayUuid: data.day.uuid,
                projectUuid: data.project.uuid,
            };

            window.history.pushState(state, "", url);
        }

        /**
         * Re-initialize player UI components that need fresh setup
         */
        function reinitializePlayerUI(data) {
            // Reset timecode chip
            const tcChip = document.getElementById("tcChip");
            if (tcChip) {
                tcChip.textContent = data.clip.tc_start || "00:00:00:00";
            }

            // Reset scrubber
            const scrubber = document.getElementById("scrubGlobal");
            if (scrubber) {
                scrubber.value = "0";
            }

            // Clear comment form
            const commentBody = document.getElementById("comment_body");
            const commentTc = document.getElementById("comment_start_tc");
            const commentParent = document.getElementById("comment_parent_uuid");

            if (commentBody) commentBody.value = "";
            if (commentTc) commentTc.value = "";
            if (commentParent) commentParent.value = "";

            // Exit reply mode if active
            if (window.exitReplyMode) {
                window.exitReplyMode();
            }

            if (window.refreshPlayerTcGlobals) {
                window.refreshPlayerTcGlobals();
            }
            // [NEW] Update the main player loop (player.js)
            if (window.updatePlayerTimecodeState) {
                window.updatePlayerTimecodeState();
            }
        }

        /**
         * HTML escape utility
         */
        function escapeHtml(text) {
            const div = document.createElement("div");
            div.textContent = text;
            return div.innerHTML;
        }

        /**
         * Format date utility
         */
        function formatDate(dateString) {
            const date = new Date(dateString);
            const year = date.getFullYear();
            const month = String(date.getMonth() + 1).padStart(2, "0");
            const day = String(date.getDate()).padStart(2, "0");
            const hours = String(date.getHours()).padStart(2, "0");
            const minutes = String(date.getMinutes()).padStart(2, "0");
            return `${year}-${month}-${day} ${hours}:${minutes}`;
        }

        // ============================================================
        // INTERCEPT CLIP CLICKS
        // ============================================================

        document.addEventListener("click", function(e) {
            const clipLink = e.target.closest(".clip-item");
            if (!clipLink) return;

            // Don't intercept if it's already active
            if (clipLink.classList.contains("is-active")) {
                e.preventDefault();
                return;
            }

            // Intercept the click
            e.preventDefault();

            const url = clipLink.getAttribute("href");
            if (!url) return;

            // Load via Ajax (pause happens inside the function)
            loadClipAjax(url, clipLink);
        });

        // ============================================================
        // HANDLE BROWSER BACK/FORWARD
        // ============================================================

        window.addEventListener("popstate", function(event) {
            if (event.state && event.state.clipUuid) {
                // Reconstruct URL from state
                const url = `/admin/projects/${event.state.projectUuid}/days/${event.state.dayUuid}/player/${event.state.clipUuid}`;

                // Find the clip element
                const clipElement = document.querySelector(
                    `.clip-item[href="${url}"]`
                );

                // Load via Ajax
                loadClipAjax(url, clipElement);
            } else {
                // No state - do a full reload
                window.location.reload();
            }
        });

        // ============================================================
        // AUTOPLAY NEXT CLIP
        // ============================================================

        vid.addEventListener('ended', function() {
            const activeClip = document.querySelector(".clip-item.is-active");
            if (!activeClip) return;

            // Find the next clip in the list
            const nextClip = activeClip.nextElementSibling;

            if (nextClip && nextClip.classList.contains('clip-item')) {
                console.log("Autoplay: Loading next clip...");
                nextClip.click();
            } else {
                console.log("Autoplay: End of list reached.");
            }
        });

        // ============================================================
        // EXPORT FUNCTIONS FOR USE IN OTHER SCRIPTS
        // ============================================================

        // Make comment functions available globally for re-attachment
        window.attachCommentListeners = attachCommentListeners;
    });
</script>

<?php $this->end(); ?>