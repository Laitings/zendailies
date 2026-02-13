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
    /* --- ZD Professional Theme Variables --- */
    :root {
        --zd-bg-page: #0b0c10;
        --zd-bg-panel: #13151b;
        --zd-bg-input: #08090b;
        --zd-border-subtle: #1f232d;
        --zd-border-focus: #3aa0ff;
        --zd-text-main: #eef1f5;
        --zd-text-muted: #8b9bb4;
        --zd-accent: #3aa0ff;
        --zd-accent-hover: #2b8ce0;
        --zd-danger: #e74c3c;
        --zd-success: #2ecc71;
    }

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

    /* --- Panel / Bounding Box --- */
    .zd-panel {
        background: var(--zd-bg-panel);
        border: 1px solid var(--zd-border-subtle);
        border-radius: 6px;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.15);
        overflow: hidden;
        margin-bottom: 20px;
    }

    .zd-panel-header {
        padding: 15px 20px;
        border-bottom: 1px solid var(--zd-border-subtle);
        font-size: 13px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        color: var(--zd-text-muted);
        background: rgba(255, 255, 255, 0.02);
    }

    .zd-panel-body {
        padding: 20px;
    }

    /* --- Form Elements --- */
    .zd-field {
        margin-bottom: 20px;
    }

    .zd-label {
        display: block;
        font-size: 11px;
        font-weight: 700;
        text-transform: uppercase;
        color: var(--zd-text-muted);
        margin-bottom: 8px;
    }

    .zd-input {
        width: 100%;
        background: var(--zd-bg-input);
        border: 1px solid var(--zd-border-subtle);
        border-radius: 4px;
        padding: 10px 12px;
        color: var(--zd-text-main);
        font-size: 14px;
        transition: all 0.2s;
        box-sizing: border-box;
    }

    .zd-input:focus {
        outline: none;
        border-color: var(--zd-border-focus);
        background: #0d0f12;
    }

    .zd-error {
        color: var(--zd-danger);
        font-size: 12px;
        margin-top: 6px;
        font-weight: 600;
    }

    /* --- Buttons --- */
    .zd-btn-primary {
        background: var(--zd-accent);
        color: #fff;
        border: none;
        padding: 10px 20px;
        border-radius: 4px;
        font-weight: 700;
        font-size: 13px;
        cursor: pointer;
        transition: background 0.2s;
    }

    .zd-btn-primary:hover {
        background: var(--zd-accent-hover);
    }

    .zd-btn-secondary {
        background: transparent;
        color: var(--zd-text-muted);
        border: 1px solid var(--zd-border-subtle);
        padding: 9px 18px;
        border-radius: 4px;
        font-weight: 600;
        font-size: 13px;
        text-decoration: none;
        transition: all 0.2s;
    }

    .zd-btn-secondary:hover {
        color: var(--zd-text-main);
        border-color: var(--zd-text-muted);
    }

    /* Target the calendar picker indicator specifically */
    input[type="date"]::-webkit-calendar-picker-indicator {
        /* Hide default icon */
        -webkit-appearance: none;

        /* Insert the SVG as a background image with the correct Zentropa Blue/Gray color */
        /* Note: %23 is the encoded # character for the color #8b9bb4 */
        background-image: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="%238b9bb4"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5m-9-6h.008v.008H12v-.008ZM12 15h.008v.008H12V15Zm0 2.25h.008v.008H12v-.008ZM9.75 15h.008v.008H9.75V15Zm0 2.25h.008v.008H9.75v-.008ZM7.5 15h.008v.008H7.5V15Zm0 2.25h.008v.008H7.5v-.008Zm6.75-4.5h.008v.008h-.008v-.008Zm0 2.25h.008v.008h-.008V15Zm0 2.25h.008v.008h-.008v-.008Zm2.25-4.5h.008v.008H16.5v-.008Zm0 2.25h.008v.008H16.5V15Z" /></svg>');

        background-repeat: no-repeat;
        background-position: center;
        background-size: 18px;
        width: 20px;
        height: 20px;
        cursor: pointer;
        opacity: 0.6;
        transition: opacity 0.2s, filter 0.2s;
    }

    input[type="date"]::-webkit-calendar-picker-indicator:hover {
        opacity: 1;
        /* Optional: Brighten to pure white on hover */
        filter: brightness(1.5);
    }

    /* Important: Keeps the actual browser popup calendar in dark mode */
    input[type="date"] {
        color-scheme: dark !important;
    }
</style>
<?php $this->end(); ?>

<?php $this->start('content'); ?>
<div class="zd-page">
    <form action="/admin/projects/<?= $project_uuid ?>/days/<?= $day['day_uuid'] ?>/edit" method="POST">

        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px;">
            <div>
                <div style="font-size: 11px; font-weight: 700; color: var(--zd-accent); text-transform: uppercase; margin-bottom: 4px;">
                    Editing Shooting Day
                </div>
                <h1 style="font-size: 22px; font-weight: 800; margin: 0;">
                    <?= htmlspecialchars($day['title']) ?>
                </h1>
            </div>
            <div style="display: flex; gap: 10px;">
                <a href="/admin/projects/<?= $project_uuid ?>/days" class="zd-btn-secondary">Cancel</a>
                <button type="submit" class="zd-btn-primary">Save Changes</button>
            </div>
        </div>

        <div class="zd-layout-grid">
            <div class="zd-col-main">
                <div class="zd-panel">
                    <div class="zd-panel-header">General Information</div>
                    <div class="zd-panel-body">

                        <div class="zd-field">
                            <label class="zd-label">Day Title</label>
                            <input type="text" name="title"
                                value="<?= htmlspecialchars($old['title'] ?? ($day['title'] ?? '')) ?>"
                                required class="zd-input">
                            <?php if (!empty($errors['title'])): ?>
                                <div class="zd-error"><?= htmlspecialchars($errors['title']) ?></div>
                            <?php endif; ?>
                        </div>

                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                            <div class="zd-field">
                                <label class="zd-label">Shoot Date</label>
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
                </div>
            </div>

            <div class="zd-col-sidebar">
                <div class="zd-panel">
                    <div class="zd-panel-header">Project Context</div>
                    <div class="zd-panel-body">
                        <div style="font-size: 12px; color: var(--zd-text-muted); line-height: 1.6;">
                            This day belongs to:<br>
                            <strong style="color: var(--zd-text-main); display: block; margin-top: 5px;">
                                <?= htmlspecialchars($project['title']) ?>
                            </strong>
                            <div style="font-family: monospace; font-size: 10px; margin-top: 8px; opacity: 0.6; text-transform: uppercase;">
                                Code: <?= htmlspecialchars($project['code']) ?>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="zd-panel">
                    <div class="zd-panel-header">Stats</div>
                    <div class="zd-panel-body">
                        <div style="font-size: 12px; color: var(--zd-text-muted);">
                            Clips: <span style="color: var(--zd-text-main); font-weight: 600;"><?= (int)($day['clip_count'] ?? 0) ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </form>
</div>
<?php $this->end(); ?>