<?php

/** @var array $projects */
/** @var bool  $isSuper */
$this->extend('layout/main');
$this->start('content'); ?>

<?php if (!empty($_SESSION['flash_error'])): ?>
    <div style="background: rgba(231, 76, 60, 0.2); color: #e74c3c; padding: 15px; border-radius: 4px; margin-bottom: 20px; border: 1px solid #e74c3c;">
        <?= htmlspecialchars($_SESSION['flash_error']) ?>
    </div>
    <?php unset($_SESSION['flash_error']); ?>
<?php endif; ?>

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
                $createdAtRaw = $p['created_at'] ?? null;
                $createdAtLabel = $createdAtRaw
                    ? (new \DateTime($createdAtRaw))->format('d-m-Y') // e.g. 07-10-2025
                    : '—';
                $status = $p['status'] ?? '';
                $code   = $p['code'] ?? '';
            ?>
                <div class="zd-card">
                    <a class="zd-card-cover" href="/projects/<?= urlencode($uuid) ?>/enter" aria-label="Enter project: <?= htmlspecialchars($title) ?>"></a>
                    <div class="zd-card-head">
                        <div class="zd-card-title"><?= htmlspecialchars($title) ?></div>
                        <div class="zd-chip <?= $status === 'active' ? 'zd-chip-ok' : 'zd-chip-muted' ?>">
                            <?= htmlspecialchars($status) ?>
                        </div>
                    </div>
                    <div class="zd-card-meta">
                        <div><span class="zd-k">Created</span> <span class="zd-v"><?= htmlspecialchars($createdAtLabel) ?></span></div>
                        <div><span class="zd-k">Code</span> <span class="zd-v"><?= htmlspecialchars($code) ?></span></div>
                        <div><span class="zd-k">ID</span> <span class="zd-v mono"><?= htmlspecialchars($uuid) ?></span></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php $this->end(); ?>