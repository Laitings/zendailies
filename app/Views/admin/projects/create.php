<?php

/** @var \App\Support\View $this */
/** @var array $old */
/** @var array $errors */
/** @var string $csrf */

$old = $old ?? ['title' => '', 'code' => '', 'status' => 'active'];
$errors = $errors ?? [];
?>

<?php $this->extend('layout/main'); ?>

<?php $this->start('head'); ?>
<title>New Project · Zentropa Dailies</title>
<style>
    /* --- ZD Pro Theme --- */
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
    }

    /* Layout */
    .zd-page {
        max-width: 1000px;
        margin: 0 auto;
        padding: 25px 20px;
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        color: var(--zd-text-main);
    }

    /* Header */
    .zd-header {
        margin-bottom: 20px;
        position: relative;
    }

    .zd-header h1 {
        font-size: 20px;
        font-weight: 700;
        letter-spacing: -0.01em;
        color: var(--zd-text-main);
        line-height: 1;
        margin: 0 0 15px 0;
        padding-bottom: 15px;
        position: relative;
    }

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

    /* Grid */
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

    /* Panels */
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

    /* Inputs */
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
    .zd-select {
        background: var(--zd-bg-input);
        border: 1px solid var(--zd-border-subtle);
        color: var(--zd-text-main);
        padding: 8px 10px;
        border-radius: 4px;
        font-size: 13px;
        width: 100%;
        box-sizing: border-box;
        transition: all 0.2s;
    }

    .zd-input:focus,
    .zd-select:focus {
        outline: none;
        border-color: var(--zd-border-focus);
        box-shadow: 0 0 0 2px rgba(58, 160, 255, 0.15);
    }

    .font-mono {
        font-family: 'Menlo', monospace;
        letter-spacing: 0.05em;
    }

    /* Buttons */
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

    /* Errors */
    .zd-alert-danger {
        background: rgba(231, 76, 60, 0.1);
        border: 1px solid rgba(231, 76, 60, 0.3);
        color: #ffb0b0;
        padding: 12px 16px;
        border-radius: 4px;
        margin-bottom: 20px;
        font-size: 13px;
    }

    .zd-err-msg {
        color: var(--zd-danger);
        font-size: 11px;
        margin-top: 4px;
        display: block;
    }
</style>
<?php $this->end(); ?>

<?php $this->start('content'); ?>
<div class="zd-page">

    <div class="zd-header">
        <h1>Create Project</h1>

    </div>

    <?php if (!empty($errors['global'])): ?>
        <div class="zd-alert-danger"><?= htmlspecialchars($errors['global']) ?></div>
    <?php endif; ?>

    <form method="post" action="/admin/projects">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>" />

        <div class="zd-layout-grid">

            <div class="zd-col-main">
                <div class="zd-panel">
                    <div class="zd-panel-header">Project Details</div>
                    <div class="zd-panel-body">

                        <div class="zd-field">
                            <label class="zd-label">Title <span style="color:var(--zd-danger)">*</span></label>
                            <input class="zd-input" name="title" value="<?= htmlspecialchars($old['title']) ?>" required autofocus>
                            <?php if (isset($errors['title'])): ?>
                                <span class="zd-err-msg"><?= htmlspecialchars($errors['title']) ?></span>
                            <?php endif; ?>
                        </div>

                        <div class="zd-field">
                            <label class="zd-label">Code <span style="color:var(--zd-danger)">*</span></label>
                            <input class="zd-input font-mono" name="code" value="<?= htmlspecialchars($old['code']) ?>" placeholder="E.g. DOGMA25" required style="text-transform:uppercase;">
                            <?php if (isset($errors['code'])): ?>
                                <span class="zd-err-msg"><?= htmlspecialchars($errors['code']) ?></span>
                            <?php endif; ?>
                        </div>

                    </div>
                </div>

                <div class="zd-actions">
                    <a href="/admin/projects" class="zd-btn zd-btn-ghost">Cancel</a>
                    <button class="zd-btn zd-btn-primary">Create Project</button>
                </div>
            </div>

            <div class="zd-col-sidebar">
                <div class="zd-panel">
                    <div class="zd-panel-header">Settings</div>
                    <div class="zd-panel-body">
                        <div class="zd-field">
                            <label class="zd-label">Status</label>
                            <select class="zd-select" name="status">
                                <option value="active" <?= $old['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                                <option value="archived" <?= $old['status'] === 'archived' ? 'selected' : '' ?>>Archived</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="zd-panel">
                    <div class="zd-panel-header">Formatting</div>
                    <div class="zd-panel-body" style="font-size:12px; color:var(--zd-text-muted); line-height:1.5;">
                        <p style="margin-bottom:8px;"><strong>Project Code:</strong><br>
                            Used for file naming and URLs.</p>
                        <ul style="margin:0; padding-left:16px;">
                            <li>Uppercase letters & digits</li>
                            <li>No spaces (use _ or -)</li>
                            <li>2–32 characters</li>
                        </ul>
                    </div>
                </div>
            </div>

        </div>
    </form>
</div>
<?php $this->end(); ?>