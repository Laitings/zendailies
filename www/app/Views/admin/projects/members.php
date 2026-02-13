<?php

/** @var array $project */
/** @var array $members */
/** @var array $all_users */
/** @var string $csrf */

$roles = ['producer', 'director', 'post_supervisor', 'editor', 'assistant_editor', 'script_supervisor', 'dop', 'dit', 'reviewer'];

// Helper to format roles
$formatRole = function ($r) {
    if (in_array($r, ['dit', 'dop'])) return strtoupper($r);
    return ucwords(str_replace('_', ' ', $r));
};
?>

<?php $this->extend('layout/main'); ?>

<?php $this->start('head'); ?>
<title>Members · <?= htmlspecialchars($project['title']) ?></title>
<style>
    /* --- Synchronized ZD Theme Variables --- */
    :root {
        --zd-bg-page: #0b0c10;
        --zd-bg-panel: #13151b;
        --zd-bg-input: #08090b;
        --zd-border-subtle: #1f232d;
        --zd-border-focus: #3aa0ff;
        --zd-text-main: #eef1f5;
        --zd-text-muted: #8b9bb4;
        --zd-accent: #3aa0ff;
        --zd-radius: 8px;
    }

    .zd-page-container {
        max-width: 1200px;
        margin: 0 auto;
        padding: 25px 20px;
    }

    /* Header Styling */
    .zd-header {
        position: relative;
        margin-bottom: 25px;
    }

    .zd-header h1 {
        font-size: 20px;
        font-weight: 700;
        color: var(--zd-text-main);
        line-height: 1;
        margin: 0 0 20px 0;
        padding-bottom: 15px;
        position: relative;
        display: flex;
        align-items: center;
        gap: 20px;
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

    /* Grid Layout */
    .members-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 30px;
    }

    .column-wrap {
        background: var(--zd-bg-panel);
        border: 1px solid var(--zd-border-subtle);
        border-radius: 8px;
        min-height: 600px;
        display: flex;
        flex-direction: column;
    }

    .column-header {
        background: #171922;
        padding: 12px 16px;
        font-size: 11px;
        text-transform: uppercase;
        font-weight: 700;
        color: var(--zd-text-muted);
        border-bottom: 1px solid var(--zd-border-subtle);
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .user-list {
        padding: 16px;
        flex-grow: 1;
        overflow-y: auto;
        border: 2px solid transparent;
        border-radius: 0 0 8px 8px;
        transition: border-color 0.2s;
        max-height: 80vh;
    }

    .user-list.drop-active {
        background: rgba(58, 160, 255, 0.05);
        border-color: var(--zd-accent);
        border-style: dashed;
    }

    /* Card Design */
    .user-item {
        background: var(--zd-bg-input);
        border: 1px solid var(--zd-border-subtle);
        border-radius: 6px;
        padding: 12px;
        margin-bottom: 8px;
        cursor: grab;
        user-select: none;
        /* Smooth transition for the lift */
        transition: transform 0.2s cubic-bezier(0.25, 0.8, 0.25, 1),
            box-shadow 0.2s,
            background-color 0.2s,
            border-color 0.2s;
        position: relative;
        /* Fix for potential z-index issues during stacking */
        transform: translateZ(0);
    }

    /* 1. Normal Hover */
    .user-item:hover {
        border-color: var(--zd-accent);
        background: #111319;
        box-shadow: 0 6px 15px rgba(0, 0, 0, 0.5);
        transform: translateY(-3px);
        z-index: 5;
    }

    /* 2. Selected State (Base) */
    .user-item.is-selected {
        background: rgba(58, 160, 255, 0.12) !important;
        /* Force blue tint */
        border-color: var(--zd-accent) !important;
    }

    /* 3. Selected + Hover (The Fix: Keep it blue when hovering!) */
    .user-item.is-selected:hover {
        background: rgba(58, 160, 255, 0.18) !important;
        /* Slightly brighter blue */
        box-shadow: 0 8px 20px rgba(0, 0, 0, 0.6);
        transform: translateY(-3px);
    }

    /* 4. Dragging State */
    .user-item.dragging {
        opacity: 0.4;
        /* Don't snap back immediately, keep the layout stable for the ghost image */
        background: var(--zd-bg-input) !important;
        border-color: var(--zd-border-subtle) !important;
        transform: none !important;
        box-shadow: none !important;
    }

    .user-info {
        margin-bottom: 4px;
    }

    .user-info .name {
        display: block;
        font-size: 14px;
        font-weight: 700;
        color: var(--zd-text-main);
    }

    .user-info .email {
        display: block;
        font-size: 11px;
        color: var(--zd-text-muted);
        font-family: monospace;
        margin-top: 2px;
    }

    /* Right Column Controls (Member Settings) */
    .member-controls {
        display: flex;
        align-items: center;
        gap: 10px;
        margin-top: 10px;
        padding-top: 10px;
        border-top: 1px solid rgba(255, 255, 255, 0.05);
    }

    .zd-select-tiny {
        background-color: #13151b !important;
        border: 1px solid var(--zd-border-subtle);
        color: var(--zd-text-main);
        border-radius: 4px;
        font-size: 11px;
        padding: 4px 20px 4px 8px;
        appearance: none;
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='24' height='24' viewBox='0 0 24 24' fill='none' stroke='%238b9bb4' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
        background-repeat: no-repeat;
        background-position: right 4px center;
        background-size: 12px;
    }

    .zd-icon-check {
        display: flex;
        align-items: center;
        gap: 6px;
        font-size: 10px;
        color: var(--zd-text-muted);
        cursor: pointer;
        user-select: none;
        padding: 4px 6px;
        border-radius: 4px;
        border: 1px solid transparent;
    }

    .zd-icon-check:hover {
        background: rgba(255, 255, 255, 0.05);
    }

    .zd-icon-check input {
        accent-color: var(--zd-accent);
    }

    .action-btn {
        background: transparent;
        border: 1px solid var(--zd-border-subtle);
        color: var(--zd-accent);
        border-radius: 4px;
        padding: 4px 10px;
        font-size: 11px;
        font-weight: 700;
        cursor: pointer;
    }

    .action-btn.add-btn {
        margin-left: auto;
    }

    .action-btn.revoke {
        color: #ef4444;
        border-color: rgba(239, 68, 68, 0.2);
        margin-left: auto;
    }

    .action-btn.revoke:hover {
        background: #ef4444;
        color: #fff;
        border-color: #ef4444;
    }

    /* Pending User Badge */
    .badge-pending {
        display: inline-block;
        padding: 2px 8px;
        font-size: 10px;
        font-weight: 700;
        color: #ff5c3a;
        /* Vibrant red-orange */
        border: 1px solid rgba(255, 92, 58, 0.3);
        background: rgba(255, 92, 58, 0.05);
        border-radius: 4px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        margin-top: 4px;
        pointer-events: none;
    }
</style>
<?php $this->end(); ?>

<?php $this->start('content'); ?>
<div class="zd-page-container">

    <div class="zd-header">
        <h1>
            <span style="color: var(--zd-text-main);">Members</span>
            <span style="color: var(--zd-border-subtle);">|</span>
            <a href="/admin/projects/<?= $project['id'] ?>/sensitive-groups" style="text-decoration:none; color: var(--zd-text-muted); font-weight: 400; transition: color 0.2s;" onmouseover="this.style.color='#3aa0ff'" onmouseout="this.style.color='var(--zd-text-muted)'">Security Groups</a>
        </h1>
    </div>

    <form id="batchForm" method="post" action="/admin/projects/<?= $project['id'] ?>/batch-members">
        <input type="hidden" name="csrf" value="<?= $csrf ?>">
        <input type="hidden" name="batch_action" id="batchAction">
        <input type="hidden" name="person_uuids" id="batchUuids">
    </form>

    <div class="members-grid">

        <div class="column-wrap">
            <div class="column-header">
                <span>All System Users</span>
                <span style="opacity:0.5;">Drag to add</span>
            </div>
            <div class="user-list" id="list-available" data-side="available">
                <?php
                $currentUuids = array_column($members, 'person_uuid');
                $countAvailable = 0;

                foreach ($all_users as $u):
                    if (in_array($u['person_uuid'], $currentUuids)) continue;
                    $countAvailable++;
                ?>
                    <div class="user-item" draggable="true" id="u-<?= $u['person_uuid'] ?>" data-uuid="<?= $u['person_uuid'] ?>" data-side="available">
                        <div class="user-info">
                            <span class="name"><?= htmlspecialchars($u['last_name'] . ', ' . $u['first_name']) ?></span>
                            <span class="email"><?= htmlspecialchars($u['email']) ?></span>

                            <?php if (($u['status'] ?? '') === 'pending'): ?>
                                <div class="badge-pending">Pending</div>
                            <?php endif; ?>
                        </div>
                        <div style="display:flex; justify-content:flex-end; margin-top:8px;">
                            <button type="button" class="action-btn add-btn" onclick="submitOne('add', '<?= $u['person_uuid'] ?>')">Add →</button>
                        </div>
                    </div>
                <?php endforeach; ?>

                <?php if ($countAvailable === 0): ?>
                    <div style="padding: 20px; text-align: center; color: var(--zd-text-muted); font-size: 12px;">
                        All users are already in this project.
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="column-wrap">
            <div class="column-header">
                <span>Project Members (<?= count($members) ?>)</span>
                <span style="opacity:0.5;">Drag left to remove</span>
            </div>
            <div class="user-list" id="list-members" data-side="members">
                <?php foreach ($members as $m):
                    $formId = "form-m-" . $m['person_uuid'];
                    $isMe = ($m['person_uuid'] === ($_SESSION['person_uuid'] ?? ''));
                ?>
                    <div class="user-item" draggable="true" id="u-<?= $m['person_uuid'] ?>" data-uuid="<?= $m['person_uuid'] ?>" data-side="members">
                        <div class="user-info">
                            <span class="name"><?= htmlspecialchars($m['display_name'] ?? ($m['first_name'] . ' ' . $m['last_name'])) ?></span>
                            <span class="email"><?= htmlspecialchars($m['email'] ?? '') ?></span>
                            <?php if (($m['status'] ?? '') === 'pending'): ?>
                                <div class="badge-pending">Pending</div>
                            <?php endif; ?>
                        </div>

                        <form id="<?= $formId ?>" method="post" action="/admin/projects/<?= $project['id'] ?>/members/<?= $m['person_uuid'] ?>" class="member-controls">
                            <input type="hidden" name="csrf" value="<?= $csrf ?>">

                            <select name="role" class="zd-select-tiny" onchange="this.form.submit()" onclick="event.stopPropagation();">
                                <?php foreach ($roles as $r): ?>
                                    <option value="<?= $r ?>" <?= ($m['role'] === $r) ? 'selected' : ''; ?>>
                                        <?= $formatRole($r) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>

                            <label class="zd-icon-check" title="Project Admin (DIT)">
                                <input type="checkbox" name="is_project_admin" value="1"
                                    onchange="this.form.submit()"
                                    onclick="event.stopPropagation();"
                                    <?= !empty($m['is_project_admin']) ? 'checked' : ''; ?>
                                    <?= $isMe ? 'disabled' : ''; ?>>
                                <span>ADM</span>
                                <?php if ($isMe): ?><input type="hidden" name="is_project_admin" value="1"><?php endif; ?>
                            </label>

                            <label class="zd-icon-check" title="Allow Downloads">
                                <input type="checkbox" name="can_download" value="1"
                                    onchange="this.form.submit()"
                                    onclick="event.stopPropagation();"
                                    <?= !empty($m['can_download']) ? 'checked' : ''; ?>>
                                <span>DL</span>
                            </label>

                            <button type="button" class="action-btn revoke"
                                onclick="event.stopPropagation(); submitOne('remove', '<?= $m['person_uuid'] ?>')">
                                Remove
                            </button>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<script>
    let lastSelectedIndex = -1;
    let selectedUuids = new Set();

    // 1. Selection Logic (Shift/Ctrl click)
    document.addEventListener('mousedown', (e) => {
        // Ignore clicks inside the form controls (selects, inputs, buttons)
        if (e.target.closest('form') || e.target.closest('button')) return;

        const item = e.target.closest('.user-item');
        if (!item) {
            clearSelection();
            return;
        }

        const list = item.parentElement;
        const allItems = Array.from(list.querySelectorAll('.user-item'));
        const idx = allItems.indexOf(item);
        const uuid = item.dataset.uuid;

        if (e.shiftKey && lastSelectedIndex !== -1) {
            e.preventDefault();
            const start = Math.min(lastSelectedIndex, idx);
            const end = Math.max(lastSelectedIndex, idx);
            for (let i = start; i <= end; i++) {
                allItems[i].classList.add('is-selected');
                selectedUuids.add(allItems[i].dataset.uuid);
            }
        } else if (e.ctrlKey || e.metaKey) {
            item.classList.toggle('is-selected');
            if (item.classList.contains('is-selected')) selectedUuids.add(uuid);
            else selectedUuids.delete(uuid);
            lastSelectedIndex = idx;
        } else {
            // Standard click: select only this one
            if (!item.classList.contains('is-selected')) {
                clearSelection();
                item.classList.add('is-selected');
                selectedUuids.add(uuid);
                lastSelectedIndex = idx;
            }
        }
    });

    function clearSelection() {
        document.querySelectorAll('.user-item').forEach(el => el.classList.remove('is-selected'));
        selectedUuids.clear();
        lastSelectedIndex = -1;
    }

    // 2. Drag & Drop Logic
    document.addEventListener('dragstart', (e) => {
        const item = e.target.closest('.user-item');
        if (!item) return;

        // Ensure current item is selected if we start dragging it
        if (!item.classList.contains('is-selected')) {
            clearSelection();
            item.classList.add('is-selected');
            selectedUuids.add(item.dataset.uuid);
        }

        item.classList.add('dragging');
        e.dataTransfer.setData('sourceSide', item.dataset.side);
        e.dataTransfer.effectAllowed = 'move';

        // Custom drag image if multiple
        if (selectedUuids.size > 1) {
            // Just default browser drag image is fine usually, 
            // or we can implement a ghost image.
        }
    });

    document.addEventListener('dragend', (e) => {
        e.target.classList?.remove('dragging');
        document.querySelectorAll('.user-list').forEach(l => l.classList.remove('drop-active'));
    });

    document.querySelectorAll('.user-list').forEach(list => {
        list.addEventListener('dragover', (e) => {
            e.preventDefault();
            list.classList.add('drop-active');
        });
        list.addEventListener('dragleave', () => list.classList.remove('drop-active'));
        list.addEventListener('drop', (e) => {
            e.preventDefault();
            const sourceSide = e.dataTransfer.getData('sourceSide');
            const targetSide = list.dataset.side;

            if (sourceSide && sourceSide !== targetSide) {
                const action = targetSide === 'members' ? 'add' : 'remove';
                submitBatch(action);
            }
        });
    });

    function submitOne(action, uuid) {
        document.getElementById('batchAction').value = action;
        document.getElementById('batchUuids').value = uuid;
        document.getElementById('batchForm').submit();
    }

    function submitBatch(action) {
        if (selectedUuids.size === 0) return;
        document.getElementById('batchAction').value = action;
        document.getElementById('batchUuids').value = Array.from(selectedUuids).join(',');
        document.getElementById('batchForm').submit();
    }
</script>
<?php $this->end(); ?>