<?php

/** @var array $project */
/** @var array $day */
/** @var array $clips */
/** @var array $assets */
/** @var string $csrf */

// Ensure this view uses the main layout
$this->extend('layout/main');
?>

<?php $this->start('title'); ?>
Converter · <?= htmlspecialchars($project['title']) ?> · <?= htmlspecialchars($day['shoot_date']) ?>
<?php $this->end(); ?>

<?php $this->start('content'); ?>

<div class="za-panel" style="background:var(--panel);padding:16px;border-radius:12px;border:1px solid var(--border)">
    <div class="za-flex za-justify-between za-items-center" style="display:flex;justify-content:space-between;align-items:center;gap:12px;">
        <div>
            <h1 style="margin:0;font-size:18px;color:var(--text)">Converter — <?= htmlspecialchars($project['title']) ?></h1>
            <div style="opacity:.8;font-size:13px"><?= htmlspecialchars($day['shoot_date'] . ($day['title'] ? ' · ' . $day['title'] : '')) ?></div>
        </div>
        <div class="za-actions" style="display:flex;gap:8px;">
            <form id="bulkPostersForm" method="post" action="">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
                <button type="button" id="btnBulkPosters" class="za-btn">Generate posters (missing)</button>
                <button class="zd-btn-pull-metadata"
                    data-clip="<?= htmlspecialchars($clip['clip_uuid']) ?>"
                    style="margin-left:6px;">
                    Pull metadata
                </button>

            </form>
        </div>
    </div>

    <div style="margin-top:14px;border-top:1px solid var(--border);padding-top:12px;">
        <table class="za-table" style="width:100%;border-collapse:separate;border-spacing:0;">
            <thead style="position:sticky;top:0;background:var(--panel);z-index:1">
                <tr>
                    <th style="text-align:left;padding:8px;border-bottom:1px solid var(--border)">Poster</th>
                    <th style="text-align:left;padding:8px;border-bottom:1px solid var(--border)">Proxy</th>
                    <th style="text-align:left;padding:8px;border-bottom:1px solid var(--border)">Encode job</th>
                    <th style="text-align:left;padding:8px;border-bottom:1px solid var(--border)">Scene</th>
                    <th style="text-align:left;padding:8px;border-bottom:1px solid var(--border)">Slate</th>
                    <th style="text-align:left;padding:8px;border-bottom:1px solid var(--border)">Take</th>
                    <th style="text-align:left;padding:8px;border-bottom:1px solid var(--border)">Camera</th>
                    <th style="text-align:left;padding:8px;border-bottom:1px solid var(--border)">File</th>
                    <th style="text-align:left;padding:8px;border-bottom:1px solid var(--border)">Ingest</th>
                    <th style="text-align:left;padding:8px;border-bottom:1px solid var(--border)">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($clips as $c):
                    $cu = $c['clip_uuid'];
                    // $assets entry may not exist yet; provide a safe default
                    $a  = $assets[$cu] ?? ['poster' => false, 'poster_path' => null, 'proxy_web' => false];

                    // Build poster URL safely:
                    // - If DB path already starts with /data/, use as-is.
                    // - If not, prepend ZEN_STOR_PUBLIC_BASE (default /data) once.
                    $posterPath = $a['poster_path'] ?? null;
                    $imgUrl = null;
                    if ($a['poster'] && $posterPath) {
                        if (strpos($posterPath, '/data/') === 0) {
                            $imgUrl = $posterPath;
                        } else {
                            $pubBase = rtrim(getenv('ZEN_STOR_PUBLIC_BASE') ?: '/data', '/');
                            $imgUrl  = $pubBase . '/' . ltrim($posterPath, '/');
                        }
                    }
                ?>
                    <tr data-clip="<?= htmlspecialchars($cu) ?>">
                        <td style="padding:8px;border-bottom:1px solid var(--border)">
                            <?php if ($imgUrl): ?>
                                <img
                                    class="poster"
                                    src="<?= htmlspecialchars($imgUrl) ?>"
                                    alt="Poster"
                                    width="160"
                                    height="90"
                                    loading="lazy"
                                    style="border-radius:6px;border:1px solid var(--border)" />
                            <?php else: ?>
                                <span style="opacity:.7">×</span>
                            <?php endif; ?>
                        </td>
                        <td style="padding:8px;border-bottom:1px solid var(--border)"><?= $a['proxy_web'] ? '✓' : '×' ?></td>
                        <td style="padding:8px;border-bottom:1px solid var(--border)">
                            <?php
                            $jobStateRaw = $c['job_state'] ?? null;
                            $jobProgress = $c['job_progress'] ?? null;

                            $jobLabel = 'No job';
                            if ($jobStateRaw !== null) {
                                $jobState = strtolower((string)$jobStateRaw);
                                if ($jobState === 'queued') {
                                    $jobLabel = 'Queued';
                                } elseif ($jobState === 'running') {
                                    $jobLabel = 'Running' . ($jobProgress !== null ? ' (' . (int)$jobProgress . '%)' : '');
                                } elseif ($jobState === 'done') {
                                    $jobLabel = 'Done';
                                } elseif ($jobState === 'failed') {
                                    $jobLabel = 'Failed';
                                } elseif ($jobState === 'canceled') {
                                    $jobLabel = 'Canceled';
                                } else {
                                    $jobLabel = $jobStateRaw;
                                }
                            }
                            ?>
                            <span class="za-badge"><?= htmlspecialchars($jobLabel, ENT_QUOTES, 'UTF-8') ?></span>
                        </td>

                        <td style="padding:8px;border-bottom:1px solid var(--border)"><?= htmlspecialchars($c['scene'] ?? '') ?></td>
                        <td style="padding:8px;border-bottom:1px solid var(--border)"><?= htmlspecialchars($c['slate'] ?? '') ?></td>
                        <td style="padding:8px;border-bottom:1px solid var(--border)"><?= htmlspecialchars($c['take'] ?? '') ?></td>
                        <td style="padding:8px;border-bottom:1px solid var(--border)"><?= htmlspecialchars($c['camera'] ?? '') ?></td>
                        <td style="padding:8px;border-bottom:1px solid var(--border)"><?= htmlspecialchars($c['file_name'] ?? '') ?></td>
                        <td style="padding:8px;border-bottom:1px solid var(--border)"><span class="za-badge"><?= htmlspecialchars($c['ingest_state'] ?? '') ?></span></td>
                        <td style="padding:8px;border-bottom:1px solid var(--border)">
                            <button class="za-btn btnPoster" data-force="0">Generate poster</button>
                            <button class="za-btn-muted btnPoster" data-force="1" style="margin-left:6px;">Force</button>
                            <button class="za-btn btnProxy" style="margin-left:6px;">Build proxy</button>
                            <span class="row-status" style="margin-left:8px;font-size:12px;opacity:.8"></span>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
    (function() {
        const csrf = <?= json_encode($csrf) ?>;
        const base = <?= json_encode("/admin/projects/{$project['project_uuid']}/days/{$day['day_uuid']}/converter") ?>;

        function setRowStatus(tr, text) {
            const el = tr.querySelector('.row-status');
            if (el) el.textContent = text || '';
        }

        async function postJSON(url, formData) {
            const res = await fetch(url, {
                method: 'POST',
                body: formData
            });
            const txt = await res.text();
            let data;
            try {
                data = JSON.parse(txt)
            } catch {
                data = {
                    ok: false,
                    error: 'Invalid JSON'
                }
            }
            if (!res.ok) data.ok = false;
            return data;
        }

        // Single poster
        document.querySelectorAll('.btnPoster').forEach(btn => {
            btn.addEventListener('click', async () => {
                const tr = btn.closest('tr');
                const clip = tr.getAttribute('data-clip');
                const force = btn.getAttribute('data-force') === '1' ? 1 : 0;

                const fd = new FormData();
                fd.append('csrf_token', csrf);
                fd.append('clip_uuid', clip);
                fd.append('force', String(force));
                setRowStatus(tr, 'Converting…');

                const data = await postJSON(base + '/poster', fd);
                if (data.ok) {
                    setRowStatus(tr, 'Done' + (data.used_seek !== undefined ? ' (t=' + data.used_seek + 's)' : ''));

                    // Refresh just the poster cell (use API href verbatim; do NOT prepend a base)
                    const imgCell = tr.querySelector('td:first-child');
                    if (imgCell && data.href) {
                        imgCell.innerHTML = '<img src="' + data.href + '?t=' + Date.now() + '" alt="poster" style="width:160px;height:auto;border-radius:6px;border:1px solid var(--border)">';
                    }
                } else {
                    setRowStatus(tr, 'Failed: ' + (data.error || ''));
                }
            });
        });

        // Bulk posters (missing)
        const bulkBtn = document.getElementById('btnBulkPosters');
        if (bulkBtn) {
            bulkBtn.addEventListener('click', async () => {
                bulkBtn.disabled = true;
                const fd = new FormData();
                fd.append('csrf_token', csrf);
                const res = await postJSON(base + '/posters-bulk', fd);
                alert(res.ok ? ('Posters generated.\nDone: ' + (res.done || 0) + '\nFailed: ' + (res.failed || 0)) : ('Failed: ' + (res.error || '')));
                bulkBtn.disabled = false;
                // Optional: trigger a full reload to pick up all new posters
                // location.reload();
            });
        }
    })();
</script>

<script>
    document.addEventListener('click', async (e) => {
        if (e.target.matches('.zd-btn-pull-metadata')) {
            const btn = e.target;
            const clipUuid = btn.getAttribute('data-clip');
            const csrf = "<?= htmlspecialchars($csrf) ?>";
            const url = "/admin/projects/<?= htmlspecialchars($project['project_uuid']) ?>/days/<?= htmlspecialchars($day['day_uuid']) ?>/pull-metadata";

            btn.disabled = true;
            btn.textContent = 'Probing...';

            try {
                const resp = await fetch(url, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: new URLSearchParams({
                        csrf_token: csrf,
                        clip_uuid: clipUuid
                    })
                });

                const json = await resp.json();
                console.log('pull-metadata result', json);

                if (json.ok) {
                    btn.textContent = 'Metadata OK';
                } else {
                    btn.textContent = 'Metadata ERR';
                    alert(json.error || json.message || 'Metadata failed');
                }
            } catch (err) {
                console.error(err);
                btn.textContent = 'Metadata ERR';
                alert('Network/JS error during metadata pull');
            }

            // Re-enable or leave disabled?
            // up to you. I'll re-enable:
            btn.disabled = false;
        }
    });
</script>


<?php $this->end(); ?>