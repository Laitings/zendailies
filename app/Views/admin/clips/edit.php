<?php

/** @var \App\Support\View $this */
/** @var array $project */
/** @var string $project_uuid */
/** @var string $day_uuid */
/** @var string $day_label */
/** @var array $clip */
/** @var array $meta_rows */
/** @var string $_csrf */

$this->extend('layout/main');
?>

<?php $this->start('head'); ?>
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
        --zd-success: #2ecc71;
    }

    /* --- Layout Structure --- */
    .zd-container {
        max-width: 1200px;
        margin: 0 auto;
        padding: 30px 20px;
        color: var(--zd-text-main);
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
    }

    .zd-header {
        margin-bottom: 24px;
        border-bottom: 1px solid var(--zd-border-subtle);
        padding-bottom: 20px;
        display: flex;
        justify-content: space-between;
        align-items: end;
    }

    .zd-header h1 {
        font-size: 24px;
        font-weight: 700;
        margin: 0 0 6px 0;
        letter-spacing: -0.02em;
    }

    .zd-breadcrumbs {
        color: var(--zd-text-muted);
        font-size: 13px;
        font-weight: 500;
    }

    /* The Main Grid: Left Content (Form) + Right Sidebar (Status) */
    .zd-layout-grid {
        display: grid;
        grid-template-columns: 1fr 340px;
        gap: 24px;
        align-items: start;
    }

    @media (max-width: 900px) {
        .zd-layout-grid {
            grid-template-columns: 1fr;
        }
    }

    /* --- Panels & Cards --- */
    .zd-panel {
        background: var(--zd-bg-panel);
        border: 1px solid var(--zd-border-subtle);
        border-radius: 8px;
        box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        overflow: hidden;
        margin-bottom: 24px;
    }

    .zd-panel-header {
        background: rgba(255, 255, 255, 0.03);
        padding: 12px 20px;
        border-bottom: 1px solid var(--zd-border-subtle);
        font-size: 12px;
        text-transform: uppercase;
        letter-spacing: 0.08em;
        font-weight: 700;
        color: var(--zd-text-muted);
    }

    .zd-panel-body {
        padding: 20px;
    }

    /* --- Form Elements --- */
    .zd-form-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
        gap: 20px;
    }

    .zd-form-full {
        grid-column: 1 / -1;
    }

    .zd-field {
        display: flex;
        flex-direction: column;
        gap: 8px;
    }

    .zd-label {
        font-size: 11px;
        font-weight: 600;
        color: var(--zd-text-muted);
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }

    .zd-input,
    .zd-select {
        background: var(--zd-bg-input);
        border: 1px solid var(--zd-border-subtle);
        color: var(--zd-text-main);
        padding: 10px 12px;
        border-radius: 6px;
        font-size: 14px;
        transition: all 0.2s ease;
        width: 100%;
        box-sizing: border-box;
    }

    .zd-input:focus,
    .zd-select:focus {
        outline: none;
        border-color: var(--zd-border-focus);
        box-shadow: 0 0 0 3px rgba(58, 160, 255, 0.15);
    }

    /* Monospace for technical data */
    .font-mono {
        font-family: 'JetBrains Mono', 'Menlo', 'Monaco', monospace;
        letter-spacing: -0.02em;
    }

    /* --- The Select Toggle Card --- */
    .zd-toggle-card {
        display: flex;
        align-items: center;
        gap: 12px;
        background: var(--zd-bg-input);
        border: 1px solid var(--zd-border-subtle);
        padding: 12px 16px;
        border-radius: 6px;
        cursor: pointer;
        transition: border-color 0.2s;
    }

    .zd-toggle-card:hover {
        border-color: var(--zd-text-muted);
    }

    .zd-checkbox {
        width: 18px;
        height: 18px;
        accent-color: var(--zd-success);
        cursor: pointer;
    }

    /* --- Metadata Table --- */
    .zd-meta-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 12px;
    }

    .zd-meta-table td {
        padding: 8px 0;
        border-bottom: 1px solid var(--zd-border-subtle);
    }

    .zd-meta-table tr:last-child td {
        border-bottom: none;
    }

    .zd-meta-key {
        color: var(--zd-text-muted);
        width: 40%;
    }

    .zd-meta-val {
        color: var(--zd-text-main);
        font-family: monospace;
    }

    /* --- Buttons --- */
    .zd-actions {
        display: flex;
        flex-direction: column;
        gap: 10px;
    }

    .zd-btn {
        display: flex;
        justify-content: center;
        align-items: center;
        width: 100%;
        padding: 12px;
        border-radius: 6px;
        font-weight: 600;
        font-size: 14px;
        border: none;
        cursor: pointer;
        transition: background 0.2s;
        text-decoration: none;
        box-sizing: border-box;
    }

    .zd-btn-primary {
        background: var(--zd-accent);
        color: #fff;
    }

    .zd-btn-primary:hover {
        background: var(--zd-accent-hover);
    }

    .zd-btn-secondary {
        background: transparent;
        border: 1px solid var(--zd-border-subtle);
        color: var(--zd-text-muted);
    }

    .zd-btn-secondary:hover {
        border-color: var(--zd-text-main);
        color: var(--zd-text-main);
    }
