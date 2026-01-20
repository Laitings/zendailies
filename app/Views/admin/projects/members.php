<?php

/** @var array $project */
/** @var array $members */
/** @var array $all_users */
$roles = ['producer', 'director', 'post_supervisor', 'editor', 'assistant_editor', 'script_supervisor', 'dop', 'dit', 'reviewer'];
?>

<?php $this->extend('layout/main'); ?>

<?php $this->start('head'); ?>
<title>Members · <?= htmlspecialchars($project['title']) ?></title>
<style>
    /* --- Synchronized ZD Theme Variables --- */
    :root {
        --zd-bg-page: #0b0c10;
        --zd-bg-panel: #13151b;
        --zd-bg-input: #08090b;
        --zd-border-subtle: #1f232d;
        --zd-border-focus: #3aa0ff;
        --zd-text-main: #eef1f5;
        --zd-text-muted: #8b9bb4;
        --zd-accent: #3aa0ff;
        --zd-radius: 8px;
    }

    .zd-page-container {
        max-width: 1100px;
        margin: 0 auto;
        padding: 25px 20px;
    }

    /* Header Styling from Index */
    .zd-header h1 {
        font-size: 20px;
        font-weight: 700;
        color: var(--zd-text-main);
        line-height: 1;
        margin: 0 0 20px 0;
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

    .zd-layout-grid {
        display: grid;
        grid-template-columns: 1fr 340px;
        gap: 20px;
        align-items: start;
    }

    /* Table Styling matched to User Index */
    .zd-table-wrap {
        border-radius: 6px;
        overflow: hidden;
        background: var(--zd-bg-panel);
        border: 1px solid var(--zd-border-subtle);
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.15);
    }

    table.zd-pro-table {
        width: 100%;
        border-collapse: collapse;
        table-layout: fixed;
        font-size: 13px;
    }

    .zd-pro-table thead th {
        text-align: left;
        padding: 10px 16px;
        background: #171922;
        /* Authoritative Index Header Color */
        font-size: 0.8rem;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        font-weight: 700;
        color: var(--zd-text-muted);
        border-bottom: 1px solid var(--zd-border-subtle);
    }

    .zd-pro-table tbody td {
        padding: 12px 16px;
        border-top: 1px solid var(--zd-border-subtle);
        color: var(--zd-text-main);
    }

    .zd-pro-table tbody tr:hover {
        background: rgba(58, 160, 255, 0.06);
    }

    /* Input/Select styling with arrow breathing room */
    .zd-select {
        background-color: var(--zd-bg-input) !important;
        border: 1px solid var(--zd-border-subtle);
        color: var(--zd-text-main);
        border-radius: 4px;
        font-size: 12px;
        outline: none;
        appearance: none !important;
        -webkit-appearance: none !important;
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='24' height='24' viewBox='0 0 24 24' fill='none' stroke='%238b9bb4' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E") !important;
        background-repeat: no-repeat !important;
        background-position: right 10px center !important;
        background-size: 12px !important;
    }

    .role-select-inline {
        padding: 6px 32px 6px 10px;
        width: 160px;
    }

    /* Sidebar specific styling */
    .zd-col-sidebar .zd-panel {
        background: var(--zd-bg-panel);
        border: 1px solid var(--zd-border-subtle);
        border-radius: var(--zd-radius);
        padding: 0;
    }

    .zd-col-sidebar .zd-panel-header {
        background: #171922;
        padding: 12px 16px;
        font-size: 11px;
        text-transform: uppercase;
        font-weight: 700;
        color: var(--zd-text-muted);
        border-bottom: 1px solid var(--zd-border-subtle);
    }

    .zd-col-sidebar .zd-panel-body {
        padding: 20px;
    }

    .zd-col-sidebar .zd-select {
        width: 100%;
        padding: 10px 32px 10px 12px;
        margin-bottom: 15px;

    }

    /* Push the sidebar down to align with the Active Crew table */
    .zd-col-sidebar {
        margin-top: 28px;
    }

    /* Button Style from Index */
    .zd-btn-primary {
        background: var(--zd-accent);
        color: #fff;
        border-radius: 4px;
        padding: 8px 16px;
        font-size: 12px;
        font-weight: 600;
        cursor: pointer;
        border: none;
    }

    .zd-btn-ghost {
        color: var(--zd-text-muted);
        text-decoration: none;
        font-size: 12px;
        border: 1px solid var(--zd-border-subtle);
        padding: 6px 12px;
        border-radius: 4px;
        transition: 0.2s;
    }

    .zd-btn-ghost:hover {
        border-color: var(--zd-text-main);
        color: var(--zd-text-main);
    }

    .zd-check-card {
        display: flex;
        align-items: center;
        gap: 12px;
        background: var(--zd-bg-input);
        border: 1px solid var(--zd-border-subtle);
        padding: 12px;
        border-radius: 4px;
        margin-bottom: 10px;
        cursor: pointer;
    }

    .zd-check-card label {
        font-size: 12px;
        color: var(--zd-text-main);
        cursor: pointer;
    }
</style>
<?php $this->end(); ?>

