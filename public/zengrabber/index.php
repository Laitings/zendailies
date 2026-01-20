<?php
// index.php

require __DIR__ . '/admin_auth.php';

// If already logged in, you can redirect to invites (or any admin landing page)
$admin = zg_current_admin();
if ($admin) {
    header('Location: admin_movies.php');
    exit;
}

// Otherwise go to the login page
header('Location: admin_login.php');
exit;
