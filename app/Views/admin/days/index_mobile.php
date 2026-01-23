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
</style>

<div class="mobile-days-page">
    <header class="mobile-days-header">
        <h1 id="mobilePageTitle">Shooting Days</h1>
    </header>

    <div class="mobile-view-toggle" style="display: flex; background: #13151b; border-radius: 8px; padding: 4px; margin: 0 0 20px 0; border: 1px solid #2a3342;">
        <button onclick="switchMobileTab('days')" id="btnTabDays" class="tab-btn" style="flex: 1; padding: 8px; border: none; border-radius: 6px; font-size: 11px; font-weight: 700; color: #fff; background: #2a3342; transition: all 0.2s;">DAYS</button>
        <button onclick="switchMobileTab('scenes')" id="btnTabScenes" class="tab-btn" style="flex: 1; padding: 8px; border: none; border-radius: 6px; font-size: 11px; font-weight: 700; color: #9ca3af; background: transparent; transition: all 0.2s;">SCENES</button>
    </div>

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

    <div id="mobileScenesContainer" style="display: none;">
        <div class="mobile-scene-grid" style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px;">
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

<script>
    function switchMobileTab(mode) {
        const days = document.getElementById('mobileDaysContainer');
        const scenes = document.getElementById('mobileScenesContainer');
        const btnDays = document.getElementById('btnTabDays');
        const btnScenes = document.getElementById('btnTabScenes');
        const titleEl = document.getElementById('mobilePageTitle');

        if (mode === 'days') {
            days.style.display = 'block';
            scenes.style.display = 'none';
            btnDays.style.background = '#2a3342';
            btnDays.style.color = '#fff';
            btnScenes.style.background = 'transparent';
            btnScenes.style.color = '#9ca3af';
            titleEl.textContent = 'Shooting Days';
        } else {
            days.style.display = 'none';
            scenes.style.display = 'block';
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



<?php $this->end(); ?>