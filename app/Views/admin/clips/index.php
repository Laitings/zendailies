<?php

/** @var \App\Support\View $this */
/** @var array $filters */
/** @var array $rows */
/** @var int $total */
/** @var int $page */
/** @var int $per */
/** @var array $cameraOptions */
/** @var string $project_uuid */
/** @var string $day_uuid */
/** @var array|null $day_info */
/** @var string $day_label */
/** @var array $project */

// Expect $project_uuid and $day_uuid from the controller
$projectUuidSafe = htmlspecialchars($project_uuid ?? '');
$dayUuidSafe     = htmlspecialchars($day_uuid ?? '');

// TODO: when we add published_at on days and pass it in day_info,
// this will reflect the real publish state. For now it's always false.
$dayIsPublished = !empty($day_info['published_at'] ?? null);

$this->extend('layout/main');

$this->start('head'); ?>

<?php
// --- Sort link helper ---
// Uses first key of current sort list; toggles dir on repeated click; preserves other query params.
function zd_sort_link(string $key, string $label, array $filters): string
{
    $current = trim(explode(',', $filters['sort'] ?? '')[0] ?? '');
    $dir = strtoupper($filters['dir'] ?? 'ASC');
    $nextDir = ($current === $key && $dir === 'ASC') ? 'DESC' : 'ASC';

    // Preserve all current GET params but override sort+dir
    $qs = $_GET ?? [];
    $qs['sort'] = $key;
    $qs['dir']  = $nextDir;
    $url = '?' . http_build_query($qs);

    // Arrow only on the active one
    $arrow = '';
    if ($current === $key) {
        $arrow = ($dir === 'ASC') ? ' ▲' : ' ▼';
    }
    return '<a href="' . $url . '" class="zd-sort">' . $label . '<span class="zd-sort-arrow">' . $arrow . '</span></a>';
}
?>

<title>Clips · Zentropa Dailies</title>
<link rel="stylesheet" href="/assets/css/admin.clips.css?v=<?= rawurlencode((string)@filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/css/admin.clips.css')) ?>">
<script type="module" defer src="/assets/js/admin.clips.js?v=<?= rawurlencode((string)@filemtime($_SERVER['DOCUMENT_ROOT'] . '/assets/js/admin.clips.js')) ?>"></script>

<style>
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
</style>


<?php $this->end(); ?>

<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$__fb = $_SESSION['import_feedback'] ?? null;
if ($__fb) {
    unset($_SESSION['import_feedback']);
} // show once
$__publish_fb = $_SESSION['publish_feedback'] ?? null;
if ($__publish_fb) {
    unset($_SESSION['publish_feedback']);
}

?>

<?php $this->start('content'); ?>

