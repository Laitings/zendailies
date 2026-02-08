<?php

/**
 * Create User form
 * Expects optional $errors (array of strings) and $old (array of prior inputs)
 */
$old    = $old    ?? [];
$errors = $errors ?? [];
?>

<?php $this->extend('layout/main'); ?>

<?php $this->start('head'); ?>
<title>New User · Zentropa Dailies</title>
<style>
    /* --- ZD Professional Theme Variables (Matched to Edit Page) --- */
    :root {
        --zd-bg-page: #0b0c10;
        --zd-bg-panel: #13151b;
        --zd-bg-input: #08090b;

        --zd-border-subtle: #1f232d;
        --zd-border-focus: #3aa0ff;

        --zd-text-main: #eef1f5;
        --zd-text-muted: #8b9bb4;

        --zd-accent: #3aa0ff;
        --zd-danger: #e74c3c;
        --zd-success: #2ecc71;
    }

    /* --- Layout --- */
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


    /* --- Grid Layout --- */
    .zd-layout-grid {
        display: grid;
        grid-template-columns: 1fr 320px;
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
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.15);
        overflow: hidden;
        margin-bottom: 20px;
    }

    .zd-panel-header {
        background: #171922;
        padding: 10px 16px;

        font-size: 0.8rem;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        font-weight: 600;
        color: var(--zd-text-muted);
    }

    .zd-panel-body {
        padding: 20px;
    }

    /* --- Forms --- */
    .zd-field-group {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 15px;
        margin-bottom: 15px;
    }

    .zd-field {
        margin-bottom: 15px;
    }

    .zd-field:last-child {
        margin-bottom: 0;
    }

    .zd-label {
        display: block;
        font-size: 10px;
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
        border-radius: 4px;
        font-size: 13px;
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
        <h1>New User</h1>
        <a href="/admin/users" class="zd-btn zd-btn-ghost"
            style="position: absolute; right: 0; top: -10px;"> Back to List
        </a>
    </div>

    <?php if ($errors): ?>
        <div class="zd-alert-danger">
            <strong>Unable to create user:</strong>
            <ul>
                <?php foreach ($errors as $e): ?>
                    <li><?= htmlspecialchars($e, ENT_QUOTES, 'UTF-8') ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="post" action="/admin/users">
        <input type="hidden" name="_csrf" value="<?= \App\Support\Csrf::token() ?>">

        <div class="zd-layout-grid">

            <div class="zd-col-main">

                <div class="zd-panel">
                    <div class="zd-panel-header">Profile Information</div>
                    <div class="zd-panel-body">

                        <div class="zd-field-group">
                            <div>
                                <label class="zd-label">First Name <span style="color:var(--zd-danger)">*</span></label>
                                <input class="zd-input" type="text" name="first_name" required autofocus
                                    value="<?= htmlspecialchars($old['first_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                            </div>
                            <div>
                                <label class="zd-label">Last Name <span style="color:var(--zd-danger)">*</span></label>
                                <input class="zd-input" type="text" name="last_name" required
                                    value="<?= htmlspecialchars($old['last_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                            </div>
                        </div>

                        <div class="zd-field">
                            <label class="zd-label">Email Address (Login) <span style="color:var(--zd-danger)">*</span></label>
                            <input class="zd-input" type="email" name="email" required placeholder="user@zentropa.dk"
                                value="<?= htmlspecialchars($old['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                        </div>

                        <div class="zd-form-group">
                            <label for="phone">Phone Number</label>
                            <input type="tel" id="phone" name="phone"
                                placeholder="+45 12 34 56 78"
                                pattern="^\+[1-9]\d{1,14}$"
                                title="Please use international format: + followed by country code (e.g., +4512345678)"
                                value="<?= htmlspecialchars($old['phone'] ?? $user['primary_phone'] ?? '') ?>">
                            <div class="zd-hint">
                                Use international format: **+** [Country Code] [Number]
                            </div>
                        </div>

                    </div>
                </div>

                <div class="zd-panel">
                    <div class="zd-panel-header">Onboarding</div>
                    <div class="zd-panel-body">
                        <div style="display: flex; align-items: center; gap: 15px; color: var(--zd-success);">
                            <div style="font-size: 24px;">✉️</div>
                            <div>
                                <div style="font-weight: 600; font-size: 13px;">Invitation Email</div>
                                <div style="font-size: 11px; color: var(--zd-text-muted);">
                                    An invitation link will be sent to the user. They will choose their own password upon first login.
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="zd-actions">
                    <a class="zd-btn zd-btn-ghost" href="/admin/users">Cancel</a>
                    <button class="zd-btn zd-btn-primary" type="submit">Create User</button>
                </div>
            </div>

            <div class="zd-col-sidebar">
                <?php if (!empty($_SESSION['account']['is_superuser'])): ?>
                    <div class="zd-panel">
                        <div class="zd-panel-header">Access Control</div>
                        <div class="zd-panel-body">
                            <div class="zd-field">
                                <label class="zd-label">System Role</label>
                                <select class="zd-select" name="user_role">
                                    <?php $role = $old['user_role'] ?? 'regular'; ?>
                                    <option value="regular" <?= $role === 'regular' ? 'selected' : ''; ?>>Regular User</option>
                                    <option value="admin" <?= $role === 'admin'  ? 'selected' : ''; ?>>System Administrator</option>
                                </select>
                            </div>

                            <div class="zd-field" style="margin-top: 20px; border-top: 1px solid var(--zd-border-subtle); padding-top: 16px;">
                                <label class="zd-label" style="margin-bottom: 8px;">Advanced</label>
                                <label class="zd-toggle-card">
                                    <input type="checkbox" class="zd-checkbox" name="is_superuser" value="1" <?= !empty($old['is_superuser']) ? 'checked' : '' ?>>
                                    <div>
                                        <div style="font-weight: 600; font-size: 12px;">Superuser Status</div>
                                        <div style="font-size: 10px; color: var(--zd-text-muted);">Full system access</div>
                                    </div>
                                </label>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <input type="hidden" name="user_role" value="regular">
                <?php endif; ?>



                <div class="zd-panel">
                    <div class="zd-panel-header">Technical Details</div>
                    <div class="zd-panel-body">
                        <div style="font-size: 12px; line-height: 1.5; color: var(--zd-text-muted);">
                            <?php if (!empty($_SESSION['account']['is_superuser'])): ?>
                                <p style="margin-bottom: 12px;">
                                    <strong style="color: var(--zd-text-main);">Database:</strong><br>
                                    Creates a <code>person</code> row linked to a new <code>account</code> row.
                                </p>
                                <p style="margin-bottom: 12px;">
                                    <strong style="color: var(--zd-text-main);">Contacts:</strong><br>
                                    Email is stored for login and duplicated in person contacts for notifications.
                                </p>
                                <p style="margin: 0;">
                                    <strong style="color: var(--zd-text-main);">Access:</strong><br>
                                    Use Superuser sparingly. Most staff should be Regular users assigned to projects.
                                </p>
                            <?php else: ?>
                                <p style="margin-top: -3px; margin-bottom: 12px;">
                                    <strong style="color: var(--zd-text-main);">Step 1: System Account</strong><br>
                                    You are creating a global Zendailies account for this user. They will receive an invitation to set their password.
                                </p>
                                <p style="margin-bottom: 12px;">
                                    <strong style="color: var(--zd-text-main);">Step 2: Project Access</strong><br>
                                    Creating a user does <em style="color: var(--zd-accent);">not</em> automatically add them to your project.
                                </p>
                                <p style="margin: 0;">
                                    <strong style="color: var(--zd-text-main);">Next Steps:</strong><br>
                                    After clicking <strong>Create User</strong>, navigate to your project's <strong>Members</strong> page to assign this person a role and grant them access to dailies.
                                </p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </form>
</div>
<script>
    const phoneInput = document.getElementById('phone');
    if (phoneInput) {
        phoneInput.addEventListener('blur', function() {
            let val = this.value.trim().replace(/\s+/g, ''); // Remove spaces
            if (val.startsWith('00')) {
                val = '+' + val.substring(2); // Convert 00 to +
            }
            if (val !== '' && !val.startsWith('+')) {
                val = '+' + val; // Force + if missing
            }
            this.value = val;
        });
    }
</script>
<?php $this->end(); ?>