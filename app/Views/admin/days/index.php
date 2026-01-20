<?php

/** @var array $project */
/** @var array $days */
/** @var array $routes */

$this->extend('layout/main');

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
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
    }

    /* --- Header --- */
    .zd-header {
        margin-bottom: 20px;
        /* No flex here, we stack the H1 and the button row */
    }

    .zd-header h1 {
        font-size: 20px;
        font-weight: 700;
        letter-spacing: -0.01em;
        color: var(--zd-text-main);
        line-height: 1;

        /* Spacing */
        margin: 0 0 15px 0;
        padding-bottom: 15px;
        /* Space between text and line */
        position: relative;
    }

    /* The Line */
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

    /* Subtitle Row inside H1 */
    .zd-header-subtitle {
        display: block;
        margin-top: 8px;
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
        border-radius: 6px;
        /* CHANGE: was 'hidden' - this was the main "wall" cutting the menu off */
        overflow: visible !important;
        background: var(--zd-bg-panel);
        border: 1px solid var(--zd-border-subtle);
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.15);
    }

    table.zd-table {
        width: 100%;
        border-collapse: collapse;
        /* CHANGE: table-layout: fixed is very rigid; switching to auto lets the menu breathe */
        table-layout: auto;
        font-size: 13px;
        overflow: visible !important;
    }

    .zd-table thead th {
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

    .zd-table tbody td {
        padding: 12px 16px;
        border-top: 1px solid var(--zd-border-subtle);
        color: var(--zd-text-main);
        /* CHANGE: was 'hidden' - this clipped the menu horizontally */
        overflow: visible !important;
        white-space: nowrap;
        vertical-align: middle;
        font-size: 14px;
    }

    .zd-table tbody tr {
        cursor: pointer;
    }

    .zd-table tbody tr:hover {
        background: rgba(58, 160, 255, 0.06);
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
</style>
<?php $this->end(); ?>

<?php $this->start('content'); ?>

<div class="zd-page" data-project="<?= htmlspecialchars($project['project_uuid']) ?>" data-csrf="<?= htmlspecialchars(\App\Support\Csrf::token()) ?>">

    <div class="zd-header">
        <h1>
            Shooting Days

            <?php if ($isPowerUser): ?>
                <div class="zd-header-subtitle">
                    <span><?= htmlspecialchars($project['title'] ?? '') ?></span>
                    <span style="opacity: 0.3; margin: 0 6px;">|</span>
                    <span class="zd-chip"><?= htmlspecialchars($project['code'] ?? '') ?></span>
                    <span class="zd-chip <?= $statusClass ?>"><?= htmlspecialchars($status) ?></span>
                </div>
            <?php else: ?>
                <div class="zd-header-subtitle">
                    for <?= htmlspecialchars($project['title'] ?? '') ?>
                </div>
            <?php endif; ?>
        </h1>
    </div>

    <?php if ($isPowerUser): ?>
        <div class="zd-action-row">
            <a class="zd-btn" href="<?= htmlspecialchars($routes['new_day'] ?? '') ?>">+ New Day</a>
        </div>
    <?php endif; ?>

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
                        $visibilityLabel  = $isPublished ? 'Public' : 'Internal';
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
                            <td style="font-family: 'Menlo', monospace;"><?= (int)$d['clip_count'] ?></td>

                            <?php if ($isPowerUser): ?>
                                <td style="text-align: center;">
                                    <span class="zd-chip <?= htmlspecialchars($visibilityClass) ?> zd-day-visibility-chip"
                                        data-day-chip="<?= htmlspecialchars($dayUuid) ?>">
                                        <?= htmlspecialchars($visibilityLabel) ?>
                                    </span>
                                </td>

                                <td class="zd-users-actions">
                                    <?php
                                    /* --- ENSURE EVERY ACTION HAS A 'link' KEY --- */
                                    $actions = [
                                        [
                                            'label' => 'Edit Day',
                                            'link'  => $editUrl,
                                            'icon'  => 'pencil',
                                            'method' => 'GET'
                                        ]
                                    ];

                                    if ($isPublished) {
                                        $actions[] = [
                                            'label' => 'Unpublish',
                                            'link'  => '#', // Fixes the PHP error on line 32
                                            'icon'  => 'eye-slash', // Use your new eye-slash.svg
                                            'class' => 'zd-day-unpublish-btn',
                                            'attr'  => 'data-unpublish-url="' . htmlspecialchars($unpublishUrl) . '"',
                                            'method' => 'BUTTON'
                                        ];
                                    } else {
                                        $actions[] = [
                                            'label' => 'Publish',
                                            'link'  => '#', // Fixes the PHP error on line 32
                                            'icon'  => 'eye', // Use your new eye.svg
                                            'class' => 'zd-day-publish-btn',
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
                <p style="margin-top: 0.75rem; font-size: 0.85rem; opacity: 0.5;">
                    (Email sending will be implemented later – this checkbox is just stored for now.)
                </p>
            </div>
        </div>
        <div class="zd-publish-footer">
            <button type="button" class="zd-btn" style="background: transparent; border: 1px solid var(--zd-border-subtle); color: var(--zd-text-muted);" id="zd-publish-cancel">Cancel</button>
            <button type="button" class="zd-btn" id="zd-publish-confirm">Confirm</button>
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
                if (!e.target.closest('.zd-actions-menu') &&
                    !e.target.closest('.zd-day-publish-btn') &&
                    !e.target.closest('.zd-day-unpublish-btn')) {
                    window.location.href = this.dataset.href;
                }
            });
        });
    });
</script>

<?php $this->end(); ?>