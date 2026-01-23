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
<style>
    /* --- Layout & Grid --- */
    .zd-page {
        max-width: 1000px;
        margin: 0 auto;
        padding: 25px 20px;
        color: var(--zd-text-main);
    }

    .zd-layout-grid {
        display: grid;
        grid-template-columns: 1fr 320px;
        gap: 20px;
        align-items: start;
    }

    /* --- Panels matching Edit User --- */
    .zd-panel {
        background: var(--zd-bg-panel);
        border: 1px solid var(--zd-border-subtle);
        border-radius: 6px;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.15);
        overflow: hidden;
        margin-bottom: 20px;
    }

    .zd-panel-header {
        background: linear-gradient(to bottom, #1c1f26, #171922);
        /* Subtle vertical depth */
        padding: 12px 16px;
        border-bottom: 1px solid var(--zd-border-subtle);
        font-size: 0.8rem;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        font-weight: 700;
        color: var(--zd-text-muted);
    }

    .zd-panel-body {
        padding: 20px;
    }

    /* --- Forms & Inputs --- */
    .zd-field {
        margin-bottom: 15px;
    }

    .zd-label {
        display: block;
        font-size: 10px;
        font-weight: 600;
        color: var(--zd-text-muted);
        text-transform: uppercase;
        letter-spacing: 0.05em;
        margin-bottom: 6px;
    }

    .zd-input,
    .zd-textarea {
        background: var(--zd-bg-input);
        border: 1px solid var(--zd-border-subtle);
        color: var(--zd-text-main);
        padding: 8px 12px;
        border-radius: 4px;
        font-size: 13px;
        width: 100%;
        box-sizing: border-box;
        transition: all 0.2s ease;
    }

    /* --- Input Enhancements --- */
    .zd-textarea {
        min-height: 150px;
        line-height: 1.6;
        resize: vertical;
    }

    /* Ensure the date input matches the vibe coding aesthetic */
    input[type="date"]::-webkit-calendar-picker-indicator {
        filter: invert(1);
        /* Makes the calendar icon white */
        opacity: 0.5;
        cursor: pointer;
    }

    .zd-input:focus,
    .zd-textarea:focus {
        outline: none;
        border-color: var(--zd-accent);
        box-shadow: 0 0 0 2px rgba(58, 160, 255, 0.15);
    }

    .zd-error {
        color: var(--zd-danger);
        font-size: 11px;
        margin-top: 5px;
    }

    /* --- Actions --- */
    .zd-actions {
        display: flex;
        gap: 10px;
        justify-content: flex-end;
        padding-top: 5px;
    }

    /* --- Restored Button Styles --- */
    .zd-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 8px 18px;
        border-radius: 4px;
        font-weight: 600;
        font-size: 13px;
        text-decoration: none;
        cursor: pointer;
        border: none;
        transition: all 0.2s ease;
    }

    .zd-btn-primary {
        background: var(--zd-accent);
        /* Zentropa Blue #3aa0ff */
        color: white;
    }

    .zd-btn-primary:hover {
        background: var(--zd-accent-hover);
        opacity: 0.9;
    }

    .zd-btn-ghost {
        background: transparent;
        color: var(--zd-text-muted);
        border: 1px solid var(--zd-border-subtle);
    }

    .zd-btn-ghost:hover {
        border-color: var(--zd-text-main);
        color: var(--zd-text-main);
        background: rgba(255, 255, 255, 0.05);
    }
</style>
<?php $this->end(); ?>

<?php $this->start('content'); ?>
<div class="zd-page">

    <div class="zd-header">
        <h1>Edit Shooting Day</h1>
        <a href="/admin/projects/<?= htmlspecialchars($project_uuid) ?>/days" class="zd-btn zd-btn-ghost"
            style="position: absolute; right: 0; top: -10px;"> Back to Days
        </a>
    </div>

    <form method="post" action="/admin/projects/<?= htmlspecialchars($project_uuid) ?>/days/<?= htmlspecialchars($day['day_uuid']) ?>/edit">
        <input type="hidden" name="_csrf" value="<?= \App\Support\Csrf::token() ?>">

        <div class="zd-layout-grid">
            <div class="zd-col-main">

                <?php if (!empty($errors['_general'])): ?>
                    <div class="zd-alert-danger" style="margin-bottom:20px;">
                        <?= htmlspecialchars($errors['_general']) ?>
                    </div>
                <?php endif; ?>

                <div class="zd-panel">
                    <div class="zd-panel-header">Day Identity & Details</div>
                    <div class="zd-panel-body">
                        <div class="zd-field">
                            <label class="zd-label">Title</label>
                            <input type="text" name="title" maxlength="120"
                                value="<?= htmlspecialchars($old['title'] ?? ($day['title'] ?? '')) ?>"
                                class="zd-input" placeholder="e.g. DAY 01, 2nd Unit…">
                        </div>

                        <div class="zd-field">
                            <label class="zd-label">Production Notes</label>
                            <textarea name="notes" rows="6" class="zd-textarea"
                                placeholder="Add any specific context for this day's footage..."><?= htmlspecialchars($old['notes'] ?? ($day['notes'] ?? '')) ?></textarea>
                        </div>
                    </div>
                </div>

                <div class="zd-actions">
                    <a class="zd-btn zd-btn-ghost" href="/admin/projects/<?= htmlspecialchars($project_uuid) ?>/days">Cancel</a>
                    <button class="zd-btn zd-btn-primary" type="submit">Save Changes</button>
                </div>
            </div>

            <div class="zd-col-sidebar">
                <div class="zd-panel">
                    <div class="zd-panel-header">Scheduling & Unit</div>
                    <div class="zd-panel-body">
                        <div class="zd-field">
                            <label class="zd-label">Shoot Date *</label>
                            <input type="date" name="shoot_date"
                                value="<?= htmlspecialchars($old['shoot_date'] ?? ($day['shoot_date'] ?? '')) ?>"
                                required class="zd-input">
                            <?php if (!empty($errors['shoot_date'])): ?>
                                <div class="zd-error"><?= htmlspecialchars($errors['shoot_date']) ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="zd-field">
                            <label class="zd-label">Unit</label>
                            <input type="text" name="unit"
                                value="<?= htmlspecialchars($old['unit'] ?? ($day['unit'] ?? '')) ?>"
                                class="zd-input" placeholder="e.g. Main Unit">
                        </div>
                    </div>
                </div>

                <div class="zd-panel">
                    <div class="zd-panel-header">Project Context</div>
                    <div class="zd-panel-body">
                        <div style="font-size: 12px; color: var(--zd-text-muted); line-height: 1.6;">
                            Editing day for project:<br>
                            <strong style="color: var(--zd-text-main);"><?= htmlspecialchars($project['title']) ?></strong><br>
                            <span class="zd-cell-mono" style="font-size:11px; opacity:0.7;"><?= htmlspecialchars($project['code']) ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>
<?php $this->end(); ?>