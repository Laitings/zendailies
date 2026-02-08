<?php

/** app/Views/auth/forgot-password.php */
$this->extend('layout/auth');

$this->start('content'); ?>
<div class="zd-login-brand">
    <img src="/assets/img/zen_logo.png" alt="Zentropa Logo">
    <h1>Reset Password</h1><br>
</div>

<?php if (!empty($success)): ?>
    <div class="success-alert" style="background: rgba(46, 204, 113, 0.1); border: 1px solid rgba(46, 204, 113, 0.3); color: #2ecc71; padding: 12px; border-radius: 4px; margin-bottom: 20px; font-size: 13px; text-align: center;">
        <?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?>
    </div>
    <div style="text-align:center; margin-top:20px;">
        <a href="/auth/login" class="btn btn-secondary">Return to Sign in</a>
    </div>
<?php else: ?>

    <?php if (!empty($error)): ?>
        <div class="error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <form method="post" action="/auth/forgot-password" style="display:grid;gap:15px">
        <p class="muted" style="font-size: 0.9rem; line-height: 1.4;">
            Enter your email address and we'll send you a link to reset your password.
        </p>
        <label>
            Email
            <input name="email" type="email" required autocomplete="email" placeholder="name@filmbyen.dk">
        </label>

        <button class="btn" type="submit">Send Reset Link</button>

        <div style="text-align: center; margin-top: 10px;">
            <a href="/auth/login" style="color: #666; text-decoration: none; font-size: 0.9rem;">Cancel</a>
        </div>
    </form>

<?php endif; ?>
<?php $this->end(); ?>