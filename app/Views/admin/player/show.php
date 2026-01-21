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
?>

<?php $this->start('title'); ?>
<?= htmlspecialchars($project['title']) ?> · Player
<?php $this->end(); ?>

<?php $this->start('head'); ?>
<link rel="stylesheet" href="/assets/css/player.css">
<style>
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

                        <a href="/admin/projects/<?= htmlspecialchars($project_uuid) ?>/days/<?= htmlspecialchars($day_uuid) ?>/clips"
                            class="hdr-back">
                            ← back
                        </a>
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
                                    <?php if (!empty($it['poster_path'])) : ?>
                                        <?php
                                        // Cache-bust per clip poster so we don’t see stale thumbs
                                        $thumbUrl = (string)$it['poster_path'];
                                        $thumbUrl .= (strpos($thumbUrl, '?') !== false ? '&' : '?') . 'v=' . time();
                                        ?>
                                        <div class="thumb-wrap">
                                            <img src="<?= htmlspecialchars($thumbUrl, ENT_QUOTES, 'UTF-8') ?>" alt="Clip thumbnail">
                                        </div>

                                    <?php else : ?>
                                        <div class="thumb-wrap">
                                            <div class="no-thumb"></div>


                                        <?php endif; ?>


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
                                            <img src="<?= htmlspecialchars($thumb, ENT_QUOTES, 'UTF-8') ?>" alt="">

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
                                        <img src="<?= htmlspecialchars($s['thumb_url'] ?: $placeholder_thumb_url) ?>">
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
                        $sceneLine = "Sc. {$scene}/{$slate}";
                    } elseif ($scene !== '') {
                        $sceneLine = "Sc. {$scene}";
                    } elseif ($slate !== '') {
                        $sceneLine = "Sc. {$slate}";
                    }
                    if ($take !== '') {
                        $sceneLine .= ($sceneLine ? '-' : 'Sc. ') . $take;
                    }
                    if ($camera !== '') {
                        $sceneLine .= " · Cam {$camera}";
                    }

                    // Base filename for header (no extension)
                    $fileNameFull = trim((string)($clip['file_name'] ?? ''));
                    $fileNameBase = $fileNameFull !== '' ? preg_replace('/\.[^.]+$/', '', $fileNameFull) : '';
                    ?>
                    <div class="zd-scene-line">
                        <div class="zd-scene-left">
                            <span><?= htmlspecialchars($sceneLine) ?></span>
                            <?php if ($fileNameBase !== ''): ?>
                                <span class="zd-clip-filename">
                                    <?= $sceneLine !== '' ? ' - ' : '' ?>
                                    <?= htmlspecialchars($fileNameBase, ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            <?php endif; ?>


                        </div>

                        <?php if (!empty($clip['is_select']) && (int)$clip['is_select'] === 1): ?>
                            <span class="zd-inline-star" title="Good take" aria-label="Good take">
                                <svg viewBox="0 0 24 24" role="img" focusable="false">
                                    <path d="M12 17.3l-5.47 3.22 1.45-6.17-4.78-4.1 6.3-.54L12 3l2.5 6.7 6.3.54-4.78 4.1 1.45 6.17z" />
                                </svg>
                            </span>
                        <?php endif; ?>
                    </div>



                    <?php if ($proxy_url) : ?>
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
                                data-fps="<?= htmlspecialchars($fpsStr) ?>"
                                data-fpsnum="<?= $fpsNum ?: '' ?>"
                                data-fpsden="<?= $fpsDen ?: '' ?>"
                                data-tc-start="<?= htmlspecialchars($clip['tc_start'] ?? '00:00:00:00') ?>"
                                <?= $posterAttr ?>>
                                <source src="<?= htmlspecialchars($proxy_url) ?>" type="video/mp4">
                                Your browser does not support the video tag.
                            </video>
                            <div id="zdFlash" style="position:absolute; inset:0; background:white; opacity:0; pointer-events:none; z-index:10; transition:none;"></div>

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
                                                <select id="selBlanking" class="zd-sort-select" style="width: 70px; height: 32px; padding: 2px 4px;" title="Aspect Ratio Blanking">
                                                    <option value="none">Off</option>
                                                    <option value="2.39">2.39</option>
                                                    <option value="1.85">1.85</option>
                                                    <option value="1.66">1.66</option>
                                                    <option value="1.33">1.33</option>
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

                    <details class="zd-metadata-group" data-meta-section="basic" open>
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
                    $commentLabel = '';
                    if ($commentCount === 1) {
                        $commentLabel = '1 comment';
                    } elseif ($commentCount > 1) {
                        $commentLabel = $commentCount . ' comments';
                    }
                    ?>
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
                        <div class="zd-section-header">Comments</div>

                        <?php if ($commentLabel !== ''): ?>
                            <div style="font-size:11px;color:var(--muted);">
                                <?= htmlspecialchars($commentLabel) ?>
                            </div>
                        <?php endif; ?>
                    </div>


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

            </div>

        </section>
    </div>
</div> <?php $this->end(); ?>

<?php $this->start('scripts'); ?>
<script src="/assets/js/player.js"></script>
<script type="module" src="/assets/js/player-lut-init.js"></script>
<script type="module" src="/assets/js/player-ui.js"></script>
<?php $this->end(); ?>