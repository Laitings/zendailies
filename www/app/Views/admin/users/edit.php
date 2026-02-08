<?php

/**
 * Edit User form
 * Expects $user (array) and optional $errors (array)
 */
$errors = $errors ?? [];
$u = $user ?? [];
$role = $u['user_role'] ?? 'regular';
$st   = $u['status']    ?? 'active';

// Helper to keep logic clean inside HTML
$is_superuser = !empty($u['is_superuser']);
?>

<?php $this->extend('layout/main'); ?>

<?php $this->start('head'); ?>
<title>Edit User · Zentropa Dailies</title>
<style>
    /* --- ZD Professional Theme Variables --- */
    :root {
        --zd-bg-page: #0b0c10;
        --zd-bg-panel: #13151b;
        --zd-bg-input: #08090b;

        --zd-border-subtle: #1f232d;
        --zd-border-focus: #3aa0ff;

        --zd-text-main: #eef1f5;
        --zd-text-muted: #8b9bb4;

        --zd-accent: #3aa0ff;
        --zd-accent-hover: #2b8ce0;
        --zd-danger: #e74c3c;
        --zd-success: #2ecc71;
    }

    /* --- Layout & Typography --- */
    .zd-page {
        max-width: 1000px;
        /* Slightly narrower to feel tighter */
        margin: 0 auto;
        padding: 25px 20px;
        color: var(--zd-text-main);
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
    }

    /* --- Header --- */
    .zd-header {
        margin-bottom: 25px !important;
        /* Unified spacing */
        padding-bottom: 0;
        /* Remove the extra padding */
        min-height: auto;
        /* Allow the header to collapse to the text size */
        position: relative;
    }



    .zd-header h1 {
        font-size: 20px;
        font-weight: 700;

        color: var(--zd-text-main);

        /* THE FIXES: */
        margin: 0;
        /* Remove external spacing */
        padding: 0;
        /* Remove internal spacing */
        line-height: 1;
        /* Removes the invisible space above/below all-caps text */
    }

    .zd-header::after {
        content: '';
        position: absolute;
        bottom: 0;
        left: 0;
        width: 100%;
        height: 2px;
        background: #2c3240;
        border-radius: 4px;
    }

    /* --- Grid Layout --- */
    .zd-layout-grid {
        display: grid;
        grid-template-columns: 1fr 320px;
        /* Reduced sidebar width */
        gap: 20px;
        align-items: start;
    }

    @media (max-width: 900px) {
        .zd-layout-grid {
            grid-template-columns: 1fr;
        }
    }

    /* --- Panels --- */
    .zd-panel {
        background: var(--zd-bg-panel);
        border: 1px solid var(--zd-border-subtle);
        border-radius: 6px;
        /* Slightly tighter radius */
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.15);
        overflow: hidden;
        margin-bottom: 20px;
    }

    .zd-panel-header {
        background: #171922;
        /* Match: Table header bg */
        padding: 10px 16px;
        /* Match: Table header padding */
        border-bottom: 1px solid var(--zd-border-subtle);

        font-size: 0.8rem;
        /* Match: Table header size */
        text-transform: uppercase;
        /* Match: Table header case */
        letter-spacing: 0.06em;
        /* Match: Table header spacing */
        font-weight: 600;
        /* Slightly bold */
        color: var(--zd-text-muted);
        /* Match: Table header color */
    }

    .zd-panel-body {
        padding: 20px;
        /* Reduced padding from 24px */
    }

    /* --- Forms --- */
    .zd-field-group {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 15px;
        /* Tighter gap */
        margin-bottom: 15px;
    }

    .zd-field {
        margin-bottom: 15px;
        /* Tighter vertical spacing */
    }

    .zd-field:last-child {
        margin-bottom: 0;
    }

    .zd-label {
        display: block;
        font-size: 10px;
        /* Smaller label */
        font-weight: 600;
        color: var(--zd-text-muted);
        text-transform: uppercase;
        letter-spacing: 0.05em;
        margin-bottom: 6px;
    }

    .zd-input,
    .zd-select {
        background: var(--zd-bg-input);
        border: 1px solid var(--zd-border-subtle);
        color: var(--zd-text-main);
        padding: 8px 10px;
        /* Tighter padding inside inputs */
        border-radius: 4px;
        font-size: 13px;
        /* Smaller font inside inputs */
        width: 100%;
        box-sizing: border-box;
        transition: all 0.2s ease;
        line-height: 1.4;
    }

    .zd-input:focus,
    .zd-select:focus {
        outline: none;
        border-color: var(--zd-border-focus);
        box-shadow: 0 0 0 2px rgba(58, 160, 255, 0.15);
    }

    .zd-hint {
        font-size: 11px;
        color: var(--zd-text-muted);
        margin-top: 5px;
        line-height: 1.3;
    }

    /* --- Toggle Switch Card --- */
    .zd-toggle-card {
        display: flex;
        align-items: center;
        gap: 10px;
        background: var(--zd-bg-input);
        border: 1px solid var(--zd-border-subtle);
        padding: 8px 12px;
        border-radius: 4px;
        cursor: pointer;
        transition: border-color 0.2s;
    }

    .zd-toggle-card:hover {
        border-color: var(--zd-text-muted);
    }

    .zd-checkbox {
        width: 16px;
        height: 16px;
        accent-color: var(--zd-success);
        cursor: pointer;
        margin: 0;
    }

    /* --- Actions --- */
    .zd-actions {
        display: flex;
        gap: 10px;
        justify-content: flex-end;
        padding-top: 5px;
    }

    .zd-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        padding: 8px 16px;
        /* Smaller buttons */
        border-radius: 4px;
        font-weight: 600;
        font-size: 12px;
        text-decoration: none;
        cursor: pointer;
        border: none;
        transition: all 0.2s;
    }

    .zd-btn-primary {
        background: var(--zd-accent);
        color: white;
    }

    .zd-btn-primary:hover {
        opacity: 0.9;
    }

    .zd-btn-ghost {
        background: transparent;
        color: var(--zd-text-muted);
        border: 1px solid var(--zd-border-subtle);
    }

    .zd-btn-ghost:hover {
        border-color: var(--zd-text-main);
        color: var(--zd-text-main);
    }

    /* --- Error Alert --- */
    .zd-alert-danger {
        background: rgba(231, 76, 60, 0.1);
        border: 1px solid rgba(231, 76, 60, 0.3);
        color: #ffb0b0;
        padding: 12px 16px;
        border-radius: 4px;
        margin-bottom: 20px;
        font-size: 13px;
    }

    .zd-alert-danger ul {
        margin: 5px 0 0 16px;
        padding: 0;
    }
