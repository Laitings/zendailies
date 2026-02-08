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

    /* --- Page Layout --- */
    .zd-page {
        max-width: 1000px;
        margin: 0 auto;
        color: var(--zd-text-main);
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
        padding: 25px 20px;
    }

    /* --- Header Area --- */
    .zd-header {
        margin-bottom: 20px;
        /* We removed position:relative and the ::after block from here */
    }

    .zd-header h1 {
        font-size: 20px;
        font-weight: 700;

        color: var(--zd-text-main);
        line-height: 1;

        /* Spacing logic */
        margin: 0 0 20px 0;
        /* Pushes the buttons down away from the line */
        padding-bottom: 15px;
        /* Pushes the line down away from the text */

        /* This now anchors the line */
        position: relative;
    }



    /* The Line (Now attached to the H1) */
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

    /* Action Row (Buttons & Filters) */
    .zd-users-header-row {
        display: flex;
        justify-content: space-between;
        /* Pushes items to edges */
        align-items: center;
        width: 100%;
    }

    /* --- Button Style --- */
    .zd-btn {
        background: var(--zd-accent);
        border: none;
        color: #fff;
        border-radius: 4px;
        padding: 8px 16px;
        cursor: pointer;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        font-size: 12px;
        font-weight: 600;
        transition: opacity 0.2s;
    }

    .zd-btn:hover {
        opacity: 0.9;
        color: #fff;
    }

    /* --- Filter Form --- */
    .zd-users-filter-form {
        display: flex;
        align-items: center;
        gap: 8px;
        font-size: 12px;
        color: var(--zd-text-muted);
        margin: 0;
    }

    .zd-users-filter-form select {
        background: var(--zd-bg-input);
        border: 1px solid var(--zd-border-subtle);
        color: var(--zd-text-main);
        border-radius: 4px;
        padding: 6px 10px;
        font-size: 12px;
        outline: none;
    }

    .zd-users-filter-form select:focus {
        border-color: var(--zd-accent);
    }

    /* --- Users Table --- */
    .zd-users-table-wrap {
        border-radius: 6px;
        background: var(--zd-bg-panel);
        border: 1px solid var(--zd-border-subtle);
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.15);
    }

    .zd-actions-menu {
        position: relative;
        display: inline-block;
        margin-right: 15px;
        /* Added breathing room */
    }

    .zd-actions-trigger {
        background: var(--zd-bg-input);
        border: 1px solid var(--zd-border-subtle);
        color: var(--zd-text-muted);
        padding: 6px 10px;
        border-radius: 4px;
        cursor: pointer;
        font-size: 11px;
        font-weight: 700;
        transition: all 0.2s;
    }

    .zd-actions-content {
        display: none;
        /* Hidden by default */
        position: absolute;
        right: 0;
        top: 100%;
        margin-top: 5px;
        background: #171922;
        /* Zentropa Charcoal */
        border: 1px solid var(--zd-border-subtle);
        border-radius: 6px;
        box-shadow: 0 10px 25px rgba(0, 0, 0, 0.5);
        z-index: 9999;
        /* Ensure it floats above rows */
        min-width: 160px;
    }

    /* This is the class the JavaScript toggles */
    .zd-actions-content.is-active {
        display: block !important;
    }

    .zd-action-item {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 10px 15px;
        color: var(--zd-text-main);
        text-decoration: none;
        font-size: 13px;
        transition: background 0.2s;
        border: none;
        background: transparent;
        width: 100%;
        text-align: left;
        cursor: pointer;
    }

    .zd-action-item:hover {
        background: rgba(58, 160, 255, 0.15);
        color: var(--zd-accent);
    }

    .zd-action-icon {
        width: 14px;
        height: 14px;
        filter: invert(1);
        /* Ensure icons show on dark background */
    }

    table.zd-users-table {
        width: 100%;
        border-collapse: collapse;

        font-size: 13px;
    }

    .zd-users-table thead th {
        text-align: left;
        padding: 10px 16px;
        background: #171922;
        font-size: 0.8rem;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        font-weight: 700;
        color: var(--zd-text-muted);
        border-bottom: 1px solid var(--zd-border-subtle);
        white-space: nowrap;
    }

    .zd-users-table tbody td {
        padding: 12px 16px;
        border-top: 1px solid var(--zd-border-subtle);
        color: var(--zd-text-main);
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .zd-users-table tbody tr:hover {
        background: rgba(58, 160, 255, 0.06);
    }

    .zd-users-name a {
        font-weight: 600;
        color: var(--zd-text-main);
        text-decoration: none;
    }

    .zd-users-name a:hover {
        text-decoration: underline;
    }

    .zd-users-email,
    .zd-users-phone {
        font-family: 'Menlo', monospace;
        font-size: 12px;
        color: var(--zd-text-muted);
    }

    .zd-users-actions {
        text-align: right;
    }

    .zd-link-inline {
        font-size: 11px;
        color: var(--zd-text-muted);
        text-decoration: none;
        border: 1px solid var(--zd-border-subtle);
        padding: 4px 8px;
        border-radius: 4px;
        transition: all 0.2s;
    }

    .zd-link-inline:hover {
        border-color: var(--zd-text-main);
        color: var(--zd-text-main);
    }

    .zd-users-projects {
        font-size: 11px;
        color: var(--zd-text-muted);
    }

    .zd-users-projects-list {
        display: flex;
        flex-direction: column;
        gap: 2px;
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
    }

    .zd-chip-muted {
        background: #1f232d;
        color: var(--zd-text-muted);
        border: 1px solid #2c3240;
    }

    .zd-chip-ok {
        background: rgba(46, 204, 113, 0.1);
        color: var(--zd-success);
        border: 1px solid rgba(46, 204, 113, 0.2);
    }

    .zd-chip-warn {
        background: rgba(231, 76, 60, 0.1);
        color: #e74c3c;
        border: 1px solid rgba(231, 76, 60, 0.2);
    }

    /* --- Sorting --- */
    .zd-sortable-header {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        color: inherit;
        text-decoration: none;
        transition: color 0.15s ease;
    }

    .zd-sortable-header:hover,
    .zd-sortable-header.is-active {
        color: var(--zd-text-main);
    }

    .zd-sort-icon {
        width: 10px;
        height: 10px;
    }

    .zd-sort-icon path {
        fill: var(--zd-text-muted);
        opacity: 0.3;
        transition: fill 0.15s ease, opacity 0.15s ease;
    }

    .zd-sortable-header:hover .zd-sort-icon path {
        fill: var(--zd-text-main);
        opacity: 0.6;
    }

    .zd-sortable-header.is-active.sort-asc .zd-sort-icon .zd-arrow-up,
    .zd-sortable-header.is-active.sort-desc .zd-sort-icon .zd-arrow-down {
        fill: var(--zd-accent);
        opacity: 1;
    }

    /* --- Icon Action Button --- */
    .zd-icon-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 28px;
        height: 28px;
        border-radius: 6px;
        /* Soft rounded corners */
        transition: background 0.2s ease;
    }

    /* The hover effect you wanted */
    .zd-icon-btn:hover {
        background: rgba(255, 255, 255, 0.08);
    }

    @media (max-width: 800px) {

        .zd-users-table thead th:nth-child(3),
        .zd-users-table tbody td:nth-child(3),
        .zd-users-table thead th:nth-child(6),
        .zd-users-table tbody td:nth-child(6) {
            display: none;
        }
    }

    /* --- Alert System --- */
    .zd-alert {
        display: flex;
        align-items: center;
        gap: 15px;
        padding: 12px 20px;
        border-radius: 6px;
        margin-bottom: 25px;
        font-size: 13px;
        position: relative;
        animation: zdSlideDown 0.3s ease-out;
    }

    @keyframes zdSlideDown {
        from {
            opacity: 0;
            transform: translateY(-10px);
        }

        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .zd-alert-success {
        background: rgba(46, 204, 113, 0.1);
        border: 1px solid rgba(46, 204, 113, 0.3);
        color: #8ff0a4;
    }

    .zd-alert-danger {
        background: rgba(231, 76, 60, 0.1);
        border: 1px solid rgba(231, 76, 60, 0.3);
        color: #ffb0b0;
    }

    .zd-alert-icon {
        font-size: 18px;
    }

    .zd-alert-text {
        font-size: 11px;
        opacity: 0.8;
        margin-top: 2px;
    }

    .zd-alert-close {
        position: absolute;
        right: 12px;
        top: 50%;
        transform: translateY(-50%);
        background: none;
        border: none;
        color: inherit;
        font-size: 20px;
        cursor: pointer;
        opacity: 0.5;
    }

    .zd-alert-close:hover {
        opacity: 1;
    }

    /* --- SEARCH BAR FOCUS STATE --- */
    #zdUserSearch:focus {
        border-color: var(--zd-accent) !important;
        box-shadow: 0 0 0 2px rgba(58, 160, 255, 0.1);
    }

    /* Base cell styling (No overflow:hidden here!) */
    .zd-users-table tbody td {
        padding: 12px 16px;
        border-top: 1px solid var(--zd-border-subtle);
        color: var(--zd-text-main);
        vertical-align: middle;
    }

    /* Apply truncation ONLY to text-heavy columns */
    .zd-users-name,
    .zd-users-email,
    .zd-users-projects {
        max-width: 0;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }
