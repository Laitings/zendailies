<?php

/** app/Views/auth/login.php */
$this->extend('layout/auth');

$this->start('content'); ?>
<div class="zd-login-brand">
  <img src="/assets/img/zen_logo.png" alt="Zentropa Logo">
  <h1> Sign in to Zentropa Dailies</h1><br>

</div>



<?php if (!empty($error)): ?>
  <div class="error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>

<form method="post" action="/auth/login" style="display:grid;gap:10px">
  <label>
    Email
    <input name="email" type="email" required autocomplete="username" value="<?= htmlspecialchars($prefill_email ?? '', ENT_QUOTES, 'UTF-8') ?>">
  </label>
  <label>
    Password
    <div class="pwd-wrap">
      <input id="login-password" name="password" type="password" required autocomplete="current-password">
      <button
        type="button"
        class="pwd-toggle"
        id="pwdToggle"
        aria-label="Show password"
        aria-pressed="false"
        title="Show password">
        <!-- eye (show) -->
        <svg viewBox="0 0 24 24" aria-hidden="true">
          <path fill="currentColor" d="M12 4.5c5.5 0 9.7 3.6 11 7.5-1.3 3.9-5.5 7.5-11 7.5S2.3 15.9 1 12C2.3 8.1 6.5 4.5 12 4.5m0 2C7.6 6.5 4.3 9.2 3.2 12c1.1 2.8 4.4 5.5 8.8 5.5S20.7 14.8 21.8 12C20.7 9.2 17.4 6.5 12 6.5m0 2.5a3 3 0 1 1 0 6 3 3 0 0 1 0-6z" />
        </svg>
      </button>
    </div>
  </label>

  <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">

  <button class="btn" type="submit">Log in</button>
  <div class="muted">Need help? Contact Post Tech.</div>
</form>

<script>
  (function() {
    const input = document.getElementById('login-password');
    const btn = document.getElementById('pwdToggle');
    if (!input || !btn) return;

    const eye = '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M12 4.5c5.5 0 9.7 3.6 11 7.5-1.3 3.9-5.5 7.5-11 7.5S2.3 15.9 1 12C2.3 8.1 6.5 4.5 12 4.5m0 2C7.6 6.5 4.3 9.2 3.2 12c1.1 2.8 4.4 5.5 8.8 5.5S20.7 14.8 21.8 12C20.7 9.2 17.4 6.5 12 6.5m0 2.5a3 3 0 1 1 0 6 3 3 0 0 1 0-6z"/></svg>';
    const eyeOff = '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M2.3 1.6 1 2.9l4 4C3.2 8.1 2 9.9 1 12c1.3 3.9 5.5 7.5 11 7.5 2.1 0 4-.5 5.6-1.3l3.5 3.5 1.3-1.3L2.3 1.6zM7.9 8.8l1.5 1.5a3 3 0 0 0 4.3 4.3l1.5 1.5a5 5 0 0 1-7.3-7.3zM12 6.5c4.4 0 7.7 2.7 8.8 5.5-.5 1.3-1.4 2.6-2.6 3.6l-1.4-1.4c.6-.5 1.1-1.1 1.5-1.7-1.1-2.8-4.4-5.5-8.8-5.5-.7 0-1.3.1-2 .2L6.5 5.2A12 12 0 0 1 12 6.5z"/></svg>';

    btn.addEventListener('click', function() {
      const showing = input.type === 'text';
      input.type = showing ? 'password' : 'text';
      btn.setAttribute('aria-pressed', String(!showing));
      btn.setAttribute('aria-label', showing ? 'Show password' : 'Hide password');
      btn.setAttribute('title', showing ? 'Show password' : 'Hide password');
      btn.innerHTML = showing ? eye : eyeOff;
    });
  })();
</script>

<?php $this->end();
