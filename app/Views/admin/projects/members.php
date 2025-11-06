<?php

/** @var array $project */
/** @var array $members */
/** @var array $errors */
/** @var array $old */
/** @var string $csrf */
$roles = ['producer', 'director', 'post_supervisor', 'editor', 'assistant_editor', 'script_supervisor', 'dop', 'dit', 'reviewer'];
?>
<?php $this->start('head'); ?>
<title>Manage Members · <?= htmlspecialchars($project['title']) ?></title>
<?php $this->end(); ?>

<?php $this->start('precontent'); ?>
<h1 class="zd-page-title">Members · <?= htmlspecialchars($project['title']) ?></h1>

<?php $this->end(); ?>

<?php $this->start('content'); ?>

<?php if (!empty($errors)): ?>
    <div class="zd-alert zd-alert-danger" style="margin-bottom:1rem;">
        <strong>Couldn’t save:</strong>
        <ul style="margin:.5rem 0 0 .9rem;">
            <?php foreach ($errors as $e): ?>
                <li><?= htmlspecialchars($e, ENT_QUOTES, 'UTF-8') ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="zd-card" style="margin-bottom:1rem;">
    <div class="zd-card-title">Add member</div>
    <div class="zd-card-body">
        <form method="post" action="/admin/projects/<?= urlencode($project['id']) ?>/members">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
            <div class="zd-grid-3">
                <label class="zd-field">
                    <span>Email</span>
                    <input type="email" name="email" required
                        value="<?= htmlspecialchars($old['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                    <small class="zd-hint">Finds a person via account email or primary contact email.</small>
                </label>
                <label class="zd-field">
                    <span>Role</span>
                    <select name="role">
                        <?php foreach ($roles as $r): ?>
                            <option value="<?= $r ?>" <?= (($old['role'] ?? 'reviewer') === $r) ? 'selected' : ''; ?>>
                                <?= ucwords(str_replace('_', ' ', $r)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <div>
                    <label class="zd-checkbox" style="margin-top:1.6rem;">
                        <input type="checkbox" name="is_project_admin" value="1">
                        <span>Project Admin (DIT)</span>
                    </label>
                    <label class="zd-checkbox">
                        <input type="checkbox" name="can_download" value="1">
                        <span>Allow downloads</span>
                    </label>
                </div>
            </div>
            <div class="zd-actions" style="margin-top:1rem;">
                <button class="zd-btn zd-btn-primary" type="submit">Add</button>
            </div>
        </form>
    </div>
</div>

<div class="zd-card">
    <div class="zd-card-title">Current members</div>
    <div class="zd-card-body">
        <?php if (!$members): ?>
            <p class="zd-muted">No members yet.</p>
        <?php else: ?>
            <table class="zd-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Email (primary)</th>
                        <th>Role</th>
                        <th>Admin</th>
                        <th>Download</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($members as $m): ?>
                        <tr>
                            <td><?= htmlspecialchars($m['display_name'] ?? ($m['first_name'] . ' ' . $m['last_name'])) ?></td>
                            <td><?= htmlspecialchars($m['email'] ?? '') ?></td>
                            <td>
                                <form method="post" action="/admin/projects/<?= urlencode($project['id']) ?>/members/<?= htmlspecialchars($m['person_uuid']) ?>">
                                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                                    <select name="role" class="zd-input zd-input-sm">
                                        <?php foreach ($roles as $r): ?>
                                            <option value="<?= $r ?>" <?= ($m['role'] === $r) ? 'selected' : ''; ?>><?= ucwords(str_replace('_', ' ', $r)) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                            </td>
                            <td style="text-align:center;">
                                <input type="checkbox" name="is_project_admin" value="1" <?= !empty($m['is_project_admin']) ? 'checked' : ''; ?>>
                            </td>
                            <td style="text-align:center;">
                                <input type="checkbox" name="can_download" value="1" <?= !empty($m['can_download']) ? 'checked' : ''; ?>>
                            </td>
                            <td class="zd-table-actions">
                                <button class="zd-btn zd-btn-small" type="submit">Save</button>
                                </form>
                                <form method="post" action="/admin/projects/<?= urlencode($project['id']) ?>/members/<?= htmlspecialchars($m['person_uuid']) ?>/remove" style="display:inline;">
                                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf) ?>">
                                    <button class="zd-btn zd-btn-small zd-btn-danger" type="submit" onclick="return confirm('Remove this member?');">Remove</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<?php $this->end(); ?>

<?php $this->start('sidebar'); ?>
<div class="zd-sidecard">
    <div class="zd-sidecard-title">About roles</div>
    <ul class="zd-muted" style="margin-left:1rem;">
        <li>Roles are per-project (Producer/Director, etc.).</li>
        <li><strong>Project Admin (DIT)</strong> can manage members and imports.</li>
        <li>Download permission is a per-project flag.</li>
    </ul>
</div>
<?php $this->end(); ?>