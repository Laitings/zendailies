<?php

/** @var array $projects */ ?>
<?php $this->extend('layout/main'); ?>

<?php $this->start('head'); ?>
<?php $this->end(); ?>


<?php $this->start('content'); ?>

<div class="zd-page">
    <div class="zd-page-header">
        <h1>Projects</h1>
        <a href="/admin/projects/new" class="zd-btn zd-btn-primary">+ New Project</a>
    </div>

    <?php if (empty($projects)): ?>
        <div class="zd-empty">No projects yet.</div>
    <?php else: ?>
        <div class="zd-card-grid">
            <?php foreach ($projects as $p): ?>
                <?php
                $uuid    = $p['project_uuid'] ?? $p['id'] ?? '';
                $title   = $p['title'] ?? '';
                $status  = $p['status'] ?? '';
                $code    = $p['code'] ?? '';
                $createdRaw = $p['created_at'] ?? null;   // <--- add this line
                $createdLabel = $createdRaw
                    ? (new \DateTime($createdRaw))->format('d-m-Y')
                    : '—';
                ?>
                <div class="zd-card">
                    <!-- Full-card cover link -->
                    <a href="/admin/projects/<?= urlencode($uuid) ?>/days" class="zd-card-cover" aria-label="Open project: <?= htmlspecialchars($title) ?>"></a>

                    <div class="zd-card-head">
                        <div class="zd-card-title"><?= htmlspecialchars($title) ?></div>
                        <div class="zd-chip <?= $status === 'active' ? 'zd-chip-ok' : 'zd-chip-muted' ?>">
                            <?= htmlspecialchars($status) ?>
                        </div>
                    </div>

                    <div class="zd-card-meta">
                        <div><span class="zd-k">Code</span> <span class="zd-v"><?= htmlspecialchars($code) ?></span></div>
                        <div><span class="zd-k">Created</span> <span class="zd-v"><?= htmlspecialchars($createdLabel) ?></span></div>
                        <div><span class="zd-k">ID</span> <span class="zd-v mono"><?= htmlspecialchars($uuid) ?></span></div>
                    </div>

                    <!-- Bottom-right action icons -->
                    <div class="zd-card-icons">
                        <a href="/admin/projects/<?= urlencode($uuid) ?>/members"
                            class="icon-btn" title="Project members">
                            <svg class="iconic" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                                <path d="M1.5 6.5C1.5 3.46243 3.96243 1 7 1C10.0376 1 12.5 3.46243 12.5 6.5C12.5 9.53757 10.0376 12 7 12C3.96243 12 1.5 9.53757 1.5 6.5Z" fill="currentColor" />
                                <path d="M14.4999 6.5C14.4999 8.00034 14.0593 9.39779 13.3005 10.57C14.2774 11.4585 15.5754 12 16.9999 12C20.0375 12 22.4999 9.53757 22.4999 6.5C22.4999 3.46243 20.0375 1 16.9999 1C15.5754 1 14.2774 1.54153 13.3005 2.42996C14.0593 3.60221 14.4999 4.99966 14.4999 6.5Z" fill="currentColor" />
                                <path d="M0 18C0 15.7909 1.79086 14 4 14H10C12.2091 14 14 15.7909 14 18V22C14 22.5523 13.5523 23 13 23H1C0.447716 23 0 22.5523 0 22V18Z" fill="currentColor" />
                                <path d="M16 18V23H23C23.5522 23 24 22.5523 24 22V18C24 15.7909 22.2091 14 20 14H14.4722C15.4222 15.0615 16 16.4633 16 18Z" fill="currentColor" />
                            </svg>
                        </a>
                        <a href="/admin/projects/<?= urlencode($uuid) ?>/edit"
                            class="icon-btn" title="Edit project">
                            <img src="/assets/icons/pencil.svg" class="icon icon--accent icon--hoverwhite" alt="Edit project">
                        </a>
                    </div>
                </div>

            <?php endforeach; ?>

        </div>
    <?php endif; ?>
</div>

<?php $this->end(); ?>