</style>
<?php $this->end(); ?>


<?php $this->start('content'); ?>

<div class="zd-page">

    <?php if (isset($_GET['invited']) && $_GET['invited'] == '1'): ?>
        <div class="zd-alert zd-alert-success">
            <span class="zd-alert-icon">✉️</span>
            <div>
                <strong>Invitation Sent!</strong>
                <div class="zd-alert-text">A setup link has been emailed to the new user.</div>
            </div>
            <button class="zd-alert-close" onclick="this.parentElement.style.display='none'">&times;</button>
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['status_updated']) && $_GET['status_updated'] == '1'): ?>
        <div class="zd-alert zd-alert-success">
            <span class="zd-alert-icon">🔐</span>
            <div>
                <strong>Status Updated!</strong>
                <div class="zd-alert-text">The user's global access status has been modified.</div>
            </div>
            <button class="zd-alert-close" onclick="this.parentElement.style.display='none'">&times;</button>
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['resent']) && $_GET['resent'] == '1'): ?>
        <div class="zd-alert zd-alert-success" style="border-color: var(--zd-accent);">
            <span class="zd-alert-icon">🔄</span>
            <div>
                <strong>Invitation Resent!</strong>
                <div class="zd-alert-text">A fresh 48-hour setup link has been dispatched.</div>
            </div>
            <button class="zd-alert-close" onclick="this.parentElement.style.display='none'">&times;</button>
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['error']) && $_GET['error'] == 'mail_failed'): ?>
        <div class="zd-alert zd-alert-danger">
            <span class="zd-alert-icon">⚠️</span>
            <div>
                <strong>Mail Error</strong>
                <div class="zd-alert-text">The system couldn't reach the mail server. Please check technical logs.</div>
            </div>
            <button class="zd-alert-close" onclick="this.parentElement.style.display='none'">&times;</button>
        </div>
    <?php endif; ?>

    <div class="zd-header">
        <h1>Users</h1>

        <div class="zd-users-header-row">

            <a href="/admin/users/create" class="zd-btn">+ New User</a>

            <div class="zd-search-wrap" style="margin-left: 15px; flex-grow: 1; max-width: 300px;">
                <input type="text" id="zdUserSearch" placeholder="Search name or email..."
                    style="width: 100%; background: var(--zd-bg-input); border: 1px solid var(--zd-border-subtle); color: var(--zd-text-main); padding: 7px 12px; border-radius: 4px; font-size: 12px; outline: none;">
            </div>

            <?php if (!empty($projects)): ?>
                <form method="get" action="/admin/users" class="zd-users-filter-form">
                    <label for="filter_project">Project:</label>
                    <select id="filter_project" name="project" onchange="this.form.submit()">
                        <?php if ($is_superuser): ?>
                            <option value="all" <?= $selected_project === null ? 'selected' : '' ?>>All Projects</option>
                        <?php else: ?>
                            <option value="" <?= $selected_project === null ? 'selected' : '' ?>>All My Projects</option>
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
        <div class="zd-empty" style="color: var(--zd-text-muted); margin-top: 20px;">No users found.</div>
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

                        <th style="width: 10%; text-align: right;">Actions</th>
                    </tr>
                </thead>

                <tbody>
                    <?php foreach ($users as $u): ?>
                        <?php
                        $first   = trim($u['first_name'] ?? '');
                        $last    = trim($u['last_name'] ?? '');
                        $name    = trim(($first . ' ' . $last)) ?: '—';
                        $email   = $u['account_email'] ?: ($u['person_email'] ?? '—');
                        $phone = str_replace(' ', '', trim((string)($u['person_phone'] ?? ''))) ?: '—';
                        $role    = !empty($u['is_superuser']) ? 'superuser' : ($u['user_role'] ?? 'regular');
                        $status  = $u['status'] ?? 'unknown';
                        $uuid    = $u['account_uuid'] ?? '';
                        $isPending = !empty($u['setup_token']);

                        $projectsRaw = trim($u['project_list'] ?? '');
                        $projectsArr = [];

                        if ($projectsRaw !== '') {
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
                                <a href="/admin/users/<?= htmlspecialchars($uuid, ENT_QUOTES, 'UTF-8') ?>/edit">
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
                                <span class="zd-chip zd-chip-muted">
                                    <?= htmlspecialchars($role) ?>
                                </span>
                            </td>
                            <td>
                                <?php
                                $statusClass = 'zd-chip-muted';

                                // Simple mapping based on real DB status
                                switch ($status) {
                                    case 'active':
                                        $statusClass = 'zd-chip-ok';
                                        break;
                                    case 'pending':
                                        $statusClass = 'zd-chip-warn';
                                        break;
                                    case 'disabled':
                                    case 'locked':
                                        $statusClass = 'zd-chip-warn';
                                        break;
                                }
                                ?>
                                <span class="zd-chip <?= $statusClass ?>">
                                    <?= htmlspecialchars(ucfirst($status)) ?>
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

                            <td class="zd-users-actions" style="overflow: visible !important;">
                                <?php
                                $currentUserUuid = $u['account_uuid'];

                                $userActions = [
                                    [
                                        'label'  => 'Edit User',
                                        // FIXED: Swapped to match the {id}/edit route structure
                                        'link'   => "/admin/users/" . $currentUserUuid . "/edit",
                                        'icon'   => 'pencil',
                                        'method' => 'GET'
                                    ]
                                ];

                                if (!empty($u['setup_token'])) {
                                    $userActions[] = [
                                        'label'  => 'Resend Invite',
                                        'link'   => "/admin/users/resend-invite/" . $currentUserUuid,
                                        'icon'   => 'mail',
                                        'method' => 'GET'
                                    ];
                                }

                                // Dev tool to copy invitation link manually for fake emails
                                if (!empty($u['setup_token'])) {
                                    $userActions[] = [
                                        'label'   => 'Copy Setup Link (Dev)',
                                        // Keeping 'event' here is good practice for Chrome/Edge
                                        'link'    => "javascript:copySetupLink('" . $u['setup_token'] . "', event)",
                                        'icon'    => 'clipboard',
                                    ];
                                }

                                if ($is_superuser || $is_global_admin) {
                                    $isCurrentlyDisabled = (trim($u['status']) === 'disabled');

                                    $userActions[] = [
                                        'label'     => $isCurrentlyDisabled ? 'Activate User' : 'Deactivate User',
                                        'link'      => "/admin/users/toggle-status/" . $currentUserUuid, // Correct
                                        'icon'      => $isCurrentlyDisabled ? 'lock-open' : 'lock-closed',
                                        'method'    => 'POST',
                                        'is_danger' => !$isCurrentlyDisabled,
                                        'confirm'   => 'Are you sure you want to ' . ($isCurrentlyDisabled ? 'activate' : 'deactivate') . ' this user?'
                                    ];

                                    $userActions[] = [
                                        'label'     => 'Delete User',
                                        'link'      => "/admin/users/delete/" . $currentUserUuid, // Correct
                                        'icon'      => 'trash',
                                        'method'    => 'POST',
                                        'is_danger' => true,
                                        'confirm'   => 'Delete user globally? This cannot be undone.'
                                    ];
                                }

                                include __DIR__ . '/../../partials/actions_dropdown.php';
                                ?>
                            </td>
                        </tr>

                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<script>
    function copySetupLink(token, e) {
        // 1. Try to find the button, but don't stop if we can't
        let btn = null;
        try {
            // Check arguments, global event, or window.event
            const evt = e || (typeof event !== 'undefined' ? event : window.event);
            if (evt && evt.target) {
                btn = evt.target.closest('a') || evt.target;
            }
        } catch (err) {
            console.log('Could not find button element, proceeding with copy anyway.');
        }

        const url = window.location.origin + '/setup-password?token=' + token;

        // 2. Define Feedback (Text change OR Toast)
        const showSuccess = () => {
            // A. If we have the button, change the text
            if (btn) {
                const originalColor = btn.style.color;
                const textNode = Array.from(btn.childNodes).find(node =>
                    node.nodeType === Node.TEXT_NODE && node.textContent.trim().length > 0
                );
                const originalText = textNode ? textNode.textContent : '';

                // Green text change
                btn.style.color = '#2ecc71';
                if (textNode) textNode.textContent = ' Link copied';

                setTimeout(() => {
                    btn.style.color = originalColor;
                    if (textNode) textNode.textContent = originalText;
                }, 2000);
            }
            // B. Fallback: Show a global toast if we missed the button
            else {
                const toast = document.createElement('div');
                toast.innerText = 'Link Copied';
                toast.style.cssText = 'position:fixed; bottom:20px; left:50%; transform:translateX(-50%); background:#2ecc71; color:white; padding:8px 16px; border-radius:4px; font-size:12px; font-weight:bold; z-index:9999; box-shadow:0 2px 10px rgba(0,0,0,0.3);';
                document.body.appendChild(toast);
                setTimeout(() => toast.remove(), 2000);
            }
        };

        // 3. Execute Copy (Modern -> Fallback -> Prompt)
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(url)
                .then(showSuccess)
                .catch(err => {
                    console.warn('Async copy failed, trying manual fallback');
                    manualCopy(url);
                });
        } else {
            manualCopy(url);
        }

        // Helper: Old school textarea hack
        function manualCopy(text) {
            const textArea = document.createElement("textarea");
            textArea.value = text;

            // Avoid scrolling to bottom
            textArea.style.top = "0";
            textArea.style.left = "0";
            textArea.style.position = "fixed";
            textArea.style.opacity = "0";

            document.body.appendChild(textArea);
            textArea.focus();
            textArea.select();

            try {
                const successful = document.execCommand('copy');
                if (successful) {
                    showSuccess();
                } else {
                    prompt("Copy this link:", text);
                }
            } catch (err) {
                prompt("Copy this link:", text);
            }
            document.body.removeChild(textArea);
        }
    }

    /* --- ADD TO YOUR <script> BLOCK --- */
    document.getElementById('zdUserSearch').addEventListener('keyup', function() {
        const searchTerm = this.value.toLowerCase();
        const rows = document.querySelectorAll('.zd-users-table tbody tr');

        rows.forEach(row => {
            // Search in Name (column 1) and Email (column 2)
            const name = row.querySelector('.zd-users-name').textContent.toLowerCase();
            const email = row.querySelector('.zd-users-email').textContent.toLowerCase();

            if (name.includes(searchTerm) || email.includes(searchTerm)) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        });
    });
</script>

<?php $this->end(); ?>