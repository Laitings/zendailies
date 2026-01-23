<?php

/** @var array $project */
/** @var array $group */
/** @var array $current */
/** @var array $all */
/** @var string $csrf */

$formatRole = function ($r) {
    if (in_array($r, ['dit', 'dop'])) return strtoupper($r);
    return ucwords(str_replace('_', ' ', $r));
};
?>

<?php $this->extend('layout/main'); ?>

<?php $this->start('head'); ?>
<style>
    :root {
        --zd-bg-page: #0b0c10;
        --zd-bg-panel: #13151b;
        --zd-bg-input: #08090b;
        --zd-border-subtle: #1f232d;
        --zd-text-main: #eef1f5;
        --zd-text-muted: #8b9bb4;
        --zd-accent: #3aa0ff;
    }

    .zd-page-container {
        max-width: 1100px;
        margin: 0 auto;
        padding: 25px 20px;
    }

    .breadcrumb-nav {
        display: flex;
        align-items: center;
        gap: 12px;
        font-family: 'Inter', sans-serif;
        text-transform: uppercase;
        letter-spacing: 0.08em;
        font-weight: 700;
        margin-bottom: 25px;
        padding-bottom: 15px;
        border-bottom: 1px solid var(--zd-border-subtle);
    }

    .breadcrumb-nav a {
        text-decoration: none;
        color: var(--zd-text-muted);
        transition: color 0.2s;
    }

    .breadcrumb-nav a:hover {
        color: var(--zd-text-main);
    }

    .breadcrumb-nav .separator {
        color: #2c3240;
    }

    .breadcrumb-nav .current {
        color: var(--zd-accent);
    }

    .clearance-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 30px;
    }

    .column-wrap {
        background: var(--zd-bg-panel);
        border: 1px solid var(--zd-border-subtle);
        border-radius: 8px;
        min-height: 500px;
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
    }

    .user-list {
        padding: 16px;
        flex-grow: 1;
        overflow-y: auto;
        border: 2px solid transparent;
        border-radius: 0 0 8px 8px;
        transition: border-color 0.2s;
    }

    .user-list.drop-active {
        background: rgba(58, 160, 255, 0.05);
        border-color: var(--zd-accent);
        border-style: dashed;
    }

    /* COMPRESSED Card Design */
    .user-item {
        background: var(--zd-bg-input);
        border: 1px solid var(--zd-border-subtle);
        border-radius: 6px;
        padding: 10px 14px;
        /* Reduced padding */
        margin-bottom: 8px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        cursor: grab;
        user-select: none;
        transition: border-color 0.2s;
    }

    .user-item:hover {
        border-color: #2c3240;
    }

    .user-item.is-selected {
        background: rgba(58, 160, 255, 0.12);
        border-color: var(--zd-accent);
    }

    .user-item.dragging {
        opacity: 0.5;
    }

    .user-info .name-row {
        display: flex;
        align-items: center;
        gap: 8px;
        margin-bottom: 0px;
    }

    .user-info .name {
        font-size: 14px;
        font-weight: 700;
        color: var(--zd-text-main);
    }

    .user-info .email {
        display: block;
        font-size: 11px;
        color: var(--zd-text-muted);
        font-family: monospace;
    }

    .role-badge {
        font-size: 9px;
        text-transform: uppercase;
        background: #1f232d;
        color: var(--zd-text-muted);
        padding: 1px 5px;
        border-radius: 3px;
        letter-spacing: 0.05em;
        font-weight: 600;
        border: 1px solid rgba(255, 255, 255, 0.05);
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

    .action-btn:hover {
        background: var(--zd-accent);
        color: #000;
    }

    /* The Danger (Revoke) State */
    .action-btn.revoke {
        color: #ef4444;
        border-color: rgba(239, 68, 68, 0.2);
    }

    .action-btn.revoke:hover {
        background: #ef4444;
        color: #fff;
        border-color: #ef4444;
    }
</style>
<?php $this->end(); ?>

<?php $this->start('content'); ?>
<div class="zd-page-container">
    <div class="breadcrumb-nav">
        <a href="/admin/projects/<?= $project['id'] ?>/sensitive-groups">Security Groups</a>
        <span class="separator">/</span>
        <span class="current"><?= htmlspecialchars($group['name']) ?></span>
    </div>

    <form id="batchForm" method="post" action="/admin/projects/<?= $project['id'] ?>/sensitive-groups/<?= $group['id'] ?>/batch">
        <input type="hidden" name="csrf" value="<?= $csrf ?>">
        <input type="hidden" name="batch_action" id="batchAction">
        <input type="hidden" name="person_uuids" id="batchUuids">
    </form>

    <div class="clearance-grid">
        <div class="column-wrap">
            <div class="column-header">Project Crew</div>
            <div class="user-list" id="list-available" data-side="available">
                <?php
                $currentUuids = array_column($current, 'person_uuid');
                foreach ($all as $p):
                    if (in_array($p['person_uuid'], $currentUuids)) continue;
                ?>
                    <div class="user-item" draggable="true" id="u-<?= $p['person_uuid'] ?>" data-uuid="<?= $p['person_uuid'] ?>" data-side="available">
                        <div class="user-info">
                            <div class="name-row">
                                <span class="name"><?= htmlspecialchars($p['display_name']) ?></span>
                                <span class="role-badge"><?= $formatRole($p['role'] ?? 'reviewer') ?></span>
                            </div>
                            <span class="email"><?= htmlspecialchars($p['email']) ?></span>
                        </div>
                        <button type="button" class="action-btn" onclick="submitOne('add', '<?= $p['person_uuid'] ?>')">Add</button>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="column-wrap">
            <div class="column-header">Personnel with Clearance</div>
            <div class="user-list" id="list-clearance" data-side="clearance">
                <?php if (empty($current)): ?>
                    <div style="padding: 60px; text-align: center; color: var(--zd-text-muted); font-size: 12px;">Drag crew here to grant access</div>
                    <?php else: foreach ($current as $m): ?>
                        <div class="user-item" draggable="true" id="u-<?= $m['person_uuid'] ?>" data-uuid="<?= $m['person_uuid'] ?>" data-side="clearance">
                            <div class="user-info">
                                <div class="name-row">
                                    <span class="name"><?= htmlspecialchars($m['display_name']) ?></span>
                                    <span class="role-badge"><?= $formatRole($m['role'] ?? 'reviewer') ?></span>
                                </div>
                                <span class="email"><?= htmlspecialchars($m['email']) ?></span>
                            </div>
                            <button type="button" class="action-btn revoke" onclick="submitOne('remove', '<?= $m['person_uuid'] ?>')">Revoke</button>
                        </div>
                <?php endforeach;
                endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
    let lastSelectedIndex = -1;
    let selectedUuids = new Set();

    // 1. IMPROVED Selection Logic
    document.addEventListener('mousedown', (e) => {
        const item = e.target.closest('.user-item');
        if (!item) {
            if (!e.target.closest('.action-btn')) clearSelection();
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
            // FIX: If we click a selected item, DON'T clear yet (might be starting a drag)
            if (!item.classList.contains('is-selected')) {
                if (!e.target.closest('.action-btn')) {
                    clearSelection();
                    item.classList.add('is-selected');
                    selectedUuids.add(uuid);
                    lastSelectedIndex = idx;
                }
            }
        }
    });

    function clearSelection() {
        document.querySelectorAll('.user-item').forEach(el => el.classList.remove('is-selected'));
        selectedUuids.clear();
        lastSelectedIndex = -1;
    }

    // 2. FIXED Drag & Drop Engine (Captures All Selected)
    document.addEventListener('dragstart', (e) => {
        const item = e.target.closest('.user-item');
        if (!item) return;

        // If dragging an unselected item, make it the only selected one
        if (!item.classList.contains('is-selected')) {
            clearSelection();
            item.classList.add('is-selected');
            selectedUuids.add(item.dataset.uuid);
        }

        item.classList.add('dragging');
        e.dataTransfer.setData('sourceSide', item.dataset.side);
        e.dataTransfer.effectAllowed = 'move';

        // Show count if multiple are being moved
        if (selectedUuids.size > 1) {
            e.dataTransfer.setDragImage(item, 10, 10);
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

            if (sourceSide !== targetSide) {
                const action = targetSide === 'clearance' ? 'add' : 'remove';
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