<?php $this->start('content'); ?>
<div class="zd-page-container">

    <div class="zd-header" style="position: relative; margin-bottom: 25px;">
        <h1 style="margin: 0; padding-bottom: 15px; position: relative;">
            Members <span style="color: var(--zd-text-muted); font-weight: 400;">· <?= htmlspecialchars($project['title']) ?></span>

            <div style="position: absolute; right: 0; top: -4px;">
                <a href="/admin/projects" class="zd-btn-ghost">Back to Projects</a>
            </div>
        </h1>
    </div>

    <div class="zd-layout-grid" style="display: grid; grid-template-columns: 1fr 340px; gap: 20px; align-items: start;">

        <div class="zd-col-main" style="margin-top: 0;">
            <div style="font-size: 11px; text-transform: uppercase; letter-spacing: 0.06em; font-weight: 700; color: var(--zd-text-muted); margin-bottom: 12px; height: 16px; line-height: 16px;">
                Active Crew (<?= count($members) ?>)
            </div>

            <div class="zd-table-wrap">
                <table class="zd-pro-table">
                    <thead>
                        <tr>
                            <th style="width: 40%;">User Details</th>
                            <th style="width: 30%;">Project Role</th>
                            <th style="width: 10%; text-align: center;">Admin</th>
                            <th style="width: 10%; text-align: center;">DL</th>
                            <th style="width: 10%; text-align: right;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($members as $m): ?>
                            <tr>
                                <td>
                                    <div style="font-weight: 600; color: var(--zd-text-main);"><?= htmlspecialchars($m['display_name'] ?? ($m['first_name'] . ' ' . $m['last_name'])) ?></div>
                                    <div style="font-size: 11px; color: var(--zd-text-muted); font-family: monospace;"><?= htmlspecialchars($m['email'] ?? '') ?></div>
                                </td>
                                <td>
                                    <form method="post" action="/admin/projects/<?= $project['id'] ?>/members/<?= $m['person_uuid'] ?>" style="margin:0;">
                                        <input type="hidden" name="csrf" value="<?= $csrf ?>">
                                        <select name="role" class="zd-select role-select-inline" onchange="this.form.submit()">
                                            <?php foreach ($roles as $r): ?>
                                                <option value="<?= $r ?>" <?= ($m['role'] === $r) ? 'selected' : ''; ?>>
                                                    <?= ucwords(str_replace('_', ' ', $r)) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                </td>
                                <td style="text-align: center;">
                                    <input type="checkbox" name="is_project_admin" value="1" onchange="this.form.submit()" <?= !empty($m['is_project_admin']) ? 'checked' : ''; ?>>
                                </td>
                                <td style="text-align: center;">
                                    <input type="checkbox" name="can_download" value="1" onchange="this.form.submit()" <?= !empty($m['can_download']) ? 'checked' : ''; ?>>
                                    </form>
                                </td>
                                <td style="text-align: right;">
                                    <form method="post" action="/admin/projects/<?= $project['id'] ?>/members/<?= $m['person_uuid'] ?>/remove" style="margin:0;">
                                        <input type="hidden" name="csrf" value="<?= $csrf ?>">
                                        <button type="submit" style="background:none; border:none; cursor:pointer;" onclick="return confirm('Remove from project?')">
                                            <img src="/assets/icons/trash.svg" style="width:14px; height:14px; filter: invert(34%) sepia(73%) saturate(4530%) hue-rotate(341deg) brightness(93%) contrast(93%); opacity: 0.7;">
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="zd-col-sidebar">
            <div class="zd-panel">
                <div class="zd-panel-header">Add to Project</div>
                <div class="zd-panel-body">
                    <form method="post" action="/admin/projects/<?= $project['id'] ?>/members">
                        <input type="hidden" name="csrf" value="<?= $csrf ?>">

                        <label style="display:block; font-size: 10px; text-transform:uppercase; color: var(--zd-text-muted); margin-bottom: 6px;">Select User</label>
                        <select name="email" class="zd-select" required>
                            <option value="">-- Choose User --</option>
                            <?php foreach ($all_users as $u): ?>
                                <option value="<?= $u['email'] ?>"><?= htmlspecialchars($u['last_name'] . ', ' . $u['first_name']) ?></option>
                            <?php endforeach; ?>
                        </select>

                        <label style="display:block; font-size: 10px; text-transform:uppercase; color: var(--zd-text-muted); margin-bottom: 6px;">Assign Role</label>
                        <select name="role" class="zd-select">
                            <?php foreach ($roles as $r): ?>
                                <?php
                                // Format the label: replace underscores with spaces
                                $label = str_replace('_', ' ', $r);

                                // Check for specific acronyms, otherwise use standard Title Case
                                if (in_array($r, ['dit', 'dop'])) {
                                    $label = strtoupper($label);
                                } else {
                                    $label = ucwords($label);
                                }
                                ?>
                                <option value="<?= $r ?>"><?= $label ?></option>
                            <?php endforeach; ?>
                        </select>

                        <div class="zd-check-card">
                            <input type="checkbox" name="is_project_admin" value="1" id="chk_admin">
                            <label for="chk_admin">Project Admin (DIT)</label>
                        </div>

                        <div class="zd-check-card" style="margin-bottom: 20px;">
                            <input type="checkbox" name="can_download" value="1" id="chk_dl">
                            <label for="chk_dl">Allow Downloads</label>
                        </div>

                        <button type="submit" class="zd-btn-primary" style="width: 100%;">Add to Project</button>
                    </form>
                </div>
            </div>
        </div>

    </div>
</div>
<?php $this->end(); ?>