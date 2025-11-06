<?php
// app/Views/home.php
// PURPOSE: Minimal dashboard after login. Gets $user from HomeController::index().
$sessionInfo = $user;
$sessionInfo['person_uuid'] = $_SESSION['person_uuid'] ?? null;
?>
<div class="zd-card" style="max-width:720px">
  <h2 style="margin-top:0">Welcome<?= !empty($user['email']) ? ', ' . htmlspecialchars($user['first_name']) : '' ?>!</h2>

  <?php if (!empty($user)): ?>
    <p style="margin:8px 0 16px 0">
      You’re signed in<?= !empty($user['is_superuser']) ? ' as <strong>superuser</strong>' : '' ?>.
    </p>

    <details style="margin:12px 0">
      <summary style="cursor:pointer">Session info (temporary)</summary>
      <pre style="background:#0b0c10;border:1px solid #1f2430;padding:12px;border-radius:8px;overflow:auto">
      <?= htmlspecialchars(json_encode($sessionInfo, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?>



      </pre>
      <p style="font-size:12px;opacity:.75">Tip: You can also visit <code>/whoami</code> to see this as JSON.</p>
    </details>

    <hr style="border:none;border-top:1px solid #1f2430;margin:16px 0">
    <?php
    $acct = $_SESSION['account'] ?? null;
    $isSuper = !empty($acct['is_superuser']);
    $isAdmin = isset($acct['user_role']) && $acct['user_role'] === 'admin';
    if ($isSuper || $isAdmin): ?>
      <a class="zd-card" href="/admin/users">
        <h3 class="zd-card-title">Users & Roles</h3>
        <p class="zd-card-text">Create users, edit details, assign admin/regular.</p>
      </a>
    <?php endif; ?>
    <h3 style="margin:0 0 8px 0">Next up</h3>
    <ul style="margin:0 0 8px 18px">
      <li>Wire project access (RBAC) using your <code>person_id</code>.</li>
      <li>Add a basic “My Projects” list here.</li>
      <li>Enable MFA step-up when <code>mfa_policy</code> == <code>required</code>.</li>
    </ul>

  <?php else: ?>
    <p>No active session found. <a href="/auth/login">Sign in</a>.</p>
  <?php endif; ?>
</div>