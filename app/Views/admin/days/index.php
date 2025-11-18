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

    /* Visibility chips */
    .zd-chip--public {
        background: #10331e;
        color: #8ff0a4;
    }

    .zd-chip--internal {
        background: #331010;
        color: #f5b5b5;
    }


    /* Keep the actions column narrow */
    .col-actions {
        width: 140px;
        text-align: right;
        white-space: nowrap;
        overflow: visible;
        /* prevent ellipsis clipping */
        text-overflow: clip;
        /* no "…" char */
    }


    /* Publish day modal (dark admin look) */
    .zd-publish-backdrop {
        position: fixed;
        inset: 0;
        background: rgba(0, 0, 0, 0.6);
        display: flex;
        align-items: center;
        justify-content: center;
        z-index: 2100;
    }

    .zd-publish-backdrop[hidden] {
        display: none;
    }

    .zd-publish-modal {
        background: #111318;
        border: 1px solid #1f2430;
        border-radius: 12px;
        padding: 16px 18px;
        width: min(440px, 92vw);
        box-shadow: 0 10px 40px rgba(0, 0, 0, 0.7);
    }

    .zd-publish-head h2 {
        margin: 0 0 8px;
        font-size: 18px;
        color: #e9eef3;
    }

    .zd-publish-body {
        font-size: 14px;
        color: #e9eef3;
    }

    .zd-publish-checkbox {
        display: flex;
        align-items: center;
        gap: 8px;
        margin-top: 16px;
        font-size: 14px;
        color: #e9eef3;
    }

    .zd-publish-note {
        margin-top: 8px;
        font-size: 12px;
        color: #9aa7b2;
    }

    .zd-publish-footer {
        margin-top: 16px;
        display: flex;
        justify-content: flex-end;
        gap: 8px;
    }


    /* Modal Backdrop */
    .zd-publish-backdrop {
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0, 0, 0, 0.7);
        z-index: 9999;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    /* Modal Box */
    .zd-publish-modal {
        background: var(--panel);
        border: 1px solid var(--border);
        border-radius: 12px;
        width: 100%;
        max-width: 450px;
        padding: 24px;
        box-shadow: 0 20px 50px rgba(0, 0, 0, 0.5);
    }

    .zd-publish-head h2 {
        margin: 0 0 16px;
        color: var(--text);
    }

    .zd-publish-body p {
        color: var(--muted);
        line-height: 1.5;
    }

    .zd-publish-footer {
        margin-top: 24px;
        display: flex;
        justify-content: flex-end;
        gap: 12px;
    }

    /* Modal Buttons */
    .zd-btn {
        padding: 10px 20px;
        border-radius: 8px;
        border: none;
        cursor: pointer;
        font-weight: 600;
    }

    #zd-publish-cancel {
        background: transparent;
        color: var(--muted);
        border: 1px solid var(--border);
    }

    #zd-publish-cancel:hover {
        border-color: var(--text);
        color: var(--text);
    }

    #zd-publish-confirm {
        background: var(--accent);
        color: #fff;
    }

    #zd-publish-confirm:hover {
        filter: brightness(1.1);
    }
</style>

<?php $this->end(); ?>

<?php $this->start('content'); ?>

<div class="days-page"
    data-project="<?= htmlspecialchars($project['project_uuid']) ?>"
    data-csrf="<?= htmlspecialchars(\App\Support\Csrf::token()) ?>">


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

                        <!-- New static column header for visibility -->
                        <th>Visibility</th>

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

                            <td><?= htmlspecialchars($niceTitle) ?></td>
                            <td><?= htmlspecialchars($date) ?></td>
                            <td><?= htmlspecialchars($unit) ?></td>
                            <td><?= $clips ?></td>

                            <!-- NEW: visibility chip column -->
                            <td>
                                <span class="<?= htmlspecialchars($visibilityClass, ENT_QUOTES, 'UTF-8') ?> zd-day-visibility-chip"
                                    data-day-chip="<?= htmlspecialchars($dayUuid) ?>">
                                    <?= htmlspecialchars($visibilityLabel, ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            </td>


                            <!-- Actions: edit / publish-unpublish / delete -->
                            <td class="col-actions">
                                <!-- Edit -->
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

                                <!-- Publish -->
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

                                <!-- Unpublish -->
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

                                <!-- Delete -->
                                <a href="<?= htmlspecialchars($delUrl) ?>"
                                    class="icon-btn danger"
                                    title="Delete day"
                                    onclick="event.stopPropagation();">
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

<!-- Publish / Unpublish modal (reused from clips page) -->
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
        const pageEl = document.querySelector(".days-page");
        if (!pageEl) return;

        const projectUuid = pageEl.dataset.project;
        const csrfToken = pageEl.dataset.csrf || "";

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