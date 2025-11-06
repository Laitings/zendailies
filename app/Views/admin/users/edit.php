<?php

/**
 * Edit User form
 * Expects $user (array) and optional $errors (array)
 */
$errors = $errors ?? [];
$u = $user ?? [];
$role = $u['user_role'] ?? 'regular';
$st   = $u['status']    ?? 'active';
?>

<?php $this->extend('layout/main'); ?>

<?php $this->start('head'); ?>
<title>Edit User · Zentropa Dailies</title>
<style>
    :root {
        --form-pad: 48px;
    }

    /* keeps all inner content on the same rail */
    /* Page + header alignment */
    .zd-page-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin: 0 0 20px 0;
        /* no auto-centering here */
        padding: 0;
        /* no extra indent */
    }

    /* Make the page header align with the card's inner content (48px left pad) */
    .zd-page-header--align-with-card {
        padding-left: 48px;
    }

    .zd-page-header h1 {
        margin: 0;
        /* remove default h1 indent */
    }


    /* Card */
    .zd-edit-card {
        background: var(--panel);
        border: 1px solid var(--border);
        border-radius: 16px;
        padding: 40px var(--form-pad);
        max-width: 880px;
        /* same as .zd-page-narrow */
        width: 100%;
        margin: 0 auto;
        /* centers inside wrapper */
        box-sizing: border-box;
        box-shadow: 0 4px 16px rgba(0, 0, 0, 0.35);
    }

    .zd-page-narrow {
        max-width: 880px;
        margin: 0 auto;
        /* centers header + card together */
        padding: 0;
        /* no stray padding */
    }

    .zd-grid-2,
    .zd-grid-3 {
        display: grid;
        gap: 16px;
    }

    .zd-grid-2 {
        grid-template-columns: repeat(2, 1fr);
    }

    .zd-grid-3 {
        grid-template-columns: repeat(3, 1fr);
    }

    .zd-label {
        display: block;
        font-weight: 500;
        margin-bottom: 6px;
        color: var(--text);
    }

    .zd-input {
        width: 100%;
        background: var(--bg);
        border: 1px solid var(--border);
        border-radius: 8px;
        padding: 10px 12px;
        color: var(--text);
        font-size: 15px;
        box-sizing: border-box;
    }

    .zd-input:focus {
        border-color: var(--accent);
        box-shadow: 0 0 0 3px rgba(58, 160, 255, 0.18);
        outline: none;
    }

    /* Fieldset aligns exactly with normal fields */
    fieldset {
        border: 1px solid var(--border);
        border-radius: 10px;
        padding: 16px var(--form-pad) 20px;
        /* same side pad as card */
        margin-top: 16px;
    }

    legend {
        color: var(--muted);
        font-size: 0.9rem;
        margin: 0 0 8px 0;
        padding: 0;
        /* legend text follows the same rail naturally */
    }



    /* Buttons: icon-only */
    .zd-actions {
        display: flex;
        justify-content: flex-end;
        gap: 14px;
        align-items: center;
    }

    .zd-btn-icon {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 42px;
        height: 42px;
        border-radius: 8px;
        border: 1px solid var(--border);
        background: var(--panel);
        cursor: pointer;
        transition: background 0.15s ease, border-color 0.15s ease;
    }

    .zd-btn-icon img {
        width: 20px;
        height: 20px;
        filter: invert(61%) sepia(53%) saturate(2574%) hue-rotate(189deg) brightness(97%) contrast(101%);
        transition: filter 0.15s ease;
    }

    .zd-btn-icon:hover img {
        filter: invert(100%) brightness(200%);
    }

    .zd-btn-icon:hover {
        border-color: var(--accent);
        background: rgba(58, 160, 255, 0.06);
    }

    .zd-alert-danger {
        background: rgba(214, 40, 40, 0.12);
        border: 1px solid var(--danger);
        border-radius: 8px;
        padding: 10px 14px;
        color: #ffb0b0;
    }
