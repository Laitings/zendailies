<?php

/** @var \App\Support\View $this */
/** @var array $project */
/** @var string $project_uuid */
/** @var array $day */
/** @var array $errors */
/** @var array $old */

$this->extend('layout/main');

$this->start('head'); ?>
<title><?= htmlspecialchars($project['title'] ?? 'Project') ?> · Edit Day · Zentropa Dailies</title>
<?php $this->end(); ?>

<?php $this->start('content'); ?>
<header class="zd-page-header" style="margin-bottom:12px">
    <h1 class="zd-title" style="margin:0 0 6px 0;">Edit Shooting Day</h1>
    <p class="zd-subtitle" style="color:var(--muted);margin:0 0 8px 0">
        Project: <span class="zd-chip"><?= htmlspecialchars($project['title'] ?? '') ?></span>
        <?php if (!empty($project['code'])): ?>
            <span class="zd-dot">•</span> Code: <span class="zd-chip"><?= htmlspecialchars($project['code']) ?></span>
        <?php endif; ?>
    </p>
</header>

<div class="zd-card" style="max-width:520px">
    <div class="zd-card-header">
        <h2 class="zd-card-title">Update Day</h2>
    </div>

    <?php if (!empty($errors['_general'])): ?>
        <div class="za-alert za-alert-danger"><?= htmlspecialchars($errors['_general']) ?></div>
    <?php endif; ?>

    <form method="post"
        action="/admin/projects/<?= htmlspecialchars($project_uuid) ?>/days/<?= htmlspecialchars($day['day_uuid']) ?>/edit"
        class="zd-form" style="display:grid;gap:12px">
        <label class="za-field">
            <div class="za-label">Title (optional)</div>
            <input type="text" name="title" maxlength="120"
                value="<?= htmlspecialchars($old['title'] ?? ($day['title'] ?? '')) ?>"
                class="za-input" placeholder="DAY 01, 2nd Unit, Birthday shoot…">
        </label>

        <label class="za-field">
            <div class="za-label">Shoot date *</div>
            <input type="date" name="shoot_date"
                value="<?= htmlspecialchars($old['shoot_date'] ?? ($day['shoot_date'] ?? '')) ?>"
                required class="za-input">
            <?php if (!empty($errors['shoot_date'])): ?>
                <div class="za-error"><?= htmlspecialchars($errors['shoot_date']) ?></div>
            <?php endif; ?>
        </label>

        <label class="za-field">
            <div class="za-label">Unit</div>
            <input type="text" name="unit"
                value="<?= htmlspecialchars($old['unit'] ?? ($day['unit'] ?? '')) ?>"
                class="za-input" placeholder="Main Unit">
        </label>

        <label class="za-field">
            <div class="za-label">Notes</div>
            <textarea name="notes" rows="4" class="za-input" placeholder="Optional"><?= htmlspecialchars($old['notes'] ?? ($day['notes'] ?? '')) ?></textarea>
        </label>

        <div style="display:flex;gap:8px;margin-top:8px">
            <button class="za-btn za-btn-primary" type="submit">Save changes</button>
            <a class="za-btn" href="/admin/projects/<?= htmlspecialchars($project_uuid) ?>/days">Cancel</a>
        </div>
    </form>
</div>
<?php $this->end(); ?>

<?php $this->start('scripts'); ?>
<!-- reserved -->
<?php $this->end(); ?>