</style>
<?php $this->end(); ?>

<?php $this->start('content'); ?>

<div class="zd-page">

    <div class="zd-header">
        <h1>Edit User</h1>
        <a href="/admin/users" class="zd-btn zd-btn-ghost"
            style="position: absolute; right: 0; top: -10px;"> Back to List
        </a>
    </div>

    <?php if ($errors): ?>
        <div class="zd-alert-danger">
            <strong>Unable to save user:</strong>
            <ul>
                <?php foreach ($errors as $e): ?>
                    <li><?= htmlspecialchars($e, ENT_QUOTES, 'UTF-8') ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="post" action="/admin/users/<?= htmlspecialchars($u['account_uuid'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="_csrf" value="<?= \App\Support\Csrf::token() ?>">

        <div class="zd-layout-grid">

            <div class="zd-col-main">

                <div class="zd-panel">
                    <div class="zd-panel-header">Profile Information</div>
                    <div class="zd-panel-body">

                        <div class="zd-field-group">
                            <div>
                                <label class="zd-label">First Name</label>
                                <input class="zd-input" type="text" name="first_name" required
                                    value="<?= htmlspecialchars($u['first_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                            </div>
                            <div>
                                <label class="zd-label">Last Name</label>
                                <input class="zd-input" type="text" name="last_name" required
                                    value="<?= htmlspecialchars($u['last_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                            </div>
                        </div>

                        <div class="zd-field">
                            <label class="zd-label">Email Address (Login)</label>
                            <input class="zd-input" type="email" name="email" required
                                value="<?= htmlspecialchars($u['account_email'] ?? $u['primary_email'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                            <div class="zd-hint">This email is used for login and notifications.</div>
                        </div>

                        <div class="zd-field">
                            <label class="zd-label">Phone Number (Optional)</label>
                            <input class="zd-input" type="text" name="phone"
                                pattern="^\+[1-9]\d{1,14}$"
                                title="Please use international format: + followed by country code (e.g., +4512345678)"
                                value="<?= htmlspecialchars($u['primary_phone'] ?? '', ENT_QUOTES, 'UTF-8') ?>">

                            <div class="zd-hint">
                                Use international format: **+** [Country Code] [Number]
                            </div>
                        </div>

                    </div>
                </div>

                <div class="zd-panel">
                    <div class="zd-panel-header">Security</div>
                    <div class="zd-panel-body">
                        <div class="zd-field-group">
                            <div>
                                <label class="zd-label">New Password</label>
                                <input class="zd-input" type="password" name="password" placeholder="•••••••" autocomplete="new-password">
                            </div>
                            <div>
                                <label class="zd-label">Confirm Password</label>
                                <input class="zd-input" type="password" name="password_confirm" placeholder="•••••••" autocomplete="new-password">
                            </div>
                        </div>
                        <div class="zd-hint" style="margin-top:-8px;">Leave blank to keep current password.</div>
                    </div>
                </div>

                <div class="zd-actions">
                    <a class="zd-btn zd-btn-ghost" href="/admin/users">Cancel</a>
                    <button class="zd-btn zd-btn-primary" type="submit">Save Changes</button>
                </div>
            </div>

            <div class="zd-col-sidebar">
                <div class="zd-panel">
                    <div class="zd-panel-header">Access Control</div>
                    <div class="zd-panel-body">

                        <?php if (!empty($_SESSION['account']['is_superuser'])): ?>
                            <div class="zd-field">
                                <label class="zd-label">Account Role</label>
                                <select class="zd-select" name="user_role">
                                    <option value="regular" <?= $role === 'regular' ? 'selected' : ''; ?>>Regular User</option>
                                    <option value="admin" <?= $role === 'admin'  ? 'selected' : ''; ?>>System Administrator</option>
                                </select>
                            </div>

                            <div class="zd-field">
                                <label class="zd-label">Account Status</label>
                                <select class="zd-select" name="status">
                                    <option value="active" <?= $st === 'active'  ? 'selected' : ''; ?>>Active</option>
                                    <option value="disabled" <?= $st === 'disabled' ? 'selected' : ''; ?>>Disabled</option>
                                    <option value="locked" <?= $st === 'locked'  ? 'selected' : ''; ?>>Locked</option>
                                </select>
                            </div>

                            <div class="zd-field" style="margin-top: 20px; border-top: 1px solid var(--zd-border-subtle); padding-top: 16px;">
                                <label class="zd-label" style="margin-bottom: 8px;">Privileges</label>
                                <label class="zd-toggle-card">
                                    <input type="checkbox" class="zd-checkbox" name="is_superuser" value="1" <?= $is_superuser ? 'checked' : '' ?>>
                                    <div>
                                        <div style="font-weight: 600; font-size: 12px;">Superuser</div>
                                        <div style="font-size: 10px; color: var(--zd-text-muted);">Full system access</div>
                                    </div>
                                </label>
                            </div>
                        <?php else: ?>
                            <div style="font-size: 12px; color: var(--zd-text-muted); line-height: 1.6;">
                                <p>Global account settings (Role, Status, Superuser) are managed by Zentropa Superusers.</p>
                                <p style="margin-top: 10px;">To manage permissions for <strong><?= htmlspecialchars($u['first_name']) ?></strong> on your specific projects, please use the <strong>Members</strong> section within those projects.</p>
                            </div>
                            <input type="hidden" name="user_role" value="<?= htmlspecialchars($role) ?>">
                            <input type="hidden" name="status" value="<?= htmlspecialchars($st) ?>">
                            <input type="hidden" name="is_superuser" value="<?= $is_superuser ? '1' : '0' ?>">
                        <?php endif; ?>

                    </div>
                </div>
            </div>
        </div>
    </form>
</div>

<?php $this->end(); ?>