<?php

/** @var \App\Support\View $this */
/** @var array $project */
/** @var string $project_uuid */
/** @var array $errors */
/** @var array $old */

$this->extend('layout/main');

$this->start('head'); ?>
<title><?= htmlspecialchars($project['title'] ?? 'Project') ?> · New Day · Zentropa Dailies</title>
<?php $this->end(); ?>

<?php $this->start('content'); ?>
<div class="zd-page-header">
    <h1 class="zd-title">New Shooting Day</h1>
    <div class="zd-subtitle">
        Project: <span class="zd-chip"><?= htmlspecialchars($project['title'] ?? '') ?></span>
        <?php if (!empty($project['code'])): ?>
            <span class="zd-dot">•</span> Code: <span class="zd-chip"><?= htmlspecialchars($project['code']) ?></span>
        <?php endif; ?>
    </div>
</div>

<div class="zd-card">
    <div class="zd-card-header">
        <h2 class="zd-card-title">Create Day</h2>
    </div>
    <form method="post" action="/admin/projects/<?= htmlspecialchars($project_uuid) ?>/days" class="zd-form" style="display:grid;gap:12px;max-width:520px">
        <?php if (!empty($errors['_general'])): ?>
            <div class="za-alert za-alert-danger"><?= htmlspecialchars($errors['_general']) ?></div>
        <?php endif; ?>
        <label class="za-field">
            <div class="za-label">Title (optional)</div>
            <input type="text" name="title" maxlength="120"
                value="<?= htmlspecialchars($old['title'] ?? '') ?>"
                class="za-input" placeholder="DAY 01, 2nd Unit, Birthday shoot…">
        </label>
        <label class="za-field">
            <div class="za-label">Shoot date *</div>
            <input type="date" name="shoot_date" value="<?= htmlspecialchars($old['shoot_date'] ?? '') ?>" required class="za-input">
            <?php if (!empty($errors['shoot_date'])): ?>
                <div class="za-error"><?= htmlspecialchars($errors['shoot_date']) ?></div>
            <?php endif; ?>
        </label>
        <label class="za-field">
            <div class="za-label">Unit</div>
            <input type="text" name="unit" value="<?= htmlspecialchars($old['unit'] ?? '') ?>" class="za-input" placeholder="Main Unit">
        </label>
        <label class="za-field">
            <div class="za-label">Notes</div>
            <textarea name="notes" rows="4" class="za-input" placeholder="Optional"><?= htmlspecialchars($old['notes'] ?? '') ?></textarea>
        </label>

        <div style="display:flex;gap:8px;margin-top:8px">
            <button class="za-btn za-btn-primary" type="submit">Create day</button>
            <a class="za-btn" href="/admin/projects/<?= htmlspecialchars($project_uuid) ?>/days">Cancel</a>
        </div>
    </form>
</div>
<?php $this->end(); ?>

<?php $this->start('scripts'); ?>
<!-- reserved -->
<?php $this->end(); ?>