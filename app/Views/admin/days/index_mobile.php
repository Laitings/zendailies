<?php

/** @var array $project */
/** @var array $days */
/** @var array $routes */
/** @var string $layout */

$this->extend($layout); // Extends layout/mobile
$this->start('content'); ?>

<style>
    .mobile-days-page {
        padding: 20px 16px;
    }

    .mobile-days-header {
        margin-bottom: 24px;
    }

    .mobile-days-header h1 {
        font-size: 20px;
        font-weight: 800;
        margin: 0;
        text-transform: uppercase;
        color: #fff;
    }

    .mobile-days-header .project-subtitle {
        font-size: 13px;
        color: #9ca3af;
        margin-top: 4px;
    }

    .mobile-day-list {
        display: flex;
        flex-direction: column;
        gap: 12px;
    }

    .mobile-day-card {
        display: flex;
        align-items: center;
        background: #18202b;
        border: 1px solid #2a3342;
        border-radius: 12px;
        padding: 16px;
        text-decoration: none;
        color: inherit;
    }

    .mobile-day-info {
        flex: 1;
        min-width: 0;
    }

    .mobile-day-title {
        font-size: 16px;
        font-weight: 700;
        color: var(--accent, #60a5fa);
        margin-bottom: 2px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .mobile-day-meta {
        font-size: 12px;
        color: #9ca3af;
    }

    .mobile-day-count {
        background: #0b0c10;
        padding: 4px 10px;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 700;
        color: #fff;
        margin-left: 12px;
    }

    .mobile-day-arrow {
        margin-left: 12px;
        opacity: 0.3;
    }

    /* Animation Wrapper */
    .mobile-view-viewport {
        overflow: hidden;
        width: 100%;
        position: relative;
    }

    .mobile-view-slider {
        display: flex;
        width: 200%;
        /* Two panels wide */
        transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        align-items: flex-start;
    }

    #mobileDaysContainer,
    #mobileScenesContainer {
        width: 50%;
        flex-shrink: 0;
    }

    /* States */
    .slide-scenes {
        transform: translateX(-50%);
    }

    .slide-days {
        transform: translateX(0%);
    }
</style>

<div class="mobile-days-page">
    <header class="mobile-days-header" style="display: flex; justify-content: space-between; align-items: center;">
        <div>
            <h1 id="mobilePageTitle" style="font-size: 20px; font-weight: 800; margin: 0; text-transform: uppercase; color: #fff;">Shooting Days</h1>
        </div>
        <div id="mobileSortBtn" style="display: flex; align-items: center; gap: 4px; color: var(--accent, #60a5fa);">
            <svg width="12" height="12" viewBox="0 0 12 12" fill="none" xmlns="http://www.w3.org/2000/svg" class="arrow-up" style="cursor: pointer; opacity: 0.4; transition: opacity 0.2s;">
                <path d="M6 2L6 10M6 2L3 5M6 2L9 5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
            <svg width="12" height="12" viewBox="0 0 12 12" fill="none" xmlns="http://www.w3.org/2000/svg" class="arrow-down" style="cursor: pointer; opacity: 1; transition: opacity 0.2s;">
                <path d="M6 10L6 2M6 10L3 7M6 10L9 7" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
            </svg>
        </div>
    </header>

    <div class="mobile-view-toggle" style="display: flex; background: #13151b; border-radius: 8px; padding: 4px; margin: 0 0 20px 0; border: 1px solid #2a3342;">
        <button onclick="switchMobileTab('days')" id="btnTabDays" class="tab-btn" style="flex: 1; padding: 8px; border: none; border-radius: 6px; font-size: 11px; font-weight: 700; color: #fff; background: #2a3342; transition: all 0.2s;">DAYS</button>
        <button onclick="switchMobileTab('scenes')" id="btnTabScenes" class="tab-btn" style="flex: 1; padding: 8px; border: none; border-radius: 6px; font-size: 11px; font-weight: 700; color: #9ca3af; background: transparent; transition: all 0.2s;">SCENES</button>
    </div>

    <div class="mobile-view-viewport">
        <div id="mobileViewSlider" class="mobile-view-slider slide-days">
            <div id="mobileDaysContainer">
                <?php if (empty($days)): ?>
                    <div style="text-align:center; padding:40px; color:#9ca3af;">No published days yet.</div>
                <?php else: ?>
                    <div class="mobile-day-list">
                        <?php foreach ($days as $d):
                            $dayUuid = $d['day_uuid'];
                            $viewUrl = "/admin/projects/{$project['project_uuid']}/days/{$dayUuid}/player";
                            $dayLabel = $d['title'] ?: ('Day ' . $d['shoot_date']);
                        ?>
                            <a href="<?= htmlspecialchars($viewUrl) ?>" class="mobile-day-card">
                                <div class="mobile-day-info">
                                    <div class="mobile-day-title"><?= htmlspecialchars($dayLabel) ?></div>
                                    <div class="mobile-day-meta"><?= htmlspecialchars($d['shoot_date']) ?></div>
                                </div>
                                <div class="mobile-day-count"><?= (int)$d['clip_count'] ?></div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <div id="mobileScenesContainer">
                <div class="mobile-scene-grid" style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; padding-bottom: 20px;">
                    <?php if (isset($scenes) && !empty($scenes)): ?>
                        <?php foreach ($scenes as $s):
                            $sceneUrl = "/admin/projects/{$project['project_uuid']}/overview?scene=" . urlencode($s['scene']);
                        ?>
                            <a href="<?= htmlspecialchars($sceneUrl) ?>" class="scene-card" style="text-decoration: none; color: inherit; background: #18202b; border: 1px solid #2a3342; border-radius: 12px; overflow: hidden; display: flex; flex-direction: column;">
                                <div class="scene-thumb" style="aspect-ratio: 16/9; position: relative; background: #000;">
                                    <img src="<?= htmlspecialchars($s['thumb_url'] ?: $placeholder_thumb_url) ?>" style="width: 100%; height: 100%; object-fit: cover;">
                                    <div style="position: absolute; top: 8px; left: 8px; background: #3aa0ff; color: #000; padding: 2px 6px; border-radius: 4px; font-size: 10px; font-weight: 800;">
                                        SC. <?= htmlspecialchars($s['scene']) ?>
                                    </div>
                                </div>
                                <div style="padding: 10px;">
                                    <div style="font-size: 11px; color: #fff; font-weight: 600;"><?= (int)$s['clip_count'] ?> Clips</div>
                                    <div style="font-size: 10px; color: #9ca3af;">Dur: <?= htmlspecialchars($s['total_hms']) ?></div>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div style="grid-column: 1/-1; text-align: center; padding: 40px; color: #9ca3af;">No scenes found.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <script>
        function switchMobileTab(mode) {
            const slider = document.getElementById('mobileViewSlider');
            const btnDays = document.getElementById('btnTabDays');
            const btnScenes = document.getElementById('btnTabScenes');
            const titleEl = document.getElementById('mobilePageTitle');

            if (mode === 'days') {
                slider.classList.remove('slide-scenes');
                slider.classList.add('slide-days');

                btnDays.style.background = '#2a3342';
                btnDays.style.color = '#fff';
                btnScenes.style.background = 'transparent';
                btnScenes.style.color = '#9ca3af';
                titleEl.textContent = 'Shooting Days';
            } else {
                slider.classList.remove('slide-days');
                slider.classList.add('slide-scenes');

                btnScenes.style.background = '#2a3342';
                btnScenes.style.color = '#fff';
                btnDays.style.background = 'transparent';
                btnDays.style.color = '#9ca3af';
                titleEl.textContent = 'Scenes';
            }
        }
    </script>

    <script>
        // On load, check if the URL told us to go to a specific tab
        document.addEventListener('DOMContentLoaded', () => {
            const urlParams = new URLSearchParams(window.location.search);
            const tab = urlParams.get('tab');

            if (tab === 'scenes') {
                switchMobileTab('scenes');
            } else {
                // Default to days if no param is set or it's 'days'
                switchMobileTab('days');
            }
        });
    </script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const DAYS_SORT_KEY = 'zd_days_sort';
            const SCENES_SORT_KEY = 'zd_scenes_sort';

            const sortBtn = document.getElementById('mobileSortBtn');
            const arrowUp = sortBtn.querySelector('.arrow-up');
            const arrowDown = sortBtn.querySelector('.arrow-down');

            let isDaysReversed = sessionStorage.getItem(DAYS_SORT_KEY) === 'reversed';
            let isScenesReversed = sessionStorage.getItem(SCENES_SORT_KEY) === 'reversed';
            let currentMode = 'days'; // Track which tab we're on

            // Update arrow states
            const updateArrows = () => {
                const isReversed = currentMode === 'days' ? isDaysReversed : isScenesReversed;
                if (isReversed) {
                    arrowUp.style.opacity = '1';
                    arrowDown.style.opacity = '0.4';
                } else {
                    arrowUp.style.opacity = '0.4';
                    arrowDown.style.opacity = '1';
                }
            };

            // Reverse a container's children
            const reverseContainer = (container) => {
                const items = Array.from(container.children);
                items.reverse().forEach(item => container.appendChild(item));
            };

            // Apply initial sort on page load
            const daysContainer = document.querySelector('.mobile-day-list');
            const scenesGrid = document.querySelector('.mobile-scene-grid');

            if (isDaysReversed && daysContainer) {
                reverseContainer(daysContainer);
            }
            if (isScenesReversed && scenesGrid) {
                reverseContainer(scenesGrid);
            }

            updateArrows();

            // Handle sort toggle
            const toggleSort = (e) => {
                e.preventDefault();
                e.stopPropagation();

                if (currentMode === 'days') {
                    isDaysReversed = !isDaysReversed;
                    sessionStorage.setItem(DAYS_SORT_KEY, isDaysReversed ? 'reversed' : 'normal');
                    if (daysContainer) reverseContainer(daysContainer);
                } else {
                    isScenesReversed = !isScenesReversed;
                    sessionStorage.setItem(SCENES_SORT_KEY, isScenesReversed ? 'reversed' : 'normal');
                    if (scenesGrid) reverseContainer(scenesGrid);
                }

                updateArrows();
            };

            arrowUp.addEventListener('click', toggleSort);
            arrowDown.addEventListener('click', toggleSort);

            // Update currentMode when switching tabs
            // Override the original switchMobileTab function
            const originalSwitch = window.switchMobileTab;
            window.switchMobileTab = function(mode) {
                originalSwitch(mode);
                currentMode = mode;
                updateArrows();
            };
        });
    </script>


    <?php $this->end(); ?>