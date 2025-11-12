<?php

/** @var array $project */
/** @var array $days */
/** @var array $routes */

$this->extend('layout/main');

$this->start('head'); ?>
<title><?= htmlspecialchars($project['title']) ?> · Shooting Days · Zentropa Dailies</title>
<style>
    /* One wrapper controls width + even gutters (16px each side). */
    .days-page {
        max-width: 960px;
        width: 100%;
        margin: 0 auto;
        /* centered inside .zd-container's own 16px gutters */
    }


    /* Header sits flush with the wrapper (no padding), so it aligns
     with the OUTER card edge, not the card’s inner padding. */
    .days-page .page-head {
        margin: 0 0 12px;
        padding: 0;
    }

    .days-page .page-head h1 {
        font-size: 1.8rem;
        /* same visual size as Projects title */
        font-weight: 700;
        margin: 0 0 6px;
    }

    .days-page .page-head .subtitle {
        color: var(--muted);
        margin: 0 0 8px;
    }

    .days-page .page-head .actions {
        display: flex;
        align-items: center;
        gap: 8px;
        margin: 6px 0 10px;
    }

    /* Card fills the wrapper width and has its own inner padding. */
    .zd-card.zd-days {
        width: 100%;
        margin: 0;
        padding: 16px;
    }

    /* Table: compact and neat */
    .zd-table {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0;
    }

    .zd-table th,
    .zd-table td {
        padding: 8px 10px;
        border-bottom: 1px solid var(--border);
        text-align: left;
    }

    .zd-table thead th {
        font-weight: 600;
        color: var(--text);
        background: var(--bg);
        position: sticky;
        top: 0;
        /* sticks at top inside the card */
        z-index: 1;
        background-clip: padding-box;
    }

    /* Rounded corners */
    .zd-table thead th:first-child {
        border-top-left-radius: 8px;
    }

    .zd-table thead th:last-child {
        border-top-right-radius: 8px;
    }

    .zd-table tbody tr:last-child td:first-child {
        border-bottom-left-radius: 8px;
    }

    .zd-table tbody tr:last-child td:last-child {
        border-bottom-right-radius: 8px;
    }

    /* Tight layout + ellipsis */
    .zd-table--tight {
        table-layout: fixed;
    }

    .zd-table--tight th,
    .zd-table--tight td {
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    /* Clickable rows */
    .zd-days table.zd-table tr {
        cursor: pointer;
        transition: background .15s;
    }

    .zd-days table.zd-table tr:hover {
        background: #151821;
    }

    .zd-days table.zd-table tr:active {
        background: #1a1f2a;
    }

    /* Sort links */
    .sort-link {
        color: var(--text);
        text-decoration: none;
    }

    .sort-link:hover {
        color: var(--accent);
        text-decoration: underline;
    }

    .sort-arrow {
        font-size: 10px;
        opacity: .7;
    }

    /* Keep the actions column narrow */
    .col-actions {
        width: 140px;
        text-align: right;
        white-space: nowrap;
    }
</style>
<?php $this->end(); ?>

<?php $this->start('content'); ?>

<div class="days-page">

    <header class="page-head">
        <h1>Shooting days</h1>
        <p class="subtitle">
            Project: <?= htmlspecialchars($project['title'] ?? '') ?> ·
            Code: <span class="zd-chip"><?= htmlspecialchars($project['code'] ?? '') ?></span> ·
            Status: <span class="zd-chip"><?= htmlspecialchars($project['status'] ?? '') ?></span>
        </p>
        <div class="actions">
            <a class="za-btn za-btn-primary" href="<?= htmlspecialchars($routes['new_day'] ?? '') ?>">+ New Day</a>
        </div>
    </header>

    <div class="zd-card zd-days">
        <?php if (empty($days)): ?>
            <div class="zd-empty">No days yet. (Try Import or add a day manually.)</div>
            <div style="margin-top:10px">
                <a class="za-btn za-btn-primary" href="<?= htmlspecialchars($routes['new_day'] ?? '') ?>">Create the first day</a>
            </div>
        <?php else: ?>
            <table class="zd-table zd-table--tight">
                <?php
                function sortArrow($col, $sort, $dir)
                {
                    if ($col !== $sort) return '';
                    return $dir === 'ASC' ? ' <span class="sort-arrow">▲</span>' : ' <span class="sort-arrow">▼</span>';
                }
                function nextDir($col, $sort, $dir)
                {
                    return ($col === $sort && $dir === 'ASC') ? 'DESC' : 'ASC';
                }
                ?>
                <thead>
                    <tr>
                        <?php
                        $cols = [
                            'title'      => 'Title',
                            'shoot_date' => 'Date',
                            'unit'       => 'Unit',
                            'clip_count' => 'Clips',
                        ];
                        foreach ($cols as $col => $label):
                            $url = "?sort=$col&dir=" . nextDir($col, $sort, $dir);
                        ?>
                            <th>
                                <a href="<?= htmlspecialchars($url) ?>" class="sort-link">
                                    <?= htmlspecialchars($label) ?><?= sortArrow($col, $sort, $dir) ?>
                                </a>
                            </th>
                        <?php endforeach; ?>
                        <th class="col-actions">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($days as $d): ?>
                        <?php
                        $date      = $d['shoot_date'];
                        $unit      = $d['unit'] ?? '';
                        $clips     = (int)$d['clip_count'];
                        $dayUuid   = $d['day_uuid'];
                        $viewUrl   = $routes['clips_base'] . '/' . $dayUuid . '/clips';
                        $title     = $d['title'] ?? '';
                        $niceTitle = $title !== '' ? $title : ('Day ' . htmlspecialchars($d['shoot_date']));
                        $editUrl   = "/admin/projects/{$project['project_uuid']}/days/{$dayUuid}/edit";
                        $delUrl    = "/admin/projects/{$project['project_uuid']}/days/{$dayUuid}/delete";
                        ?>
                        <tr onclick="window.location.href='<?= htmlspecialchars($viewUrl) ?>'">
                            <td><?= htmlspecialchars($niceTitle) ?></td>
                            <td><?= htmlspecialchars($date) ?></td>
                            <td><?= htmlspecialchars($unit) ?></td>
                            <td><?= $clips ?></td>
                            <td class="col-actions">
                                <a href="<?= htmlspecialchars($editUrl) ?>" class="icon-btn" title="Edit day" onclick="event.stopPropagation();">
                                    <img src="/assets/icons/pencil.svg" alt="Edit" class="icon">
                                </a>
                                <a href="<?= htmlspecialchars($delUrl) ?>" class="icon-btn danger" title="Delete day" onclick="event.stopPropagation();">
                                    <img src="/assets/icons/trash.svg" alt="Delete" class="icon">
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

</div>

<?php $this->end(); ?>

<?php $this->start('scripts'); ?>
<!-- No page JS yet -->
<?php $this->end(); ?>