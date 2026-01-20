<?php

/**
 * User Profile / Settings 
 */
$u = $user ?? [];
$errors = $errors ?? [];
$success = $_GET['success'] ?? false;
?>

<?php $this->extend('layout/main'); ?>

<?php $this->start('head'); ?>
<title>Profile Settings · Zentropa Dailies</title>
<style>
    /* --- Re-using your ZD Professional Theme Variables --- */
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

    .zd-page {
        max-width: 1000px;
        margin: 0 auto;
        padding: 25px 20px;
        color: var(--zd-text-main);
    }

    .zd-header {
        margin-bottom: 20px;
        padding-bottom: 20px;
        min-height: 32px;
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
        text-transform: uppercase;
        letter-spacing: 0.05em;
        margin: 0;
        line-height: 1;
    }

    /* --- Grid Layout matches your Edit page --- */
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

    /* --- Panels & Inputs --- */
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
        border-bottom: 1px solid var(--zd-border-subtle);
        font-size: 0.8rem;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        font-weight: 600;
        color: var(--zd-text-muted);
    }

    .zd-panel-body {
        padding: 20px;
    }

    .zd-field-group {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 15px;
        margin-bottom: 15px;
    }

    .zd-field {
        margin-bottom: 15px;
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

    .zd-input {
        background: var(--zd-bg-input);
        border: 1px solid var(--zd-border-subtle);
        color: var(--zd-text-main);
        padding: 8px 10px;
        border-radius: 4px;
        font-size: 13px;
        width: 100%;
        box-sizing: border-box;
    }

    .zd-input:focus {
        outline: none;
        border-color: var(--zd-border-focus);
    }

    .zd-btn {
        display: inline-flex;
        padding: 8px 16px;
        border-radius: 4px;
        font-weight: 600;
        font-size: 12px;
        cursor: pointer;
        border: none;
        transition: all 0.2s;
        text-decoration: none;
    }

    .zd-btn-primary {
        background: var(--zd-accent);
        color: white;
    }

    .zd-btn-ghost {
        background: transparent;
        color: var(--zd-text-muted);
        border: 1px solid var(--zd-border-subtle);
    }

    .zd-success-banner {
        background: rgba(46, 204, 113, 0.1);
        border: 1px solid rgba(46, 204, 113, 0.3);
        color: #2ecc71;
        padding: 12px;
        border-radius: 4px;
        margin-bottom: 20px;
        font-size: 13px;
    }
</style>
<?php $this->end(); ?>

<?php $this->start('content'); ?>
<div class="zd-page">

    <div class="zd-header">
        <h1>Profile Settings</h1>
    </div>

    <?php if ($success): ?>
        <div class="zd-success-banner">✓ Your profile has been successfully updated.</div>
    <?php endif; ?>

    <?php if ($errors): ?>
        <div class="zd-alert-danger" style="background: rgba(231,76,60,0.1); border:1px solid rgba(231,76,60,0.3); color:#ffb0b0; padding:12px; border-radius:4px; margin-bottom:20px; font-size:13px;">
            <strong>Unable to save changes:</strong>
            <ul style="margin:5px 0 0 18px; padding:0;">
                <?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <form method="post" action="/account">
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
                                value="<?= htmlspecialchars($u['email'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                            <div class="zd-hint" style="font-size: 11px; color: var(--zd-text-muted); margin-top: 5px;">
                                This email is used for login and notifications.
                            </div>
                        </div>

                        <div class="zd-field">
                            <label class="zd-label">Phone Number (Optional)</label>
                            <input class="zd-input" type="text" name="phone"
                                value="<?= htmlspecialchars($u['phone'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                    </div>
                </div>



                <div class="zd-panel">
                    <div class="zd-panel-header">Security</div>
                    <div class="zd-panel-body">
                        <div class="zd-field-group">
                            <div>
                                <label class="zd-label">New Password</label>
                                <input class="zd-input" type="password" name="password" placeholder="••••••••" autocomplete="new-password">
                            </div>
                            <div>
                                <label class="zd-label">Confirm Password</label>
                                <input class="zd-input" type="password" name="password_confirm" placeholder="••••••••" autocomplete="new-password">
                            </div>
                        </div>
                        <div style="font-size: 11px; color: var(--zd-text-muted); margin-top: -5px;">Leave blank to keep your current password.</div>
                    </div>
                </div>

                <div class="zd-panel" style="margin-top: 20px;">
                    <div class="zd-panel-header">Passkeys & Biometrics</div>
                    <div class="zd-panel-body">
                        <p style="font-size: 13px; color: var(--zd-text-muted); margin-bottom: 15px;">
                            Secure your account using Windows Hello, FaceID, or TouchID. This allows you to log in without typing your password.
                        </p>

                        <div id="passkeyList">
                            <div style="font-size: 12px; color: var(--zd-text-muted); font-style: italic; margin-bottom: 15px;">
                                No passkeys registered yet.
                            </div>
                        </div>

                        <button type="button" class="zd-btn zd-btn-ghost" id="registerPasskeyBtn" style="border-color: var(--zd-accent); color: var(--zd-accent);">
                            + Register New Passkey
                        </button>
                    </div>
                </div>

                <div style="display: flex; gap: 10px; justify-content: flex-end;">
                    <a href="/dashboard" class="zd-btn zd-btn-ghost">Cancel</a>
                    <button type="submit" class="zd-btn zd-btn-primary">Save Settings</button>
                </div>
            </div>

            <div class="zd-col-sidebar">
                <div class="zd-panel">
                    <div class="zd-panel-header">Account Summary</div>
                    <div class="zd-panel-body">
                        <label class="zd-label">Member Since</label>
                        <div style="font-size: 13px; font-weight: 600;">
                            <?= !empty($u['created_at']) ? date('F d, Y', strtotime($u['created_at'])) : 'Joined recently' ?>
                        </div>

                        <div class="zd-field" style="margin-top: 15px; border-top: 1px solid var(--zd-border-subtle); padding-top: 15px;">
                            <label class="zd-label">Current Role</label>
                            <div style="display: flex; align-items: center; gap: 8px;">
                                <div style="width: 8px; height: 8px; border-radius: 50%; background: var(--zd-success);"></div>
                                <div style="font-size: 12px; font-weight: 600; text-transform: uppercase; color: var(--zd-success);">
                                    <?= htmlspecialchars($_SESSION['account']['user_role'] ?? 'User') ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </form>
</div>
<?php $this->end(); ?>