<?php

/** @var array $projects */
?>
<?php $this->extend('layout/main'); ?>

<?php $this->start('head'); ?>
<style>
    /* --- ZD Pro Theme Variables --- */
    :root {
        --zd-bg-page: #0b0c10;
        --zd-bg-panel: #13151b;
        --zd-border-subtle: #1f232d;
        --zd-text-main: #eef1f5;
        --zd-text-muted: #8b9bb4;
        --zd-accent: #3aa0ff;
        --zd-success: #2ecc71;
    }

    .zd-page {
        max-width: 1000px;
        margin: 0 auto;
        color: var(--zd-text-main);
        padding: 25px 20px;
        font-family: "Inter", -apple-system, sans-serif;
    }

    .zd-header h1 {
        font-size: 20px;
        font-weight: 700;
        margin: 0 0 20px 0;
        padding-bottom: 15px;
        position: relative;
        border-bottom: 2px solid #2c3240;
    }

    .zd-header-actions {
        display: flex;
        align-items: center;
        gap: 15px;
        margin-bottom: 30px;
    }

    /* --- THE CLIPPING & STACKING CURE --- */
    .zd-table-wrap {
        border-radius: 6px;
        background: var(--zd-bg-panel);
        border: 1px solid var(--zd-border-subtle);
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.15);
        overflow: visible !important;
    }

    table.zd-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 13px;
        overflow: visible !important;
    }

    .zd-table tbody tr {
        position: relative;
        z-index: 1;
    }

    /* 1. Normal hover lift */
    .zd-table tbody tr:hover {
        background: rgba(58, 160, 255, 0.06);
        z-index: 10;
    }

    /* 2. ULTIMATE LIFT: If a row contains an ACTIVE menu, lock it to the front */
    .zd-table tbody tr:has(.is-active) {
        z-index: 999 !important;
    }

    .zd-table thead th {
        text-align: left;
        padding: 10px 16px;
        background: #171922;
        font-size: 0.8rem;
        text-transform: uppercase;
        font-weight: 700;
        color: var(--zd-text-muted);
        border-bottom: 1px solid var(--zd-border-subtle);
    }

    .zd-table tbody td {
        padding: 12px 16px;
        border-top: 1px solid var(--zd-border-subtle);
        vertical-align: middle;
        overflow: visible !important;
    }

    .zd-users-actions {
        text-align: right;
        position: relative;
        /* Ensure the actions cell itself doesn't limit the child */
        overflow: visible !important;
    }

    /* --- Actions Dropdown Fix --- */
    .zd-actions-content {
        display: none;
        position: absolute;
        right: 0;
        top: calc(100% + 5px);
        background: #1c1e26;
        border: 1px solid var(--zd-border-subtle);
        border-radius: 6px;
        box-shadow: 0 10px 30px rgba(0, 0, 0, 0.8);

        /* 3. Dropdown must be the highest element on the page */
        z-index: 1000 !important;
        min-width: 200px;
        padding: 6px 0;
        white-space: nowrap;
    }

    .zd-actions-content.is-active {
        display: block;
    }

    /* --- Content Styling --- */
    .zd-cell-title div {
        max-width: 300px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
        font-weight: 600;
    }

    .zd-cell-title a {
        color: var(--zd-text-main);
        text-decoration: none;
    }

    .zd-cell-title a:hover {
        text-decoration: underline;
    }

    .zd-cell-mono {
        font-family: 'Menlo', monospace;
        font-size: 12px;
        color: var(--zd-text-muted);
    }

    .zd-chip {
        display: inline-block;
        padding: 2px 6px;
        border-radius: 3px;
        font-size: 10px;
        text-transform: uppercase;
        font-weight: 700;
    }

    .zd-chip-active {
        background: rgba(46, 204, 113, 0.1);
        color: var(--zd-success);
        border: 1px solid rgba(46, 204, 113, 0.2);
    }

    .zd-btn {
        background: var(--zd-accent);
        color: #fff;
        border-radius: 4px;
        padding: 8px 16px;
        text-decoration: none;
        font-size: 12px;
        font-weight: 600;
    }
</style>
<?php $this->end(); ?>


<?php $this->start('content'); ?>