</style>
<title>Edit Clip · Zentropa Dailies</title>
<?php $this->end(); ?>

<?php $this->start('content'); ?>
<div class="zd-container">

    <div class="zd-header">
        <div>
            <h1>Edit Clip</h1>
            <div class="zd-breadcrumbs">
                <?= htmlspecialchars($project['title'] ?? 'Project') ?> /
                <?= htmlspecialchars($day_label ?: $day_uuid) ?> /
                Clip #<?= htmlspecialchars($clip['clip_uuid']) ?>
            </div>
        </div>
    </div>

    <form method="post" action="/admin/projects/<?= htmlspecialchars($project_uuid) ?>/days/<?= htmlspecialchars($day_uuid) ?>/clips/<?= htmlspecialchars($clip['clip_uuid']) ?>/edit" novalidate>
        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($_csrf) ?>">

        <div class="zd-layout-grid">

            <div class="zd-col-main">

                <div class="zd-panel">
                    <div class="zd-panel-header">Slate Identification</div>
                    <div class="zd-panel-body">
                        <div class="zd-form-grid">
                            <div class="zd-field">
                                <label class="zd-label">Scene</label>
                                <input class="zd-input font-mono" name="scene" value="<?= htmlspecialchars($clip['scene'] ?? '') ?>">
                            </div>
                            <div class="zd-field">
                                <label class="zd-label">Slate</label>
                                <input class="zd-input font-mono" name="slate" value="<?= htmlspecialchars($clip['slate'] ?? '') ?>">
                            </div>
                            <div class="zd-field">
                                <label class="zd-label">Take</label>
                                <input class="zd-input font-mono" name="take" value="<?= htmlspecialchars($clip['take'] ?? '') ?>">
                            </div>
                            <div class="zd-field">
                                <label class="zd-label">Camera</label>
                                <input class="zd-input" name="camera" value="<?= htmlspecialchars($clip['camera'] ?? '') ?>">
                            </div>
                            <div class="zd-field">
                                <label class="zd-label">Reel</label>
                                <input class="zd-input" name="reel" value="<?= htmlspecialchars($clip['reel'] ?? '') ?>">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="zd-panel">
                    <div class="zd-panel-header">File & Timecode</div>
                    <div class="zd-panel-body">
                        <div class="zd-form-grid">
                            <div class="zd-field zd-form-full">
                                <label class="zd-label">Source File Name</label>
                                <input class="zd-input font-mono" style="color: #9aa7b2;" name="file_name" value="<?= htmlspecialchars($clip['file_name'] ?? '') ?>">
                            </div>

                            <div class="zd-field">
                                <label class="zd-label">Timecode In</label>
                                <input class="zd-input font-mono" name="tc_start" value="<?= htmlspecialchars($clip['tc_start'] ?? '') ?>">
                            </div>
                            <div class="zd-field">
                                <label class="zd-label">Timecode Out</label>
                                <input class="zd-input font-mono" name="tc_end" value="<?= htmlspecialchars($clip['tc_end'] ?? '') ?>">
                            </div>
                            <div class="zd-field">
                                <label class="zd-label">
                                    Duration
                                    <?= isset($clip['fps']) && $clip['fps'] ? '<span style="opacity:0.5; margin-left:4px;">@ ' . htmlspecialchars((string)$clip['fps']) . ' fps</span>' : '' ?>
                                </label>
                                <input class="zd-input font-mono" name="duration_pretty" placeholder="MM:SS:FF" value="<?= htmlspecialchars($clip['duration_pretty'] ?? '') ?>">
                            </div>
                        </div>
                    </div>
                </div>

                <?php if (!empty($meta_rows)): ?>
                    <div class="zd-panel">
                        <div class="zd-panel-header">Raw Metadata</div>
                        <div class="zd-panel-body">
                            <table class="zd-meta-table">
                                <?php foreach ($meta_rows as $m): ?>
                                    <tr>
                                        <td class="zd-meta-key"><?= htmlspecialchars($m['meta_key']) ?></td>
                                        <td class="zd-meta-val"><?= nl2br(htmlspecialchars((string)$m['meta_value'])) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>

            </div>
            <div class="zd-col-sidebar">

                <div class="zd-panel">
                    <div class="zd-panel-header">Actions</div>
                    <div class="zd-panel-body">
                        <div class="zd-actions">
                            <button class="zd-btn zd-btn-primary" type="submit">Save Changes</button>
                            <a class="zd-btn zd-btn-secondary" href="/admin/projects/<?= htmlspecialchars($project_uuid) ?>/days/<?= htmlspecialchars($day_uuid) ?>/clips">Discard</a>
                        </div>
                        <div style="margin-top: 15px; text-align: center; font-size: 11px; color: var(--zd-text-muted);">
                            Last updated: <?= htmlspecialchars($clip['updated_at'] ?? 'N/A') ?>
                        </div>
                    </div>
                </div>

                <div class="zd-panel">
                    <div class="zd-panel-header">Clip Status</div>
                    <div class="zd-panel-body">
                        <div class="zd-field" style="margin-bottom: 20px;">
                            <label class="zd-label">Editorial Decision</label>
                            <label class="zd-toggle-card">
                                <input type="checkbox" class="zd-checkbox" name="is_select" value="1" <?= !empty($clip['is_select']) ? 'checked' : '' ?>>
                                <div>
                                    <div style="font-weight: 600; font-size: 14px;">Select</div>
                                    <div style="font-size: 11px; color: var(--zd-text-muted);">Mark as circle take</div>
                                </div>
                            </label>
                        </div>

                        <div class="zd-field" style="margin-bottom: 20px;">
                            <label class="zd-label">Rating</label>
                            <select class="zd-select" name="rating">
                                <option value="">No Rating</option>
                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                    <option value="<?= $i ?>" <?= ((string)($clip['rating'] ?? '') === (string)$i) ? 'selected' : '' ?>>
                                        <?= $i ?> Stars <?= str_repeat('★', $i) ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>

                        <div class="zd-field">
                            <label class="zd-label">Workflow Stage</label>
                            <select class="zd-select" name="ingest_state">
                                <?php
                                $state = (string)($clip['ingest_state'] ?? 'provisional');
                                foreach (['provisional', 'ready', 'locked', 'archived'] as $opt) {
                                    $sel = ($state === $opt) ? 'selected' : '';
                                    echo "<option value=\"" . htmlspecialchars($opt) . "\" $sel>" . htmlspecialchars(ucfirst($opt)) . "</option>";
                                }
                                ?>
                            </select>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </form>
</div>
<?php $this->end(); ?>