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
</style>


<?php $this->end(); ?>

<?php $this->start('content'); ?>

<?php $clip_count = is_array($clip_list ?? null) ? count($clip_list) : 0; ?>

<div class="zd-bleed">

    <div class="player-layout"
        data-project-uuid="<?= htmlspecialchars($project_uuid) ?>"
        data-initial-mode="<?= htmlspecialchars($initial_mode ?? '') ?>">


        <aside style="background:var(--panel);border:1px solid var(--border);border-radius:12px;padding:12px;overflow:visible;position:relative;">

            <div class="zd-left-head" style="display:flex;justify-content:space-between;align-items:center;gap:8px;margin-bottom:8px;">

                <div style="font-size:13px;font-weight:500;color:var(--text);display:flex;flex-wrap:wrap;align-items:center;gap:4px;">
                    <button id="zd-day-switch-btn" style="all:unset;cursor:pointer;color:var(--accent);font-weight:600;">
                        <span id="zd-current-day-label">
                            <?= htmlspecialchars($current_day_label ?? ($day['title'] ?? 'Current Day')) ?>
                        </span>
                    </button>

                    <span id="zd-header-slash" style="opacity:.5;">/</span>

                    <span id="zd-header-clips" style="opacity:.8;">
                        <?= (int)$clip_count . ' ' . ($clip_count === 1 ? 'Clip' : 'Clips') ?>
                    </span>

                </div>

                <div style="display:flex;align-items:center;gap:6px;">
                    <button id="viewToggleBtn" class="icon-btn" title="Switch view" aria-label="Switch view">
                        <img id="viewToggleIcon" src="/assets/icons/grid.svg" alt="" class="icon">
                    </button>


                    <a href="/admin/projects/<?= htmlspecialchars($project_uuid) ?>/days/<?= htmlspecialchars($day_uuid) ?>/clips" style="font-size:12px;text-decoration:none;color:#3aa0ff">← back</a>
                </div>
            </div>


            <?php
            // expects $clip_list and $current_clip
            ?>
            <div class="clipScrollOuter" style="margin-top:12px;max-height:72vh;position:relative;overflow:visible;">

                <div id="clipScrollInner" class="clipScrollInner" style="overflow-y:auto;overflow-x:hidden;max-height:72vh;padding-inline-end:10px;box-sizing:border-box;position:relative;">

                    <div id="clipListContainer" class="list-view" style="position:relative;overflow:visible;">

                        <?php if (empty($clip_list)) : ?>
                            <div class="zd-meta">No clips on this day.</div>
                        <?php else : ?>
                            <?php foreach ($clip_list as $it) :
                                $isActive = ($it['clip_uuid'] === ($current_clip ?? ''));
                                $href = "/admin/projects/" . htmlspecialchars($project_uuid)
                                    . "/days/" . htmlspecialchars($day_uuid)
                                    . "/player/" . htmlspecialchars($it['clip_uuid']);
                            ?>
                                <a class="clip-item <?= $isActive ? 'is-active' : '' ?>"
                                    href="<?= $href ?>"
                                    data-is-select="<?= (int)($it['is_select'] ?? 0) ?>">

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
                                    ?>


                                    <?php if (!empty($it['poster_path'])) : ?>
                                        <div class="thumb-wrap">
                                            <img src="<?= htmlspecialchars($it['poster_path']) ?>" alt="">
                                            <?php if ((int)($it['is_select'] ?? 0) === 1): ?>
                                                <div class="zd-flag-star" aria-label="Good take">
                                                    <svg viewBox="0 0 24 24" role="img" focusable="false" width="16" height="16">
                                                        <path fill="#ffd54a" d="M12 17.3l-5.47 3.22 1.45-6.17-4.78-4.1 6.3-.54L12 3l2.5 6.7 6.3.54-4.78 4.1 1.45 6.17z" />
                                                    </svg>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php else : ?>
                                        <div class="thumb-wrap">
                                            <div class="no-thumb"></div>
                                            <?php if ($clipLabel !== '') : ?>
                                                <div class="clip-badge"><?= htmlspecialchars($clipLabel) ?></div>
                                            <?php endif; ?>
                                            <?php if ((int)($it['is_select'] ?? 0) === 1): ?>
                                                <div class="zd-flag-star" aria-label="Good take">
                                                    <svg viewBox="0 0 24 24" role="img" focusable="false" width="16" height="16">
                                                        <path fill="#ffd54a" d="M12 17.3l-5.47 3.22 1.45-6.17-4.78-4.1 6.3-.54L12 3l2.5 6.7 6.3.54-4.78 4.1 1.45 6.17z" />
                                                    </svg>
                                                </div>
                                            <?php endif; ?>

                                        </div>
                                    <?php endif; ?>

                                    <div class="clip-text">
                                        <?php if ($clipLabel !== ''): ?>
                                            <div class="clip-title">
                                                <span><?= htmlspecialchars($clipLabel) ?></span>
                                                <?php if ((int)($it['is_select'] ?? 0) === 1): ?>
                                                    <span class="zd-inline-star" title="Good take" aria-label="Good take">
                                                        <svg viewBox="0 0 24 24" role="img" focusable="false">
                                                            <path d="M12 17.3l-5.47 3.22 1.45-6.17-4.78-4.1 6.3-.54L12 3l2.5 6.7 6.3.54-4.78 4.1 1.45 6.17z" />
                                                        </svg>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($it['file_name'])): ?>

                                            <div class="zd-meta">
                                                <div class="zd-filename"><?= htmlspecialchars($it['file_name']) ?></div>
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
                            ?>
                                <button class="day-item" data-day-uuid="<?= htmlspecialchars($d['day_uuid']) ?>" data-day-label="<?= htmlspecialchars($title) ?>">

                                    <div class="day-thumb">
                                        <div class="day-thumb-inner">
                                            <img src="<?= htmlspecialchars($thumb) ?>" alt="">
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
        </aside>
        <div id="sidebarResizer" class="sidebar-resizer"></div>
        <section class="player-main">
            <div class="zd-player-wrap">

                <div style="background:var(--panel);border:1px solid var(--border);border-radius:12px;padding:12px;margin-bottom:12px;">
                    <?php
                    $scene = trim((string)($clip['scene'] ?? ''));
                    $slate = trim((string)($clip['slate'] ?? ''));
                    $take  = trim((string)($clip['take']  ?? ''));
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
                    ?>
                    <div class="zd-scene-line">
                        <div class="zd-scene-left">
                            <span><?= htmlspecialchars($sceneLine) ?></span>
                            <?php if (!empty($clip['file_name'])): ?>
                                <span class="zd-clip-filename"> -
                                    <?= htmlspecialchars($clip['file_name']) ?>
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

                            <video
                                id="zdVideo"
                                data-fps="<?= htmlspecialchars($fpsStr) ?>"
                                data-fpsnum="<?= $fpsNum ?: '' ?>"
                                data-fpsden="<?= $fpsDen ?: '' ?>"
                                data-tc-start="<?= htmlspecialchars($clip['tc_start'] ?? '00:00:00:00') ?>"
                                <?= $poster_url ? 'poster="' . htmlspecialchars($poster_url) . '"' : '' ?>>
                                <source src="<?= htmlspecialchars($proxy_url) ?>" type="video/mp4">
                                Your browser does not support the video tag.
                            </video>
                            <input type="range"
                                id="scrubGlobal"
                                class="zd-scrub-global"
                                min="0" max="1000" step="1" value="0"
                                aria-label="Timeline" title="Timeline">
                            <div class="zd-controls" role="group" aria-label="Player controls">
                                <button class="zd-btn" id="btnPlayPause" title="Play/Pause" aria-label="Play/Pause">
                                    <span data-state="play">▶</span><span data-state="pause" style="display:none;">⏸</span>
                                </button>
                                <button class="zd-btn" id="btnStepBack" title="Step 1 frame back" aria-label="Step back 1 frame">◀ 1f</button>
                                <button class="zd-btn" id="btnStepFwd" title="Step 1 frame forward" aria-label="Step forward 1 frame">1f ▶</button>
                                <button id="tcChip" class="zd-tc-chip" type="button" title="Click to enter TC">00:00:00:00</button>


                                <div class="zd-vol">
                                    <button class="zd-btn" id="btnMute" title="Mute" aria-label="Mute">🔊</button>
                                    <input type="range" id="vol" min="0" max="1" step="0.01" value="1" aria-label="Volume">
                                </div>
                                <button class="zd-btn" id="btnTheater" title="Theater mode" aria-label="Theater mode">
                                    <span class="theater-icon" data-state="enter"></span>
                                    <span class="theater-icon theater-exit" data-state="exit" style="display:none;"></span>
                                </button>

                                <button class="zd-btn" id="btnFS" title="Fullscreen" aria-label="Fullscreen">⛶</button>
                            </div>

                            <canvas id="lutCanvas" class="lut-canvas"></canvas>
                        </div>
                    <?php else : ?>
                        <div style="padding:24px;color:#d62828;">No proxy available for this clip yet.</div>
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

                    <details class="zd-metadata-group" open>
                        <summary class="zd-metadata-summary">Basic Metadata</summary>
                        <div class="zd-metadata-content">
                            <?php
                            // Build the 8 basic fields in desired order
                            $basic = [
                                'Duration' => $duration_tc,
                                'FPS'      => ($fpsVal !== null ? rtrim(rtrim(number_format($fpsVal, 3, '.', ''), '0'), '.') : null),
                                'TC In'    => $clip['tc_start'] ?? null,
                                'TC Out'   => $clip['tc_end']   ?? null,
                                'Resolution' => $frameSize,
                                'Codec'      => $codec,
                                'Camera'     => $clip['camera'] ?? null,
                                'Reel'       => $clip['reel']   ?? null,
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


                    <details class="zd-metadata-group" style="margin-top: 8px;">
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
                    <div style="font-weight:600;color:var(--text);margin-bottom:8px;">Comments</div>

                    <?php if (!empty($comments)) : ?>
                        <div style="display:flex;flex-direction:column;gap:8px;">
                            <?php foreach ($comments as $c) : ?>
                                <div style="border:1px solid var(--border);border-radius:8px;padding:8px;">
                                    <div style="font-size:11px;color:var(--muted);margin-bottom:4px;">
                                        <?= htmlspecialchars($c['created_at']) ?>
                                        <?php if (!empty($c['start_tc']) || !empty($c['end_tc'])) : ?>
                                            · <?= htmlspecialchars(($c['start_tc'] ?? '') . ' – ' . ($c['end_tc'] ?? '')) ?>
                                        <?php endif; ?>
                                    </div>
                                    <div style="font-size:13px;color:var(--text);white-space:pre-wrap;">
                                        <?= htmlspecialchars($c['body']) ?>
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