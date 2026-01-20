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
        <h1>Shooting Days</h1>
        <div class="project-subtitle"><?= htmlspecialchars($project['title'] ?? '') ?></div>
    </header>

    <?php if (empty($days)): ?>
        <div style="text-align:center; padding:40px; color:#9ca3af;">
            No published days yet for this project.
        </div>
    <?php else: ?>
        <div class="mobile-day-list">
            <?php foreach ($days as $d):
                $dayUuid = $d['day_uuid'];
                // For mobile reviewers, we always go straight to the Player
                $viewUrl = "/admin/projects/{$project['project_uuid']}/days/{$dayUuid}/player";
                $dayLabel = $d['title'] ?: ('Day ' . $d['shoot_date']);
            ?>
                <a href="<?= htmlspecialchars($viewUrl) ?>" class="mobile-day-card">
                    <div class="mobile-day-info">
                        <div class="mobile-day-title"><?= htmlspecialchars($dayLabel) ?></div>
                        <div class="mobile-day-meta">
                            <?= htmlspecialchars($d['shoot_date']) ?>
                            <?= !empty($d['unit']) ? ' • ' . htmlspecialchars($d['unit']) : '' ?>
                        </div>
                    </div>

                    <div class="mobile-day-count">
                        <?= (int)$d['clip_count'] ?>
                    </div>

                    <div class="mobile-day-arrow">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="9 18 15 12 9 6"></polyline>
                        </svg>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php $this->end(); ?>