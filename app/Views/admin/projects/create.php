<?php $old = $old ?? ['title' => '', 'code' => '', 'status' => 'active']; ?>
<?php $errors = $errors ?? []; ?>
<?php $this->extend('layout/main'); ?>
<?php $this->start('content'); ?>

<div class="zd-page">
    <div class="zd-page-header">
        <h1>Create Project</h1>
    </div>

    <form method="post" action="/admin/projects" class="zd-form">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>" />

        <label class="zd-field">
            <span>Title</span>
            <input name="title" value="<?= htmlspecialchars($old['title']) ?>" required>
            <?php if (isset($errors['title'])): ?><em class="zd-err"><?= htmlspecialchars($errors['title']) ?></em><?php endif; ?>
        </label>

        <label class="zd-field">
            <span>Code</span>
            <input name="code" value="<?= htmlspecialchars($old['code']) ?>" placeholder="E.g. DOGMA25" required>
            <?php if (isset($errors['code'])): ?><em class="zd-err"><?= htmlspecialchars($errors['code']) ?></em><?php endif; ?>
            <small>Uppercase letters, digits, _ or -, 2–32 chars.</small>
        </label>

        <label class="zd-field">
            <span>Status</span>
            <select name="status">
                <option value="active" <?= $old['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                <option value="archived" <?= $old['status'] === 'archived' ? 'selected' : '' ?>>Archived</option>
            </select>
        </label>

        <div class="zd-form-actions">
            <a href="/admin/projects" class="zd-btn">Cancel</a>
            <button class="zd-btn zd-btn-primary">Create</button>
        </div>
    </form>
</div>

<?php $this->end(); ?>