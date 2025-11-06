<?php

/**
 * @var array $users
 */
?>

<?php $this->extend('layout/main'); ?>

<?php $this->start('head'); ?>
<style>
    /* Users grid same layout as projects */
    .zd-page .zd-card-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(min(300px, 100%), 1fr));
        gap: 24px;
        margin-top: 16px;
        width: 100%;
    }

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
        position: relative;
        isolation: isolate;
        transform-origin: center center;
        transition: transform 0.25s ease, box-shadow 0.25s ease, background 0.2s ease;
    }

    /* Hover zoom + outline */
    .zd-page .zd-card-grid .zd-card:hover {
        background: rgba(58, 160, 255, 0.05);
        box-shadow:
            0 6px 18px rgba(0, 0, 0, 0.45),
            0 0 0 1px var(--accent) inset;
        transform: scale(1.02);
    }

    .zd-card-cover {
        position: absolute;
        inset: 0;
        z-index: 1;
        text-decoration: none;
        border-radius: inherit;
    }

    /* Bottom-right icons */
    .zd-card-icons {
        position: absolute;
        bottom: 12px;
        right: 12px;
        display: flex;
        gap: 10px;
        z-index: 2;
        transform-origin: bottom right;
        will-change: transform;
    }

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

    /* Icons use currentColor system for blue→white hover */
    .iconic {
        width: 20px;
        height: 20px;
        display: block;
        color: var(--accent);
        transition: color 0.15s ease;
    }

    .icon-btn:hover .iconic {
        color: #fff;
    }
</style>
<?php $this->end(); ?>

<?php $this->start('content'); ?>

<div class="zd-page">
    <div class="zd-page-header">
        <h1>Users</h1>
        <a href="/admin/users/create" class="zd-btn zd-btn-primary">New User</a>
    </div>

    <?php if (empty($users)): ?>
        <div class="zd-empty">No users yet.</div>
    <?php else: ?>
        <div class="zd-card-grid">
            <?php foreach ($users as $u): ?>
                <?php
                $first  = trim($u['first_name'] ?? '');
                $last   = trim($u['last_name'] ?? '');
                $name   = trim(($first . ' ' . $last)) ?: '—';
                $email  = $u['account_email'] ?: ($u['person_email'] ?? '—');
                $phone  = $u['person_phone'] ?: '—';
                $role   = !empty($u['is_superuser']) ? 'superuser' : ($u['user_role'] ?? 'regular');
                $status = $u['status'] ?? 'unknown';
                $uuid   = $u['account_uuid'] ?? '';
                ?>
                <div class="zd-card">
                    <!-- Full-card clickable cover link -->
                    <a href="/admin/users/<?= htmlspecialchars($uuid, ENT_QUOTES, 'UTF-8') ?>/edit"
                        class="zd-card-cover" aria-label="Edit user <?= htmlspecialchars($name) ?>"></a>

                    <div class="zd-card-head">
                        <div class="zd-card-title"><?= htmlspecialchars($name) ?></div>
                        <div class="zd-chip <?= $role === 'superuser' ? 'zd-chip-ok' : 'zd-chip-muted' ?>">
                            <?= htmlspecialchars($role) ?>
                        </div>
                    </div>

                    <div class="zd-card-meta">
                        <div><span class="zd-k">Email</span> <span class="zd-v"><?= htmlspecialchars($email) ?></span></div>
                        <div><span class="zd-k">Phone</span> <span class="zd-v"><?= htmlspecialchars($phone) ?></span></div>
                        <div><span class="zd-k">Status</span> <span class="zd-v"><?= htmlspecialchars($status) ?></span></div>
                    </div>

                    <!-- Bottom-right action icons -->
                    <div class="zd-card-icons">
                        <a href="/admin/users/<?= htmlspecialchars($uuid, ENT_QUOTES, 'UTF-8') ?>/edit"
                            class="icon-btn" title="Edit user">
                            <svg class="iconic" viewBox="0 0 24 24" aria-hidden="true">
                                <path d="M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25zM20.71 7.04a1.004 1.004 0 0 0 0-1.42L18.37 3.3a1.004 1.004 0 0 0-1.42 0l-1.83 1.83 3.75 3.75 1.84-1.84z" fill="currentColor" />
                            </svg>
                        </a>
                    </div>
                </div>

            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php $this->end(); ?>


<?php $this->start('scripts'); ?>
<!-- (optional) future JS -->
<?php $this->end(); ?>