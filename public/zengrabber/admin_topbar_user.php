<?php
require_once __DIR__ . '/admin_auth.php';

$admin = zg_current_admin();
?>
<div class="zg-topbar-user">
    <div class="zg-topbar-user-trigger">
        <?= htmlspecialchars($admin['full_name'] ?? 'Unknown', ENT_QUOTES, 'UTF-8') ?>
        <span class="zg-topbar-caret">▾</span>
    </div>

    <div class="zg-topbar-user-menu">
        <a href="create_admin.php">Manage Admins</a>
        <a href="admin_logout.php" class="danger">Log out</a>
    </div>
</div>

<style>
    .zg-topbar-user {
        position: relative;
        margin-left: 18px;
        font-size: 14px;
        user-select: none;
    }

    .zg-topbar-user-trigger {
        padding: 6px 10px;
        background: #0b0c10;
        border: 1px solid #1f2430;
        border-radius: 6px;
        cursor: pointer;
        display: flex;
        align-items: center;
        gap: 6px;
        color: #e9eef3;
    }

    .zg-topbar-user-trigger:hover {
        border-color: #3aa0ff;
    }

    .zg-topbar-caret {
        opacity: 0.7;
        font-size: 10px;
    }

    .zg-topbar-user-menu {
        display: none;
        position: absolute;
        right: 0;
        top: calc(100% + 6px);
        background: #0d1117;
        border: 1px solid #202634;
        border-radius: 6px;
        box-shadow: 0 12px 28px rgba(0, 0, 0, 0.45);
        min-width: 160px;
        padding: 6px 0;
        z-index: 9999;
    }

    .zg-topbar-user:hover .zg-topbar-user-menu {
        display: block;
    }

    .zg-topbar-user-menu a {
        display: block;
        padding: 8px 14px;
        font-size: 14px;
        color: #e9eef3;
        text-decoration: none;
    }

    .zg-topbar-user-menu a:hover {
        background: #111318;
    }

    .zg-topbar-user-menu a.danger {
        color: #d62828;
    }
</style>