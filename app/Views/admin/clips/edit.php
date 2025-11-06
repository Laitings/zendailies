<?php

/** @var \App\Support\View $this */
/** @var array $project */
/** @var string $project_uuid */
/** @var string $day_uuid */
/** @var string $day_label */
/** @var array $clip */
/** @var array $meta_rows */
/** @var string $_csrf */

$this->extend('layout/main');
?>

<?php $this->start('head'); ?>
<style>
    .zd-form {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 12px;
    }

    .zd-form .full {
        grid-column: 1 / -1;
    }

    .zd-input,
    .zd-select,
    .zd-textarea {
        background: #0f1218;
        color: #e9eef3;
        border: 1px solid #1f2430;
        border-radius: 8px;
        padding: 8px;
        width: 100%;
        box-sizing: border-box;
    }

    .zd-label {
        font-size: 12px;
        color: #9aa7b2;
        margin-bottom: 4px;
        display: block;
    }

    .zd-actions {
        display: flex;
        gap: 8px;
        margin-top: 14px;
    }

    .zd-btn {
        background: #3aa0ff;
        color: #0b0c10;
        border: none;
        border-radius: 10px;
        padding: 8px 12px;
        cursor: pointer;
    }

    .zd-btn.secondary {
        background: #111318;
        color: #e9eef3;
        border: 1px solid #1f2430;
    }

    .zd-card {
        background: #111318;
        border: 1px solid #1f2430;
        border-radius: 12px;
        padding: 12px;
    }

    .zd-meta {
        color: #9aa7b2;
        font-size: 12px;
    }

    /* --- Edit page: make it comfortably wide and responsive, page-local only --- */
    .zd-edit-page {
        /* Adjust once if you want even wider later */
        --page-max: 1200px;
        max-width: var(--page-max);
        margin-inline: auto;
        padding-inline: 8px;
    }

    /* Your form grid (whatever class you use: zd-form or zd-grid) — scale columns up with width */
    .zd-form,
    .zd-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        /* 2 cols by default */
        gap: 12px;
    }

    /* >= 900px: 3 columns */
    @media (min-width: 900px) {

        .zd-form,
        .zd-grid {
            grid-template-columns: repeat(3, minmax(200px, 1fr));
        }
    }

    /* >= 1200px: 4 columns */
    @media (min-width: 1200px) {

        .zd-form,
        .zd-grid {
            grid-template-columns: repeat(4, minmax(200px, 1fr));
        }
    }

    /* Ensure cards stretch to the new width */
    .zd-card {
        width: 100%;
        box-sizing: border-box;
    }

    /* Make any section with .full span all columns */
    .full {
        grid-column: 1 / -1;
    }

    /* === Single-column layout override (Edit Clip) === */
    .zd-edit-page.single-col {
        max-width: 760px;
        /* tweak if you want wider/narrower */
        margin: 0 auto;
        /* center horizontally */
        padding: 0 12px;
        /* small side padding */
    }

    .zd-edit-page.single-col .zd-form,
    .zd-edit-page.single-col .zd-grid {
        display: grid;
        grid-template-columns: 1fr !important;
        /* force 1 column */
        gap: 12px;
        float: none;
        /* just in case */
    }

    .zd-edit-page.single-col .zd-card {
        width: 100%;
        box-sizing: border-box;
        overflow: hidden;
        /* ensures card background wraps any inner overflow */
    }

    /* --- Center the page & card, allow multi-column fields --- */
    .zd-edit-page {
        /* keep it to a nice readable width; tweak if you want wider */
        --page-max: 1100px;
        max-width: var(--page-max);
        margin: 0 auto;
        padding-inline: 16px;

        /* defensive resets in case a parent layout uses CSS columns or floats */
        column-count: 1;
        display: block;
    }

    .zd-card {
        /* the card itself should also be centered and full-width of the wrapper */
        max-width: var(--page-max);
        margin: 0 auto 24px;
        width: 100%;
        box-sizing: border-box;
    }

    /* Multi-column grid INSIDE the card */
    .zd-form,
    .zd-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(220px, 1fr));
        /* 2 cols by default */
        gap: 12px;
        float: none;
        /* defensive */
    }

    /* ≥ 1000px: 3 columns */
    @media (min-width: 1000px) {

        .zd-form,
        .zd-grid {
            grid-template-columns: repeat(3, minmax(220px, 1fr));
        }
    }

    /* ≥ 1280px: 4 columns */
    @media (min-width: 1280px) {

        .zd-form,
        .zd-grid {
            grid-template-columns: repeat(4, minmax(220px, 1fr));
        }
    }

    /* Rows that should span across (e.g., Created/Updated, button rows, metadata block) */
    .full {
        grid-column: 1 / -1;
    }
</style>
<title>Edit Clip · Zentropa Dailies</title>
<?php $this->end(); ?>

