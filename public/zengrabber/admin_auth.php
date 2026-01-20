<?php
// admin_auth.php

// Make sure config is only loaded once
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Return the currently logged in admin as an array, or null.
 */
function zg_current_admin(): ?array
{
    static $cached = false;
    static $admin  = null;

    if ($cached) {
        return $admin;
    }

    $cached = true;

    if (empty($_SESSION['admin_id'])) {
        return null;
    }

    $pdo = zg_pdo();

    $stmt = $pdo->prepare("
        SELECT *
        FROM admins
        WHERE id = ? AND is_active = 1
        LIMIT 1
    ");
    $stmt->execute([(int) $_SESSION['admin_id']]);
    $row = $stmt->fetch();

    if (!$row) {
        unset($_SESSION['admin_id'], $_SESSION['admin_email']);
        $admin = null;
        return null;
    }

    $admin = $row;
    $_SESSION['admin_email'] = $row['email'] ?? null;

    return $admin;
}

/**
 * Require that an admin is logged in.
 * Redirects to admin_login.php if not.
 *
 * @return array the admin row
 */
function zg_require_admin(): array
{
    $admin = zg_current_admin();

    if (!$admin) {
        $redirect = $_SERVER['REQUEST_URI'] ?? '';
        $location = 'admin_login.php';

        if ($redirect !== '') {
            $location .= '?redirect=' . urlencode($redirect);
        }

        header('Location: ' . $location);
        exit;
    }

    return $admin;
}

/**
 * Log out the current admin.
 */
function zg_logout_admin(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    unset($_SESSION['admin_id'], $_SESSION['admin_email']);
    // session_destroy(); // only if you want full destroy
}
