<?php

/** @var array $project */
/** @var array $groups */
/** @var string $csrf */
?>

<?php $this->extend('layout/main'); ?>

<?php $this->start('head'); ?>
<title>Security Groups · <?= htmlspecialchars($project['title']) ?></title>
<style>
    /* --- Synchronized ZD Theme Variables (Identical to members.php) --- */
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

    /* Header Styling */
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

    /* Table Styling */
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
        font-size: 13px;
    }

    .zd-pro-table thead th {
        text-align: left;
        padding: 10px 16px;
        background: #171922;
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

    /* Sidebar and Panels */
    .zd-col-sidebar {
        margin-top: 28px;
    }

    .zd-panel {
        background: var(--zd-bg-panel);
        border: 1px solid var(--zd-border-subtle);
        border-radius: var(--zd-radius);
    }

    .zd-panel-header {
        background: #171922;
        padding: 12px 16px;
        font-size: 11px;
        text-transform: uppercase;
        font-weight: 700;
        color: var(--zd-text-muted);
        border-bottom: 1px solid var(--zd-border-subtle);
    }

    .zd-panel-body {
        padding: 20px;
    }

    /* Button and Input Styles */
    .zd-btn-primary {
        background: var(--zd-accent);
        color: #000;
        border-radius: 4px;
        padding: 10px 16px;
        font-size: 12px;
        font-weight: 700;
        cursor: pointer;
        border: none;
        transition: opacity 0.2s;
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

    .zd-input {
        width: 100%;
        background-color: var(--zd-bg-input);
        border: 1px solid var(--zd-border-subtle);
        color: var(--zd-text-main);
        padding: 10px;
        border-radius: 4px;
        font-size: 13px;
        margin-bottom: 15px;
        outline: none;
        box-sizing: border-box;
    }

    .zd-input:focus {
        border-color: var(--zd-accent);
    }
</style>
<?php $this->end(); ?>

<?php $this->start('content'); ?>
<div class="zd-page-container">
    <div class="zd-header">
        <h1 style="display: flex; align-items: center; gap: 20px;">
            <a href="/admin/projects/<?= $project['id'] ?>/members" style="text-decoration:none; color: var(--zd-text-muted); font-weight: 400; transition: color 0.2s;" onmouseover="this.style.color='#3aa0ff'" onmouseout="this.style.color='var(--zd-text-muted)'">Members</a>
            <span style="color: var(--zd-border-subtle);">|</span>
            <span style="color: var(--zd-text-main);">Security Groups</span>
        </h1>
    </div>

    <div class="zd-layout-grid">
        <div class="zd-col-main">
            <div style="font-size: 11px; text-transform: uppercase; letter-spacing: 0.06em; font-weight: 700; color: var(--zd-text-muted); margin-bottom: 12px; height: 16px; line-height: 16px;">
                Defined Groups (<?= count($groups) ?>)
            </div>

            <div class="zd-table-wrap">
                <table class="zd-pro-table">
                    <thead>
                        <tr>
                            <th style="width: 60%;">Group Name</th>
                            <th style="width: 20%; text-align: center;">Members</th>
                            <th style="width: 20%; text-align: right;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($groups)): ?>
                            <tr>
                                <td colspan="3" style="text-align:center; color: var(--zd-text-muted); padding: 40px;">No security groups defined.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($groups as $g): ?>
                                <tr>
                                    <td style="font-weight: 600;"><?= htmlspecialchars($g['name']) ?></td>
                                    <td style="text-align: center; color: var(--zd-accent); font-weight: 700;"><?= (int)$g['member_count'] ?></td>
                                    <td style="text-align: right; display: flex; justify-content: flex-end; gap: 8px; align-items: center;">
                                        <a href="/admin/projects/<?= $project['id'] ?>/sensitive-groups/<?= $g['group_uuid'] ?>/members" class="zd-btn-ghost">Manage</a>

                                        <form method="post" action="/admin/projects/<?= $project['id'] ?>/sensitive-groups/<?= $g['group_uuid'] ?>/delete" style="margin:0;" onsubmit="return confirm('Delete this group? This will revoke access for all members.')">
                                            <input type="hidden" name="csrf" value="<?= $csrf ?>">
                                            <button type="submit" style="background:none; border:none; cursor:pointer; padding: 4px; display: flex; align-items: center;">
                                                <img src="/assets/icons/trash.svg" style="width:14px; height:14px; filter: invert(34%) sepia(73%) saturate(4530%) hue-rotate(341deg) brightness(93%) contrast(93%); opacity: 0.7;">
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="zd-col-sidebar">
            <div class="zd-panel">
                <div class="zd-panel-header">Create New Group</div>
                <div class="zd-panel-body">
                    <form method="post" action="/admin/projects/<?= $project['id'] ?>/sensitive-groups">
                        <input type="hidden" name="csrf" value="<?= $csrf ?>">
                        <label style="display:block; font-size: 10px; text-transform:uppercase; color: var(--zd-text-muted); margin-bottom: 6px;">Group Name</label>
                        <input type="text" name="name" class="zd-input" placeholder="e.g. Director's Cut" required>
                        <button type="submit" class="zd-btn-primary" style="width: 100%;">Create Group</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
<?php $this->end(); ?>