<?php $this->start('content'); ?>
<div class="zd-edit-page">


    <h1>Edit Clip <span class="zd-meta">· <?= htmlspecialchars($day_label ?: $day_uuid) ?></span></h1>
    <p class="zd-meta"><?= htmlspecialchars($project['title'] ?? 'Project') ?> · Clip #<?= htmlspecialchars($clip['clip_uuid']) ?></p>

    <div class="zd-card">
        <form method="post" action="/admin/projects/<?= htmlspecialchars($project_uuid) ?>/days/<?= htmlspecialchars($day_uuid) ?>/clips/<?= htmlspecialchars($clip['clip_uuid']) ?>/edit" novalidate>
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars($_csrf) ?>">

            <div class="zd-form">

                <div>
                    <label class="zd-label">Scene</label>
                    <input class="zd-input" name="scene" value="<?= htmlspecialchars($clip['scene'] ?? '') ?>">
                </div>

                <div>
                    <label class="zd-label">Slate</label>
                    <input class="zd-input" name="slate" value="<?= htmlspecialchars($clip['slate'] ?? '') ?>">
                </div>

                <div>
                    <label class="zd-label">Take</label>
                    <input class="zd-input" name="take" value="<?= htmlspecialchars($clip['take'] ?? '') ?>">
                </div>

                <div>
                    <label class="zd-label">Camera</label>
                    <input class="zd-input" name="camera" value="<?= htmlspecialchars($clip['camera'] ?? '') ?>">
                </div>

                <div>
                    <label class="zd-label">Reel</label>
                    <input class="zd-input" name="reel" value="<?= htmlspecialchars($clip['reel'] ?? '') ?>">
                </div>

                <div>
                    <label class="zd-label">File name</label>
                    <input class="zd-input" name="file_name" value="<?= htmlspecialchars($clip['file_name'] ?? '') ?>">
                </div>

                <div>
                    <label class="zd-label">TC In</label>
                    <input class="zd-input" name="tc_start" value="<?= htmlspecialchars($clip['tc_start'] ?? '') ?>">
                </div>

                <div>
                    <label class="zd-label">TC Out</label>
                    <input class="zd-input" name="tc_end" value="<?= htmlspecialchars($clip['tc_end'] ?? '') ?>">
                </div>

                <div>
                    <label class="zd-label">
                        Duration (MM:SS:FF<?= isset($clip['fps']) && $clip['fps'] ? ' @ ' . htmlspecialchars((string)$clip['fps']) . ' fps' : '' ?>)
                    </label>
                    <input class="zd-input" name="duration_pretty" pattern="\d{2}:\d{2}:\d{2}"
                        placeholder="MM:SS:FF"
                        value="<?= htmlspecialchars($clip['duration_pretty'] ?? '') ?>">
                    <!-- Optional: show raw ms as a hint (not used for saving) -->
                    <div class="zd-meta" style="margin-top:4px;">
                        Raw (ms): <?= htmlspecialchars((string)($clip['duration_ms'] ?? '')) ?>
                    </div>
                </div>

                <div>
                    <label class="zd-label">Rating (1–5)</label>
                    <select class="zd-select" name="rating">
                        <option value="">—</option>
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                            <option value="<?= $i ?>" <?= ((string)($clip['rating'] ?? '') === (string)$i) ? 'selected' : '' ?>><?= $i ?>★</option>
                        <?php endfor; ?>
                    </select>
                </div>

                <div>
                    <label class="zd-label">Select (Circle take)</label>
                    <label style="display:flex; gap:8px; align-items:center;">
                        <input type="checkbox" name="is_select" value="1" <?= !empty($clip['is_select']) ? 'checked' : '' ?>>
                        <span class="zd-meta">Marked as Select</span>
                    </label>
                </div>

                <div>
                    <label class="zd-label">Ingest state</label>
                    <select class="zd-select" name="ingest_state">
                        <?php
                        $state = (string)($clip['ingest_state'] ?? 'provisional');
                        foreach (['provisional', 'ready', 'locked', 'archived'] as $opt) {
                            $sel = ($state === $opt) ? 'selected' : '';
                            echo "<option value=\"" . htmlspecialchars($opt) . "\" $sel>" . htmlspecialchars(ucfirst($opt)) . "</option>";
                        }
                        ?>
                    </select>
                </div>

                <div class="full">
                    <div class="zd-meta" style="margin-top:6px;">
                        Created: <?= htmlspecialchars($clip['created_at'] ?? '') ?> · Updated: <?= htmlspecialchars($clip['updated_at'] ?? '') ?>
                    </div>
                </div>
            </div> <!-- end .zd-form -->

            <div class="zd-actions">
                <button class="zd-btn" type="submit">Save changes</button>
                <a class="zd-btn secondary" href="/admin/projects/<?= htmlspecialchars($project_uuid) ?>/days/<?= htmlspecialchars($day_uuid) ?>/clips">Cancel</a>
            </div>
        </form>
    </div>
    <div class="full">
        <?php if (!empty($meta_rows)): ?>
            <div class="zd-card" style="margin-top:12px;">
                <h3 style="margin-top:0;">Additional Metadata (read-only)</h3>
                <div class="zd-meta">We’ll make these key–value pairs editable in a follow-up step.</div>
                <div style="margin-top:8px;">
                    <?php foreach ($meta_rows as $m): ?>
                        <div style="display:grid; grid-template-columns: 220px 1fr; gap:8px; padding:6px 0; border-bottom:1px solid #1f2430;">
                            <div class="zd-meta"><?= htmlspecialchars($m['meta_key']) ?></div>
                            <div><?= nl2br(htmlspecialchars((string)$m['meta_value'])) ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div> <!-- /.zd-edit-page -->
<?php $this->end(); ?>