<div class="zd-clips-page"
    data-project="<?= htmlspecialchars($project_uuid) ?>"
    data-day="<?= htmlspecialchars($day_uuid) ?>"
    data-converter-csrf="<?= htmlspecialchars($converter_csrf ?? '') ?>"
    data-quick-csrf="<?= htmlspecialchars($quick_csrf ?? '') ?>">


    <?php
    $dayLabel = !empty($dayInfo['title']) ? $dayInfo['title']
        : (!empty($dayInfo['shoot_date']) ? 'Day ' . $dayInfo['shoot_date'] : '');
    ?>
    <div class="zd-clips-head">
        <h1>
            Clips - <?= htmlspecialchars($day_label ?? ($day_uuid ?? '')) ?>
            <span class="muted">
                - <?= (int)$total ?> clip<?= ($total == 1 ? '' : 's') ?>
            </span>

            <?php if ($dayIsPublished): ?>
                <span class="zd-chip zd-chip-ok" style="margin-left: 8px;">
                    Public
                </span>
            <?php endif; ?>
        </h1>


        <p class="zd-meta"
            id="zd-runtime-block"
            data-day-total-ms="<?= isset($day_total_duration_ms) ? (int)$day_total_duration_ms : 0 ?>">
            Project: <?= htmlspecialchars($project['title'] ?? 'Project') ?><br>
            <?= (int)$total ?> clip<?= ($total == 1 ? '' : 's') ?>
            · Total runtime:
            <span id="zd-total-runtime">00:00:00:00</span>
        </p>

    </div>
    <div class="zd-actions" style="display:flex; gap:12px; align-items:center">

        <!-- Add clips -->
        <a class="zd-btn zd-btn-primary"
            href="/admin/projects/<?= htmlspecialchars($project_uuid) ?>/days/<?= htmlspecialchars($day_uuid) ?>/clips/upload">
            + Add clips
        </a>

        <!-- Publish -->
        <?php if (!$dayIsPublished): ?>
            <button type="button"
                class="zd-btn zd-btn-primary"
                id="zd-publish-open">
                Publish
            </button>
        <?php else: ?>
            <button type="button"
                class="zd-btn"
                id="zd-unpublish-open">
                Unpublish
            </button>

        <?php endif; ?>


        <!-- Delete -->
        <a class="zd-btn zd-btn-danger"
            href="/admin/projects/<?= htmlspecialchars($project['project_uuid'] ?? $project_uuid) ?>/days/<?= htmlspecialchars($day_uuid) ?>/delete">
            Delete day
        </a>

    </div>
    <?php if (!empty($__publish_fb['published'])): ?>
        <div class="zd-flash-ok">
            Day published<?= !empty($__publish_fb['send_email']) ? ' (Email planned)' : '' ?>.
        </div>
    <?php endif; ?>



    <div class="zd-bulk-actions" style="display:flex; flex-wrap:wrap; gap:12px; align-items:center; font-size:13px; color:#9aa7b2;">
        <div style="display:flex; flex-direction:column; line-height:1.3; min-width:140px;">
            <div id="zd-selected-count">0 selected</div>
            <div>
                Selected runtime:
                <span id="zd-selected-runtime">00:00:00:00</span>
            </div>
        </div>

        <button id="zd-bulk-poster"
            class="zd-btn"
            style="background:#3aa0ff;color:#0b0c10;"
            disabled>
            Generate poster for selected
        </button>

        <div style="margin-left:auto; display:flex; align-items:center;">
            <form id="zd-import-form"
                method="post"
                action="/admin/projects/<?= htmlspecialchars($project_uuid) ?>/days/<?= htmlspecialchars($day_uuid) ?>/clips/import_csv"
                enctype="multipart/form-data"
                style="display:flex; gap:8px; align-items:center; background:#111318; border:1px solid #1f2430; border-radius:10px; padding:6px 10px; font-size:13px; color:#9aa7b2;">
                <input type="hidden" name="_csrf" value="<?= \App\Support\Csrf::token() ?>">
                <!-- NEW: populated by JS with comma-separated clip UUIDs when selection > 0 -->
                <input type="hidden" id="zd-import-uuids" name="limit_to_uuids" value="">

                <label style="display:flex; flex-direction:column; cursor:pointer;">
                    <span style="font-size:11px; color:#9aa7b2;">Resolve CSV</span>
                    <input id="zd-import-file"
                        type="file"
                        name="csv_file"
                        accept=".csv,text/csv"
                        style="font-size:12px; color:#e9eef3; background:#0f1218; border:1px solid #1f2430; border-radius:6px; padding:4px 6px; min-width:180px;">
                </label>
                <label style="display:flex; align-items:center; gap:6px; margin-left:8px;">
                    <input type="checkbox" name="overwrite" id="zd-import-overwrite" value="1" />
                    <span>Overwrite existing values</span>
                </label>
                <button id="zd-import-btn"
                    type="submit"
                    class="zd-btn"
                    title="Import metadata"
                    style="background:#3aa0ff;color:#0b0c10;">
                    Import metadata
                </button>
            </form>
        </div>
    </div>

    <div class="zd-stack">
        <form method="get" class="zd-filters">
            <input class="zd-input" name="scene" value="<?= htmlspecialchars($filters['scene']) ?>" placeholder="Scene">
            <input class="zd-input" name="slate" value="<?= htmlspecialchars($filters['slate']) ?>" placeholder="Slate">
            <input class="zd-input" name="take" value="<?= htmlspecialchars($filters['take']) ?>" placeholder="Take">
            <select class="zd-select" name="camera">
                <option value="">Camera</option>
                <?php foreach ($cameraOptions as $c): ?>
                    <option value="<?= htmlspecialchars($c) ?>" <?= $filters['camera'] === $c ? 'selected' : '' ?>><?= htmlspecialchars($c) ?></option>
                <?php endforeach; ?>
            </select>
            <select class="zd-select" name="rating">
                <option value="">Rating</option>
                <?php for ($i = 1; $i <= 5; $i++): ?>
                    <option value="<?= $i ?>" <?= $filters['rating'] === (string)$i ? 'selected' : '' ?>><?= $i ?>★</option>
                <?php endfor; ?>
            </select>
            <select class="zd-select" name="select">
                <option value="">Select?</option>
                <option value="1" <?= $filters['select'] === '1' ? 'selected' : '' ?>>Yes</option>
                <option value="0" <?= $filters['select'] === '0' ? 'selected' : '' ?>>No</option>
            </select>
            <input class="zd-input" name="text" value="<?= htmlspecialchars($filters['text']) ?>" placeholder="Search file/reel">
            <div style="display:flex;gap:8px">
                <button class="zd-btn" type="submit">Apply</button>
                <a class="zd-btn" href="?">Reset</a>
            </div>
        </form>


        <div class="zd-table-wrap">
            <table class="zd-table">
                <thead>
                    <tr>
                        <th>Thumb</th>
                        <th><?= zd_sort_link('scene',    'Scene',  $filters) ?></th>
                        <th><?= zd_sort_link('slate',    'Slate',  $filters) ?></th>
                        <th><?= zd_sort_link('take',     'Take',   $filters) ?></th>
                        <th><?= zd_sort_link('camera',   'Cam',    $filters) ?></th>
                        <th><?= zd_sort_link('select', 'Selected', $filters) ?></th>
                        <th><?= zd_sort_link('reel',     'Reel',   $filters) ?></th>
                        <th><?= zd_sort_link('file',     'File',   $filters) ?></th>
                        <th><?= zd_sort_link('tc_start', 'TC In',  $filters) ?></th>
                        <th><?= zd_sort_link('duration', 'Dur',    $filters) ?></th>
                        <th>Proxy / Job</th>
                        <th>Actions</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if (!$rows): ?>
                        <tr>
                            <td colspan="10" class="zd-meta">No clips found.</td>
                        </tr>
                        <?php else: foreach ($rows as $idx => $r): ?>
                            <tr id="clip-<?= htmlspecialchars($r['clip_uuid']) ?>"
                                data-clip-uuid="<?= htmlspecialchars($r['clip_uuid']) ?>"
                                data-row-index="<?= (int)$idx ?>">
                                <!-- Thumb (live-updated after poster gen) -->
                                <td data-field="thumb">
                                    <?php if (!empty($r['poster_path'])): ?>
                                        <img class="zd-thumb" src="<?= htmlspecialchars($r['poster_path']) ?>" alt="">
                                    <?php else: ?>
                                        <div class="zd-thumb"></div>
                                    <?php endif; ?>
                                </td>

                                <!-- Scene / Slate / Take / Cam / Reel -->
                                <td class="zd-editable" data-edit="scene"><span><?= htmlspecialchars($r['scene'] ?? '') ?></span></td>
                                <td class="zd-editable" data-edit="slate"><span><?= htmlspecialchars($r['slate'] ?? '') ?></span></td>
                                <td class="zd-editable" data-edit="take"><span><?= htmlspecialchars(($r['take_int'] ?? null) !== null ? (string)$r['take_int'] : ($r['take'] ?? '')) ?></span></td>
                                <td><?= htmlspecialchars($r['camera'] ?? '') ?></td>
                                <td class="zd-select-td" data-field="select">
                                    <button class="star-toggle"
                                        type="button"
                                        data-clip="<?= htmlspecialchars($r['clip_uuid']) ?>"
                                        data-selected="<?= (int)($r['is_select'] ?? 0) ?>"
                                        aria-label="Toggle selected"
                                        title="Toggle selected">
                                        <?php if ((int)($r['is_select'] ?? 0) === 1): ?>
                                            <svg viewBox="0 0 24 24" class="star on" aria-hidden="true">
                                                <path d="M12 17.3l-5.47 3.22 1.45-6.17-4.78-4.1 6.3-.54L12 3l2.5 6.7 6.3.54-4.78 4.1 1.45 6.17z" />
                                            </svg>
                                        <?php else: ?>
                                            <svg viewBox="0 0 24 24" class="star off" aria-hidden="true">
                                                <path d="M12 17.3l-5.47 3.22 1.45-6.17-4.78-4.1 6.3-.54L12 3l2.5 6.7 6.3.54-4.78 4.1 1.45 6.17z" />
                                            </svg>
                                        <?php endif; ?>
                                    </button>
                                </td>

                                <td><?= htmlspecialchars($r['reel'] ?? '') ?></td>

                                <!-- File name + UUID -->
                                <td class="col-file">
                                    <div class="filename" title="<?= htmlspecialchars($r['file_name'] ?? '') ?>">
                                        <?= htmlspecialchars($r['file_name'] ?? '') ?>
                                    </div>
                                    <div class="zd-meta uuid">#<?= htmlspecialchars($r['clip_uuid']) ?></div>
                                </td>

                                <!-- TC In -->
                                <td data-field="tc_start"><?= htmlspecialchars($r['tc_start'] ?? '') ?></td>

                                <!-- Duration (live-updated after metadata pull) -->
                                <td data-field="duration"
                                    data-duration-ms="<?= $r['duration_ms'] !== null ? (int)$r['duration_ms'] : '' ?>"
                                    data-fps="<?= isset($r['fps']) && $r['fps'] !== null ? (int)$r['fps'] : '' ?>">
                                </td>

                                <!-- proxy_web / Encode job status -->
                                <td data-field="proxy_job">
                                    <?php
                                    $proxyCount  = (int)($r['proxy_count'] ?? 0);
                                    $jobStateRaw = $r['job_state'] ?? null;
                                    $jobProgress = $r['job_progress'] ?? null;

                                    $hasProxy = $proxyCount > 0;

                                    $jobState = $jobStateRaw !== null
                                        ? strtolower((string)$jobStateRaw)
                                        : null;
                                    $hasJob   = $jobState !== null;

                                    // --- Proxy label semantics ---
                                    //  - Proxy ✓          => at least one proxy_web asset
                                    //  - Proxy converting => encode job queued/running but no proxy yet
                                    //  - Proxy none       => no proxy_web and no encode job
                                    if ($hasProxy) {
                                        $proxyLabel = 'Proxy ✓';
                                    } elseif ($hasJob && ($jobState === 'queued' || $jobState === 'running')) {
                                        $proxyLabel = 'Proxy converting';
                                    } else {
                                        $proxyLabel = 'Proxy none';
                                    }

                                    // --- Job label semantics ---
                                    //  - No job         => no encode_jobs row
                                    //  - Queued         => state = queued
                                    //  - Running (xx%)  => state = running (+ optional %)
                                    //  - Done / Failed / Canceled => final states
                                    if (!$hasJob) {
                                        $jobLabel = 'No job';
                                    } elseif ($jobState === 'queued') {
                                        $jobLabel = 'Queued';
                                    } elseif ($jobState === 'running') {
                                        if ($jobProgress !== null && $jobProgress !== '') {
                                            $jobLabel = 'Running (' . (int)$jobProgress . '%)';
                                        } else {
                                            $jobLabel = 'Running';
                                        }
                                    } elseif ($jobState === 'done') {
                                        $jobLabel = 'Done';
                                    } elseif ($jobState === 'failed') {
                                        $jobLabel = 'Failed';
                                    } elseif ($jobState === 'canceled') {
                                        $jobLabel = 'Canceled';
                                    } else {
                                        // Fallback for any odd state strings
                                        $jobLabel = (string)$jobStateRaw;
                                    }
                                    ?>
                                    <span class="zd-meta">
                                        <?= htmlspecialchars($proxyLabel, ENT_QUOTES, 'UTF-8') ?>
                                        ·
                                        <?= htmlspecialchars($jobLabel, ENT_QUOTES, 'UTF-8') ?>
                                    </span>

                                </td>
                                <!-- Actions -->
                                <td style="white-space:nowrap">
                                    <!-- Play -->
                                    <a href="/admin/projects/<?= $projectUuidSafe ?>/days/<?= $dayUuidSafe ?>/player/<?= htmlspecialchars($r['clip_uuid']) ?>"
                                        class="icon-btn"
                                        title="Play">
                                        <img src="/assets/icons/play.svg" alt="Play" class="icon">
                                    </a>

                                    <!-- Edit (NEW) -->
                                    <a href="/admin/projects/<?= $projectUuidSafe ?>/days/<?= $dayUuidSafe ?>/clips/<?= htmlspecialchars($r['clip_uuid']) ?>/edit"
                                        class="icon-btn"
                                        title="Edit clip">
                                        <img src="/assets/icons/pencil.svg" alt="Edit" class="icon">
                                    </a>

                                    <!-- Generate poster (AJAX) -->
                                    <button class="icon-btn"
                                        type="button"
                                        title="Generate poster"
                                        data-clip="<?= htmlspecialchars($r['clip_uuid']) ?>"
                                        data-action="poster">
                                        <img src="/assets/icons/thumbnail.svg"
                                            alt="Generate poster"
                                            class="icon icon--light">
                                    </button>

                                    <!-- Delete -->
                                    <form method="post"
                                        action="/admin/projects/<?= $projectUuidSafe ?>/days/<?= $dayUuidSafe ?>/clips/<?= htmlspecialchars($r['clip_uuid']) ?>/delete"
                                        onsubmit="return confirm('Delete this clip and all its files? This cannot be undone.');"
                                        style="display:inline;">
                                        <input type="hidden" name="_csrf" value="<?= \App\Support\Csrf::token() ?>">
                                        <button class="icon-btn danger" type="submit" title="Delete">
                                            <img src="/assets/icons/trash.svg" alt="Delete" class="icon">
                                        </button>
                                    </form>
                                </td>
                            </tr>
                    <?php endforeach;
                    endif; ?>
                </tbody>
            </table>
        </div>

        <?php
        $totalPages = (int)ceil($total / $per);
        ?>
        <div class="zd-pager">
            <form method="get" style="display:flex;gap:8px;align-items:center">
                <?php foreach (['scene', 'slate', 'take', 'camera', 'rating', 'select', 'text', 'sort', 'dir'] as $k): ?>
                    <input type="hidden" name="<?= $k ?>" value="<?= htmlspecialchars($filters[$k]) ?>">
                <?php endforeach; ?>
                <button class="zd-btn" name="page" value="<?= max(1, $page - 1) ?>" <?= $page <= 1 ? 'disabled' : '' ?>>Prev</button>
                <span class="zd-meta">Page <?= $page ?> / <?= $totalPages ?: 1 ?></span>
                <button class="zd-btn" name="page" value="<?= min($totalPages ?: 1, $page + 1) ?>" <?= ($page >= $totalPages) ? 'disabled' : '' ?>>Next</button>
                <select class="zd-select" name="per" onchange="this.form.submit()">
                    <?php foreach ([25, 50, 100, 150, 200] as $opt): ?>
                        <option value="<?= $opt ?>" <?= $per === $opt ? 'selected' : '' ?>><?= $opt ?>/page</option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>
    </div>
