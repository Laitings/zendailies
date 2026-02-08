<?php

/** @var array $jobs */
/** @var bool $isPaused */
/** @var int  $hideDone */

$this->extend('layout/main');
?>

<?php $this->start('head'); ?>
<style>
    /* Match Users Directory narrow width (1000px) */
    .zd-page {
        max-width: 1000px;
        margin: 0 auto;
        padding: 25px 20px;
    }

    .zd-header h1 {
        font-size: 20px;
        font-weight: 700;
        margin: 0 0 20px 0;
        padding-bottom: 15px;
        position: relative;
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

    /* Table styling identical to Users */
    .zd-table-wrap {
        border-radius: 6px;
        background: #13151b;
        border: 1px solid #1f232d;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.15);
        overflow: hidden;
    }

    table.zd-jobs-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 13px;
    }

    .zd-jobs-table thead th {
        text-align: left;
        padding: 10px 16px;
        background: #171922;
        font-size: 0.8rem;
        text-transform: uppercase;
        font-weight: 700;
        color: #8b9bb4;
        border-bottom: 1px solid #1f232d;
    }

    .zd-jobs-table tbody td {
        padding: 12px 16px;
        /* Exactly match Users */
        border-top: 1px solid #1f232d;
        vertical-align: middle;
    }

    .test {}

    /* Row Hover */
    .zd-jobs-table tbody tr:hover {
        background: rgba(58, 160, 255, 0.06);
    }

    /* Standardized Chips (3px radius) */
    .zd-chip {
        display: inline-block;
        padding: 2px 6px;
        border-radius: 3px;
        font-size: 10px;
        text-transform: uppercase;
        font-weight: 700;
        letter-spacing: 0.03em;
    }

    .zd-chip-muted {
        background: #1f232d;
        color: #8b9bb4;
        border: 1px solid #2c3240;
    }

    .zd-chip-ok {
        background: rgba(46, 204, 113, 0.1);
        color: #2ecc71;
        border: 1px solid rgba(46, 204, 113, 0.2);
    }

    .zd-chip-warn {
        background: rgba(231, 76, 60, 0.1);
        color: #e74c3c;
        border: 1px solid rgba(231, 76, 60, 0.2);
    }

    .zd-chip-running {
        background: rgba(58, 160, 255, 0.1);
        color: #3aa0ff;
        border: 1px solid rgba(58, 160, 255, 0.2);
    }

    /* Dropdown Hover: Blue Highlight + White Icon */
    .zd-pro-item:hover {
        background: #3aa0ff !important;
        /* Zentropa Blue */
        color: #ffffff !important;
        /* White Text */
    }

    .zd-pro-item:hover .zd-pro-icon {
        filter: brightness(0) invert(1) !important;
        opacity: 1 !important;
    }

    .zd-pro-icon {
        width: 14px;
        height: 14px;
        filter: brightness(0) invert(1);
        /* Ensure it's visible on dark bg */
        opacity: 0.8;
    }

    /* Progress UI */
    .zd-progress-track {
        height: 6px;
        background: #08090b;
        border-radius: 3px;
        overflow: hidden;
        border: 1px solid #1f232d;
    }

    .zd-progress-fill {
        height: 100%;
        background: #3aa0ff;
        transition: width 0.4s ease;
    }

    .zd-jobs-meta {
        font-family: 'Menlo', monospace;
        font-size: 11px;
        color: #8b9bb4;
    }

    .zd-btn-success {
        background: var(--zd-success);
        color: #fff;
        border: none;
        padding: 8px 16px;
        border-radius: 4px;
        cursor: pointer;
        font-weight: 600;
    }

    .zd-btn-danger {
        background: #e74c3c;
        color: #fff;
        border: none;
        padding: 8px 16px;
        border-radius: 4px;
        cursor: pointer;
        font-weight: 600;
    }

    .zd-btn:hover {
        filter: brightness(1.1);
    }
</style>
<?php $this->end(); ?>

