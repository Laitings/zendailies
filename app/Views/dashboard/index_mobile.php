<?php

/** @var array $projects */
/** @var bool  $isSuper */
/** @var string $layout */

$this->extend($layout); // Extends layout/mobile
$this->start('content'); ?>

<style>
    .mobile-dash-page {
        padding: 20px 16px;
    }

    .mobile-dash-header h1 {
        font-size: 20px;
        font-weight: 800;
        margin: 0 0 20px 0;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: #fff;
    }

    .mobile-project-list {
        display: flex;
        flex-direction: column;
        gap: 16px;
    }

    .mobile-project-card {
        display: block;
        background: #18202b;
        border: 1px solid #2a3342;
        border-radius: 12px;
        padding: 16px;
        text-decoration: none;
        color: inherit;
        position: relative;
        transition: background 0.2s;
    }

    .mobile-project-card:active {
        background: #232d3b;
    }

    .mobile-card-title {
        font-size: 16px;
        font-weight: 700;
        margin-bottom: 4px;
        color: var(--accent, #60a5fa);
    }

    .mobile-card-meta {
        font-size: 12px;
        color: #9ca3af;
        display: flex;
        justify-content: space-between;
    }

    .mobile-status-pill {
        font-size: 10px;
        padding: 2px 6px;
        border-radius: 4px;
        text-transform: uppercase;
        font-weight: 700;
        background: #0b0c10;
    }
</style>

<div class="mobile-dash-page">
    <div class="mobile-dash-header">
        <h1><?= $isSuper ? 'All Projects' : 'Your Projects' ?></h1>
    </div>

    <?php if (empty($projects)): ?>
        <div style="text-align:center; padding:40px; color:#9ca3af;">No projects available.</div>
    <?php else: ?>
        <div class="mobile-project-list">
            <?php foreach ($projects as $p):
                $uuid  = $p['id'] ?? $p['project_uuid'] ?? '';
                $title = $p['title'] ?? '';
                $code  = $p['code'] ?? '';
                $status = $p['status'] ?? '';
            ?>
                <a href="/projects/<?= urlencode($uuid) ?>/enter" class="mobile-project-card">
                    <div class="mobile-card-title"><?= htmlspecialchars($title) ?></div>
                    <div class="mobile-card-meta">
                        <span>Code: <?= htmlspecialchars($code) ?></span>
                        <span class="mobile-status-pill"><?= htmlspecialchars($status) ?></span>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php $this->end(); ?>