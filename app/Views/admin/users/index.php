<?php

/**
 * @var array      $users
 * @var array      $projects
 * @var string|null $selected_project
 * @var bool       $is_superuser
 * @var string     $sort
 * @var string     $dir
 */

?>

<?php $this->extend('layout/main'); ?>

<?php $this->start('head'); ?>
<style>
    /* --- Button Style (Matched to Clips/Days Page) --- */
    .zd-btn {
        background: #3aa0ff;
        border: none;
        color: #0b0c10;
        border-radius: 10px;
        padding: 8px 12px;
        cursor: pointer;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        font-size: 14px;
        font-weight: 500;
        line-height: 1.2;
        transition: opacity 0.2s;
    }

    .zd-btn:hover {
        opacity: 0.9;
        color: #0b0c10;
    }

    .zd-users-filter-form {
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 0.85rem;
        color: var(--muted, #9aa7b2);
    }

    .zd-users-filter-form select {
        background: #171922;
        border: 1px solid var(--border, #1f2430);
        color: var(--text, #e9eef3);
        border-radius: 6px;
        padding: 4px 8px;
        font-size: 0.85rem;
    }

    /* Users list – table-style layout */

    .zd-users-table-wrap {
        margin-top: 16px;
        border-radius: 12px;
        overflow: hidden;
        background: var(--panel, #111318);
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.35);
    }

    table.zd-users-table {
        width: 100%;
        border-collapse: collapse;
        table-layout: fixed;
        font-size: 0.9rem;
    }

    .zd-users-table thead th {
        text-align: left;
        padding: 10px 16px;
        background: #171922;
        font-size: 0.8rem;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        color: var(--muted, #9aa7b2);
        border-bottom: 1px solid var(--border, #1f2430);
        white-space: nowrap;
    }

    .zd-users-table tbody td {
        padding: 10px 16px;
        border-top: 1px solid var(--border, #1f2430);
        color: var(--text, #e9eef3);
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .zd-users-table tbody tr:hover {
        background: rgba(58, 160, 255, 0.05);
    }

    .zd-users-name {
        font-weight: 500;
    }

    .zd-users-email,
    .zd-users-phone {
        font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono",
            "Courier New", monospace;
        font-size: 0.85rem;
        color: var(--muted, #9aa7b2);
    }

    .zd-users-actions {
        text-align: right;
    }

    .zd-users-actions .zd-link-inline {
        font-size: 0.85rem;
        color: var(--accent, #3aa0ff);
        text-decoration: none;
    }

    .zd-users-actions .zd-link-inline:hover {
        text-decoration: underline;
    }

    .zd-users-projects {
        font-size: 0.85rem;
        color: var(--muted, #9aa7b2);
    }

    .zd-users-projects-list {
        display: flex;
        flex-direction: column;
        gap: 2px;
    }


    /* Role + status chips reuse existing chip styles */
    .zd-chip-role {
        margin-right: 4px;
    }

    .zd-chip-status-active {
        /* active → ok */
    }

    .zd-chip-status-disabled,
    .zd-chip-status-locked {
        background-color: #3c1b1f;
        color: #f8d7da;
    }

    .zd-users-header {
        display: flex;
        flex-direction: column;
        gap: 6px;
        /* small space between title and second row */
    }

    .zd-users-header-right {
        display: flex;
        align-items: center;
        gap: 16px;
        /* spacing between dropdown and button */
    }

    .zd-users-filter-form {
        display: flex;
        align-items: center;
        gap: 8px;
        margin: 0;
        margin-left: auto;
        /* remove vertical offset */
    }

    .zd-users-header {
        display: flex;
        flex-direction: column;
        gap: 6px;
        /* small space between title and second row */
    }

    .zd-users-header-row {
        display: flex;
        align-items: center;
    }

    .zd-sortable-header {
        display: inline-flex;
        align-items: center;
        gap: 2px;
        color: inherit;
        text-decoration: none;
        transition: color 0.15s ease;
    }

    .zd-sortable-header:hover,
    .zd-sortable-header.is-active {
        color: var(--text);
    }

    /* Default arrow color (inactive direction) */
    .zd-sort-icon path {
        fill: var(--muted);
        opacity: 0.4;
        transition: fill 0.15s ease, opacity 0.15s ease;
    }

    .zd-sortable-header:hover .zd-sort-icon path {
        fill: var(--text);
        opacity: 0.6;
    }

    .zd-sortable-header.is-active.sort-asc .zd-sort-icon .zd-arrow-up,
    .zd-sortable-header.is-active.sort-desc .zd-sort-icon .zd-arrow-down {
        fill: var(--text);
        opacity: 1;
    }

    @media (max-width: 800px) {

        .zd-users-table thead th:nth-child(3),
        .zd-users-table tbody td:nth-child(3),
        .zd-users-table thead th:nth-child(6),
        .zd-users-table tbody td:nth-child(6) {
            display: none;
            /* hide phone + projects on small screens */
        }
    }
</style>
<?php $this->end(); ?>


<?php $this->start('content'); ?>

<div class="zd-page">
    <div class="zd-page-header zd-users-header">
        <h1>Users</h1>

        <div class="zd-users-header-row">
            <a href="/admin/users/create" class="zd-btn">+ New User</a>

            <?php if (!empty($projects)): ?>
                <form method="get" action="/admin/users" class="zd-users-filter-form">
                    <label for="filter_project">
                        Show only users from
                    </label>
                    <select id="filter_project" name="project" onchange="this.form.submit()">
                        <?php if ($is_superuser): ?>
                            <option value="all" <?= $selected_project === null ? 'selected' : '' ?>>
                                All projects
                            </option>
                        <?php else: ?>
                            <option value="" <?= $selected_project === null ? 'selected' : '' ?>>
                                All my projects
                            </option>
                        <?php endif; ?>

                        <?php foreach ($projects as $proj): ?>
                            <?php
                            $puuid    = $proj['project_uuid'];
                            $ptitle   = $proj['title'];
                            $selected = ($selected_project === $puuid) ? 'selected' : '';
                            ?>
                            <option
                                value="<?= htmlspecialchars($puuid, ENT_QUOTES, 'UTF-8') ?>"
                                <?= $selected ?>>
                                <?= htmlspecialchars($ptitle) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
            <?php endif; ?>
        </div>
    </div>



    <?php if (empty($users)): ?>
        <div class="zd-empty">No users yet.</div>
    <?php else: ?>
        <div class="zd-users-table-wrap">
            <table class="zd-users-table">
                <?php
                // Helper function to generate sortable header links
                $sortLink = function (string $key, string $label) use ($sort, $dir, $selected_project) {
                    $isCurrent = ($sort === $key);
                    $nextDir = ($isCurrent && $dir === 'asc') ? 'desc' : 'asc';

                    $queryParams = [
                        'sort' => $key,
                        'dir'  => $nextDir,
                    ];
                    if ($selected_project) {
                        $queryParams['project'] = $selected_project;
                    }
                    $href = '/admin/users?' . http_build_query($queryParams);

                    $activeClass = $isCurrent ? 'is-active' : '';
                    $dirClass    = $isCurrent ? 'sort-' . $dir : '';

                    echo "<a href=\"{$href}\" class=\"zd-sortable-header {$activeClass} {$dirClass}\">";
                    echo "<span>" . htmlspecialchars($label) . "</span>";
                    // Always show icon container, but paths are only visible when active
                    echo '<svg viewBox="0 0 24 24" class="icon zd-sort-icon" aria-hidden="true">';
                    echo '<path class="zd-arrow-up" d="M12 4l-4 4h8z" />';
                    echo '<path class="zd-arrow-down" d="M12 20l4-4H8z" />';
                    echo '</svg>';
                    echo "</a>";
                };
                ?>
                <thead>
                    <tr>
                        <th style="width: 20%;"><?php $sortLink('name', 'Name'); ?></th>
                        <th style="width: 24%;"><?php $sortLink('email', 'Email'); ?></th>
                        <th style="width: 14%;">Phone</th>
                        <th style="width: 10%;"><?php $sortLink('role', 'Role'); ?></th>
                        <th style="width: 10%;"><?php $sortLink('status', 'Status'); ?></th>
                        <th style="width: 14%;"><?php $sortLink('projects', 'Projects'); ?></th>
                        <th style="width: 8%; text-align: right;">Actions</th>
                    </tr>
                </thead>

                <tbody>
                    <?php foreach ($users as $u): ?>
                        <?php
                        $first   = trim($u['first_name'] ?? '');
                        $last    = trim($u['last_name'] ?? '');
                        $name    = trim(($first . ' ' . $last)) ?: '—';
                        $email   = $u['account_email'] ?: ($u['person_email'] ?? '—');
                        $phone   = $u['person_phone'] ?: '—';
                        $role    = !empty($u['is_superuser']) ? 'superuser' : ($u['user_role'] ?? 'regular');
                        $status  = $u['status'] ?? 'unknown';
                        $uuid    = $u['account_uuid'] ?? '';

                        $projectsRaw = trim($u['project_list'] ?? '');
                        $projectsArr = [];

                        if ($projectsRaw !== '') {
                            // We used SEPARATOR ', ' in GROUP_CONCAT, so split on comma
                            foreach (explode(',', $projectsRaw) as $pTitle) {
                                $pTitle = trim($pTitle);
                                if ($pTitle !== '') {
                                    $projectsArr[] = $pTitle;
                                }
                            }
                        }

                        ?>
                        <tr>
                            <td class="zd-users-name">
                                <a href="/admin/users/<?= htmlspecialchars($uuid, ENT_QUOTES, 'UTF-8') ?>/edit"
                                    class="zd-link-inline">
                                    <?= htmlspecialchars($name) ?>
                                </a>
                            </td>
                            <td class="zd-users-email">
                                <?= htmlspecialchars($email) ?>
                            </td>
                            <td class="zd-users-phone">
                                <?= htmlspecialchars($phone) ?>
                            </td>
                            <td>
                                <span class="zd-chip zd-chip-muted zd-chip-role">
                                    <?= htmlspecialchars($role) ?>
                                </span>
                            </td>
                            <td>
                                <?php
                                $statusClass = 'zd-chip-muted';
                                if ($status === 'active') {
                                    $statusClass = 'zd-chip-ok zd-chip-status-active';
                                } elseif (in_array($status, ['disabled', 'locked'], true)) {
                                    $statusClass = 'zd-chip zd-chip-status-' . $status;
                                }
                                ?>
                                <span class="zd-chip <?= $statusClass ?>">
                                    <?= htmlspecialchars($status) ?>
                                </span>
                            </td>
                            <td class="zd-users-projects">
                                <?php if (empty($projectsArr)): ?>
                                    —
                                <?php else: ?>
                                    <div class="zd-users-projects-list">
                                        <?php foreach ($projectsArr as $pTitle): ?>
                                            <div><?= htmlspecialchars($pTitle) ?></div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </td>

                            <td class="zd-users-actions">
                                <a href="/admin/users/<?= htmlspecialchars($uuid, ENT_QUOTES, 'UTF-8') ?>/edit"
                                    class="zd-link-inline">
                                    Edit
                                </a>
                            </td>
                        </tr>

                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>



<?php $this->end(); ?>


<?php $this->start('scripts'); ?>
<?php $this->end(); ?>