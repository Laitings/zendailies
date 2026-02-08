<?php

/** @var array $project */
/** @var array $clip */
/** @var string $proxy_url */
/** @var array $clip_list */
/** @var string $layout */
/** @var array $comments */

// Extends layout/mobile to bypass desktop CSS conflicts
$this->extend($layout ?? 'layout/mobile');
?>

<?php $this->start('head'); ?>
<link rel="stylesheet" href="/assets/css/player-mobile.css?v=<?= time() ?>">
<?php $this->end(); ?>

<?php $this->start('content'); ?>
<div class="mobile-player-page player-layout"
    data-project-uuid="<?= htmlspecialchars($project_uuid) ?>"
    data-day-uuid="<?= htmlspecialchars($day_uuid) ?>">

    <input type="hidden" name="_csrf" value="<?= htmlspecialchars(\App\Support\Csrf::token()) ?>">

    <div class="sticky-player-container">

        <?php if ($proxy_url): ?>
            <div class="zd-player-frame" id="playerFrame">
                <video id="zdVideo"
                    playsinline
                    preload="metadata"
                    crossorigin="use-credentials"
                    controlsList="nodownload"
                    oncontextmenu="return false;"
                    data-fps="<?= $clip['fps'] ?? '25' ?>"
                    data-fpsnum="<?= $clip['fps_num'] ?? '0' ?>"
                    data-fpsden="<?= $clip['fps_den'] ?? '0' ?>"
                    data-tc-start="<?= $clip['tc_start'] ?? '00:00:00:00' ?>">
                    <source src="/admin/projects/<?= $project_uuid ?>/clips/<?= $clip['clip_uuid'] ?>/stream.mp4" type="video/mp4">
                </video>

                <div id="zdMask" class="zd-mask"></div>

                <div id="mobileUiOverlay" class="mobile-ui-overlay">
                    <div class="m-top-context" style="position: absolute; top: 12px; left: 12px; z-index: 120; pointer-events: none; display: flex; flex-direction: column; gap: 8px;">

                        <div class="m-clip-info-overlay" style="background: rgba(0,0,0,0.6); backdrop-filter: blur(8px); padding: 8px 12px; border-radius: 10px; border: 1px solid rgba(255, 255, 255, 0.1); pointer-events: auto; width: fit-content;">
                            <h3 style="margin:0; font-size: 13px; color: #fff;">
                                <?php
                                $hasScene = !empty($clip['scene']);
                                $hasSlate = !empty($clip['slate']);
                                $hasTake = !empty($clip['take']);

                                if ($hasScene || $hasSlate || $hasTake): ?>
                                    <?= htmlspecialchars($clip['scene'] ?? 'Sc') ?> / <?= htmlspecialchars($clip['slate'] ?? '') ?> - <?= htmlspecialchars($clip['take'] ?? 'Tk') ?>
                                <?php else: ?>
                                    No scene info
                                <?php endif; ?>
                            </h3>
                            <div class="filename" style="font-size: 10px; color: var(--m-accent); margin-top: 2px;"><?= htmlspecialchars(pathinfo($clip['file_name'] ?? '', PATHINFO_FILENAME)) ?></div>
                            <?php if (in_array($clip['clip_uuid'] ?? '', $sensitiveClips ?? [])): ?>
                                <div class="sensitive-indicator" style="font-size: 9px; color: #ef4444; margin-top: 2px; font-weight: 700;">Restricted</div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="m-play-trigger" id="mPlayTrigger" style="z-index: 10;">
                        <div id="mPlayIconContainer" style="width: 80px; height: 80px; display: flex; align-items: center; justify-content: center; background: transparent;">
                        </div>
                    </div>


                    <div class="m-controls-bar" style="z-index: 30; pointer-events: auto;">
                        <div class="m-controls-top">
                            <input type="range" id="mScrub" class="m-scrub" min="0" max="1000" step="1" value="0">
                        </div>
                        <div class="m-controls-bottom">
                            <div class="m-left">
                                <span id="mTcDisplay" class="m-tc">00:00:00:00</span>
                            </div>
                            <div class="m-right" style="position: relative; display: flex; align-items: center; gap: 12px;">
                                <button type="button" class="m-icon-btn" id="mVolBtn" title="Mute">🔊</button>

                                <div style="position: relative; display: flex; align-items: center; justify-content: center;">
                                    <button type="button" class="m-icon-btn" id="mBlankBtn" title="Aspect Ratio">
                                        <svg viewBox="0 0 24 24" width="20" height="20" fill="white">
                                            <path d="M19 12h-2v3h-3v2h5v-5zM7 9h3V7H5v5h2V9zm14-6H3c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h18c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 16H3V5h18v14z" />
                                        </svg>
                                    </button>

                                    <div id="mBlankPicker" class="m-popover"
                                        data-default="<?= number_format((float)($project['default_aspect_ratio'] ?? 1.78), 2, '.', '') ?>"
                                        data-project-uuid="<?= htmlspecialchars($project['project_uuid'] ?? $project_uuid) ?>">
                                        <button onclick="setMobileBlanking('none')">Off</button>
                                        <button onclick="setMobileBlanking('2.39')">2.39</button>
                                        <button onclick="setMobileBlanking('2.00')">2:1</button>
                                        <button onclick="setMobileBlanking('1.85')">1.85</button>
                                        <button onclick="setMobileBlanking('1.66')">1.66</button>
                                        <button onclick="setMobileBlanking('1.33')">1.33</button>
                                    </div>
                                </div>

                                <button type="button" class="m-icon-btn" id="mFsBtn">⛶</button>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        <?php else: ?>
            <div style="aspect-ratio:16/9; display:flex; align-items:center; justify-content:center; color:red;">
                Proxy Unavailable
            </div>
        <?php endif; ?>
    </div>

    <div class="mobile-content-area">

        <div class="drawer-tab-row">
            <button type="button" class="drawer-tab-btn" id="tabBtnMeta" onclick="toggleDrawer('meta')">Metadata</button>
            <button type="button" class="drawer-tab-btn" id="tabBtnComments" onclick="toggleDrawer('comments')">
                Comments (<?= count($comments) ?>)
            </button>
        </div>

        <div id="drawerMeta" class="drawer-pane">
            <div class="mobile-meta-grid">
                <div class="mobile-meta-cell">
                    <div class="k">TC In</div>
                    <div class="v"><?= htmlspecialchars($clip['tc_start'] ?? '--') ?></div>
                </div>
                <div class="mobile-meta-cell">
                    <div class="k">TC Out</div>
                    <div class="v"><?= htmlspecialchars($clip['tc_end'] ?? '--') ?></div>
                </div>
                <div class="mobile-meta-cell">
                    <div class="k">Camera</div>
                    <div class="v"><?= htmlspecialchars($clip['camera'] ?? '--') ?></div>
                </div>
                <div class="mobile-meta-cell">
                    <div class="k">Reel</div>
                    <div class="v"><?= htmlspecialchars($clip['reel'] ?? '--') ?></div>
                </div>
            </div>
        </div>

        <!-- Comments Drawer: Form is sticky, comments scroll with clips -->
        <div id="drawerComments" class="drawer-pane">
            <div class="m-comment-form-sticky">
                <form id="mCommentForm" method="post" style="display: flex; flex-direction: column; gap: 8px;">
                    <input type="hidden" name="_csrf" value="<?= htmlspecialchars(\App\Support\Csrf::token()) ?>">
                    <input type="hidden" name="parent_comment_uuid" id="mParentUuid" value="">

                    <div id="mReplyIndicator" style="display: none; justify-content: space-between; align-items: center; background: #1f2937; padding: 6px 10px; border-radius: 4px;">
                        <span style="font-size: 11px; color: var(--m-accent);">Replying to <span id="mReplyName"></span></span>
                        <button type="button" onclick="cancelMobileReply()" style="background: none; border: none; color: #ef4444; font-size: 16px;">&times;</button>
                    </div>

                    <div style="display: flex; gap: 8px; align-items: center;">
                        <input type="text" name="start_tc" id="mCommentTc" placeholder="--:--:--:--"
                            style="width: 100px; background: #000; border: 1px solid #2a3342; border-radius: 6px; color: var(--m-accent); padding: 8px; font-size: 12px; font-family: monospace; outline: none;">
                        <button type="button" id="mGetTcBtn" style="background: #2a3342; color: #fff; border: none; border-radius: 6px; padding: 8px 12px; font-size: 11px; font-weight: 700;">USE CURRENT TC</button>
                    </div>

                    <textarea id="mCommentText" name="comment_body" placeholder="Add a note..." required
                        style="width: 100%; background: #000; border: 1px solid #2a3342; border-radius: 6px; color: #fff; padding: 10px; font-size: 14px; outline: none;"></textarea>
                    <button type="submit" style="background: var(--m-accent); color: #000; border: none; border-radius: 6px; padding: 10px; font-weight: 700;">POST COMMENT</button>
                </form>
            </div>
        </div>

        <!-- Comments List (scrolls with clips) -->
        <div class="m-comments-list" id="mCommentsList" style="display: <?= !empty($comments) ? 'block' : 'none' ?>;">
            <?php if (!empty($comments)): ?>
                <?php foreach ($comments as $c):
                    $indent = min($c['depth'], 2) * 20;
                ?>
                    <div class="m-comment-item" style="margin-left: <?= $indent ?>px;">
                        <div class="m-comment-header">
                            <span class="m-comment-author"><?= htmlspecialchars($c['author_name']) ?></span>
                            <div class="m-comment-meta">
                                <span class="m-comment-tc"><?= $c['start_tc'] ?? '' ?></span>
                                <button type="button" class="m-comment-reply-btn"
                                    onclick="setupMobileReply('<?= $c['comment_uuid'] ?>', '<?= addslashes($c['author_name']) ?>')">REPLY</button>
                            </div>
                        </div>
                        <div class="m-comment-body"><?= nl2br(htmlspecialchars($c['body'])) ?></div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="mobile-nav-header" style="padding: 20px 16px 12px;">
            <div style="display: grid; grid-template-columns: 1fr auto 1fr; align-items: center; gap: 8px; width: 100%;">

                <div style="font-size: 10px; font-weight: 700; text-transform: uppercase; color: var(--m-muted, #9ca3af); letter-spacing: 0.05em; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; min-width: 0;">
                    Clips in <?= htmlspecialchars($current_day_label ?? 'Day') ?>
                </div>

                <div style="display: flex; align-items: center; gap: 6px;">
                    <button type="button"
                        id="mobileSortToggle"
                        style="background: rgba(58, 160, 255, 0.15); border: 1px solid rgba(58, 160, 255, 0.3); border-radius: 6px; padding: 4px 8px; display: flex; align-items: center; gap: 4px; font-size: 10px; font-weight: 700; color: var(--m-accent, #3aa0ff); text-transform: uppercase; height: 28px;">
                        <span id="sortModeLabel">Scene</span>
                        <svg width="10" height="10" viewBox="0 0 10 10" fill="none">
                            <path d="M2 3.5L5 6.5L8 3.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                    </button>

                    <button type="button"
                        id="mobileSortDir"
                        data-dir="asc"
                        style="background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); border-radius: 6px; padding: 2px; display: flex; align-items: center; justify-content: center; width: 28px; height: 28px;"
                        title="Toggle direction">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none">
                            <path class="arrow-up" d="M12 2l-8 8h16z" fill="var(--m-accent, #3aa0ff)" opacity="0.4" />
                            <path class="arrow-down" d="M12 22l8-8H4z" fill="var(--m-accent, #3aa0ff)" opacity="1" />
                        </svg>
                    </button>
                </div>

                <?php
                $isSceneMode = (isset($_GET['scene']) && $_GET['scene'] !== '');
                $backLabel   = $isSceneMode ? 'Scenes' : 'Days';
                $backUrl     = "/admin/projects/{$project_uuid}/overview" . ($isSceneMode ? "?tab=scenes" : "?tab=days");
                ?>
                <div style="display: flex; justify-content: flex-end;">
                    <a href="<?= $backUrl ?>"
                        class="m-back-link"
                        style="font-size: 10px; font-weight: 800; text-transform: uppercase; color: #fff; background: var(--m-accent, #3aa0ff); padding: 0 10px; height: 28px; border-radius: 6px; text-decoration: none; display: flex; align-items: center; gap: 4px;">
                        <svg width="8" height="8" viewBox="0 0 8 10" fill="none">
                            <path d="M7 1L2 5L7 9" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                        <span><?= $backLabel ?></span>
                    </a>
                </div>

            </div>
        </div>

        <div class="mobile-clip-nav">
            <?php foreach ($clip_list as $it):
                $isActive = ($it['clip_uuid'] === ($current_clip ?? ''));
                $href = "/admin/projects/{$project_uuid}/days/{$day_uuid}/player/{$it['clip_uuid']}";
                $itFileName = ($it['file_name'] ?? '');
            ?>
                <a href="<?= $href ?>"
                    class="mobile-clip-link <?= $isActive ? 'is-active' : '' ?>"
                    data-proxy="/admin/projects/<?= $project_uuid ?>/clips/<?= $it['clip_uuid'] ?>/stream.mp4"
                    data-scene="<?= htmlspecialchars($it['scene'] ?? 'Sc') ?>"
                    data-slate="<?= htmlspecialchars($it['slate'] ?? '') ?>"
                    data-take="<?= htmlspecialchars($it['take'] ?? 'Tk') ?>"
                    data-filename="<?= htmlspecialchars(pathinfo($itFileName, PATHINFO_FILENAME)) ?>"
                    data-fps="<?= $it['fps'] ?? '25' ?>"
                    data-tc-start="<?= $it['tc_start'] ?? '00:00:00:00' ?>">
                    <div style="position: relative;">
                        <img src="<?= $it['poster_path'] ?? $placeholder_thumb_url ?>" class="m-thumb" alt="">
                        <?php
                        // Check if this clip should show the sensitive chip
                        $isSensitive = in_array($it['clip_uuid'], $sensitiveClips ?? []);
                        if ($isSensitive):
                        ?>
                            <div class="m-sensitive-chip">R</div>
                        <?php endif; ?>
                    </div>
                    <div style="flex:1; min-width:0;">
                        <div class="m-clip-title" style="white-space: nowrap; overflow: hidden; text-overflow: ellipsis; display: block;">
                            <?php
                            $title = trim(($it['scene'] ?? '') . ($it['slate'] ?? '') . ($it['take'] ?? ''));
                            echo !empty($title)
                                ? htmlspecialchars($it['scene'] ?? 'Sc') . ' / ' . htmlspecialchars($it['slate'] ?? '') . ' - ' . htmlspecialchars($it['take'] ?? 'Tk')
                                : htmlspecialchars(pathinfo($it['file_name'] ?? '', PATHINFO_FILENAME));
                            ?>
                        </div>

                        <div class="m-clip-meta" style="display: flex; justify-content: space-between; align-items: center; margin-top: 4px;">
                            <div style="white-space: nowrap; overflow: hidden; text-overflow: ellipsis; flex: 1; padding-right: 8px;">
                                <?php
                                // If the title is metadata, show the filename here. 
                                // If title IS the filename, show project code or similar (optional).
                                echo htmlspecialchars(pathinfo($it['file_name'] ?? '', PATHINFO_FILENAME));
                                ?>
                            </div>

                            <div style="display: flex; gap: 8px; align-items: center; flex-shrink: 0;">
                                <?php if ($it['is_select']): ?>
                                    <span style="color: #fbbf24; font-size: 14px;" title="Select">★</span>
                                <?php endif; ?>

                                <?php if ($it['comment_count'] > 0): ?>
                                    <div style="display: flex; align-items: center; gap: 3px; color: var(--m-muted); font-size: 11px;">
                                        <span style="font-size: 12px;">💬</span>
                                        <span><?= $it['comment_count'] ?></span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>

        <div id="mContextMenu" class="m-context-menu" style="display:none;" onclick="closeMContextMenu()">
            <div class="m-context-menu-inner" onclick="event.stopPropagation()">
                <div id="ctxSuccess" style="display:none; position:absolute; inset:0; background:var(--m-panel); z-index:10; flex-direction:column; align-items:center; justify-content:center;">
                    <span style="font-size:40px;">✅</span>
                    <span style="font-size:14px; font-weight:700; margin-top:8px; color:var(--m-accent);">SAVED</span>
                </div>

                <button type="button" id="btnCtxFavorite" class="ctx-btn">★ Mark as Select</button>
                <button type="button" class="ctx-btn cancel" onclick="closeMContextMenu()">Cancel</button>
            </div>
        </div>

        <style>
            .m-context-menu {
                position: fixed;
                inset: 0;
                background: rgba(0, 0, 0, 0.8);
                backdrop-filter: blur(4px);
                z-index: 2000;
                display: flex;
                align-items: center;
                justify-content: center;
            }

            .m-context-menu-inner {
                background: #1a1c23;
                width: 280px;
                border-radius: 14px;
                border: 1px solid #2d3139;
                overflow: hidden;
            }

            .ctx-btn {
                width: 100%;
                padding: 16px;
                background: transparent;
                border: none;
                color: #fff;
                font-size: 16px;
                font-weight: 600;
                border-bottom: 1px solid #2d3139;
            }

            .ctx-btn:active {
                background: #2d3139;
            }

            .ctx-btn.cancel {
                color: #ef4444;
                border-bottom: none;
            }

            /* Ensure the clip nav doesn't collapse during transitions */
            .mobile-clip-nav {
                overflow-y: auto;
                -webkit-overflow-scrolling: touch;
                scroll-behavior: auto !important;
                /* Disables smooth scroll which causes the 'sliding' look */
            }

            /* Hide scrollbar but keep functionality for a cleaner mobile look */
            .mobile-clip-nav::-webkit-scrollbar {
                display: none;
            }
        </style>
    </div>


    <?php $this->end(); ?>

    <?php $this->start('scripts'); ?>
    <script src="/assets/js/player-mobile-ui.js?v=<?= time() ?>"></script>
    <script src="/assets/js/player-mobile-batch-thumbnails.js?v=<?= time() ?>"></script>

    <div id="zd-debug-console" style="position:fixed; top:0; left:0; width:100%; background:rgba(255,0,0,0.9); color:#fff; font-size:10px; z-index:9999; pointer-events:none; max-height:100px; overflow:auto; display:none; padding:4px; font-family:monospace;"></div>
    <script>
        window.onerror = function(msg, url, line) {
            const consoleEl = document.getElementById('zd-debug-console');
            consoleEl.style.display = 'block';
            consoleEl.innerText += `\nERR: ${msg} (Line: ${line})`;
            return false;
        };
    </script>

    <script>
        // INITIAL LOAD SCROLL - Fast, instant, no visible animation
        document.addEventListener('DOMContentLoaded', () => {
            const activeClip = document.querySelector('.mobile-clip-link.is-active');
            if (!activeClip) return;

            activeClip.scrollIntoView({
                behavior: 'instant', // No smooth animation
                block: 'center'
            });
        });
    </script>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const SORT_KEY = 'mobile_clip_sort_mode';
            const DIR_KEY = 'mobile_clip_sort_dir';

            const sortToggle = document.getElementById('mobileSortToggle');
            const sortModeLabel = document.getElementById('sortModeLabel');
            const sortDirBtn = document.getElementById('mobileSortDir');
            const clipNav = document.querySelector('.mobile-clip-nav');

            if (!sortToggle || !sortDirBtn || !clipNav) return;

            const arrowUp = sortDirBtn.querySelector('.arrow-up');
            const arrowDown = sortDirBtn.querySelector('.arrow-down');

            // Sort modes
            const modes = ['Scene', 'Name'];
            let currentModeIndex = 0;

            // Restore saved preferences
            const savedMode = sessionStorage.getItem(SORT_KEY) || 'scene';
            const savedDir = sessionStorage.getItem(DIR_KEY) || 'asc';

            if (savedMode === 'filename') {
                currentModeIndex = 1;
                sortModeLabel.textContent = 'Name';
            } else {
                currentModeIndex = 0;
                sortModeLabel.textContent = 'Scene';
            }

            sortDirBtn.dataset.dir = savedDir;
            updateArrows(savedDir);

            // Apply initial sort
            sortClips(savedMode, savedDir);

            // Sort function
            function sortClips(mode, direction) {
                const clips = Array.from(clipNav.querySelectorAll('.mobile-clip-link'));

                clips.sort((a, b) => {
                    let aVal, bVal;

                    if (mode === 'scene') {
                        // Sort by scene/slate/take
                        const aScene = (a.dataset.scene || '').toLowerCase();
                        const aSlate = (a.dataset.slate || '').toLowerCase();
                        const aTake = (a.dataset.take || '').toLowerCase();
                        const bScene = (b.dataset.scene || '').toLowerCase();
                        const bSlate = (b.dataset.slate || '').toLowerCase();
                        const bTake = (b.dataset.take || '').toLowerCase();

                        aVal = aScene + '/' + aSlate + '/' + aTake;
                        bVal = bScene + '/' + bSlate + '/' + bTake;
                    } else if (mode === 'filename') {
                        // Sort by filename
                        aVal = (a.dataset.filename || '').toLowerCase();
                        bVal = (b.dataset.filename || '').toLowerCase();
                    }

                    if (direction === 'asc') {
                        return aVal.localeCompare(bVal);
                    } else {
                        return bVal.localeCompare(aVal);
                    }
                });

                // Re-append in sorted order
                clips.forEach(clip => clipNav.appendChild(clip));

                // Scroll active clip back into view
                const activeClip = clipNav.querySelector('.mobile-clip-link.is-active');
                if (activeClip && clips.length > 1) {
                    setTimeout(() => {
                        activeClip.scrollIntoView({
                            behavior: 'smooth',
                            block: 'center'
                        });
                    }, 100);
                }
            }

            function updateArrows(direction) {
                if (direction === 'asc') {
                    arrowUp.setAttribute('opacity', '0.4');
                    arrowDown.setAttribute('opacity', '1');
                } else {
                    arrowUp.setAttribute('opacity', '1');
                    arrowDown.setAttribute('opacity', '0.4');
                }
            }

            // Handle sort mode toggle (cycles through modes)
            sortToggle.addEventListener('click', () => {
                currentModeIndex = (currentModeIndex + 1) % modes.length;
                const newLabel = modes[currentModeIndex];
                const newMode = currentModeIndex === 0 ? 'scene' : 'filename';

                sortModeLabel.textContent = newLabel;
                sessionStorage.setItem(SORT_KEY, newMode);

                const dir = sortDirBtn.dataset.dir;
                sortClips(newMode, dir);
            });

            // Handle direction toggle
            sortDirBtn.addEventListener('click', () => {
                const currentDir = sortDirBtn.dataset.dir;
                const newDir = currentDir === 'asc' ? 'desc' : 'asc';
                const mode = currentModeIndex === 0 ? 'scene' : 'filename';

                sortDirBtn.dataset.dir = newDir;
                updateArrows(newDir);
                sessionStorage.setItem(DIR_KEY, newDir);
                sortClips(mode, newDir);
            });
        });
    </script>

    <script src="/assets/js/player-mobile-ajax-navigation.js"></script>

    <?php $this->end(); ?>