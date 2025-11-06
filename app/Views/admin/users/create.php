<?php

/**
 * Create User form
 * Expects optional $errors (array of strings) and $old (array of prior inputs)
 */
$old    = $old    ?? [];
$errors = $errors ?? [];
?>

<?php $this->start('head'); ?>
<title>New User · Zentropa Dailies</title>
<?php $this->end(); ?>

<?php $this->start('precontent'); ?>
<h1 class="zd-page-title">New User</h1>
<p class="zd-muted">Create a person + account and link them.</p>
<?php $this->end(); ?>

<?php $this->start('content'); ?>
<div class="zd-card">
    <div class="zd-card-body">
        <?php if ($errors): ?>
            <div class="zd-alert zd-alert-danger" style="margin-bottom:1rem;">
                <strong>Couldn’t save:</strong>
                <ul style="margin: .5rem 0 0 .9rem;">
                    <?php foreach ($errors as $e): ?>
                        <li><?= htmlspecialchars($e, ENT_QUOTES, 'UTF-8') ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="post" action="/admin/users">
            <input type="hidden" name="_csrf" value="<?= \App\Support\Csrf::token() ?>">

            <div class="zd-grid-2">
                <div>
                    <label class="zd-label">First name</label>
                    <input class="zd-input" type="text" name="first_name" required
                        value="<?= htmlspecialchars($old['first_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div>
                    <label class="zd-label">Last name</label>
                    <input class="zd-input" type="text" name="last_name" required
                        value="<?= htmlspecialchars($old['last_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                </div>
            </div>

            <div class="zd-grid-2" style="margin-top:1rem;">
                <div>
                    <label class="zd-label">Email (account login)</label>
                    <input class="zd-input" type="email" name="email" required
                        value="<?= htmlspecialchars($old['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                    <p class="zd-hint">Also saved as the person’s primary email contact.</p>
                </div>
                <div>
                    <label class="zd-label">Phone (optional)</label>
                    <input class="zd-input" type="text" name="phone"
                        placeholder="+45 12 34 56 78"
                        value="<?= htmlspecialchars($old['phone'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                </div>
            </div>

            <div class="zd-grid-2" style="margin-top:1rem;">
                <div>
                    <label class="zd-label">Role</label>
                    <select class="zd-input" name="user_role">
                        <?php
                        $role = $old['user_role'] ?? 'regular';
                        ?>
                        <option value="regular" <?= $role === 'regular' ? 'selected' : ''; ?>>Regular</option>
                        <option value="admin" <?= $role === 'admin'  ? 'selected' : ''; ?>>Admin</option>
                    </select>
                </div>
                <div>
                    <label class="zd-label">Superuser</label>
                    <label class="zd-checkbox">
                        <input type="checkbox" name="is_superuser" value="1"
                            <?= !empty($old['is_superuser']) ? 'checked' : '' ?>>
                        <span>Grant superuser privileges</span>
                    </label>
                </div>
            </div>

            <div class="zd-grid-2" style="margin-top:1rem;">
                <div>
                    <label class="zd-label">Password</label>
                    <input class="zd-input" type="password" name="password" required>
                </div>
                <div>
                    <label class="zd-label">Confirm password</label>
                    <input class="zd-input" type="password" name="password_confirm" required>
                </div>
            </div>

            <div class="zd-actions" style="margin-top:1.25rem;">
                <a class="zd-btn" href="/admin/users">Cancel</a>
                <button class="zd-btn zd-btn-primary" type="submit">Create user</button>
            </div>
        </form>
    </div>
</div>
<?php $this->end(); ?>

<?php $this->start('sidebar'); ?>
<div class="zd-sidecard">
    <div class="zd-sidecard-title">Notes</div>
    <ul class="zd-muted" style="margin-left:1rem;">
        <li>Creates a <em>person</em> row and links it to the new account.</li>
        <li>Email is stored in <code>accounts.email</code> and in <code>person_contacts</code> as primary.</li>
        <li>Phone is optional; saved as <code>person_contacts</code> (<code>type=phone</code>).</li>
    </ul>
</div>
<?php $this->end(); ?>

<?php $this->start('scripts'); ?>
<!-- (optional) page JS -->
<?php $this->end(); ?>