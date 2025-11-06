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
 *      [
 *          [
 *              'day_uuid'   => '...',
 *              'title'      => 'DAY 03' or '2025-10-30' etc,
 *              'shoot_date' => '2025-10-30', // fallback
 *              'thumb_url'  => '/data/.../poster.jpg' or null,
 *          ],
 *          ...
 *      ]
 */
/** @var string $placeholder_thumb_url  URL for generic day thumb */

$this->extend('layout/main');
?>

<?php $this->start('title'); ?>
<?= htmlspecialchars($project['title']) ?> · Player
<?php $this->end(); ?>

<?php $this->start('head'); ?>
<link rel="stylesheet" href="/assets/css/player.css">
<?php $this->end(); ?>

<?php $this->start('content'); ?>

<?php $clip_count = is_array($clip_list ?? null) ? count($clip_list) : 0; ?>

<div class="zd-bleed">

    <div class="player-layout" data-project-uuid="<?= htmlspecialchars($project_uuid) ?>">


        <!-- Left: navigation (minimal for Step 1) -->
        <aside style="background:var(--panel);border:1px solid var(--border);border-radius:12px;padding:12px;overflow:visible;position:relative;">


            <div class="zd-left-head" style="display:flex;justify-content:space-between;align-items:center;gap:8px;margin-bottom:8px;">

                <!-- Left side: "(DAY NAME) / Clips" -->
                <div style="font-size:13px;font-weight:500;color:var(--text);display:flex;flex-wrap:wrap;align-items:center;gap:4px;">
                    <!-- Clickable day label -->
                    <button
                        id="zd-day-switch-btn"
                        style="all:unset;cursor:pointer;color:var(--accent);font-weight:600;">
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
                    <a href="/admin/projects/<?= htmlspecialchars($project_uuid) ?>/days/<?= htmlspecialchars($day_uuid) ?>/clips"
                        style="font-size:12px;text-decoration:none;color:#3aa0ff">← back</a>
                </div>
            </div>


            <?php
            // expects $clip_list and $current_clip
            ?>
            <div class="clipScrollOuter" style="margin-top:12px;max-height:72vh;position:relative;overflow:visible;">

                <div id="clipScrollInner"
                    class="clipScrollInner"
                    style="overflow-y:auto;overflow-x:hidden;max-height:72vh;padding-inline-end:10px;box-sizing:border-box;position:relative;">

                    <div id="clipListContainer"
                        class="list-view"
                        style="position:relative;overflow:visible;">

                        <?php if (empty($clip_list)): ?>
                            <div class="zd-meta">No clips on this day.</div>
                        <?php else: ?>
                            <?php foreach ($clip_list as $it):
                                $isActive = ($it['clip_uuid'] === ($current_clip ?? ''));
                                $href = "/admin/projects/" . htmlspecialchars($project_uuid)
                                    . "/days/" . htmlspecialchars($day_uuid)
                                    . "/player/" . htmlspecialchars($it['clip_uuid']);
                            ?>
                                <a class="clip-item <?= $isActive ? 'is-active' : '' ?>" href="<?= $href ?>">
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


                                    <?php if (!empty($it['poster_path'])): ?>
                                        <div class="thumb-wrap">
                                            <img src="<?= htmlspecialchars($it['poster_path']) ?>" alt="">
                                        </div>
                                    <?php else: ?>
                                        <div class="thumb-wrap">
                                            <div class="no-thumb"></div>
                                            <?php if ($clipLabel !== ''): ?>
                                                <div class="clip-badge"><?= htmlspecialchars($clipLabel) ?></div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>

                                    <div class="clip-text">
                                        <?php if ($clipLabel !== ''): ?>
                                            <div class="clip-title">
                                                <?= htmlspecialchars($clipLabel) ?>
                                            </div>
                                        <?php endif; ?>
                                        <div class="zd-meta">
                                            <?= htmlspecialchars($it['file_name'] ?? '') ?>
                                        </div>
                                    </div><!-- /.clip-text -->

                                </a>
                            <?php endforeach; ?>
                        <?php endif; ?>

                    </div> <!-- /#clipListContainer -->

                </div> <!-- /.clipScrollInner -->
            </div> <!-- /.clipScrollOuter -->


            <!-- DAY PICKER (hidden by default; toggled via "(DAY NAME)" click) -->
            <div class="dayScrollOuter"
                style="display:none;margin-top:12px;max-height:72vh;position:relative;overflow:visible;">

                <div id="dayScrollInner"
                    class="dayScrollInner"
                    style="overflow-y:auto;overflow-x:hidden;max-height:72vh;padding-inline-end:10px;box-sizing:border-box;position:relative;">

                    <div id="dayListContainer"
                        class="grid-view"
                        style="position:relative;overflow:visible;">

                        <?php
                        $days = $days ?? [];
                        $placeholder_thumb_url = $placeholder_thumb_url ?? '/assets/img/empty_day_placeholder.png';
                        ?>


                        <?php if (empty($days)): ?>
                            <div class="zd-meta">No days found.</div>
                        <?php else: ?>
                            <?php foreach ($days as $d):
                                $thumb = $d['thumb_url'] ?? $placeholder_thumb_url;
                                $title = $d['title'] ?? ($d['shoot_date'] ?? 'Untitled Day');
                            ?>
                                <button
                                    class="day-item"
                                    data-day-uuid="<?= htmlspecialchars($d['day_uuid']) ?>"
                                    data-day-label="<?= htmlspecialchars($title) ?>">

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

                    </div> <!-- /#dayListContainer -->

                </div> <!-- /.dayScrollInner -->
            </div> <!-- /.dayScrollOuter -->



        </aside>
        <div id="sidebarResizer" class="sidebar-resizer"></div>
        <!-- Right: player + info -->
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
                    <div style="margin-bottom:8px;color:var(--text);font-weight:600;">
                        <?= htmlspecialchars($sceneLine) ?>
                    </div>


                    <?php if ($proxy_url): ?>
                        <div class="zd-player-frame" id="playerFrame">
                            <div id="tcOverlay" class="tc-overlay">00:00:00:00</div>
                            <video id="zdVideo"
                                data-fps="<?= htmlspecialchars((string)($clip['fps'] ?? '25')) ?>"
                                data-tc-start="<?= htmlspecialchars($clip['tc_start'] ?? '00:00:00:00') ?>"
                                <?= $poster_url ? 'poster="' . htmlspecialchars($poster_url) . '"' : '' ?>>
                                <source src="<?= htmlspecialchars($proxy_url) ?>" type="video/mp4">
                                Your browser does not support the video tag.
                            </video>
                            <!-- Custom Controls -->
                            <div class="zd-controls" role="group" aria-label="Player controls">
                                <button class="zd-btn" id="btnPlayPause" title="Play/Pause" aria-label="Play/Pause">
                                    <!-- simple text for now; swap to SVG later -->
                                    <span data-state="play">▶</span><span data-state="pause" style="display:none;">⏸</span>
                                </button>
                                <button class="zd-btn" id="btnStepBack" title="Step 1 frame back" aria-label="Step back 1 frame">◀ 1f</button>
                                <button class="zd-btn" id="btnStepFwd" title="Step 1 frame forward" aria-label="Step forward 1 frame">1f ▶</button>


                                <!-- Scrubber -->
                                <div class="zd-scrub-wrap" aria-label="Scrub">
                                    <input type="range" id="scrub" min="0" max="1000" value="0" step="1" aria-label="Timeline">
                                    <div class="zd-time">
                                        <span id="timeCur">00:00</span> / <span id="timeDur">00:00</span>
                                    </div>
                                </div>

                                <!-- Volume -->
                                <div class="zd-vol">
                                    <button class="zd-btn" id="btnMute" title="Mute" aria-label="Mute">🔊</button>
                                    <input type="range" id="vol" min="0" max="1" step="0.01" value="1" aria-label="Volume">
                                </div>

                                <!-- Fullscreen -->
                                <button class="zd-btn" id="btnFS" title="Fullscreen" aria-label="Fullscreen">⛶</button>
                            </div>

                            <canvas id="lutCanvas" class="lut-canvas"></canvas> <!-- LUT render target -->

                        </div>
                    <?php else: ?>
                        <div style="padding:24px;color:#d62828;">No proxy available for this clip yet.</div>
                    <?php endif; ?>


                </div>

                <!-- METADATA (full width card under player) -->
                <div style="background:var(--panel);border:1px solid var(--border);border-radius:12px;padding:12px;margin-bottom:12px;">
                    <div style="font-weight:600;color:var(--text);margin-bottom:8px;">Metadata</div>

                    <div style="font-size:12px;color:var(--muted);margin-bottom:8px;">
                        File: <?= htmlspecialchars($clip['file_name'] ?? '') ?><br>
                        TC: <?= htmlspecialchars(($clip['tc_start'] ?? '') . ' – ' . ($clip['tc_end'] ?? '')) ?><br>
                        Reel: <?= htmlspecialchars($clip['reel'] ?? '') ?>
                    </div>

                    <?php if (!empty($metadata)): ?>
                        <table style="width:100%;border-collapse:collapse;font-size:12px;">
                            <?php foreach ($metadata as $m): ?>
                                <tr>
                                    <td style="padding:6px 8px;border-bottom:1px solid var(--border);color:var(--muted);width:30%;white-space:nowrap;">
                                        <?= htmlspecialchars($m['meta_key']) ?>
                                    </td>
                                    <td style="padding:6px 8px;border-bottom:1px solid var(--border);color:var(--text);">
                                        <?= htmlspecialchars((string)$m['meta_value']) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </table>
                    <?php else: ?>
                        <div style="font-size:12px;color:var(--muted);">No extra metadata.</div>
                    <?php endif; ?>
                </div>

                <!-- COMMENTS (full width card under metadata) -->
                <div style="background:var(--panel);border:1px solid var(--border);border-radius:12px;padding:12px;">
                    <div style="font-weight:600;color:var(--text);margin-bottom:8px;">Comments</div>

                    <?php if (!empty($comments)): ?>
                        <div style="display:flex;flex-direction:column;gap:8px;">
                            <?php foreach ($comments as $c): ?>
                                <div style="border:1px solid var(--border);border-radius:8px;padding:8px;">
                                    <div style="font-size:11px;color:var(--muted);margin-bottom:4px;">
                                        <?= htmlspecialchars($c['created_at']) ?>
                                        <?php if (!empty($c['start_tc']) || !empty($c['end_tc'])): ?>
                                            · <?= htmlspecialchars(($c['start_tc'] ?? '') . ' – ' . ($c['end_tc'] ?? '')) ?>
                                        <?php endif; ?>
                                    </div>
                                    <div style="font-size:13px;color:var(--text);white-space:pre-wrap;">
                                        <?= htmlspecialchars($c['body']) ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div style="font-size:12px;color:var(--muted);">No comments yet.</div>
                    <?php endif; ?>
                </div>

            </div> <!-- /.zd-player-wrap -->

        </section>
    </div>
</div> <!-- /.zd-bleed -->

<?php $this->end(); ?>

<?php $this->start('scripts'); ?>
<script src="/assets/js/player.js"></script>
<script type="module" src="/assets/js/player-lut-init.js"></script>
<script type="module" src="/assets/js/player-ui.js"></script>
<?php $this->end(); ?>