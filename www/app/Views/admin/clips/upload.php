<?php

/** @var array $project */
/** @var string $project_uuid */
/** @var array $day */



$this->extend('layout/main');

$this->start('head'); ?>
<title><?= htmlspecialchars($project['title']) ?> · Add Clips · Zentropa Dailies</title>
<style>
    .dz {
        border: 2px dashed var(--border);
        border-radius: 12px;
        padding: 28px;
        text-align: center;
        background: rgba(255, 255, 255, 0.02);
        transition: .15s border-color;
    }

    .dz.dragover {
        border-color: var(--accent, #6ea8fe);
    }

    .file-list {
        margin-top: 16px;
        border: 1px solid var(--border);
        border-radius: 12px;
        overflow: hidden
    }

    .file-row {
        display: flex;
        gap: 12px;
        align-items: center;
        padding: 10px 12px;
        border-top: 1px solid var(--border)
    }

    .file-row:first-child {
        border-top: 0
    }

    .file-meta {
        flex: 1;
        overflow: hidden
    }

    .file-name {
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis
    }

    .file-actions button {
        margin-left: 8px
    }

    .progress {
        height: 6px;
        background: #222;
        border-radius: 6px;
        overflow: hidden;
        margin-top: 6px
    }

    .bar {
        height: 100%;
        width: 0%
    }

    .bar.ok {
        background: #3fb950
    }

    .bar.err {
        background: #d73a49
    }

    /* Modal Overlay */
    .zd-modal-overlay {
        position: fixed;
        inset: 0;
        background: rgba(0, 0, 0, 0.7);
        display: none;
        place-items: center;
        z-index: 9999;
        backdrop-filter: blur(2px);
    }

    /* Modal Box */
    .zd-modal {
        background: #13151b;
        /* Charcoal Panel */
        border: 1px solid var(--border);
        box-shadow: 0 10px 40px rgba(0, 0, 0, 0.5);
        border-radius: 12px;
        padding: 24px;
        max-width: 400px;
        width: 90%;
        text-align: center;
        color: #fff;
    }

    .zd-modal h3 {
        margin-top: 0;
        font-size: 18px;
        color: #fff;
    }

    .zd-modal p {
        color: #aaa;
        margin-bottom: 24px;
        line-height: 1.5;
    }

    .zd-modal-actions {
        display: flex;
        gap: 12px;
        justify-content: center;
    }
</style>
<?php $this->end(); ?>

<?php $this->start('content'); ?>

<div class="zd-breadcrumb">
    <a class="zd-link" href="/admin/projects/<?= htmlspecialchars($project_uuid) ?>/days">Days</a>
    <span class="zd-sep">›</span>
    <a class="zd-link" href="/admin/projects/<?= htmlspecialchars($project_uuid) ?>/days/<?= htmlspecialchars($day['day_uuid']) ?>/clips">Clips</a>
    <span class="zd-sep">›</span>
    <span>Add clips</span>
</div>

<h1 class="zd-h1">Add clips to <?= htmlspecialchars($project['title']) ?> · Day <?= htmlspecialchars($day['shoot_date']) ?></h1>

<div class="zd-card" style="padding:18px">
    <p class="zd-meta">Drag and drop files below, or <label class="zd-link" style="cursor:pointer"><input id="pick" type="file" multiple style="display:none">browse</label>.</p>

    <div id="drop" class="dz">Drop files here</div>

    <div id="list" class="file-list" style="display:none"></div>

    <div style="display:flex; gap:8px; margin-top:16px">
        <button id="start" class="za-btn za-btn-primary" disabled>Upload &amp; register</button>
        <a class="za-btn" href="/admin/projects/<?= htmlspecialchars($project_uuid) ?>/days/<?= htmlspecialchars($day['day_uuid']) ?>/clips">Cancel</a>
    </div>
</div>
<div id="status" class="zd-meta" style="margin-top:8px;color:#f66"></div>

<div id="nav-modal" class="zd-modal-overlay">
    <div class="zd-modal">
        <h3>Upload in progress</h3>
        <p>If you leave this page now, pending uploads will be cancelled and lost. Are you sure?</p>
        <div class="zd-modal-actions">
            <button id="modal-stay" class="za-btn za-btn-primary">Stay here</button>
            <button id="modal-leave" class="za-btn">Leave page</button>
        </div>
    </div>
</div>

<script>
    (() => {
        const drop = document.getElementById('drop');
        const pick = document.getElementById('pick');
        const list = document.getElementById('list');
        const start = document.getElementById('start');

        // Modal elements
        const navModal = document.getElementById('nav-modal');
        const btnStay = document.getElementById('modal-stay');
        const btnLeave = document.getElementById('modal-leave');

        /** @type {File[]} */
        let queue = [];
        let isUploading = false;
        let pendingUrl = null;

        // --- 1. Navigation Guard Logic ---

        // Native Browser Guard (Close Tab / Refresh) - Can't be styled
        window.addEventListener('beforeunload', (e) => {
            if (isUploading) {
                e.preventDefault();
                e.returnValue = '';
            }
        });

        // Intercept all internal links (Cancel button, Breadcrumbs, Sidebar)
        document.addEventListener('click', (e) => {
            // Find if a link was clicked
            const link = e.target.closest('a');

            // If we are uploading, and it's a link, and it's not a JS href="#"
            if (isUploading && link && link.href && !link.href.includes('#')) {
                e.preventDefault(); // Stop navigation
                pendingUrl = link.href; // Remember where they wanted to go
                navModal.style.display = 'grid'; // Show custom modal
            }
        });

        // Modal: Stay
        btnStay.addEventListener('click', () => {
            navModal.style.display = 'none';
            pendingUrl = null;
        });

        // Modal: Leave
        btnLeave.addEventListener('click', () => {
            isUploading = false; // Disable guard
            if (pendingUrl) {
                window.location.href = pendingUrl; // Resume navigation
            }
        });


        // --- 2. UI Rendering Logic ---

        function render() {
            list.innerHTML = '';
            if (queue.length === 0) {
                list.style.display = 'none';
                start.disabled = true;
                return;
            }
            list.style.display = 'block';
            start.disabled = false;

            queue.forEach((f, i) => {
                const row = document.createElement('div');
                row.className = 'file-row';

                const meta = document.createElement('div');
                meta.className = 'file-meta';
                meta.innerHTML = `
        <div class="file-name">${escapeHtml(f.name)}</div>
        <div class="zd-meta">${(f.size/1048576).toFixed(2)} MB</div>
        <div class="progress"><div class="bar" id="bar-${i}"></div></div>
      `;

                const actions = document.createElement('div');
                actions.className = 'file-actions';
                const rm = document.createElement('button');
                rm.className = 'za-btn';
                rm.textContent = 'Remove';
                // Disable remove button if uploading
                if (isUploading) rm.disabled = true;

                rm.onclick = () => {
                    queue.splice(i, 1);
                    render();
                };
                actions.appendChild(rm);

                row.appendChild(meta);
                row.appendChild(actions);
                list.appendChild(row);
            });
        }

        function escapeHtml(s) {
            return s.replace(/[&<>"']/g, c => ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            } [c]));
        }

        function addFiles(files) {
            if (isUploading) return; // Lock drops during upload
            for (const f of files) queue.push(f);
            render();
        }

        // drag & drop
        ['dragenter', 'dragover'].forEach(ev => drop.addEventListener(ev, e => {
            e.preventDefault();
            e.stopPropagation();
            drop.classList.add('dragover');
        }));
        ['dragleave', 'drop'].forEach(ev => drop.addEventListener(ev, e => {
            e.preventDefault();
            e.stopPropagation();
            drop.classList.remove('dragover');
        }));
        drop.addEventListener('drop', e => addFiles(e.dataTransfer.files));
        pick.addEventListener('change', e => addFiles(e.target.files));

        // upload
        start.addEventListener('click', async () => {
            const status = document.getElementById('status');
            status.textContent = '';
            start.disabled = true;
            isUploading = true;
            render(); // Re-render to disable remove buttons

            let hadErrors = false;
            const originalTitle = document.title;
            const originalBtnText = start.textContent;

            for (let i = 0; i < queue.length; i++) {
                // Feedback
                start.textContent = `Uploading ${i + 1} of ${queue.length}...`;
                document.title = `[${i + 1}/${queue.length}] Uploading...`;

                const file = queue[i];
                const bar = document.getElementById('bar-' + i);

                const form = new FormData();
                form.append('file', file);

                const url = `/admin/projects/<?= htmlspecialchars($project_uuid) ?>/days/<?= htmlspecialchars($day['day_uuid']) ?>/clips/upload`;

                try {
                    const res = await fetch(url, {
                        method: 'POST',
                        body: form,
                        credentials: 'same-origin',
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    });

                    if (res.redirected && res.url.includes('/login')) {
                        bar.style.width = '100%';
                        bar.classList.add('err');
                        status.textContent = 'Session expired. Reload page.';
                        hadErrors = true;
                        continue;
                    }

                    const text = await res.text();
                    let data;
                    try {
                        data = JSON.parse(text);
                    } catch {
                        data = {
                            ok: false
                        };
                    }

                    if (res.ok && data.ok) {
                        bar.style.width = '100%';
                        bar.classList.add('ok');
                    } else {
                        bar.style.width = '100%';
                        bar.classList.add('err');
                        hadErrors = true;
                        status.textContent = `Error on "${file.name}"`;
                    }
                } catch (err) {
                    bar.style.width = '100%';
                    bar.classList.add('err');
                    hadErrors = true;
                    status.textContent = `Network error on "${file.name}"`;
                }
            }

            isUploading = false;
            document.title = originalTitle;
            start.textContent = originalBtnText;

            // Only leave if perfect run
            if (!hadErrors) {
                // Redirect directly to the Clips Index (Day View)
                window.location.href = `/admin/projects/<?= htmlspecialchars($project_uuid) ?>/days/<?= htmlspecialchars($day['day_uuid']) ?>/clips`;
            } else {
                start.disabled = false;
                render(); // Re-enable remove buttons
            }
        });

    })();
</script>

<?php $this->end(); ?>