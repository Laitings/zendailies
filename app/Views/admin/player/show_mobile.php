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
                    data-fps="<?= $clip['fps'] ?? '25' ?>"
                    data-fpsnum="<?= $clip['fps_num'] ?? '0' ?>"
                    data-fpsden="<?= $clip['fps_den'] ?? '0' ?>"
                    data-tc-start="<?= $clip['tc_start'] ?? '00:00:00:00' ?>">
                    <source src="<?= htmlspecialchars($proxy_url) ?>" type="video/mp4">
                </video>

                <div id="zdMask" class="zd-mask"></div>

                <div id="mobileUiOverlay" class="mobile-ui-overlay">
                    <div class="m-clip-info-overlay">
                        <h3><?= htmlspecialchars($clip['scene'] ?? 'Sc') ?> / <?= htmlspecialchars($clip['slate'] ?? '') ?> - <?= htmlspecialchars($clip['take'] ?? 'Tk') ?></h3>
                        <?php
                        // Fallback to file_name and ensure no warnings if keys are missing
                        $displayName = ($clip['file_name'] ?? '');
                        ?>
                        <div class="filename"><?= htmlspecialchars(pathinfo($displayName, PATHINFO_FILENAME)) ?></div>
                    </div>

                    <div class="m-play-trigger" id="mPlayTrigger">
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
                            <div class="m-right" style="position: relative;"> <button type="button" class="m-icon-btn" id="mVolBtn" title="Unmute">🔇</button>

                                <div style="position: relative; display: flex; align-items: center; justify-content: center;">
                                    <button type="button" class="m-icon-btn" id="mBlankBtn" title="Aspect Ratio">
                                        <svg viewBox="0 0 24 24" width="20" height="20" fill="white">
                                            <path d="M19 12h-2v3h-3v2h5v-5zM7 9h3V7H5v5h2V9zm14-6H3c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h18c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 16H3V5h18v14z" />
                                        </svg>
                                    </button>

                                    <div id="mBlankPicker" class="m-popover">
                                        <button onclick="setMobileBlanking('none')">Off</button>
                                        <button onclick="setMobileBlanking('2.39')">2.39</button>
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
        <?php if (!empty($comments)): ?>
            <div class="m-comments-list" id="mCommentsList" style="display: none;">
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
            </div>
        <?php endif; ?>

        <div class="mobile-nav-title">Clips in <?= htmlspecialchars($current_day_label ?? 'Day') ?></div>
        <div class="mobile-clip-nav">
            <?php foreach ($clip_list as $it):
                $isActive = ($it['clip_uuid'] === ($current_clip ?? ''));
                $href = "/admin/projects/{$project_uuid}/days/{$day_uuid}/player/{$it['clip_uuid']}";
                $itFileName = ($it['file_name'] ?? '');
            ?>
                <a href="<?= $href ?>"
                    class="mobile-clip-link <?= $isActive ? 'is-active' : '' ?>"
                    data-proxy="<?= htmlspecialchars($it['proxy_url'] ?? '') ?>"
                    data-scene="<?= htmlspecialchars($it['scene'] ?? 'Sc') ?>"
                    data-slate="<?= htmlspecialchars($it['slate'] ?? '') ?>"
                    data-take="<?= htmlspecialchars($it['take'] ?? 'Tk') ?>"
                    data-filename="<?= htmlspecialchars(pathinfo($itFileName, PATHINFO_FILENAME)) ?>"
                    data-fps="<?= $it['fps'] ?? '25' ?>"
                    data-tc-start="<?= $it['tc_start'] ?? '00:00:00:00' ?>">
                    <img src="<?= $it['poster_path'] ?? $placeholder_thumb_url ?>" class="m-thumb" alt="">
                    <div style="flex:1; min-width:0;">
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <div class="m-clip-title">
                                <?= htmlspecialchars($it['scene'] ?? 'Sc') ?> / <?= htmlspecialchars($it['slate'] ?? '') ?> - <?= htmlspecialchars($it['take'] ?? 'Tk') ?>
                            </div>

                            <div style="display: flex; gap: 8px; align-items: center;">
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

                        <div class="m-clip-meta">
                            <?php
                            // Use the null coalescing operator to avoid "Undefined array key" warnings
                            // and default to 'file_name' as 'original_filename' is not in the DB schema.
                            $clipName = ($it['file_name'] ?? '');
                            echo htmlspecialchars(pathinfo($clipName, PATHINFO_FILENAME));
                            ?>
                        </div>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>

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

<script>
    document.addEventListener('DOMContentLoaded', () => {
        // Find the active clip in the navigation list
        const activeClip = document.querySelector('.mobile-clip-link.is-active');
        if (activeClip) {
            // scrollIntoView with 'instant' prevents the visible sliding animation 
            // that causes the "scrolling down" look after the page loads.
            activeClip.scrollIntoView({
                behavior: 'auto',
                block: 'center'
            });
        }
    });
</script>

<?php $this->end(); ?>