<?php

/** @var \App\Support\View $this */
/** @var string $project_uuid */
/** @var array $project */
/** @var array $day */
/** @var int $clip_count */
/** @var string $csrf */

$this->extend('layout/main');

$this->start('head'); ?>
<title>Delete Day · <?= htmlspecialchars($project['title'] ?? 'Project') ?> · Zentropa Dailies</title>
<?php $this->end(); ?>

<?php $this->start('content'); ?>
<div class="zd-page-header">
    <h1 class="zd-title">Delete Shooting Day</h1>
    <div class="zd-subtitle">
        Project: <span class="zd-chip"><?= htmlspecialchars($project['title'] ?? '') ?></span>
        <span class="zd-dot">•</span>
        Day: <span class="zd-chip">
            <?= htmlspecialchars($day['title'] ?? ($day['shoot_date'] ?? $day['day_uuid'] ?? '')) ?>
        </span>
    </div>
</div>

<div class="zd-card">
    <div class="zd-card-header">
        <h2 class="zd-card-title">Are you sure?</h2>
    </div>
    <div class="zd-card-body">
        <p>This will delete the day and <strong><?= (int)$clip_count ?></strong> associated clip(s) from the database.</p>
        <p><em>Files on disk will ALSO be deleted.</em></p>

        <form method="post" action="/admin/projects/<?= htmlspecialchars($project_uuid) ?>/days/<?= htmlspecialchars($day['day_uuid']) ?>/delete" style="display:flex;gap:10px;align-items:center;margin-top:12px">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
            <button class="za-btn za-btn-danger" type="submit">Yes, delete day</button>
            <a class="za-btn" href="/admin/projects/<?= htmlspecialchars($project_uuid) ?>/days">Cancel</a>
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
        </form>
    </div>
</div>
<?php $this->end(); ?>