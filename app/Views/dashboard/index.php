<?php

/** @var array $projects */
/** @var bool  $isSuper */
$this->extend('layout/main');
$this->start('content'); ?>

<div class="zd-page">
    <div class="zd-page-header">
        <h1><?= $isSuper ? 'All Projects' : 'Your Projects' ?></h1>
    </div>

    <?php if (empty($projects)): ?>
        <div class="zd-empty">No projects available.</div>
    <?php else: ?>
        <div class="zd-card-grid">
            <?php foreach ($projects as $p):
                $uuid   = $p['id'] ?? $p['project_uuid'] ?? '';
                $title  = $p['title'] ?? '';
                $status = $p['status'] ?? '';
                $code   = $p['code'] ?? '';
            ?>
                <a class="zd-card" href="/projects/<?= urlencode($uuid) ?>/enter" style="text-decoration:none">
                    <div class="zd-card-head">
                        <div class="zd-card-title"><?= htmlspecialchars($title) ?></div>
                        <div class="zd-chip <?= $status === 'active' ? 'zd-chip-ok' : 'zd-chip-muted' ?>">
                            <?= htmlspecialchars($status) ?>
                        </div>
                    </div>
                    <div class="zd-card-meta">
                        <div><span class="zd-k">Code</span> <span class="zd-v"><?= htmlspecialchars($code) ?></span></div>
                        <div><span class="zd-k">ID</span> <span class="zd-v mono"><?= htmlspecialchars($uuid) ?></span></div>
                    </div>
                    <div class="zd-card-actions">
                        <span class="zd-link">Enter project →</span>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php $this->end(); ?>