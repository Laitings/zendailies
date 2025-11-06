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

<script>
    (() => {
        const drop = document.getElementById('drop');
        const pick = document.getElementById('pick');
        const list = document.getElementById('list');
        const start = document.getElementById('start');

        /** @type {File[]} */
        let queue = [];

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

            let hadErrors = false;

            for (let i = 0; i < queue.length; i++) {
                const file = queue[i];
                const bar = document.getElementById('bar-' + i);

                const form = new FormData();
                form.append('file', file);

                // OPTIONAL CSRF token support (uncomment if you add a meta tag with the token)
                // const token = document.querySelector('meta[name="csrf-token"]')?.content;

                const url = `/admin/projects/<?= htmlspecialchars($project_uuid) ?>/days/<?= htmlspecialchars($day['day_uuid']) ?>/clips/upload`;

                try {
                    const res = await fetch(url, {
                        method: 'POST',
                        body: form,
                        credentials: 'same-origin', // <-- include PHPSESSID cookie
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest',
                            // ...(token ? { 'X-CSRF-Token': token } : {})
                        }
                    });

                    // If AuthGuard redirected to /login, tell the user
                    if (res.redirected && res.url.includes('/login')) {
                        bar.style.width = '100%';
                        bar.classList.add('err');
                        status.textContent = 'Session not sent/expired. Reload the page and try again.';
                        hadErrors = true;
                        continue;
                    }

                    const text = await res.text();
                    let data;
                    try {
                        data = JSON.parse(text);
                    } catch {
                        data = {
                            ok: false,
                            error: `Server did not return JSON (HTTP ${res.status}).`
                        };
                    }

                    if (res.ok && data.ok) {
                        bar.style.width = '100%';
                        bar.classList.add('ok');
                    } else {
                        bar.style.width = '100%';
                        bar.classList.add('err');
                        hadErrors = true;
                        const msg = data?.error || `HTTP ${res.status} ${res.statusText}`;
                        status.textContent = `Upload failed for "${file.name}": ${msg}`;
                        console.error('Upload failed:', msg, 'Body:', text);
                    }
                } catch (err) {
                    bar.style.width = '100%';
                    bar.classList.add('err');
                    hadErrors = true;
                    status.textContent = `Network error uploading "${file.name}": ${String(err)}`;
                    console.error(err);
                }
            }

            // Only leave the page if all files succeeded
            if (!hadErrors) {
                window.location.href = `/admin/projects/<?= htmlspecialchars($project_uuid) ?>/days/<?= htmlspecialchars($day['day_uuid']) ?>/converter`;
            } else {
                start.disabled = false; // let them fix/remove and retry
            }
        });

    })();
</script>

<?php $this->end(); ?>