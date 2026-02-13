<?php

/** @var array $project */
/** @var array $days */
/** @var array $routes */
$navActive = 'days';
$this->extend('layout/main');
$navActive = 'days';
$this->start('head'); ?>
<title><?= htmlspecialchars($project['title']) ?> · Shooting Days · Zentropa Dailies</title>
<?php
// Determine status chip class
$status = $project['status'] ?? 'inactive';
$statusClass = $status === 'active' ? 'zd-chip-ok' : 'zd-chip-danger';

// Power-user flags coming from controller (superuser or project admin)
$isSuperuser    = (int)($isSuperuser    ?? 0);
$isProjectAdmin = (int)($isProjectAdmin ?? 0);
$isPowerUser    = ($isSuperuser === 1 || $isProjectAdmin === 1);
?>

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
        --zd-danger: #e74c3c;
    }

    /* --- Page Layout --- */
    .zd-page {
        max-width: 1000px;
        margin: 0 auto;
        color: var(--zd-text-main);
        padding: 25px 20px;
    }

    .zd-header {
        margin-bottom: 25px !important;
    }

    .zd-header h1 {
        font-size: 20px;
        font-weight: 700;
        margin: 0 0 20px 0 !important;
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

    .zd-header-subtitle {
        display: block;
        margin-top: 10px;
        font-size: 13px;
        font-weight: 400;
        color: var(--zd-text-muted);
    }

    .zd-header-subtitle span {
        display: inline-block;
        vertical-align: middle;
    }

    /* Action Row (Below the line) */
    .zd-action-row {
        display: flex;
        justify-content: flex-start;
        /* Aligns button to the left */
        margin-bottom: 20px;
    }

    /* --- Button --- */
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

    /* --- Table Layout --- */
    .zd-table-wrap {
        border-radius: 8px;
        background: var(--zd-bg-panel);
        border: 1px solid var(--zd-border-subtle);
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
        overflow: visible !important;
        margin-top: 10px;
    }

    table.zd-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 13px;
        overflow: visible !important;
    }

    .zd-table thead th {
        text-align: left;
        padding: 12px 16px;
        background: #171922;
        font-family: "Inter", -apple-system, sans-serif !important;
        font-size: 0.8rem !important;
        text-transform: uppercase;
        font-weight: 700;
        color: var(--zd-text-muted);
        border-bottom: 1px solid var(--zd-border-subtle);
    }

    .zd-table tbody td {
        padding: 14px 16px;
        border-top: 1px solid var(--zd-border-subtle);
        vertical-align: middle;
        overflow: visible !important;
    }

    .zd-table tbody tr {
        cursor: pointer;
    }

    .zd-table tbody tr:hover {
        background: rgba(58, 160, 255, 0.04);
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

    .zd-chip-ok {
        background: rgba(46, 204, 113, 0.1);
        color: var(--zd-success);
        border: 1px solid rgba(46, 204, 113, 0.2);
    }

    .zd-chip-danger {
        background: rgba(231, 76, 60, 0.1);
        color: var(--zd-danger);
        border: 1px solid rgba(231, 76, 60, 0.2);
    }

    .zd-chip--public {
        background: rgba(46, 204, 113, 0.1);
        color: #8ff0a4;
        border: 1px solid rgba(46, 204, 113, 0.2);
    }

    .zd-chip--internal {
        background: rgba(231, 76, 60, 0.1);
        color: #f5b5b5;
        border: 1px solid rgba(231, 76, 60, 0.2);
    }

    /* --- Actions --- */
    .zd-actions-cell {
        display: flex;
        justify-content: flex-end;
        gap: 6px;
    }

    .zd-icon-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 28px;
        height: 28px;
        border-radius: 6px;
        transition: background 0.2s ease;
        background: transparent;
        border: none;
        cursor: pointer;
        padding: 0;
    }

    .zd-icon-btn:hover {
        background: rgba(255, 255, 255, 0.08);
    }

    .zd-icon-svg {
        width: 16px;
        height: 16px;
        fill: none;
        stroke: var(--zd-text-muted);
        stroke-width: 2;
        stroke-linecap: round;
        stroke-linejoin: round;
        transition: stroke 0.2s;
    }

    .zd-icon-btn:hover .zd-icon-svg {
        stroke: var(--zd-text-main);
    }

    /* Specific Colors */
    .zd-icon-btn.is-danger:hover .zd-icon-svg {
        stroke: var(--zd-danger);
    }

    /* --- Sorting Headers --- */
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

    /* --- Publish Modal Backdrop --- */
    .zd-publish-backdrop {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.75);
        display: none;
        /* CHANGED: Hide it by default */
        align-items: center;
        justify-content: center;
        z-index: 2000;
    }

    /* --- The Modal Box --- */
    .zd-publish-modal {
        background: var(--zd-bg-panel);
        border: 1px solid var(--zd-border-subtle);
        border-radius: 8px;
        width: 100%;
        max-width: 440px;
        /* Professional compact width */
        box-shadow: 0 20px 60px rgba(0, 0, 0, 0.5);
        overflow: hidden;
        animation: zdModalFadeIn 0.2s ease-out;
    }

    @keyframes zdModalFadeIn {
        from {
            opacity: 0;
            transform: translateY(10px);
        }

        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .zd-publish-head {
        padding: 20px 24px 15px;
        border-bottom: 1px solid var(--zd-border-subtle);
    }

    .zd-publish-head h2 {
        margin: 0;
        font-size: 18px;
        font-weight: 700;
        color: var(--zd-text-main);
    }

    .zd-publish-body {
        padding: 24px;
    }

    .zd-publish-body p {
        margin: 0 0 15px;
        font-size: 14px;
        line-height: 1.5;
        color: var(--zd-text-main);
    }

    .zd-publish-checkbox {
        display: flex;
        align-items: center;
        gap: 10px;
        cursor: pointer;
        user-select: none;
        margin-top: 20px;
        padding: 12px;
        background: rgba(255, 255, 255, 0.03);
        border-radius: 6px;
        border: 1px solid var(--zd-border-subtle);
    }

    .zd-publish-checkbox input {
        width: 16px;
        height: 16px;
        accent-color: var(--zd-accent);
    }

    .zd-publish-footer {
        padding: 16px 24px;
        background: rgba(0, 0, 0, 0.2);
        display: flex;
        justify-content: flex-end;
        gap: 12px;
        border-top: 1px solid var(--zd-border-subtle);
    }

    .zd-table tbody tr {
        position: relative;
        z-index: 1;
    }

    .zd-table tbody tr:hover,
    .zd-table tbody tr:has(.is-active) {
        z-index: 100 !important;
    }

    /* Ensure the dropdown is wide enough for "Manage Members" */
    .zd-actions-content {
        min-width: 200px !important;
        white-space: nowrap;
        z-index: 9999 !important;
    }

    /* Security Chips */
    .zd-security-cell {
        display: flex;
        flex-wrap: wrap;
        gap: 4px;
    }

    .zd-sec-chip {
        display: inline-flex;
        align-items: center;
        padding: 2px 6px;
        border-radius: 4px;
        font-size: 10px;
        font-weight: 700;
        text-transform: uppercase;
        color: #fff;
        background: #444;
        /* fallback */
        box-shadow: 0 1px 2px rgba(0, 0, 0, 0.3);
        border: 1px solid rgba(255, 255, 255, 0.1);
    }

    /* Security Chips */
    .zd-security-cell {
        display: flex;
        flex-wrap: wrap;
        gap: 4px;
        align-items: center;
        /* Ensures chips align with each other */
    }

    .zd-clips-table .col-restricted {
        vertical-align: middle !important;
    }

    .zd-pro-menu {
        display: flex;
        align-items: center;
        height: 100%;
    }

    /* Optional: Ensure the button inside doesn't have stray margins */
    .zd-pro-trigger {
        margin: 0;
    }
</style>
<?php $this->end(); ?>

<?php $this->start('content'); ?>

<div class="zd-page" data-project="<?= htmlspecialchars($project['project_uuid']) ?>" data-csrf="<?= htmlspecialchars(\App\Support\Csrf::token()) ?>">

    <?php if (!empty($_SESSION['flash_success'])): ?>
        <div style="background: rgba(46, 204, 113, 0.1); border: 1px solid var(--zd-success); color: var(--zd-success); padding: 12px 16px; border-radius: 6px; margin-bottom: 20px; font-size: 13px; font-weight: 600;">
            <?= htmlspecialchars($_SESSION['flash_success']) ?>
            <?php unset($_SESSION['flash_success']); ?>
        </div>
    <?php endif; ?>

    <div class="zd-header">
        <h1>
            Shooting Days
            <div class="zd-header-subtitle">
                <span><?= htmlspecialchars($project['title'] ?? '') ?></span>
                <span style="opacity: 0.3; margin: 0 6px;">|</span>
                <span class="zd-cell-mono" style="font-size:11px;"><?= htmlspecialchars($project['code'] ?? '') ?></span>
                <span class="zd-chip zd-chip-ok" style="margin-left:8px;"><?= htmlspecialchars($project['status'] ?? 'active') ?></span>
            </div>
        </h1>

        <div class="zd-action-row" style="display: flex; justify-content: space-between; align-items: center; margin-top: 20px;">
            <?php if ($isPowerUser): ?>
                <a class="zd-btn" href="<?= htmlspecialchars($routes['new_day'] ?? '') ?>">+ New Day</a>
            <?php else: ?>
                <div></div>
            <?php endif; ?>

            <div style="width: 220px;" class="hide-on-narrow"></div>
        </div>
    </div>


    <div class="zd-table-wrap">
        <?php if (empty($days)): ?>
            <div style="padding: 20px; color: var(--zd-text-muted); font-size: 13px;">
                <?php if ($isPowerUser): ?>
                    No days yet. (Try Import or add a day manually.)
                    <div style="margin-top:10px">
                        <a class="zd-btn" href="<?= htmlspecialchars($routes['new_day'] ?? '') ?>">Create the first day</a>
                    </div>
                <?php else: ?>
                    No published days yet.
                <?php endif; ?>
            </div>
        <?php else: ?>

            <table class="zd-table">
                <?php
                // Helper for sort headers
                $sortLink = function (string $key, string $label) use ($sort, $dir) {
                    $isCurrent = ($sort === $key);
                    $nextDir = ($isCurrent && $dir === 'ASC') ? 'DESC' : 'ASC';
                    $href = "?sort={$key}&dir={$nextDir}";
                    $activeClass = $isCurrent ? 'is-active' : '';
                    $dirClass    = $isCurrent ? 'sort-' . strtolower($dir) : '';

                    echo "<a href=\"{$href}\" class=\"zd-sortable-header {$activeClass} {$dirClass}\">";
                    echo "<span>" . htmlspecialchars($label) . "</span>";
                    echo '<svg viewBox="0 0 24 24" class="icon zd-sort-icon" aria-hidden="true">';
                    echo '<path class="zd-arrow-up" d="M12 4l-4 4h8z" />';
                    echo '<path class="zd-arrow-down" d="M12 20l4-4H8z" />';
                    echo '</svg></a>';
                }
                ?>
                <thead>
                    <tr>
                        <th style="width: 25%;"><?php $sortLink('title', 'Title'); ?></th>
                        <th style="width: 20%;"><?php $sortLink('shoot_date', 'Date'); ?></th>
                        <th style="width: 15%;"><?php $sortLink('unit', 'Unit'); ?></th>
                        <?php if ($isPowerUser): ?>
                            <th style="width: 15%;">Security</th>
                        <?php endif; ?>
                        <th style="width: 10%;"><?php $sortLink('clip_count', 'Clips'); ?></th>

                        <?php if ($isPowerUser): ?>
                            <th style="width: 15%; text-align: center;">Visibility</th>
                            <th style="width: 15%; text-align: right;">Actions</th>
                        <?php endif; ?>
                    </tr>
                </thead>

                <tbody>
                    <?php foreach ($days as $d): ?>
                        <?php
                        $dayUuid = $d['day_uuid'];

                        // Determine view URL
                        if ($isPowerUser) {
                            $viewUrl = $routes['clips_base'] . '/' . $dayUuid . '/clips';
                        } else {
                            $viewUrl = $routes['player_base'] . '/' . $dayUuid . '/player';
                        }

                        $editUrl = "/admin/projects/{$project['project_uuid']}/days/{$dayUuid}/edit";
                        $delUrl  = "/admin/projects/{$project['project_uuid']}/days/{$dayUuid}/delete";

                        // Publish info
                        $isPublished      = !empty($d['published_at'] ?? null);
                        $visibilityLabel  = $isPublished ? 'Published' : 'Internal';
                        $visibilityClass  = $isPublished ? 'zd-chip--public' : 'zd-chip--internal';

                        $publishUrl   = "/admin/projects/{$project['project_uuid']}/days/{$dayUuid}/publish";
                        $unpublishUrl = "/admin/projects/{$project['project_uuid']}/days/{$dayUuid}/unpublish";
                        ?>
                        <tr class="zd-clickable-row"
                            data-href="<?= htmlspecialchars($viewUrl) ?>"
                            data-day-uuid="<?= htmlspecialchars($dayUuid) ?>"
                            data-published="<?= $isPublished ? '1' : '0' ?>">

                            <td style="font-weight: 600;"><?= htmlspecialchars($d['title'] ?: ('Day ' . $d['shoot_date'])) ?></td>
                            <td style="font-family: 'Menlo', monospace; color: var(--zd-text-muted);"><?= htmlspecialchars($d['shoot_date']) ?></td>
                            <td><?= htmlspecialchars($d['unit'] ?? '') ?></td>

                            <?php if ($isPowerUser): ?>
                                <td>
                                    <div class="zd-security-cell">
                                        <?php
                                        $secGroups = !empty($d['security_groups_json']) ? json_decode($d['security_groups_json'], true) : [];
                                        $secGroupIds = array_column($secGroups ?? [], 'uuid');
                                        $secGroupIdsJson = htmlspecialchars(json_encode($secGroupIds));
                                        ?>

                                        <input type="hidden" class="zd-day-security-data" value="<?= $secGroupIdsJson ?>">

                                        <?php if (empty($secGroups)): ?>
                                            <span class="zd-chip zd-chip--public">Public</span>
                                        <?php else: ?>
                                            <?php foreach ($secGroups as $sg): ?>
                                                <span class="zd-sec-chip"
                                                    style="background-color: <?= htmlspecialchars($sg['color'] ?? '#d9534f') ?>;">
                                                    <?= htmlspecialchars($sg['name']) ?>
                                                </span>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            <?php endif; ?>

                            <td style="font-family: 'Menlo', monospace;"><?= (int)$d['clip_count'] ?></td>

                            <?php if ($isPowerUser): ?>
                                <td style="text-align: center;">
                                    <span class="zd-chip <?= htmlspecialchars($visibilityClass) ?> zd-day-visibility-chip"
                                        data-day-chip="<?= htmlspecialchars($dayUuid) ?>">
                                        <?= htmlspecialchars($visibilityLabel) ?>
                                    </span>
                                </td>

                                <td style="text-align: right; overflow: visible !important;">
                                    <?php
                                    // Re-mapping your existing variables to the new $actions standard
                                    $actions = [
                                        [
                                            'label' => 'Edit Day',
                                            'link'  => "/admin/projects/{$project['project_uuid']}/days/{$dayUuid}/edit",
                                            'icon'  => 'pencil',
                                            'method' => 'GET'
                                        ],
                                        // NEW: Restrict Button
                                        [
                                            'label' => 'Manage Access',
                                            'link'  => '#',
                                            'icon'  => 'lock-closed', // Ensure you have a lock icon or use 'shield'
                                            'class' => 'zd-day-restrict-btn',
                                            'attr'  => 'data-day-uuid="' . htmlspecialchars($dayUuid) . '"',
                                            'method' => 'BUTTON'
                                        ]
                                    ];

                                    if ($isPublished) {
                                        $actions[] = [
                                            'label' => 'Unpublish',
                                            'link'  => '#',
                                            'icon'  => 'eye-slash',
                                            'class' => 'zd-day-unpublish-btn', // KEEPING YOUR JS CLASS
                                            'attr'  => 'data-unpublish-url="' . htmlspecialchars($unpublishUrl) . '"',
                                            'method' => 'BUTTON'
                                        ];
                                    } else {
                                        $actions[] = [
                                            'label' => 'Publish',
                                            'link'  => '#',
                                            'icon'  => 'eye',
                                            'class' => 'zd-day-publish-btn', // KEEPING YOUR JS CLASS
                                            'attr'  => 'data-publish-url="' . htmlspecialchars($publishUrl) . '"',
                                            'method' => 'BUTTON'
                                        ];
                                    }

                                    include __DIR__ . '/../../partials/actions_dropdown.php';
                                    ?>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

</div>

<?php $this->end(); ?>

<?php $this->start('scripts'); ?>

<div class="zd-publish-backdrop" id="zd-publish-backdrop">
    <div class="zd-publish-modal">
        <div class="zd-publish-head">
            <h2 id="zd-modal-title">Publish day</h2>
        </div>
        <div class="zd-publish-body">
            <p id="zd-modal-message">Are you sure you want to publish this day?</p>
            <div id="zd-modal-email-block">
                <label class="zd-publish-checkbox">
                    <input type="checkbox" id="zd-publish-send-email" value="1">
                    <span>Send email to users with link to this day</span>
                </label>
            </div>
            <div id="zd-modal-publish-extra">
                <p style="margin-top: 0.5rem; color: #9aa7b2;">
                    When published, this day becomes visible to regular users on the project.
                </p>
            </div>
        </div>
        <div class="zd-publish-footer">
            <button type="button" class="zd-btn" style="background: transparent; border: 1px solid var(--zd-border-subtle); color: var(--zd-text-muted);" id="zd-publish-cancel">Cancel</button>
            <button type="button" class="zd-btn" id="zd-publish-confirm">Confirm</button>
        </div>
    </div>
</div>

<div class="zd-publish-backdrop" id="zd-security-backdrop">
    <div class="zd-publish-modal">
        <div class="zd-publish-head">
            <h2>Day Security</h2>
        </div>
        <div class="zd-publish-body">
            <p style="color: var(--zd-text-muted); margin-bottom: 15px;">
                Select security groups allowed to see this day.
                <br><span style="font-size: 12px; opacity: 0.7;">(If no groups are selected, the day is visible to everyone on the project.)</span>
            </p>

            <form id="zd-security-form">
                <div class="zd-security-list" style="max-height: 250px; overflow-y: auto; background: rgba(0,0,0,0.2); border: 1px solid var(--zd-border-subtle); border-radius: 4px; padding: 10px;">
                    <?php if (empty($allSecurityGroups)): ?>
                        <div style="padding: 10px; color: var(--zd-text-muted);">No security groups defined.</div>
                    <?php else: ?>
                        <?php foreach ($allSecurityGroups as $g): ?>
                            <label class="zd-publish-checkbox" style="margin-top: 5px; margin-bottom: 5px; padding: 8px;">
                                <input type="checkbox" name="groups[]" value="<?= htmlspecialchars($g['id']) ?>" class="zd-security-cb">
                                <span class="zd-sec-chip" style="background-color: <?= htmlspecialchars($g['color'] ?? '#d9534f') ?>; margin-left: 8px;">
                                    <?= htmlspecialchars($g['name']) ?>
                                </span>
                            </label>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </form>
        </div>
        <div class="zd-publish-footer">
            <button type="button" class="zd-btn" style="background: transparent; border: 1px solid var(--zd-border-subtle); color: var(--zd-text-muted);" id="zd-security-cancel">Cancel</button>
            <button type="button" class="zd-btn" id="zd-security-save">Save Access</button>
        </div>
    </div>
</div>

<script>
    document.addEventListener("DOMContentLoaded", function() {
        const pageEl = document.querySelector(".zd-page");
        const csrfToken = pageEl ? (pageEl.dataset.csrf || "") : "";

        const backdrop = document.getElementById("zd-publish-backdrop");
        const btnCancel = document.getElementById("zd-publish-cancel");
        const btnConfirm = document.getElementById("zd-publish-confirm");
        const titleEl = document.getElementById("zd-modal-title");
        const messageEl = document.getElementById("zd-modal-message");
        const sendEmailBlk = document.getElementById("zd-modal-email-block");
        const sendEmailInp = document.getElementById("zd-publish-send-email");
        const publishExtra = document.getElementById("zd-modal-publish-extra");

        let currentMode = "publish";
        let currentRow = null;
        let targetUrl = "";

        function openModal(mode, row, url) {
            currentMode = mode;
            currentRow = row;
            targetUrl = url;

            if (mode === "publish") {
                titleEl.textContent = "Publish day";
                messageEl.textContent = "Are you sure you want to publish this day?";
                btnConfirm.textContent = "Publish";
                sendEmailBlk.style.display = "block";
                publishExtra.style.display = "block";
                sendEmailInp.checked = false;
            } else {
                titleEl.textContent = "Unpublish day";
                messageEl.textContent = "Unpublish this day? It will no longer be visible to regular users.";
                btnConfirm.textContent = "Unpublish";
                sendEmailBlk.style.display = "none";
                publishExtra.style.display = "none";
            }

            backdrop.style.display = "flex";
        }

        function closeModal() {
            backdrop.style.display = "none";
            currentRow = null;
            targetUrl = "";
        }

        // Use CAPTURE phase (true as third parameter) to catch the event before stopPropagation
        document.addEventListener("click", function(e) {
            const pubBtn = e.target.closest(".zd-day-publish-btn");
            const unpubBtn = e.target.closest(".zd-day-unpublish-btn");

            if (pubBtn) {
                e.preventDefault();
                e.stopPropagation();

                const row = pubBtn.closest("tr");
                const url = pubBtn.getAttribute("data-publish-url");

                if (row && url) {
                    openModal("publish", row, url);
                }
                return;
            }

            if (unpubBtn) {
                e.preventDefault();
                e.stopPropagation();

                const row = unpubBtn.closest("tr");
                const url = unpubBtn.getAttribute("data-unpublish-url");

                if (row && url) {
                    openModal("unpublish", row, url);
                }
                return;
            }
        }, true); // <<< TRUE = capture phase, runs BEFORE any stopPropagation

        if (btnCancel) btnCancel.addEventListener("click", closeModal);
        if (backdrop) backdrop.addEventListener("click", e => {
            if (e.target === backdrop) closeModal();
        });

        if (btnConfirm) {
            btnConfirm.addEventListener("click", async function() {
                if (!currentRow || !targetUrl) {
                    closeModal();
                    return;
                }

                const payload = new URLSearchParams();
                if (csrfToken) payload.append("_csrf", csrfToken);
                if (currentMode === "publish") {
                    payload.append("send_email", sendEmailInp.checked ? "1" : "0");
                }

                try {
                    const response = await fetch(targetUrl, {
                        method: "POST",
                        headers: {
                            "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8",
                            "X-Requested-With": "XMLHttpRequest"
                        },
                        body: payload.toString()
                    });

                    if (response.ok || response.status === 302) {
                        window.location.reload();
                    } else {
                        console.error("Action failed", response.status);
                    }
                } catch (err) {
                    console.error("Network error:", err);
                } finally {
                    closeModal();
                }
            });
        }

        // Row click navigation
        document.querySelectorAll('.zd-clickable-row').forEach(row => {
            row.addEventListener('click', function(e) {
                // Stop navigation if clicking the Actions menu or its contents
                if (e.target.closest('.zd-pro-menu') ||
                    e.target.closest('.zd-day-publish-btn') ||
                    e.target.closest('.zd-day-unpublish-btn')) {
                    return;
                }

                window.location.href = this.dataset.href;
            });
        });
    });

    // --- Security Modal Logic ---
    const secBackdrop = document.getElementById("zd-security-backdrop");
    const secCancel = document.getElementById("zd-security-cancel");
    const secSave = document.getElementById("zd-security-save");
    const secCheckboxes = document.querySelectorAll(".zd-security-cb");

    // 1. INJECT PHP VARIABLES HERE (Fixes ReferenceError)
    const currentProjectUuid = "<?= htmlspecialchars($project['project_uuid'] ?? '') ?>";
    const currentCsrfToken = "<?= \App\Support\Csrf::token() ?>";

    let activeDayUuid = null;

    function closeSecModal() {
        if (secBackdrop) secBackdrop.style.display = "none";
        activeDayUuid = null;
    }

    if (secCancel) secCancel.addEventListener("click", closeSecModal);
    if (secBackdrop) secBackdrop.addEventListener("click", (e) => {
        if (e.target === secBackdrop) closeSecModal();
    });

    // Capture click on "Manage Access" in dropdown
    document.addEventListener("click", function(e) {
        const btn = e.target.closest(".zd-day-restrict-btn");
        if (btn) {
            e.preventDefault();
            e.stopPropagation();

            activeDayUuid = btn.getAttribute("data-day-uuid");
            const row = btn.closest("tr");

            // Get currently assigned groups from the hidden input in the row
            const dataInput = row.querySelector(".zd-day-security-data");
            const currentGroups = dataInput ? JSON.parse(dataInput.value) : [];

            // Reset checkboxes
            secCheckboxes.forEach(cb => {
                cb.checked = currentGroups.includes(cb.value);
            });

            if (secBackdrop) secBackdrop.style.display = "flex";
        }
    }, true);

    if (secSave) {
        secSave.addEventListener("click", async function() {
            if (!activeDayUuid) {
                console.error("No active day UUID found.");
                return;
            }

            const selected = [];
            secCheckboxes.forEach(cb => {
                if (cb.checked) selected.push(cb.value);
            });

            // Prepare Payload
            const payload = new URLSearchParams();
            // USE THE INJECTED TOKEN VARIABLE
            payload.append("_csrf", currentCsrfToken);
            selected.forEach(id => payload.append("groups[]", id));

            // USE THE INJECTED PROJECT UUID
            const targetUrl = `/admin/projects/${currentProjectUuid}/days/${activeDayUuid}/restrict`;

            try {
                // UI Feedback
                const originalText = secSave.textContent;
                secSave.textContent = "Saving...";
                secSave.disabled = true;

                const response = await fetch(targetUrl, {
                    method: "POST",
                    headers: {
                        "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8",
                        "X-Requested-With": "XMLHttpRequest"
                    },
                    body: payload.toString()
                });

                if (response.ok) {
                    window.location.reload();
                } else {
                    console.error("Server returned:", response.status);
                    alert("Failed to save permissions.");
                    secSave.textContent = originalText;
                    secSave.disabled = false;
                }
            } catch (err) {
                console.error(err);
                alert("Network error.");
                secSave.textContent = "Save Access";
                secSave.disabled = false;
            }
        });
    }
</script>

<?php $this->end(); ?>