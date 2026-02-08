<?php

/** @var array $project */
/** @var string $project_uuid */
/** @var array $day */



$this->extend('layout/main');

$this->start('head'); ?>
<title><?= htmlspecialchars($project['title']) ?> - Add Clips - Zentropa Dailies</title>
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

<div id="upload-sticky-status" style="display:none; position:fixed; top:0; left:0; right:0; background:var(--zd-accent); color:#0b0c10; padding:16px; text-align:center; font-weight:800; z-index:9999; box-shadow:0 10px 30px rgba(0,0,0,0.5); font-size:16px; letter-spacing:0.02em;">
    <span id="upload-sticky-text">Initializing upload...</span>
    <span style="font-weight:400; opacity:0.8; margin-left:15px; font-size:14px;">(Do not close this window)</span>
</div>

<div class="zd-breadcrumb">
    <a class="zd-link" href="/admin/projects/<?= htmlspecialchars($project_uuid) ?>/days">Days</a>
    <span class="zd-sep">&gt;</span>
    <a class="zd-link" href="/admin/projects/<?= htmlspecialchars($project_uuid) ?>/days/<?= htmlspecialchars($day['day_uuid']) ?>/clips">Clips</a>
    <span class="zd-sep">&gt;</span>
    <span>Add clips</span>
</div>

<h1 class="zd-h1">Add clips to <?= htmlspecialchars($project['title']) ?> - Day <?= htmlspecialchars($day['shoot_date']) ?></h1>

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
    (function() {
        // Fixed element IDs to match HTML
        var dz = document.getElementById('drop');
        var inp = document.getElementById('pick');
        var list = document.getElementById('list');
        var start = document.getElementById('start');
        var files = [];

        // Global State
        var isUploading = false;
        var stickyBar = document.getElementById('upload-sticky-status');
        var stickyText = document.getElementById('upload-sticky-text');

        // Prevent closing tab
        window.addEventListener('beforeunload', function(e) {
            if (isUploading) {
                e.preventDefault();
                e.returnValue = 'Uploads are in progress. Leaving now will cancel them.';
                return e.returnValue;
            }
        });

        // 1. Drag & Drop Visuals
        dz.addEventListener('dragover', function(e) {
            e.preventDefault();
            dz.classList.add('dragover');
        });
        dz.addEventListener('dragleave', function() {
            dz.classList.remove('dragover');
        });

        // 2. Handle File Drop
        dz.addEventListener('drop', function(e) {
            e.preventDefault();
            dz.classList.remove('dragover');
            handleFiles(e.dataTransfer.files);
        });

        // 3. Handle Click & Select
        dz.addEventListener('click', function() {
            inp.click();
        });
        inp.addEventListener('change', function() {
            handleFiles(inp.files);
        });

        function handleFiles(incoming) {
            if (isUploading) return;

            Array.from(incoming).forEach(function(f) {
                files.push(f);
                addFileRow(f);
            });

            if (files.length > 0) {
                list.style.display = 'block';
                start.disabled = false;
                start.textContent = 'Start Upload (' + files.length + ' Files)';
                start.classList.remove('zd-btn-secondary');
                start.classList.add('zd-btn-primary');
            }
        }

        function addFileRow(file) {
            var div = document.createElement('div');
            div.className = 'file-row';
            div.id = 'row-' + file.name.replace(/[^a-zA-Z0-9]/g, '');

            var sizeMB = (file.size / 1024 / 1024).toFixed(1);
            div.innerHTML =
                '<div class="file-meta">' +
                '<div class="file-name">' + file.name + '</div>' +
                '<div class="file-size">' + sizeMB + ' MB</div>' +
                '</div>' +
                '<div class="progress-wrap">' +
                '<div class="progress-bar"></div>' +
                '</div>' +
                '<div class="status-text">Pending</div>';

            list.appendChild(div);
        }

        // 4. Upload Logic
        start.addEventListener('click', function() {
            if (files.length === 0) return;

            isUploading = true;
            start.disabled = true;
            start.textContent = 'Uploading in progress...';

            if (stickyBar) stickyBar.style.display = 'block';

            var hadErrors = false;
            var total = files.length;
            var currentIndex = 0;

            function uploadNext() {
                if (currentIndex >= total) {
                    // All done
                    isUploading = false;
                    if (stickyBar) stickyBar.style.display = 'none';

                    if (!hadErrors) {
                        window.location.href = '/admin/projects/<?= htmlspecialchars($project_uuid) ?>/days/<?= htmlspecialchars($day['day_uuid']) ?>/converter';
                    } else {
                        start.disabled = false;
                        start.textContent = 'Retry Failed Uploads';
                        alert('Some files failed to upload. Please check the list.');
                    }
                    return;
                }

                var file = files[currentIndex];
                var index = currentIndex + 1;
                currentIndex++;

                if (stickyText) {
                    stickyText.textContent = 'UPLOADING FILE ' + index + ' OF ' + total + ': ' + file.name;
                }

                var rowId = 'row-' + file.name.replace(/[^a-zA-Z0-9]/g, '');
                var row = document.getElementById(rowId);

                if (!row) {
                    var allRows = document.querySelectorAll('.file-row');
                    for (var r = 0; r < allRows.length; r++) {
                        if (allRows[r].querySelector('.file-name').textContent === file.name) {
                            row = allRows[r];
                            break;
                        }
                    }
                }

                if (!row) {
                    uploadNext();
                    return;
                }

                var bar = row.querySelector('.progress-bar');
                var status = row.querySelector('.status-text');

                status.textContent = 'Uploading...';
                status.className = 'status-text text-accent';

                var formData = new FormData();
                formData.append('clip', file);
                formData.append('csrf', '<?= $csrf ?>');

                var xhr = new XMLHttpRequest();

                xhr.upload.onprogress = function(e) {
                    if (e.lengthComputable) {
                        var percent = (e.loaded / e.total) * 100;
                        bar.style.width = percent + '%';
                    }
                };

                xhr.onload = function() {
                    if (xhr.status >= 200 && xhr.status < 300) {
                        try {
                            var data = JSON.parse(xhr.responseText);
                            if (data.ok) {
                                bar.style.width = '100%';
                                bar.classList.add('ok');
                                status.textContent = 'Done';
                                status.className = 'status-text text-ok';
                            } else {
                                bar.classList.add('err');
                                status.textContent = 'Failed';
                                status.className = 'status-text text-err';
                                hadErrors = true;
                            }
                        } catch (e) {
                            hadErrors = true;
                            bar.classList.add('err');
                            status.textContent = 'Parse Error';
                            status.className = 'status-text text-err';
                        }
                    } else {
                        hadErrors = true;
                        bar.classList.add('err');
                        status.textContent = 'HTTP Error';
                        status.className = 'status-text text-err';
                    }
                    uploadNext();
                };

                xhr.onerror = function() {
                    hadErrors = true;
                    bar.classList.add('err');
                    status.textContent = 'Network Error';
                    status.className = 'status-text text-err';
                    uploadNext();
                };

                var uploadUrl = '/admin/projects/<?= htmlspecialchars($project_uuid) ?>/days/<?= htmlspecialchars($day['day_uuid']) ?>/upload';
                xhr.open('POST', uploadUrl);
                xhr.send(formData);
            }

            uploadNext();
        });

    })();
</script>

<?php $this->end(); ?>