<div class="zd-page">

    <div class="zd-header">
        <h1>Projects</h1>
        <div class="zd-header-actions" style="display: flex; align-items: center; gap: 15px;">
            <a href="/admin/projects/new" class="zd-btn">+ New Project</a>

            <div class="zd-search-wrap" style="flex-grow: 1; max-width: 300px;">
                <input type="text" id="zdProjectSearch" placeholder="Search projects or codes..."
                    style="width: 100%; background: var(--zd-bg-input); border: 1px solid var(--zd-border-subtle); color: var(--zd-text-main); padding: 7px 12px; border-radius: 4px; font-size: 12px; outline: none;">
            </div>
        </div>
    </div>

    <?php if (empty($projects)): ?>
        <div class="zd-empty" style="color: var(--zd-text-muted); margin-top: 20px;">No projects yet.</div>
    <?php else: ?>
        <div class="zd-table-wrap">
            <table class="zd-table">
                <thead>
                    <tr>
                        <th style="width: 30%;">Title</th>
                        <th style="width: 15%;">Code</th>
                        <th style="width: 15%;">Status</th>
                        <th style="width: 10%; text-align: center;">Days</th>
                        <th style="width: 15%;">Created</th>
                        <th style="width: 15%; text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($projects as $p): ?>
                        <?php
                        $uuid   = $p['project_uuid'] ?? $p['id'] ?? '';
                        $title  = trim((string)$p['title']);
                        $status = trim((string)$p['status']);
                        $code   = trim((string)($p['code'] ?? '—'));
                        $createdRaw = $p['created_at'] ?? null;
                        $createdLabel = $createdRaw
                            ? (new \DateTime($createdRaw))->format('d-m-Y')
                            : '—';

                        // Define Actions for the partial
                        $actions = [
                            [
                                'label'  => 'Enter Project',
                                'link'   => "/projects/" . urlencode($uuid) . "/enter",
                                'icon'   => 'enter', // Updated to your new enter.svg
                                'method' => 'GET'
                            ],
                            [
                                'label'  => 'Edit Project',
                                'link'   => "/admin/projects/" . urlencode($uuid) . "/edit",
                                'icon'   => 'pencil',
                                'method' => 'GET'
                            ],
                            [
                                'label'  => 'Manage Members',
                                'link'   => "/admin/projects/" . urlencode($uuid) . "/members",
                                'icon'   => 'users', // Updated to your new users.svg
                                'method' => 'GET'
                            ]
                        ];
                        ?>
                        <tr>
                            <td style="overflow: visible !important;">
                                <div class="zd-cell-title">
                                    <a href="/admin/projects/<?= urlencode($uuid) ?>/days">
                                        <?= htmlspecialchars($title) ?>
                                    </a>
                                </div>
                            </td>
                            <td class="zd-cell-mono">
                                <?= htmlspecialchars($code) ?>
                            </td>
                            <td>
                                <span class="zd-chip <?= $status === 'active' ? 'zd-chip-active' : 'zd-chip-muted' ?>">
                                    <?= htmlspecialchars($status) ?>
                                </span>
                            </td>
                            <?php
                            $dayCount = (int)($p['day_count'] ?? 0);
                            ?>

                            <td style="text-align: center;">
                                <span class="zd-cell-mono" style="color: <?= $dayCount > 0 ? 'var(--zd-accent)' : 'var(--zd-text-muted)' ?>;">
                                    <?= $dayCount ?>
                                </span>
                            </td>
                            <td class="zd-cell-mono">
                                <?= htmlspecialchars($createdLabel) ?>
                            </td>
                            <td class="zd-users-actions" style="text-align: right;">
                                <?php include __DIR__ . '/../../partials/actions_dropdown.php'; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<script>
    document.getElementById('zdProjectSearch').addEventListener('keyup', function() {
        const searchTerm = this.value.toLowerCase();
        const rows = document.querySelectorAll('.zd-table tbody tr');

        rows.forEach(row => {
            // Search in Title (first column) and Code (second column)
            const title = row.querySelector('.zd-cell-title').textContent.toLowerCase();
            const code = row.querySelector('.zd-cell-mono').textContent.toLowerCase();

            if (title.includes(searchTerm) || code.includes(searchTerm)) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        });
    });
</script>
<?php $this->end(); ?>