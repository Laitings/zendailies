<?php

/** app/Views/auth/setup-password.php */
$this->extend('layout/auth');

$this->start('content'); ?>
<div class="zd-login-brand">
    <img src="/assets/img/zen_logo.png" alt="Zentropa Logo">
    <h1> Welcome to Zentropa Dailies</h1>
</div>

<p style="font-size: 13px; color: var(--muted); margin-bottom: 24px; line-height: 1.5;">
    Please set a secure password for <strong style="color: var(--text);"><?= htmlspecialchars($email) ?></strong> to complete your account setup.
</p>

<?php if (isset($_GET['error'])): ?>
    <div class="error">
        <?php if ($_GET['error'] === 'invalid'): ?>
            Passwords do not match or are too short (min 8 chars).
        <?php else: ?>
            An error occurred. Please try again.
        <?php endif; ?>
    </div>
<?php endif; ?>

<form method="post" action="/setup-password" style="display:grid;gap:12px">
    <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">

    <label>
        New Password
        <div class="pwd-wrap">
            <input id="setup-password" name="password" type="password" required autofocus autocomplete="new-password">
            <button type="button" class="pwd-toggle" onclick="togglePassword('setup-password', this)" title="Show password">
                <svg viewBox="0 0 24 24" aria-hidden="true">
                    <path fill="currentColor" d="M12 4.5c5.5 0 9.7 3.6 11 7.5-1.3 3.9-5.5 7.5-11 7.5S2.3 15.9 1 12C2.3 8.1 6.5 4.5 12 4.5m0 2C7.6 6.5 4.3 9.2 3.2 12c1.1 2.8 4.4 5.5 8.8 5.5S20.7 14.8 21.8 12C20.7 9.2 17.4 6.5 12 6.5m0 2.5a3 3 0 1 1 0 6 3 3 0 0 1 0-6z" />
                </svg>
            </button>
        </div>
    </label>

    <label>
        Confirm Password
        <div class="pwd-wrap">
            <input id="setup-password-confirm" name="password_confirm" type="password" required autocomplete="new-password">
            <button type="button" class="pwd-toggle" onclick="togglePassword('setup-password-confirm', this)" title="Show password">
                <svg viewBox="0 0 24 24" aria-hidden="true">
                    <path fill="currentColor" d="M12 4.5c5.5 0 9.7 3.6 11 7.5-1.3 3.9-5.5 7.5-11 7.5S2.3 15.9 1 12C2.3 8.1 6.5 4.5 12 4.5m0 2C7.6 6.5 4.3 9.2 3.2 12c1.1 2.8 4.4 5.5 8.8 5.5S20.7 14.8 21.8 12C20.7 9.2 17.4 6.5 12 6.5m0 2.5a3 3 0 1 1 0 6 3 3 0 0 1 0-6z" />
                </svg>
            </button>
        </div>
    </label>

    <button class="btn" type="submit" style="margin-top: 8px;">Activate Account</button>
    <div class="muted">Need help? Contact Post Tech.</div>
</form>

<script>
    function togglePassword(inputId, btn) {
        const input = document.getElementById(inputId);
        const eye = '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M12 4.5c5.5 0 9.7 3.6 11 7.5-1.3 3.9-5.5 7.5-11 7.5S2.3 15.9 1 12C2.3 8.1 6.5 4.5 12 4.5m0 2C7.6 6.5 4.3 9.2 3.2 12c1.1 2.8 4.4 5.5 8.8 5.5S20.7 14.8 21.8 12C20.7 9.2 17.4 6.5 12 6.5m0 2.5a3 3 0 1 1 0 6 3 3 0 0 1 0-6z"/></svg>';
        const eyeOff = '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M2.3 1.6 1 2.9l4 4C3.2 8.1 2 9.9 1 12c1.3 3.9 5.5 7.5 11 7.5 2.1 0 4-.5 5.6-1.3l3.5 3.5 1.3-1.3L2.3 1.6zM7.9 8.8l1.5 1.5a3 3 0 0 0 4.3 4.3l1.5 1.5a5 5 0 0 1-7.3-7.3zM12 6.5c4.4 0 7.7 2.7 8.8 5.5-.5 1.3-1.4 2.6-2.6 3.6l-1.4-1.4c.6-.5 1.1-1.1 1.5-1.7-1.1-2.8-4.4-5.5-8.8-5.5-.7 0-1.3.1-2 .2L6.5 5.2A12 12 0 0 1 12 6.5z"/></svg>';

        const showing = input.type === 'text';
        input.type = showing ? 'password' : 'text';
        btn.innerHTML = showing ? eye : eyeOff;
    }
</script>

<?php $this->end(); ?>