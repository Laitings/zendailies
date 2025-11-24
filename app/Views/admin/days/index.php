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
    /* --- Button Style (Matched to Clips Page) --- */
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

    /* Visibility chips */
    .zd-chip--public {
        background: #10331e;
        color: #8ff0a4;
    }

    .zd-chip--internal {
        background: #331010;
        color: #f5b5b5;
    }

    /* Center align the visibility chip */
    .td-visibility {
        text-align: center;
    }

    /* --- Table styles from users/index.php --- */
    .zd-users-table-wrap {
        margin-top: 16px;
        max-width: 900px;
        margin-left: auto;
        margin-right: auto;
        border-radius: 12px;
        overflow: hidden;
        background: var(--panel, #111318);
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.35);
    }

    .page-head {
        max-width: 900px;
        /* same width you used for the table */
        margin-left: auto;
        margin-right: auto;
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

    .zd-users-table tbody tr {
        cursor: pointer;
    }

    .col-actions {
        text-align: right;
        width: 140px;
    }

    /* Fix for publish/unpublish icons */
    .col-actions .icon {
        stroke: var(--text);
        /* white */
        fill: none;
    }

    /* --- Sortable Headers from users/index.php --- */
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

    .zd-sort-icon {

        display: block;
        flex-shrink: 0;
        transition: fill 0.15s ease, opacity 0.15s ease;
    }

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
</style>

<?php $this->end(); ?>

<?php $this->start('content'); ?>

<div class="zd-page" data-project="<?= htmlspecialchars($project['project_uuid']) ?>" data-csrf="<?= htmlspecialchars(\App\Support\Csrf::token()) ?>">

    <header class="page-head">
        <?php if ($isPowerUser): ?>
            <h1>Shooting days</h1>
            <p class="subtitle">
                Project: <?= htmlspecialchars($project['title'] ?? '') ?> &middot;
                Code: <span class="zd-chip"><?= htmlspecialchars($project['code'] ?? '') ?></span> &middot;
                Status: <span class="zd-chip <?= $statusClass ?>"><?= htmlspecialchars($status) ?></span>
            </p>
            <div class="actions">
                <a class="zd-btn" href="<?= htmlspecialchars($routes['new_day'] ?? '') ?>">+ New Day</a>
            </div>
        <?php else: ?>
            <h1>Shooting days for <?= htmlspecialchars($project['title'] ?? '') ?></h1>
        <?php endif; ?>
    </header>

    <div class="zd-users-table-wrap">
        <?php if (empty($days)): ?>
            <?php if ($isPowerUser): ?>
                <div class="zd-empty">No days yet. (Try Import or add a day manually.)</div>
                <div style="margin-top:10px">
                    <a class="zd-btn" href="<?= htmlspecialchars($routes['new_day'] ?? '') ?>">Create the first day</a>
                </div>
            <?php else: ?>
                <div class="zd-empty">No published days yet.</div>
            <?php endif; ?>
        <?php else: ?>

            <table class="zd-users-table">
                <?php
                // Helper function to generate sortable header links
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
                    echo '</svg>';
                    echo "</a>";
                }
                ?>
                <thead>
                    <tr>
                        <th style="width: 25%;"><?php $sortLink('title', 'Title'); ?></th>
                        <th style="width: 20%;"><?php $sortLink('shoot_date', 'Date'); ?></th>
                        <th style="width: 15%;"><?php $sortLink('unit', 'Unit'); ?></th>
                        <th style="width: 10%;"><?php $sortLink('clip_count', 'Clips'); ?></th>

                        <?php if ($isPowerUser): ?>
                            <th style="width: 10%; text-align: center;">Visibility</th>
                            <th class="col-actions" style="text-align: right;">Actions</th>
                        <?php endif; ?>
                    </tr>
                </thead>


                <tbody>
                    <?php foreach ($days as $d): ?>
                        <?php
                        $dayUuid = $d['day_uuid'];

                        // Power users go to clips index, regular users go to player for that day
                        if ($isPowerUser) {
                            $viewUrl = $routes['clips_base'] . '/' . $dayUuid . '/clips';
                        } else {
                            $viewUrl = $routes['player_base'] . '/' . $dayUuid . '/player';
                        }

                        $editUrl = "/admin/projects/{$project['project_uuid']}/days/{$dayUuid}/edit";
                        $delUrl  = "/admin/projects/{$project['project_uuid']}/days/{$dayUuid}/delete";


                        // NEW: publish / visibility info
                        $isPublished      = !empty($d['published_at'] ?? null);
                        $visibilityLabel  = $isPublished ? 'Public' : 'Internal';
                        $visibilityClass  = $isPublished ? 'zd-chip zd-chip--public' : 'zd-chip zd-chip--internal';

                        $publishUrl   = "/admin/projects/{$project['project_uuid']}/days/{$dayUuid}/publish";
                        $unpublishUrl = "/admin/projects/{$project['project_uuid']}/days/{$dayUuid}/unpublish";
                        ?>
                        <tr onclick="window.location.href='<?= htmlspecialchars($viewUrl) ?>'"
                            data-day-uuid="<?= htmlspecialchars($dayUuid) ?>"
                            data-published="<?= $isPublished ? '1' : '0' ?>">

                            <td><?= htmlspecialchars($d['title'] ?: ('Day ' . $d['shoot_date'])) ?></td>
                            <td><?= htmlspecialchars($d['shoot_date']) ?></td>
                            <td><?= htmlspecialchars($d['unit'] ?? '') ?></td>
                            <td><?= (int)$d['clip_count'] ?></td>

                            <?php if ($isPowerUser): ?>
                                <td class="td-visibility">
                                    <span class="<?= htmlspecialchars($visibilityClass, ENT_QUOTES, 'UTF-8') ?> zd-day-visibility-chip"
                                        data-day-chip="<?= htmlspecialchars($dayUuid) ?>">
                                        <?= htmlspecialchars($visibilityLabel, ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                </td>

                                <td class="col-actions">
                                    <a href="<?= htmlspecialchars($editUrl) ?>"
                                        class="icon-btn"
                                        title="Edit day"
                                        onclick="event.stopPropagation();">
                                        <img src="/assets/icons/pencil.svg" alt="Edit" class="icon">
                                    </a>

                                    <?php
                                    // Decide initial display for the two buttons
                                    $pubDisplay   = $isPublished ? 'none'        : 'inline-flex';
                                    $unpubDisplay = $isPublished ? 'inline-flex' : 'none';
                                    ?>

                                    <button type="button"
                                        class="icon-btn zd-day-publish-btn"
                                        title="Publish"
                                        data-publish-url="<?= htmlspecialchars($publishUrl) ?>"
                                        style="display: <?= $pubDisplay ?>;"
                                        onclick="event.stopPropagation();">
                                        <svg viewBox="0 0 24 24"
                                            class="icon"
                                            xmlns="http://www.w3.org/2000/svg"
                                            fill="none"
                                            stroke="currentColor"
                                            stroke-width="2"
                                            stroke-linecap="round"
                                            stroke-linejoin="round">
                                            <path d="M12 19V5" />
                                            <path d="M5 12l7-7 7 7" />
                                        </svg>
                                    </button>

                                    <button type="button"
                                        class="icon-btn zd-day-unpublish-btn"
                                        title="Unpublish"
                                        data-unpublish-url="<?= htmlspecialchars($unpublishUrl) ?>"
                                        style="display: <?= $unpubDisplay ?>;"
                                        onclick="event.stopPropagation();">
                                        <svg viewBox="0 0 24 24"
                                            class="icon"
                                            xmlns="http://www.w3.org/2000/svg"
                                            fill="none"
                                            stroke="currentColor"
                                            stroke-width="2"
                                            stroke-linecap="round"
                                            stroke-linejoin="round">
                                            <path d="M3 3l18 18" />
                                            <path d="M17.94 17.94A10.94 10.94 0 0 1 12 19c-5 0-9.27-3.11-11-7.5a11.35 11.35 0 0 1 4.28-5.27" />
                                            <path d="M9.53 9.53A3 3 0 0 1 12 9c1.66 0 3 1.34 3 3 0 .82-.33 1.57-.86 2.11" />
                                            <path d="M14.47 14.47L9.53 9.53" />
                                        </svg>
                                    </button>

                                    <a href="<?= htmlspecialchars($delUrl) ?>"
                                        class="icon-btn danger"
                                        title="Delete day"
                                        onclick="event.stopPropagation();">
                                        <img src="/assets/icons/trash.svg" alt="Delete" class="icon">
                                    </a>
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

<div class="zd-publish-backdrop" id="zd-publish-backdrop" hidden>
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
                <p style="margin-top: 0.5rem;">
                    When published, this day becomes visible to regular users on the project.
                </p>
                <p style="margin-top: 0.75rem; font-size: 0.85rem; opacity: 0.7;">
                    (Email sending will be implemented later – this checkbox is just stored for now.)
                </p>
            </div>
        </div>

        <div class="zd-publish-footer">
            <button type="button"
                class="zd-btn"
                id="zd-publish-cancel">
                Cancel
            </button>
            <button type="button"
                class="zd-btn"
                id="zd-publish-confirm">
                Publish
            </button>
        </div>
    </div>
</div>

<script>
    document.addEventListener("DOMContentLoaded", function() {
        const pageEl = document.querySelector(".days-page"); // Note: .days-page class isn't on root div in provided code, checking if script relies on it. Root div is .zd-page. Script below looks ok but pageEl might be null.
        // However, keeping script logic as is since user didn't ask to fix JS bugs, just button style. 
        // But 'days-page' class is missing in the HTML above (<div class="zd-page"...). 
        // Assuming existing JS works or this file is partial. 

        // ... (Rest of JS logic remains identical to provided file) ...
        if (!pageEl) {
            // Fallback if class is missing on root div (common issue when renaming classes)
            // The script logic provided uses pageEl for dataset.project. 
            // If the root div is .zd-page, the script might fail if it looks for .days-page.
            // I will leave the JS as provided to avoid out-of-scope breakage, 
            // but standard practice is to match the selector.
        }

        const projectUuid = pageEl ? pageEl.dataset.project : document.querySelector('.zd-page').dataset.project;
        const csrfToken = pageEl ? (pageEl.dataset.csrf || "") : document.querySelector('.zd-page').dataset.csrf;

        const backdrop = document.getElementById("zd-publish-backdrop");
        const btnCancel = document.getElementById("zd-publish-cancel");
        const btnConfirm = document.getElementById("zd-publish-confirm");
        const titleEl = document.getElementById("zd-modal-title");
        const messageEl = document.getElementById("zd-modal-message");
        const sendEmailBlk = document.getElementById("zd-modal-email-block");
        const sendEmailInp = document.getElementById("zd-publish-send-email");
        const publishExtra = document.getElementById("zd-modal-publish-extra");

        let currentMode = "publish"; // "publish" | "unpublish"
        let currentRow = null;

        function openModal(mode, row) {
            currentMode = mode;
            currentRow = row;

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

            backdrop.hidden = false;
        }

        function closeModal() {
            backdrop.hidden = true;
            currentRow = null;
        }

        // Wire all publish/unpublish buttons in the table
        document.querySelectorAll(".zd-day-publish-btn").forEach(btn => {
            btn.addEventListener("click", function(e) {
                e.stopPropagation();
                e.preventDefault();
                const row = btn.closest("tr");
                if (!row) return;
                openModal("publish", row);
            });
        });

        document.querySelectorAll(".zd-day-unpublish-btn").forEach(btn => {
            btn.addEventListener("click", function(e) {
                e.stopPropagation();
                e.preventDefault();
                const row = btn.closest("tr");
                if (!row) return;
                openModal("unpublish", row);
            });
        });

        if (btnCancel) {
            btnCancel.addEventListener("click", function() {
                closeModal();
            });
        }

        if (backdrop) {
            backdrop.addEventListener("click", function(e) {
                if (e.target === backdrop) {
                    closeModal();
                }
            });
        }

        if (btnConfirm) {
            btnConfirm.addEventListener("click", async function() {
                if (!currentRow) {
                    closeModal();
                    return;
                }

                const dayUuid = currentRow.dataset.dayUuid;
                const publishBtn = currentRow.querySelector(".zd-day-publish-btn");
                const unpublishBtn = currentRow.querySelector(".zd-day-unpublish-btn");
                const chip = document.querySelector(
                    '.zd-day-visibility-chip[data-day-chip="' + dayUuid + '"]'
                );

                let url;
                const payload = new URLSearchParams();
                if (csrfToken) {
                    payload.append("_csrf", csrfToken);
                }

                if (currentMode === "publish") {
                    const publishUrl = publishBtn && publishBtn.dataset.publishUrl;
                    if (!publishUrl) return;
                    url = publishUrl;
                    payload.append("send_email", sendEmailInp.checked ? "1" : "0");
                } else {
                    const unpublishUrl = unpublishBtn && unpublishBtn.dataset.unpublishUrl;
                    if (!unpublishUrl) return;
                    url = unpublishUrl;
                }

                try {
                    const resp = await fetch(url, {
                        method: "POST",
                        headers: {
                            "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8",
                            "X-Requested-With": "XMLHttpRequest"
                        },
                        body: payload.toString()
                    });

                    if (!resp.ok && resp.status !== 302) {
                        console.error("Publish/unpublish failed", resp.status);
                    } else {
                        if (currentMode === "publish") {
                            currentRow.dataset.published = "1";
                            if (chip) {
                                chip.textContent = "Public";
                                chip.classList.remove("zd-chip--internal");
                                chip.classList.add("zd-chip--public");
                            }
                            if (publishBtn) publishBtn.style.display = "none";
                            if (unpublishBtn) unpublishBtn.style.display = "inline-flex";
                        } else {
                            currentRow.dataset.published = "0";
                            if (chip) {
                                chip.textContent = "Internal";
                                chip.classList.remove("zd-chip--public");
                                chip.classList.add("zd-chip--internal");
                            }
                            if (publishBtn) publishBtn.style.display = "inline-flex";
                            if (unpublishBtn) unpublishBtn.style.display = "none";
                        }
                    }
                } catch (err) {
                    console.error("Error calling publish/unpublish:", err);
                } finally {
                    closeModal();
                }
            });
        }
    });
</script>

<?php $this->end(); ?>