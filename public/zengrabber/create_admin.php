<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require __DIR__ . '/admin_auth.php';

// Require that an admin is logged in
$admin = zg_require_admin();

$pdo     = zg_pdo();
$error   = null;
$notice  = null;

// For edit mode
$editAdmin = null;
$editId = isset($_GET['edit_id']) ? (int)$_GET['edit_id'] : 0;
if ($editId > 0) {
    $stmt = $pdo->prepare("SELECT * FROM admins WHERE id = ? LIMIT 1");
    $stmt->execute([$editId]);
    $editAdmin = $stmt->fetch() ?: null;
}

// ---------- POST actions ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // CREATE / UPDATE
    if ($action === 'save_admin') {
        $adminId  = isset($_POST['admin_id']) ? (int)$_POST['admin_id'] : 0;
        $fullName = trim($_POST['full_name'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($fullName === '' || $email === '') {
            $error = 'Full name and email are required.';
        } elseif ($adminId === 0 && $password === '') {
            $error = 'Password is required when creating a new admin.';
        } else {
            try {
                if ($adminId > 0) {
                    // UPDATE existing
                    if ($password !== '') {
                        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                        $sql = "
                            UPDATE admins
                            SET full_name = ?, email = ?, password_hash = ?
                            WHERE id = ?
                            LIMIT 1
                        ";
                        $params = [$fullName, $email, $passwordHash, $adminId];
                    } else {
                        // Keep existing password
                        $sql = "
                            UPDATE admins
                            SET full_name = ?, email = ?
                            WHERE id = ?
                            LIMIT 1
                        ";
                        $params = [$fullName, $email, $adminId];
                    }
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute($params);
                    $notice = 'Admin updated.';
                } else {
                    // CREATE new
                    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("
                        INSERT INTO admins (full_name, email, password_hash, is_active)
                        VALUES (?, ?, ?, 1)
                    ");
                    $stmt->execute([$fullName, $email, $passwordHash]);
                    $notice = 'Admin created.';
                }

                // After success, redirect to avoid resubmits
                header('Location: create_admin.php');
                exit;
            } catch (Throwable $e) {
                $error = 'Database error: ' . $e->getMessage();
            }
        }
    }

    // ACTIVATE / DEACTIVATE
    if ($action === 'toggle_active') {
        $targetId = isset($_POST['admin_id']) ? (int)$_POST['admin_id'] : 0;
        $newState = isset($_POST['new_state']) ? (int)$_POST['new_state'] : 0;

        if ($targetId === (int)$admin['id']) {
            $error = 'You cannot change your own active state.';
        } elseif ($targetId > 0) {
            $stmt = $pdo->prepare("UPDATE admins SET is_active = ? WHERE id = ? LIMIT 1");
            $stmt->execute([$newState, $targetId]);
            header('Location: create_admin.php');
            exit;
        }
    }

    // DELETE ADMIN + INVITES + GRABS + THUMBS
    if ($action === 'delete_admin') {
        $targetId = isset($_POST['admin_id']) ? (int)$_POST['admin_id'] : 0;

        if ($targetId === (int)$admin['id']) {
            $error = 'You cannot delete your own admin account.';
        } elseif ($targetId > 0) {
            try {
                $pdo->beginTransaction();

                // 1) Find invites created by this admin
                $stmt = $pdo->prepare("
                    SELECT id, token
                    FROM invite_links
                    WHERE created_by_admin_id = ?
                ");
                $stmt->execute([$targetId]);
                $invites = $stmt->fetchAll() ?: [];

                // Base storage root for thumbs
                $storRoot = rtrim(getenv('ZEN_STOR_DIR') ?: '/var/www/html/data', '/');

                foreach ($invites as $inv) {
                    $inviteId = (int)$inv['id'];
                    $token    = $inv['token'];

                    // 2) Get grabs for this invite (to remove thumbs)
                    $gStmt = $pdo->prepare("
                        SELECT id, thumbnail_path
                        FROM grabs
                        WHERE invite_id = ?
                    ");
                    $gStmt->execute([$inviteId]);
                    $grabs = $gStmt->fetchAll() ?: [];

                    foreach ($grabs as $g) {
                        $thumbPath = $g['thumbnail_path'] ?? '';

                        if ($thumbPath && strpos($thumbPath, '/data/') === 0) {
                            // Map /data/... to filesystem path
                            $relative = substr($thumbPath, strlen('/data')); // e.g. /zengrabber/grabs/...
                            $fsPath   = $storRoot . $relative;

                            if (is_file($fsPath)) {
                                @unlink($fsPath);
                            }
                        }
                    }

                    // Attempt to remove the token folder (best-effort, ignore errors)
                    if (!empty($token)) {
                        $tokenDirFs = $storRoot . '/zengrabber/grabs/' . $token;
                        if (is_dir($tokenDirFs)) {
                            @rmdir($tokenDirFs); // will only work if empty
                        }
                    }

                    // 3) Delete grabs rows
                    $delGrabs = $pdo->prepare("DELETE FROM grabs WHERE invite_id = ?");
                    $delGrabs->execute([$inviteId]);

                    // 4) Delete invite row
                    $delInv = $pdo->prepare("DELETE FROM invite_links WHERE id = ?");
                    $delInv->execute([$inviteId]);
                }

                // 5) Finally delete admin
                $delAdmin = $pdo->prepare("DELETE FROM admins WHERE id = ? LIMIT 1");
                $delAdmin->execute([$targetId]);

                $pdo->commit();

                header('Location: create_admin.php');
                exit;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = 'Error deleting admin: ' . $e->getMessage();
            }
        }
    }
}

// ---------- Fetch admins for list & form defaults ----------
$admins = $pdo->query("
    SELECT id, full_name, email, is_active, created_at
    FROM admins
    ORDER BY full_name ASC
")->fetchAll() ?: [];

// Pre-fill form values (for edit)
$formAdminId = $editAdmin['id'] ?? 0;
$formName    = $editAdmin['full_name'] ?? '';
$formEmail   = $editAdmin['email'] ?? '';

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Zengrabber · Manage Admins</title>
    <link rel="stylesheet" href="assets/style.css?v=<?= time() ?>">

    <style>
        .zg-admin-form-wrapper {
            max-width: 520px;
            margin-bottom: 24px;
        }

        .zg-form-row label {
            display: block;
            margin-bottom: 4px;
            font-size: 0.9rem;
        }

        .zg-form-row input[type="text"],
        .zg-form-row input[type="email"],
        .zg-form-row input[type="password"] {
            width: 100%;
            padding: 8px 10px;
            border-radius: 6px;
            border: 1px solid #323a4a;
            background: #05070c;
            color: #e4e8ef;
            font-size: 0.95rem;
            box-sizing: border-box;
        }

        .zg-form-row input:focus {
            outline: none;
            border-color: #3aa0ff;
            box-shadow: 0 0 0 1px rgba(58, 160, 255, 0.4);
        }

        .zg-form-actions {
            margin-top: 16px;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }

        .zg-help-text {
            font-size: 0.8rem;
            color: var(--zg-text-muted, #9aa5b1);
            margin-top: 4px;
        }

        .zg-chip {
            display: inline-flex;
            align-items: center;
            padding: 2px 8px;
            border-radius: 999px;
            font-size: 0.75rem;
        }

        .zg-chip-active {
            background: rgba(74, 222, 128, 0.12);
            color: #4ade80;
        }

        .zg-chip-inactive {
            background: rgba(248, 113, 113, 0.12);
            color: #f87171;
        }

        .zg-table td.actions {
            text-align: right;
            white-space: nowrap;
        }

        .zg-btn-danger {
            background: #d62828;
            color: #fff;
        }

        .zg-btn-danger:hover {
            background: #e64545;
        }
    </style>
</head>

<body class="zg-body">

    <header class="zg-topbar">
        <div class="zg-topbar-left">
            <img src="assets/img/zen_logo.png" alt="Zen" class="zg-logo-img">
            <span class="zg-topbar-title">Zengrabber</span>
            <span class="zg-topbar-subtitle">Admin · Manage Admins</span>
        </div>

        <?php include __DIR__ . '/admin_topbar_user.php'; ?>
    </header>

    <main class="zg-main">

        <section class="zg-card zg-admin-form-wrapper">
            <h1 class="zg-card-title" style="margin-bottom: 6px;">
                <?= $formAdminId ? 'Edit Admin' : 'Create Admin' ?>
            </h1>
            <p class="zg-help" style="margin-top:0; margin-bottom: 16px;">
                <?= $formAdminId ? 'Update admin details below.' : 'Create a new admin account.' ?>
            </p>

            <?php if ($error): ?>
                <div class="zg-alert zg-alert-error">
                    <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
                </div>
            <?php endif; ?>

            <?php if ($notice): ?>
                <div class="zg-alert zg-alert-success">
                    <?= htmlspecialchars($notice, ENT_QUOTES, 'UTF-8'); ?>
                </div>
            <?php endif; ?>

            <form method="post">
                <input type="hidden" name="action" value="save_admin">
                <input type="hidden" name="admin_id" value="<?= (int)$formAdminId ?>">

                <div class="zg-form-row">
                    <label for="full_name">Full name</label>
                    <input
                        type="text"
                        id="full_name"
                        name="full_name"
                        value="<?= htmlspecialchars($formName, ENT_QUOTES, 'UTF-8'); ?>"
                        required>
                </div>

                <div class="zg-form-row">
                    <label for="email">Email</label>
                    <input
                        type="email"
                        id="email"
                        name="email"
                        value="<?= htmlspecialchars($formEmail, ENT_QUOTES, 'UTF-8'); ?>"
                        required>
                </div>

                <div class="zg-form-row">
                    <label for="password">
                        Password <?= $formAdminId ? '(leave empty to keep current)' : '' ?>
                    </label>
                    <input
                        type="password"
                        id="password"
                        name="password">
                    <?php if (!$formAdminId): ?>
                        <div class="zg-help-text">
                            A password is required when creating a new admin.
                        </div>
                    <?php endif; ?>
                </div>

                <div class="zg-form-actions">
                    <?php if ($formAdminId): ?>
                        <a href="create_admin.php" class="zg-btn zg-btn-ghost">Cancel edit</a>
                    <?php else: ?>
                        <a href="admin_movies.php" class="zg-btn zg-btn-ghost">Back to Movies</a>
                    <?php endif; ?>
                    <button type="submit" class="zg-btn zg-btn-primary">
                        <?= $formAdminId ? 'Save Changes' : 'Create Admin' ?>
                    </button>
                </div>
            </form>
        </section>

        <section class="zg-card">
            <h2 class="zg-card-title">All Admins</h2>

            <?php if (empty($admins)): ?>
                <p class="zg-empty-state">No admins found.</p>
            <?php else: ?>
                <div class="zg-table-wrapper">
                    <table class="zg-table">
                        <thead>
                            <tr>
                                <th style="width:60px;">ID</th>
                                <th>Name</th>
                                <th>Email</th>
                                <th>Status</th>
                                <th>Created</th>
                                <th style="text-align:right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($admins as $a): ?>
                                <tr>
                                    <td class="zg-mono">#<?= (int)$a['id'] ?></td>
                                    <td><?= htmlspecialchars($a['full_name'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td class="zg-mono"><?= htmlspecialchars($a['email'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td>
                                        <?php if (!empty($a['is_active'])): ?>
                                            <span class="zg-chip zg-chip-active">Active</span>
                                        <?php else: ?>
                                            <span class="zg-chip zg-chip-inactive">Inactive</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="zg-mono">
                                        <?= htmlspecialchars(substr((string)$a['created_at'], 0, 16), ENT_QUOTES, 'UTF-8') ?>
                                    </td>
                                    <td class="actions">
                                        <!-- Edit -->
                                        <a href="create_admin.php?edit_id=<?= (int)$a['id'] ?>" class="zg-btn zg-btn-small zg-btn-ghost">
                                            Edit
                                        </a>

                                        <!-- Toggle active -->
                                        <?php if ((int)$a['id'] !== (int)$admin['id']): ?>
                                            <form method="post" style="display:inline;">
                                                <input type="hidden" name="action" value="toggle_active">
                                                <input type="hidden" name="admin_id" value="<?= (int)$a['id'] ?>">
                                                <input type="hidden" name="new_state" value="<?= !empty($a['is_active']) ? 0 : 1 ?>">
                                                <button type="submit" class="zg-btn zg-btn-small zg-btn-ghost">
                                                    <?= !empty($a['is_active']) ? 'Deactivate' : 'Activate' ?>
                                                </button>
                                            </form>
                                        <?php endif; ?>

                                        <!-- Delete -->
                                        <?php if ((int)$a['id'] !== (int)$admin['id']): ?>
                                            <form method="post" style="display:inline;" onsubmit="return confirm('Delete this admin and all invites & grabs they created? This cannot be undone.');">
                                                <input type="hidden" name="action" value="delete_admin">
                                                <input type="hidden" name="admin_id" value="<?= (int)$a['id'] ?>">
                                                <button type="submit" class="zg-btn zg-btn-small zg-btn-danger">
                                                    Delete
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </main>

</body>

</html>