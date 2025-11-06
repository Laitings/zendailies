<?php

/** @var array $project */
/** @var array $old */
/** @var array $errors */
/** @var string $csrf */
$old    = $old ?? ['title' => '', 'code' => '', 'status' => 'active'];
$errors = $errors ?? [];
?>
<?php $this->extend('layout/main'); ?>
<?php $this->start('content'); ?>

<div class="zd-page">
    <div class="zd-page-header">
        <h1>Edit Project</h1>
    </div>

    <?php if ($errors): ?>
        <div class="zd-alert zd-alert-danger" style="margin-bottom:1rem;">
            <strong>Couldn’t save:</strong>
            <ul style="margin:.5rem 0 0 .9rem;">
                <?php foreach ($errors as $e): ?>
                    <li><?= htmlspecialchars($e, ENT_QUOTES, 'UTF-8') ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="post" action="/admin/projects/<?= urlencode($project['id']) ?>" class="zd-form">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>" />

        <label class="zd-field">
            <span>Title</span>
            <input name="title" value="<?= htmlspecialchars($old['title'], ENT_QUOTES, 'UTF-8') ?>" required>
        </label>

        <label class="zd-field">
            <span>Code</span>
            <input name="code" value="<?= htmlspecialchars($old['code'], ENT_QUOTES, 'UTF-8') ?>" required>
            <small class="zd-hint">Must be unique (e.g. ZEN001).</small>
        </label>

        <label class="zd-field">
            <span>Status</span>
            <select name="status">
                <option value="active" <?= ($old['status'] === 'active')   ? 'selected' : '' ?>>Active</option>
                <option value="archived" <?= ($old['status'] === 'archived') ? 'selected' : '' ?>>Archived</option>
            </select>
        </label>

        <div class="zd-form-actions">
            <a href="/admin/projects" class="zd-btn">Cancel</a>
            <button class="zd-btn zd-btn-primary" type="submit">Save</button>
        </div>
    </form>

    <div class="zd-muted" style="margin-top:1rem;">
        <div><strong>ID:</strong> <span class="mono"><?= htmlspecialchars($project['id']) ?></span></div>
        <div><strong>Created:</strong> <?= htmlspecialchars($project['created_at'] ?? '') ?></div>
        <div><strong>Updated:</strong> <?= htmlspecialchars($project['updated_at'] ?? '') ?></div>
    </div>
</div>

<?php $this->end(); ?>