</style>
<?php $this->end(); ?>




<?php $this->start('content'); ?>

<div class="zd-page">
    <div class="zd-page-narrow">
        <div class="zd-page-header">
            <h1>Edit User</h1>
            <a href="/admin/users" class="zd-btn">Back to Users</a>
        </div>

        <div class="zd-edit-card">
            <?php if ($errors): ?>
                <div class="zd-alert zd-alert-danger">
                    <strong>Couldn’t save:</strong>
                    <ul style="margin:.5rem 0 0 .9rem;">
                        <?php foreach ($errors as $e): ?>
                            <li><?= htmlspecialchars($e, ENT_QUOTES, 'UTF-8') ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form method="post" action="/admin/users/<?= htmlspecialchars($u['account_uuid'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="_csrf" value="<?= \App\Support\Csrf::token() ?>">

                <div class="zd-grid-2">
                    <div>
                        <label class="zd-label">First name</label>
                        <input class="zd-input" type="text" name="first_name" required
                            value="<?= htmlspecialchars($u['first_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                    <div>
                        <label class="zd-label">Last name</label>
                        <input class="zd-input" type="text" name="last_name" required
                            value="<?= htmlspecialchars($u['last_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                </div>

                <div class="zd-grid-2" style="margin-top:1rem;">
                    <div>
                        <label class="zd-label">Email (account login)</label>
                        <input class="zd-input" type="email" name="email" required
                            value="<?= htmlspecialchars($u['account_email'] ?? $u['primary_email'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                        <p class="zd-hint">Also synced to primary email in person contacts.</p>
                    </div>
                    <div>
                        <label class="zd-label">Phone (primary, optional)</label>
                        <input class="zd-input" type="text" name="phone"
                            value="<?= htmlspecialchars($u['primary_phone'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                    </div>
                </div>

                <div class="zd-grid-3" style="margin-top:1rem;">
                    <div>
                        <label class="zd-label">Role</label>
                        <select class="zd-input" name="user_role">
                            <option value="regular" <?= $role === 'regular' ? 'selected' : ''; ?>>Regular</option>
                            <option value="admin" <?= $role === 'admin'  ? 'selected' : ''; ?>>Admin</option>
                        </select>
                    </div>
                    <div>
                        <label class="zd-label">Status</label>
                        <select class="zd-input" name="status">
                            <option value="active" <?= $st === 'active'  ? 'selected' : ''; ?>>Active</option>
                            <option value="disabled" <?= $st === 'disabled' ? 'selected' : ''; ?>>Disabled</option>
                            <option value="locked" <?= $st === 'locked'  ? 'selected' : ''; ?>>Locked</option>
                        </select>
                    </div>
                    <div>
                        <label class="zd-label">Superuser</label>
                        <label class="zd-checkbox">
                            <input type="checkbox" name="is_superuser" value="1"
                                <?= !empty($u['is_superuser']) ? 'checked' : '' ?>>
                            <span>Grant superuser privileges</span>
                        </label>
                    </div>
                </div>

                <fieldset style="margin-top:1.25rem;">
                    <legend>Change password (optional)</legend>
                    <div class="zd-grid-2">
                        <div>
                            <label class="zd-label">New password</label>
                            <input class="zd-input" type="password" name="password" placeholder="Leave blank to keep">
                        </div>
                        <div>
                            <label class="zd-label">Confirm new password</label>
                            <input class="zd-input" type="password" name="password_confirm" placeholder="Leave blank to keep">
                        </div>
                    </div>
                </fieldset>

                <div class="zd-actions" style="margin-top:1.25rem;">
                    <a class="zd-btn-icon" href="/admin/users" title="Cancel">
                        <img src="/assets/icons/cancel.svg" alt="">
                    </a>
                    <button class="zd-btn-icon" type="submit" title="Save">
                        <img src="/assets/icons/save.svg" alt="">
                    </button>
                </div>


            </form>
        </div>
    </div>
</div>

<?php $this->end(); ?>