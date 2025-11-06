<?php

/** @var array $project */
/** @var array $days */
/** @var array $routes */

$this->extend('layout/main');

$this->start('head'); ?>
<title><?= htmlspecialchars($project['title']) ?> · Shooting Days · Zentropa Dailies</title>
<style>
    .zd-sep {
        opacity: .6;
        margin: 0 10px;
        user-select: none
    }

    /* Card should use full container width (Clips style) */
    .zd-days.zd-card {
        max-width: none;
        width: 100%;
        padding: 16px
    }

    /* Table base (copied from Clips style) */
    .zd-table {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0
    }

    .zd-table th,
    .zd-table td {
        padding: 8px 10px;
        border-bottom: 1px solid #1f2430
    }

    /* Sticky header like Clips */
    .zd-table th {
        font-weight: 600;
        color: #e9eef3;
        text-align: left;
        position: sticky;
        top: 0;
        z-index: 1;
        background: #0b0c10;
        background-clip: padding-box
    }

    /* Rounded header corners + last row corners */
    .zd-table thead th:first-child {
        border-top-left-radius: 8px
    }

    .zd-table thead th:last-child {
        border-top-right-radius: 8px
    }

    .zd-table tbody tr:last-child td:first-child {
        border-bottom-left-radius: 8px
    }

    .zd-table tbody tr:last-child td:last-child {
        border-bottom-right-radius: 8px
    }

    /* Days-specific left alignment */
    .zd-days,
    .zd-days th,
    .zd-days td {
        text-align: left !important
    }

    /* Header stack (copied approach from Clips head) */
    .zd-page-header {
        display: block;
        margin: 6px 0 8px
    }



    .zd-subtitle {
        color: var(--muted);
        margin: 0 0 8px
    }

    /* Actions row like Clips */
    .zd-actions {
        display: flex;
        gap: 8px;
        align-items: center;
        margin: 6px 0 10px
    }

    .zd-chip {
        display: inline-block;
        background: #18202b;
        border: 1px solid var(--border);
        border-radius: 999px;
        padding: 2px 8px
    }

    /* Optional: compact card title if you re-introduce it */
    .zd-days .zd-card-title {
        font-size: 1.1rem;
        font-weight: 600;
        margin-bottom: 8px;
        text-align: left !important
    }

    /* --- Clickable rows --- */
    .zd-days table.zd-table tr {
        cursor: pointer;
        transition: background 0.15s ease, color 0.15s ease;
    }

    .zd-days table.zd-table tr:hover {
        background: #151821;
        /* slightly lighter panel tone */
    }

    .zd-days table.zd-table tr:active {
        background: #1a1f2a;
    }

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
        opacity: 0.7;
    }
</style>

<?php $this->end(); ?>

<?php $this->start('content'); ?>

<header class="zd-page-header">
    <h1 class="zd-title">Shooting days</h1>
    <p class="zd-subtitle">
        Project: <?= htmlspecialchars($project['title'] ?? '') ?> ·
        Code: <span class="zd-chip"><?= htmlspecialchars($project['code'] ?? '') ?></span> ·
        Status: <span class="zd-chip"><?= htmlspecialchars($project['status'] ?? '') ?></span>
    </p>

    <div class="zd-actions">
        <a class="za-btn za-btn-primary" href="<?= htmlspecialchars($routes['new_day'] ?? '') ?>">+ New Day</a>
    </div>
</header>


<div class="zd-card zd-days">
    <?php if (empty($days)): ?>
        <div class="zd-empty">No days yet. (Try Import or add a day manually.)</div>
        <div style="margin-top:10px">
            <a class="za-btn za-btn-primary" href="<?= htmlspecialchars($routes['new_day'] ?? '') ?>">Create the first day</a>
        </div>
</div>
<?php else: ?>
    <table class="zd-table">
        <?php
        // Small helper for arrow icons
        function sortArrow($col, $sort, $dir)
        {
            if ($col !== $sort) return '';
            return $dir === 'ASC'
                ? ' <span class="sort-arrow">▲</span>'
                : ' <span class="sort-arrow">▼</span>';
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
                <th style="width:140px;text-align:right;">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($days as $d): ?>
                <?php
                $date = $d['shoot_date'];
                $unit = $d['unit'] ?? '';
                $clips = (int)$d['clip_count'];
                $dayUuid = $d['day_uuid'];
                $viewClipsUrl = $routes['clips_base'] . '/' . $dayUuid . '/clips';
                $title = $d['title'] ?? '';
                $niceTitle = $title !== '' ? $title : ('Day ' . htmlspecialchars($d['shoot_date']));
                ?>
                <tr onclick="window.location.href='<?= htmlspecialchars($viewClipsUrl) ?>'">
                    <td><?= htmlspecialchars($niceTitle) ?></td>
                    <td><?= htmlspecialchars($date) ?></td>
                    <td><?= htmlspecialchars($unit) ?></td>
                    <td><?= $clips ?></td>
                    <td style="white-space:nowrap">
                        <?php
                        $editUrl = "/admin/projects/{$project['project_uuid']}/days/{$dayUuid}/edit";
                        $deleteUrl = "/admin/projects/{$project['project_uuid']}/days/{$dayUuid}/delete";
                        ?>
                        <a href="<?= htmlspecialchars($editUrl) ?>" class="icon-btn" title="Edit day" onclick="event.stopPropagation();">
                            <img src="/assets/icons/pencil.svg" alt="Edit" class="icon">
                        </a>
                        <a href="<?= htmlspecialchars($deleteUrl) ?>" class="icon-btn danger" title="Delete day" onclick="event.stopPropagation();">
                            <img src="/assets/icons/trash.svg" alt="Delete" class="icon">
                        </a>
                    </td>


                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
</div>

<?php $this->end(); ?>

<?php $this->start('scripts'); ?>
<!-- Reserved for day-page JS later -->
<?php $this->end(); ?>