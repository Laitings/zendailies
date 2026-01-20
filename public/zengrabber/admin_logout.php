<?php
require __DIR__ . '/admin_auth.php';

zg_logout_admin();

header('Location: admin_login.php');
exit;
