<?php

/** @var \App\Support\View $this */
/** @var array $project */
/** @var string $project_uuid */
/** @var array $errors */
/** @var array $old */

$this->extend('layout/main');

$this->start('head'); ?>
<title><?= htmlspecialchars($project['title'] ?? 'Project') ?> · New Day · Zentropa Dailies</title>
<style>
    /* --- ZD Pro Theme Variables --- */
    :root {
        --zd-bg-page: #0b0c10;
        --zd-bg-panel: #13151b;
        --zd-bg-input: #08090b;

        --zd-border-subtle: #1f232d;
        --zd-border-focus: #3aa0ff;

        --zd-text-main: #eef1f5;
        --zd-text-muted: #8b9bb4;

        --zd-accent: #3aa0ff;
        --zd-danger: #e74c3c;
        --zd-success: #2ecc71;
    }

    /* --- Layout --- */
    .zd-page {
        max-width: 1000px;
        margin: 0 auto;
        padding: 25px 20px;
        color: var(--zd-text-main);
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
    }

    /* --- Header --- */
    .zd-header {
        margin-bottom: 20px;
    }

    .zd-header h1 {
        font-size: 20px;
        font-weight: 700;
        letter-spacing: -0.02em;
        color: var(--zd-text-main);
        line-height: 1;
        margin: 0 0 15px 0;
        padding-bottom: 15px;
        position: relative;
    }

    /* The Line */
    .zd-header h1::after {
        content: '';
        position: absolute;
        bottom: 0;
        left: 0;
        width: 100%;
        height: 2px;
        background: #2c3240;
        border-radius: 4px;
    }

    /* --- Grid Layout --- */
    .zd-layout-grid {
        display: grid;
        grid-template-columns: 1fr 320px;
        gap: 20px;
        align-items: start;
    }

    @media (max-width: 900px) {
        .zd-layout-grid {
            grid-template-columns: 1fr;
        }
    }

    /* --- Panels --- */
    .zd-panel {
        background: var(--zd-bg-panel);
        border: 1px solid var(--zd-border-subtle);
        border-radius: 6px;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.15);
        overflow: hidden;
        margin-bottom: 20px;
    }

    .zd-panel-header {
        background: #171922;
        padding: 10px 16px;
        border-bottom: 1px solid var(--zd-border-subtle);
        font-size: 0.8rem;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        font-weight: 600;
        color: var(--zd-text-muted);
    }

    .zd-panel-body {
        padding: 20px;
    }

    /* --- Forms --- */
    .zd-field-group {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 15px;
        margin-bottom: 15px;
    }

    .zd-field {
        margin-bottom: 15px;
    }

    .zd-field:last-child {
        margin-bottom: 0;
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
        padding: 8px 10px;
        border-radius: 4px;
        font-size: 13px;
        width: 100%;
        box-sizing: border-box;
        transition: all 0.2s ease;
        line-height: 1.4;
        font-family: inherit;
    }

    .zd-input:focus,
    .zd-textarea:focus {
        outline: none;
        border-color: var(--zd-border-focus);
        box-shadow: 0 0 0 2px rgba(58, 160, 255, 0.15);
    }

    .zd-textarea {
        resize: vertical;
        min-height: 100px;
    }

    /* --- Chips --- */
    .zd-chip {
        display: inline-block;
        padding: 2px 6px;
        border-radius: 3px;
        font-size: 10px;
        text-transform: uppercase;
        font-weight: 700;
        letter-spacing: 0.03em;
        background: #1f232d;
        color: var(--zd-text-muted);
        border: 1px solid #2c3240;
    }

    /* --- Actions --- */
    .zd-actions {
        display: flex;
        gap: 10px;
        justify-content: flex-end;
        padding-top: 5px;
    }

    .zd-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 8px 16px;
        border-radius: 4px;
        font-weight: 600;
        font-size: 12px;
        text-decoration: none;
        cursor: pointer;
        border: none;
        transition: all 0.2s;
    }

    .zd-btn-primary {
        background: var(--zd-accent);
        color: white;
    }

    .zd-btn-primary:hover {
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
    }

    /* --- Alerts --- */
    .zd-alert-danger {
        background: rgba(231, 76, 60, 0.1);
        border: 1px solid rgba(231, 76, 60, 0.3);
        color: #ffb0b0;
        padding: 12px 16px;
        border-radius: 4px;
        margin-bottom: 20px;
        font-size: 13px;
    }
</style>
<?php $this->end(); ?>

<?php $this->start('content'); ?>
<div class="zd-page">

    <div class="zd-header">
        <h1>New Shooting Day</h1>
    </div>

    <?php if (!empty($errors['_general'])): ?>
        <div class="zd-alert-danger"><?= htmlspecialchars($errors['_general']) ?></div>
    <?php endif; ?>

    <form method="post" action="/admin/projects/<?= htmlspecialchars($project_uuid) ?>/days">

        <div class="zd-layout-grid">

            <div class="zd-col-main">
                <div class="zd-panel">
                    <div class="zd-panel-header">Day Information</div>
                    <div class="zd-panel-body">

                        <div class="zd-field-group">
                            <div>
                                <label class="zd-label">Title (Optional)</label>
                                <input type="text" name="title" maxlength="120"
                                    value="<?= htmlspecialchars($old['title'] ?? '') ?>"
                                    class="zd-input" placeholder="e.g. DAY 01, 2nd Unit...">
                            </div>
                            <div>
                                <label class="zd-label">Unit</label>
                                <input type="text" name="unit"
                                    value="<?= htmlspecialchars($old['unit'] ?? '') ?>"
                                    class="zd-input" placeholder="e.g. Main Unit">
                            </div>
                        </div>

                        <div class="zd-field">
                            <label class="zd-label">Shoot Date <span style="color:var(--zd-danger)">*</span></label>
                            <input type="date" name="shoot_date"
                                value="<?= htmlspecialchars($old['shoot_date'] ?? '') ?>"
                                required class="zd-input">
                            <?php if (!empty($errors['shoot_date'])): ?>
                                <div style="color:var(--zd-danger); font-size:11px; margin-top:4px;">
                                    <?= htmlspecialchars($errors['shoot_date']) ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="zd-field">
                            <label class="zd-label">Notes</label>
                            <textarea name="notes" rows="4" class="zd-textarea" placeholder="Optional internal notes..."><?= htmlspecialchars($old['notes'] ?? '') ?></textarea>
                        </div>

                    </div>
                </div>

                <div class="zd-actions">
                    <a class="zd-btn zd-btn-ghost" href="/admin/projects/<?= htmlspecialchars($project_uuid) ?>/days">Cancel</a>
                    <button class="zd-btn zd-btn-primary" type="submit">Create Day</button>
                </div>
            </div>

            <div class="zd-col-sidebar">
                <div class="zd-panel">
                    <div class="zd-panel-header">Project Context</div>
                    <div class="zd-panel-body">
                        <div class="zd-field" style="margin-bottom:12px;">
                            <label class="zd-label">Project</label>
                            <div style="font-size:13px; font-weight:600;"><?= htmlspecialchars($project['title'] ?? '') ?></div>
                        </div>
                        <?php if (!empty($project['code'])): ?>
                            <div class="zd-field">
                                <label class="zd-label">Code</label>
                                <span class="zd-chip"><?= htmlspecialchars($project['code']) ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

        </div>
    </form>
</div>
<?php $this->end(); ?>

<?php $this->start('scripts'); ?>
<?php $this->end(); ?>