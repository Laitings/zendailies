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

</style>
<?php $this->end(); ?>

<?php $this->start('content'); ?>

<?php $clip_count = is_array($clip_list ?? null) ? count($clip_list) : 0; ?>

<div class="zd-bleed">

    <div class="player-layout" data-project-uuid="<?= htmlspecialchars($project_uuid) ?>">


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
                                        <div class="zd-meta"><?= htmlspecialchars($it['file_name'] ?? '') ?></div>
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
                                        </div>
                                    </div>

                                    <div class="day-rowtext">
                                        <?= htmlspecialchars($title) ?>
                                    </div>

                                </button>
                            <?php endforeach; ?>
                        <?php endif; ?>

                    </div>
                </div>
            </div>
        </aside>
        <div id="sidebarResizer" class="sidebar-resizer"></div>
        <section>
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
                    <div class="zd-scene-line" style="margin-bottom:8px;color:var(--text);font-weight:600;">
                        <span><?= htmlspecialchars($sceneLine) ?></span>
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
                            <div id="tcOverlay" class="tc-overlay">00:00:00:00</div>
                            <video id="zdVideo" data-fps="<?= htmlspecialchars((string)($clip['fps'] ?? '25')) ?>" data-tc-start="<?= htmlspecialchars($clip['tc_start'] ?? '00:00:00:00') ?>" <?= $poster_url ? 'poster="' . htmlspecialchars($poster_url) . '"' : '' ?>>
                                <source src="<?= htmlspecialchars($proxy_url) ?>" type="video/mp4">
                                Your browser does not support the video tag.
                            </video>
                            <div class="zd-controls" role="group" aria-label="Player controls">
                                <button class="zd-btn" id="btnPlayPause" title="Play/Pause" aria-label="Play/Pause">
                                    <span data-state="play">▶</span><span data-state="pause" style="display:none;">⏸</span>
                                </button>
                                <button class="zd-btn" id="btnStepBack" title="Step 1 frame back" aria-label="Step back 1 frame">◀ 1f</button>
                                <button class="zd-btn" id="btnStepFwd" title="Step 1 frame forward" aria-label="Step forward 1 frame">1f ▶</button>


                                <div class="zd-scrub-wrap" aria-label="Scrub">
                                    <input type="range" id="scrub" min="0" max="1000" value="0" step="1" aria-label="Timeline">
                                    <div class="zd-time">
                                        <span id="timeCur">00:00</span> / <span id="timeDur">00:00</span>
                                    </div>
                                </div>

                                <div class="zd-vol">
                                    <button class="zd-btn" id="btnMute" title="Mute" aria-label="Mute">🔊</button>
                                    <input type="range" id="vol" min="0" max="1" step="0.01" value="1" aria-label="Volume">
                                </div>

                                <button class="zd-btn" id="btnFS" title="Fullscreen" aria-label="Fullscreen">⛶</button>
                            </div>

                            <canvas id="lutCanvas" class="lut-canvas"></canvas>
                        </div>
                    <?php else : ?>
                        <div style="padding:24px;color:#d62828;">No proxy available for this clip yet.</div>
                    <?php endif; ?>


                </div>

                <?php
                // --- Prepare Metadata ---
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

                <div style="background:var(--panel);border:1px solid var(--border);border-radius:12px;padding:12px;margin-bottom:12px;">

                    <details class="zd-metadata-group" open>
                        <summary class="zd-metadata-summary">Basic Metadata</summary>
                        <div class="zd-metadata-content">
                            <table class="zd-meta-table">
                                <?php foreach ($basicMeta as $key => $val) : if ($val === null || $val === '') continue; ?>
                                    <tr>
                                        <td><?= htmlspecialchars($key) ?></td>
                                        <td><?= htmlspecialchars($val) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </table>
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