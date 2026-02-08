<?php

/** app/Views/auth/reset-password.php */
$this->extend('layout/auth');

$this->start('content'); ?>
<div class="zd-login-brand">
    <img src="/assets/img/zen_logo.png" alt="Zentropa Logo">
    <h1>Set New Password</h1><br>
</div>

<?php if (!empty($error)): ?>
    <div class="error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<form method="post" action="/auth/reset-password" style="display:grid;gap:15px">
    <input type="hidden" name="id" value="<?= htmlspecialchars($id) ?>">
    <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">

    <label>
        New Password
        <input name="password" type="password" required minlength="8" autocomplete="new-password">
    </label>

    <label>
        Confirm Password
        <input name="password_confirm" type="password" required minlength="8" autocomplete="new-password">
    </label>

    <button class="btn" type="submit">Save Password</button>
</form>
<?php $this->end(); ?>