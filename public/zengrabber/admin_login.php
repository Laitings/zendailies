<?php
require __DIR__ . '/admin_auth.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$pdo      = zg_pdo();
$error    = null;
$email    = '';
$redirect = $_GET['redirect'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $redirect = $_POST['redirect'] ?? '';

    if ($email === '' || $password === '') {
        $error = 'Please enter both email and password.';
    } else {
        $stmt = $pdo->prepare("
            SELECT *
            FROM admins
            WHERE email = ? AND is_active = 1
            LIMIT 1
        ");
        $stmt->execute([$email]);
        $admin = $stmt->fetch();

        if ($admin && password_verify($password, $admin['password_hash'])) {
            $_SESSION['admin_id']    = (int) $admin['id'];
            $_SESSION['admin_email'] = $admin['email'];

            // Redirect back to where the user was heading, if any
            if ($redirect !== '') {
                header('Location: ' . $redirect);
                exit;
            }

            // Default landing page after login
            header('Location: admin_movies.php');
            exit;


            // TODO: if you have a main admin dashboard, change this target
            header('Location: admin_invites.php');
            exit;
        } else {
            $error = 'Invalid email or password.';
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Zengrabber – Admin login</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/png" href="assets/img/zentropa-favicon.png">

    <style>
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background: #05060a;
            color: #e4e8ef;
        }

        .zg-login-card {
            background: #0d1117;
            border: 1px solid #202634;
            border-radius: 10px;
            padding: 24px 28px 26px;
            width: 100%;
            max-width: 360px;
            box-shadow: 0 18px 45px rgba(0, 0, 0, 0.6);
        }

        .zg-login-card h1 {
            font-size: 20px;
            margin: 0 0 6px;
        }

        .zg-login-card p {
            margin: 0 0 18px;
            font-size: 13px;
            color: #9aa5b1;
        }

        .zg-field {
            margin-bottom: 14px;
        }

        .zg-field label {
            display: block;
            font-size: 13px;
            margin-bottom: 4px;
        }

        .zg-field input[type="email"],
        .zg-field input[type="password"] {
            width: 100%;
            padding: 8px 9px;
            border-radius: 6px;
            border: 1px solid #323a4a;
            background: #05070c;
            color: #e4e8ef;
            font-size: 14px;
            box-sizing: border-box;
        }

        .zg-field input[type="email"]:focus,
        .zg-field input[type="password"]:focus {
            outline: none;
            border-color: #3aa0ff;
            box-shadow: 0 0 0 1px rgba(58, 160, 255, 0.4);
        }

        .zg-error {
            background: rgba(214, 40, 40, 0.1);
            border: 1px solid #d62828;
            color: #ffc9c9;
            padding: 8px 10px;
            font-size: 13px;
            border-radius: 6px;
            margin-bottom: 14px;
        }

        .zg-actions {
            margin-top: 10px;
            display: flex;
            justify-content: flex-end;
        }

        .zg-btn {
            border: none;
            border-radius: 6px;
            padding: 7px 14px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            background: #3aa0ff;
            color: #020308;
            transition: background 0.15s ease, transform 0.08s ease;
        }

        .zg-btn:hover {
            background: #60b4ff;
        }

        .zg-btn:active {
            transform: translateY(1px);
            background: #1f7fd6;
        }

        .zg-login-card small {
            display: block;
            margin-top: 12px;
            font-size: 11px;
            color: #6c7685;
        }
    </style>
</head>

<body>

    <div class="zg-login-card">
        <div style="text-align:center; margin-bottom:0px;">
            <img src="assets/img/zen_logo.png"
                alt="Zentropa Logo"
                style="width:80px; opacity:0.9;">
        </div>
        <h1 style="text-align:center; margin:0 0 12px;">Zengrabber login</h1>

        <p style="margin:0 0 18px;">Sign in to manage movies, invites and EDL exports.</p>


        <?php if ($error): ?>
            <div class="zg-error">
                <?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>

        <form method="post" action="admin_login.php">
            <div class="zg-field">
                <label for="email">Email</label>
                <input
                    type="email"
                    id="email"
                    name="email"
                    required
                    autocomplete="email"
                    value="<?php echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?>">
            </div>

            <div class="zg-field">
                <label for="password">Password</label>
                <input
                    type="password"
                    id="password"
                    name="password"
                    required
                    autocomplete="current-password">
            </div>

            <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($redirect, ENT_QUOTES, 'UTF-8'); ?>">

            <div class="zg-actions">
                <button type="submit" class="zg-btn">Log ind</button>
            </div>


        </form>
    </div>

</body>

</html>