<?php $this->start('content'); ?>
<div class="zd-page">
    <div class="zd-header">
        <h1>Background Job Queue</h1>

        <div style="display:flex; justify-content:space-between; align-items:center;">
            <div style="display:flex; gap:16px; align-items:center;">
                <div class="zd-header-actions">
                    <form action="/admin/jobs/toggle" method="POST" style="display:inline;">
                        <?php if ($isPaused): ?>
                            <button type="submit" class="zd-btn zd-btn-success">
                                <i class="zd-icon-play"></i> Resume Queue
                            </button>
                        <?php else: ?>
                            <button type="submit" class="zd-btn zd-btn-danger">
                                <i class="zd-icon-pause"></i> Pause Queue
                            </button>
                        <?php endif; ?>
                    </form>
                </div>

                <form method="get" id="zd-filter-form" style="display:flex; align-items:center; gap:8px; font-size:12px; color:#8b9bb4;">
                    <label style="cursor:pointer; display:flex; align-items:center; gap:6px;">
                        <input type="checkbox" name="hide_done" value="1" <?= $hideDone ? 'checked' : '' ?> onchange="this.form.submit()">
                        Hide finished jobs
                    </label>
                </form>
            </div>
            <div class="zd-jobs-meta">Monitoring system queue</div>
        </div>
    </div>

    <div class="zd-table-wrap">
        <table class="zd-jobs-table">
            <thead>
                <tr>
                    <th style="width: 80px;">Type</th>
                    <th>Project / Clip</th>
                    <th style="width: 100px;">Status</th>
                    <th style="width: 180px;">Progress</th>
                    <th style="width: 100px;">Created</th>
                    <th style="width: 80px; text-align:right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($jobs as $j): ?>
                    <tr>
                        <td>
                            <?php if (($j['task_type'] ?? 'video') === 'email'): ?>
                                <span class="zd-chip zd-chip-muted" style="background: #2c3240; color: #8b9bb4;">EMAIL NOTIFY</span>
                            <?php else: ?>
                                <span class="zd-chip zd-chip-muted"><?= strtoupper($j['preset'] ?? 'H264 Proxy') ?></span>
                            <?php endif; ?>
                        </td>

                        <td>
                            <div style="font-weight:700; font-size:11px; color:#3aa0ff; text-transform:uppercase;">
                                <?php if (($j['task_type'] ?? 'video') === 'email'): ?>
                                    System Notification
                                <?php else: ?>
                                    <?= htmlspecialchars($j['project_title'] ?? 'Global') ?>
                                <?php endif; ?>
                            </div>
                            <div style="font-size:13px;">
                                <?php
                                if (($j['task_type'] ?? 'video') === 'email') {
                                    $payload = json_decode($j['payload'] ?? '{}', true);
                                    echo "To: " . htmlspecialchars($payload['email'] ?? 'Unknown Recipient');
                                } else {
                                    echo htmlspecialchars($j['file_name'] ?: 'System Task');
                                }
                                ?>
                            </div>
                        </td>

                        <td>
                            <?php
                            $state = $j['state'];
                            $cls = 'zd-chip-muted';
                            if ($state === 'running') $cls = 'zd-chip-running';
                            if ($state === 'done' || $state === 'finished') $cls = 'zd-chip-ok';
                            if ($state === 'failed')  $cls = 'zd-chip-warn';
                            ?>
                            <span class="zd-chip <?= $cls ?>"><?= htmlspecialchars(strtoupper($state)) ?></span>
                        </td>

                        <td>
                            <div style="display:flex; align-items:center; gap:10px;">
                                <div class="zd-progress-track" style="flex-grow:1;">
                                    <div class="zd-progress-fill" style="width: <?= (int)$j['progress_pct'] ?>%"></div>
                                </div>
                                <span class="zd-jobs-meta" style="min-width:30px;"><?= (int)$j['progress_pct'] ?>%</span>
                            </div>
                        </td>

                        <td class="zd-jobs-meta"><?= date('H:i:s', strtotime($j['created_at'])) ?></td>

                        <td style="text-align:right; overflow:visible;">
                            <?php
                            $userActions = [[
                                'label'  => 'Requeue Job',
                                'link'   => "/admin/jobs/requeue/" . $j['id'],
                                'icon'   => 'arrow-path',
                                'method' => 'POST'
                            ]];
                            include __DIR__ . '/../../partials/actions_dropdown.php';
                            ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<script>
    (function() {
        function refreshJobRow(jobId) {
            // We can reuse your statusSummary or a specific row-update endpoint
            fetch('/admin/jobs/status-summary')
                .then(res => res.json())
                .then(data => {
                    // If the global count changed, it's worth a full reload or row check
                    if (data.running > 0 || data.queued > 0) {
                        // For now, a simple reload is easiest, but we can target rows later
                        window.location.reload();
                    }
                });
        }

        // Replace your current 10s reload with a smarter check
        setInterval(() => {
            const hasActive = document.querySelector('.zd-chip-running, .zd-chip-muted');
            if (hasActive && !document.hidden) {
                window.location.reload();
            }
        }, 4000); // Check every 4 seconds
    })();
</script>
<?php $this->end(); ?>