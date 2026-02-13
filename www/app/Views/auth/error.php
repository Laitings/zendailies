<?php

/** @var string $title */
/** @var string $message */

$this->extend('layout/auth');

$this->start('content'); ?>
<div class="zd-login-brand">
    <img src="/assets/img/zen_logo.png" alt="Zentropa Logo">
    <h1><?= htmlspecialchars($title ?? 'Access Denied') ?></h1>
</div>
<br>
<div class="error">
    <?= htmlspecialchars($message ?? 'This invitation link has expired or is no longer valid. Please contact your DIT or Administrator for a new invite.') ?>
</div>
<br>
<form method="get" action="/auth/login" style="margin-top: 8px;">
    <button class="btn" type="submit">Back to Login</button>
</form>

<div class="muted">Need help? Contact Post Tech.</div>

<?php $this->end(); ?>