</div>

<!-- Publish day modal -->
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

<!-- Hidden forms for real publish and unpublish POST  -->
<form id="zd-publish-form"
    method="post"
    action="/admin/projects/<?= htmlspecialchars($project_uuid) ?>/days/<?= htmlspecialchars($day_uuid) ?>/publish"
    style="display:none;">
    <input type="hidden" name="_csrf" value="<?= \App\Support\Csrf::token() ?>">
    <input type="hidden" name="send_email" id="zd-publish-send-email-field" value="0">
</form>

<form id="zd-unpublish-form"
    method="post"
    action="/admin/projects/<?= htmlspecialchars($project_uuid) ?>/days/<?= htmlspecialchars($day_uuid) ?>/unpublish"
    style="display:none;">
    <input type="hidden" name="_csrf" value="<?= \App\Support\Csrf::token() ?>">
</form>



<?php $this->end(); /* end of content section */ ?>

<?php $this->start('scripts'); ?>
<?= $this->includeWithin('partials/import_csv_modal', ['feedback' => $__fb]) ?>

<script>
    document.addEventListener("DOMContentLoaded", function() {
        const backdrop = document.getElementById("zd-publish-backdrop");

        const btnOpenPublish = document.getElementById("zd-publish-open");
        const btnOpenUnpublish = document.getElementById("zd-unpublish-open");

        const btnCancel = document.getElementById("zd-publish-cancel");
        const btnConfirm = document.getElementById("zd-publish-confirm");

        const sendEmailBlock = document.getElementById("zd-modal-email-block");
        const sendEmailInput = document.getElementById("zd-publish-send-email");
        const titleEl = document.getElementById("zd-modal-title");
        const messageEl = document.getElementById("zd-modal-message");
        const publishExtra = document.getElementById("zd-modal-publish-extra");


        const publishForm = document.getElementById("zd-publish-form");
        const unpublishForm = document.getElementById("zd-unpublish-form");
        const sendField = document.getElementById("zd-publish-send-email-field");

        let currentMode = "publish";

        function openModal(mode) {
            currentMode = mode;

            if (mode === "publish") {
                titleEl.textContent = "Publish day";
                messageEl.textContent = "Are you sure you want to publish this day?";
                btnConfirm.textContent = "Publish";

                sendEmailBlock.style.display = "block";
                publishExtra.style.display = "block";

            } else {
                titleEl.textContent = "Unpublish day";
                messageEl.textContent = "Unpublish this day? It will no longer be visible to regular users.";
                btnConfirm.textContent = "Unpublish";

                sendEmailBlock.style.display = "none";
                publishExtra.style.display = "none";
            }


            backdrop.hidden = false;
        }

        function closeModal() {
            backdrop.hidden = true;
        }

        if (btnOpenPublish) {
            btnOpenPublish.addEventListener("click", () => openModal("publish"));
        }
        if (btnOpenUnpublish) {
            btnOpenUnpublish.addEventListener("click", () => openModal("unpublish"));
        }

        if (btnCancel) {
            btnCancel.addEventListener("click", closeModal);
        }

        backdrop.addEventListener("click", (e) => {
            if (e.target === backdrop) closeModal();
        });

        if (btnConfirm) {
            btnConfirm.addEventListener("click", () => {
                if (currentMode === "publish") {
                    sendField.value = sendEmailInput.checked ? "1" : "0";
                    publishForm.submit();
                } else {
                    unpublishForm.submit();
                }
            });
        }
    });
</script>


<?php $this->end(); ?>