<?php

/** @var array $projects */ ?>
<?php $this->extend('layout/main'); ?>

<?php $this->start('head'); ?>
<style>
    /* Projects grid: force proper layout */
    .zd-page .zd-card-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(min(300px, 100%), 1fr));
        gap: 24px;
        margin-top: 16px;
        width: 100%;
    }

    /* Reset any inherited card sizing */
    .zd-page .zd-card-grid .zd-card {
        margin: 0;
        max-width: none;
        min-width: 0;
        width: 100%;
        box-sizing: border-box;
        border-radius: 12px;
        overflow: hidden;
        background-clip: padding-box;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.28);
    }

    /* Card is the container; cover link sits on top */
    .zd-page .zd-card-grid .zd-card {
        position: relative;
        /* NEW: enable absolute children */
    }

    /* Full-card clickable cover (does not change layout) */
    .zd-card-cover {
        position: absolute;
        inset: 0;
        z-index: 1;
        /* sits above content but below icons */
        text-decoration: none;
        border-radius: inherit;
    }

    /* Hover effect on the card (tint + subtle outline + lift) */
    .zd-page .zd-card-grid .zd-card:hover {
        background: rgba(58, 160, 255, 0.05);
        box-shadow:
            0 6px 18px rgba(0, 0, 0, 0.45),
            0 0 0 1px var(--accent) inset;
        transform: scale(1.02);
        /* gentle zoom instead of lift */
        transition:
            transform 0.25s ease,
            box-shadow 0.25s ease,
            background 0.2s ease;
        isolation: isolate;

    }



    /* Bottom-right icon group (above the cover link) */
    .zd-card-icons {
        position: absolute;
        transform-origin: bottom right;
        will-change: transform;
        bottom: 12px;
        right: 12px;
        display: flex;
        gap: 10px;
        z-index: 2;
        /* ensure clickable above cover */
    }

    /* Icon buttons */
    .zd-card-icons .icon-btn {
        padding: 4px;
        border-radius: 8px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        transition: background 0.15s ease;
        background: transparent;
        border: 0;
    }

    .zd-card-icons .icon-btn:hover {
        background: rgba(255, 255, 255, 0.06);
    }

    /* Force icons to link blue, even if the SVG is black */
    .zd-card-icons .icon {
        width: 20px;
        height: 20px;
        display: block;
        filter: invert(61%) sepia(53%) saturate(2574%) hue-rotate(189deg) brightness(97%) contrast(101%);
    }

    .zd-card-icons .icon-btn:hover .icon {
        filter: brightness(1.3);
    }

    /* --- Icon color control --- */
    .icon--accent {
        filter: invert(61%) sepia(53%) saturate(2574%) hue-rotate(189deg) brightness(97%) contrast(101%);
        /* Zentropa blue */
        transition: filter 0.15s ease;
    }


    /* Inline SVG icons colored by currentColor */
    .iconic {
        width: 20px;
        height: 20px;
        display: block;
        color: var(--accent);
        /* base = link blue */
        transition: color 0.15s ease;
    }

    .icon-btn:hover .iconic {
        color: #fff;
        /* hover = white */
    }
</style>
<?php $this->end(); ?>


This keeps the change scoped
<?php $this->start('content'); ?>

<div class="zd-page">
    <div class="zd-page-header">
        <h1>Projects</h1>
        <a href="/admin/projects/new" class="zd-btn zd-btn-primary">New Project</a>
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
                $created = $p['created_at'] ?? '';
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
                        <div><span class="zd-k">Created</span> <span class="zd-v"><?= htmlspecialchars($created) ?></span></div>
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