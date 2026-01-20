<?php
require __DIR__ . '/admin_auth.php';

ini_set('display_errors', 1);
error_reporting(E_ALL);

// Make sure an admin is logged in.
// $admin will contain the row from the "admins" table.
$admin = zg_require_admin();

$pdo   = zg_pdo();
$error = null;

// Helper: delete a file given its public /data/... path
$zgDeleteStorageFile = function (?string $publicPath): void {
    $publicPath = trim((string)$publicPath);
    if ($publicPath === '' || strpos($publicPath, '/data/') !== 0) {
        return;
    }

    // Base storage root: same as uploads
    $storRoot = rtrim(getenv('ZEN_STOR_DIR') ?: '/var/www/html/data', '/');

    // Map /data/... -> $storRoot/...
    $relative = substr($publicPath, strlen('/data/')); // e.g. "zengrabber/grabs/thumb.png"
    $fullPath = $storRoot . '/' . ltrim($relative, '/');

    if (!file_exists($fullPath)) {
        return;
    }

    // Safety: ensure path is really under storage root
    $realStor = realpath($storRoot);
    $realFull = realpath($fullPath);

    if ($realStor !== false && $realFull !== false && strpos($realFull, $realStor) === 0) {
        @unlink($realFull);
    }
};

$movie_id = isset($_GET['movie_id']) ? (int)$_GET['movie_id'] : 0;


// If no movie is selected, show a simple movie picker instead of dying
if ($movie_id <= 0) {
    $stmt = $pdo->query("SELECT id, title, is_active FROM movies ORDER BY is_active DESC, id DESC");

    $movies = $stmt->fetchAll();
?>
    <!DOCTYPE html>
    <html lang="en">

    <head>
        <meta charset="UTF-8">
        <title>Select movie – Zengrabber</title>
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <style>
            body {
                margin: 0;
                min-height: 100vh;
                background: #05060a;
                color: #e4e8ef;
                font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
                display: flex;
                align-items: center;
                justify-content: center;
            }

            .zg-card {
                background: #0d1117;
                border: 1px solid #202634;
                border-radius: 12px;
                padding: 24px 26px;
                width: 100%;
                max-width: 480px;
                box-shadow: 0 18px 45px rgba(0, 0, 0, 0.6);
            }

            h1 {
                margin-top: 0;
                margin-bottom: 14px;
                font-size: 22px;
            }

            p {
                margin-top: 0;
                margin-bottom: 16px;
                font-size: 14px;
                color: #9aa5b1;
            }

            ul {
                list-style: none;
                padding: 0;
                margin: 0;
            }

            li+li {
                margin-top: 6px;
            }

            a {
                display: block;
                padding: 8px 10px;
                border-radius: 6px;
                text-decoration: none;
                color: #e4e8ef;
                background: #05070c;
                border: 1px solid #323a4a;
                font-size: 14px;
            }

            a:hover {
                border-color: #3aa0ff;
                background: #111827;
            }

            .empty {
                font-size: 13px;
                color: #b0b7c6;
            }
        </style>
    </head>

    <body>
        <div class="zg-card">
            <h1>Select movie</h1>
            <p>Choose a movie to manage invites and EDL exports.</p>

            <?php if (!$movies): ?>
                <div class="empty">No movies found in the system yet.</div>
            <?php else: ?>
                <ul>
                    <?php foreach ($movies as $m): ?>
                        <li>
                            <?php
                            $title      = htmlspecialchars($m['title'] ?? ('Movie #' . $m['id']), ENT_QUOTES, 'UTF-8');
                            $isActive   = !empty($m['is_active']);
                            $labelExtra = $isActive ? '' : ' (deactivated)';
                            ?>
                            <a href="admin_invites.php?movie_id=<?php echo (int)$m['id']; ?>">
                                <?php echo $title . $labelExtra; ?>
                            </a>

                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    </body>

    </html>
<?php
    exit;
}

// From here down we know we have a valid movie_id
$stmt = $pdo->prepare("SELECT * FROM movies WHERE id = ?");
$stmt->execute([$movie_id]);
$movie = $stmt->fetch();
if (!$movie) {
    die("Movie not found");
}

// Delete a single invite (and its grabs/EDL files)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_invite') {
    $inviteId = isset($_POST['invite_id']) ? (int)$_POST['invite_id'] : 0;

    if ($inviteId > 0) {
        try {
            // 1) Collect all thumbnail paths for grabs on this invite
            $stmt = $pdo->prepare("SELECT thumbnail_path FROM grabs WHERE invite_id = :invite_id");
            $stmt->execute([':invite_id' => $inviteId]);
            $grabRows = $stmt->fetchAll() ?: [];

            // 2) Collect all EDL file paths for this invite
            $stmt = $pdo->prepare("SELECT file_path FROM edl_exports WHERE invite_id = :invite_id");
            $stmt->execute([':invite_id' => $inviteId]);
            $edlRows = $stmt->fetchAll() ?: [];

            // 3) Delete the invite (FK will cascade deletes to grabs + edl_exports)
            $stmt = $pdo->prepare("DELETE FROM invite_links WHERE id = :id AND movie_id = :movie_id");
            $stmt->execute([
                ':id'       => $inviteId,
                ':movie_id' => $movie_id,
            ]);

            // 4) Physically remove thumbnail + EDL files
            foreach ($grabRows as $g) {
                $zgDeleteStorageFile($g['thumbnail_path'] ?? null);
            }
            foreach ($edlRows as $e) {
                $zgDeleteStorageFile($e['file_path'] ?? null);
            }

            header("Location: admin_invites.php?movie_id=" . $movie_id);
            exit;
        } catch (Throwable $e) {
            $error = 'Failed to delete invite: ' . $e->getMessage();
        }
    } else {
        $error = 'Invalid invite id.';
    }
}

$movieIsActive = !empty($movie['is_active']);



if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_invite') {
    $full_name = trim($_POST['full_name'] ?? '');
    $email     = trim($_POST['email'] ?? '');

    if (!$movieIsActive) {
        $error = "This movie is deactivated. You cannot create new invites.";
    } elseif ($full_name === '' || $email === '') {
        $error = "Please fill in all fields.";
    } else {
        $token = bin2hex(random_bytes(16));

        $stmt = $pdo->prepare("
            INSERT INTO invite_links (movie_id, token, full_name, email, created_by_admin_id)
            VALUES (:movie_id, :token, :full_name, :email, :created_by_admin_id)
        ");
        $stmt->execute([
            ':movie_id'            => $movie_id,
            ':token'               => $token,
            ':full_name'           => $full_name,
            ':email'               => $email,
            ':created_by_admin_id' => (int)$admin['id'],
        ]);

        header("Location: admin_invites.php?movie_id=" . $movie_id);
        exit;
    }
}



$stmt = $pdo->prepare("SELECT il.*, (SELECT COUNT(*) FROM grabs g WHERE g.invite_id = il.id) AS grab_count FROM invite_links il WHERE il.movie_id = ? ORDER BY il.created_at DESC");
$stmt->execute([$movie_id]);
$invites = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Zengrabber · Invites</title>
    <link rel="stylesheet" href="assets/style.css?v=<?= time() ?>">
    <link rel="icon" type="image/png" href="assets/img/zentropa-favicon.png">
</head>

<body class="zg-body">

    <header class="zg-topbar">
        <div class="zg-topbar-left">
            <img src="assets/img/zen_logo.png" alt="Zen" class="zg-logo-img">
            <span class="zg-topbar-title">Zenreview</span>

            <span style="margin: 0 8px; color: var(--zg-text-muted);">/</span>
            <a href="admin_movies.php" style="color: var(--zg-text-muted); text-decoration: none; font-size: 0.9rem; font-weight: 500;">
                ← Back to Movies
            </a>
        </div>

        <?php include __DIR__ . '/admin_topbar_user.php'; ?>
    </header>

    <main class="zg-main">

        <a href="admin_movies.php" class="zg-btn zg-btn-ghost" style="align-self: flex-start;">
            &larr; Back to Movies
        </a>

        <section class="zg-card">
            <?php
            $movieTitle   = htmlspecialchars($movie['title']);
            $statusLabel  = $movieIsActive ? 'Active' : 'Deactivated';
            $statusColor  = $movieIsActive ? '#4ade80' : '#f87171';
            $statusBg     = $movieIsActive ? 'rgba(74,222,128,0.1)' : 'rgba(248,113,113,0.1)';
            ?>
            <div style="margin-bottom:20px;">
                <h1 class="zg-card-title" style="margin-bottom:4px;">Invites: <?= $movieTitle ?></h1>
                <div style="display:flex; align-items:center; gap:10px;">
                    <span class="zg-help">Manage who has access to this movie.</span>
                    <span style="font-size:0.8rem; padding:2px 8px; border-radius:999px; color:<?= $statusColor ?>; background:<?= $statusBg ?>;">
                        <?= $statusLabel ?>
                    </span>
                </div>
            </div>


            <?php if ($error): ?>
                <div class="zg-alert zg-alert-error"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="post" class="zg-form" style="max-width: 100%; display: grid; grid-template-columns: 1fr 1fr auto; align-items: end; <?= $movieIsActive ? '' : 'opacity:0.5; pointer-events:none;' ?>">

                <input type="hidden" name="action" value="create_invite">
                <div class="zg-form-row">
                    <label for="full_name">Full Name</label>
                    <input type="text" id="full_name" name="full_name" required placeholder="John Doe">
                </div>
                <div class="zg-form-row">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" required placeholder="john@example.com">
                </div>
                <div class="zg-form-row">
                    <button class="zg-btn zg-btn-primary" style="height: 42px;">Create Link</button>
                </div>
            </form>
        </section>

        <section class="zg-card">
            <h2 class="zg-card-title">Active Invites</h2>
            <?php if (empty($invites)): ?>
                <p class="zg-empty-state">No invites created yet.</p>
            <?php else: ?>
                <div class="zg-table-wrapper">
                    <table class="zg-table">
                        <thead>
                            <tr>
                                <th>User</th>
                                <th>Status</th>
                                <th>Grabs</th>
                                <th>Last Access</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($invites as $i):
                                $inviteUrl    = "grab.php?t=" . urlencode($i['token']);
                                $inviteActive = !empty($i['is_active']);
                                $statusOpen   = $inviteActive && empty($i['is_finalized']);
                            ?>

                                <tr>
                                    <td>
                                        <div style="font-weight:600;"><?= htmlspecialchars($i['full_name']) ?></div>
                                        <div class="zg-mono" style="font-size:0.75rem; opacity:0.6;"><?= htmlspecialchars($i['email']) ?></div>
                                    </td>
                                    <td>
                                        <?php if (!$inviteActive): ?>
                                            <span style="color:#9ca3af; font-size:0.8rem; background:rgba(148,163,184,0.12); padding:2px 6px; border-radius:4px;">Deactivated</span>
                                        <?php elseif ($statusOpen): ?>
                                            <span style="color:#4ade80; font-size:0.8rem; background:rgba(74,222,128,0.1); padding:2px 6px; border-radius:4px;">Active</span>
                                        <?php else: ?>
                                            <span style="color:#f87171; font-size:0.8rem; background:rgba(248,113,113,0.1); padding:2px 6px; border-radius:4px;">Finalized</span>
                                        <?php endif; ?>
                                    </td>

                                    <td class="zg-mono" style="font-size:1rem;"><?= (int)$i['grab_count'] ?></td>
                                    <td class="zg-mono"><?= htmlspecialchars($i['last_accessed_at'] ?? '-') ?></td>
                                    <td class="zg-actions-cell">
                                        <div class="zg-actions-wrapper">
                                            <button type="button"
                                                class="zg-btn zg-btn-small zg-btn-ghost zg-actions-toggle"
                                                data-invite-id="<?= (int)$i['id'] ?>">
                                                Actions ▾
                                            </button>

                                            <div class="zg-actions-menu" data-actions-menu-for="<?= (int)$i['id'] ?>">

                                                <button type="button"
                                                    class="zg-actions-item-btn"
                                                    onclick="copyInviteLink(this, '<?= $inviteUrl ?>')">
                                                    Copy Link
                                                </button>

                                                <a href="<?= $inviteUrl ?>"
                                                    target="_blank"
                                                    class="zg-actions-item"
                                                    title="Open Player Link">
                                                    Open link ↗
                                                </a>

                                                <a href="admin_export_edl.php?invite_id=<?= (int)$i['id'] ?>"
                                                    class="zg-actions-item"
                                                    title="Export still EDL">
                                                    Export still EDL
                                                </a>

                                                <button
                                                    type="button"
                                                    class="zg-actions-item-btn js-avid-export"
                                                    data-invite-id="<?= (int)$i['id'] ?>"
                                                    title="Export Avid marker EDL">
                                                    Export Avid marker EDL
                                                </button>


                                                <button
                                                    type="button"
                                                    class="zg-actions-item-btn js-resolve-export"
                                                    data-invite-id="<?= (int)$i['id'] ?>"
                                                    title="Export Resolve marker EDL">
                                                    Export Resolve marker EDL
                                                </button>


                                                <a href="export_grabs_pdf.php?t=<?= urlencode($i['token']) ?>"
                                                    target="_blank"
                                                    class="zg-actions-item"
                                                    title="Download PDF of grabs">
                                                    Export PDF
                                                </a>


                                                <?php if (!$statusOpen): ?>
                                                    <form method="post"
                                                        action="admin_reopen_invite.php"
                                                        class="zg-actions-item-form">
                                                        <input type="hidden" name="invite_id" value="<?= (int)$i['id'] ?>">
                                                        <input type="hidden" name="movie_id" value="<?= (int)$movie_id ?>">
                                                        <button type="submit" class="zg-actions-item-btn" title="Unlock list">
                                                            Reopen invite
                                                        </button>
                                                    </form>
                                                <?php endif; ?>

                                                <form method="post"
                                                    class="zg-actions-item-form"
                                                    onsubmit="return confirm('Delete this invite and all its grabs & EDL exports? This cannot be undone.');">
                                                    <input type="hidden" name="action" value="delete_invite">
                                                    <input type="hidden" name="invite_id" value="<?= (int)$i['id'] ?>">
                                                    <button type="submit" class="zg-actions-item-btn zg-actions-item-danger">
                                                        Delete invite
                                                    </button>
                                                </form>
                                            </div>
                                        </div>
                                    </td>



                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </main>

    <!-- Resolve Marker Color Modal -->
    <div class="zg-modal" id="zgResolveColorModal" aria-hidden="true">
        <div class="zg-modal-backdrop" data-close></div>

        <div class="zg-modal-panel" role="dialog" aria-modal="true" aria-labelledby="zgResolveColorTitle">
            <div class="zg-modal-header">
                <div>
                    <div class="zg-modal-title" id="zgResolveColorTitle">Resolve marker color</div>
                    <div class="zg-help">Pick one color for all exported markers.</div>
                </div>
                <button type="button" class="zg-btn zg-btn-ghost zg-btn-small" data-close>✕</button>
            </div>

            <div class="zg-modal-body">
                <div class="zg-color-grid" id="zgResolveColorGrid"></div>
                <input type="hidden" id="zgResolveColorValue" value="ResolveColorBlue">
                <input type="hidden" id="zgResolveInviteId" value="">
            </div>

            <div class="zg-modal-footer">
                <button type="button" class="zg-btn zg-btn-ghost" data-close>Cancel</button>
                <button type="button" class="zg-btn zg-btn-primary" id="zgResolveColorExportBtn">Export</button>
            </div>
        </div>
    </div>

    <!-- Avid Marker Color Modal -->
    <div class="zg-modal" id="zgAvidColorModal" aria-hidden="true">
        <div class="zg-modal-backdrop" data-close></div>

        <div class="zg-modal-panel" role="dialog" aria-modal="true" aria-labelledby="zgAvidColorTitle">
            <div class="zg-modal-header">
                <div>
                    <div class="zg-modal-title" id="zgAvidColorTitle">Avid marker color</div>
                    <div class="zg-help">Pick one color for all exported markers.</div>
                </div>
                <button type="button" class="zg-btn zg-btn-ghost zg-btn-small" data-close>✕</button>
            </div>

            <div class="zg-modal-body">
                <div class="zg-color-grid" id="zgAvidColorGrid"></div>
                <input type="hidden" id="zgAvidColorValue" value="red">
                <input type="hidden" id="zgAvidInviteId" value="">
            </div>

            <div class="zg-modal-footer">
                <button type="button" class="zg-btn zg-btn-ghost" data-close>Cancel</button>
                <button type="button" class="zg-btn zg-btn-primary" id="zgAvidColorExportBtn">Export</button>
            </div>
        </div>
    </div>

    <script>
        function copyInviteLink(btn, relativeUrl) {
            // Create the absolute URL based on the current page's location
            const absoluteUrl = new URL(relativeUrl, window.location.href).href;

            if (navigator.clipboard) {
                navigator.clipboard.writeText(absoluteUrl).then(() => {
                    // Visual feedback
                    const originalText = btn.textContent;
                    btn.textContent = 'Copied!';
                    btn.style.color = '#4ade80'; // Optional: Green text

                    setTimeout(() => {
                        btn.textContent = originalText;
                        btn.style.color = '';
                    }, 2000);
                }).catch(err => {
                    console.error('Failed to copy: ', err);
                    prompt('Press Ctrl+C to copy link:', absoluteUrl);
                });
            } else {
                // Fallback for older browsers
                prompt('Press Ctrl+C to copy link:', absoluteUrl);
            }
        }

        (function() {
            const toggles = document.querySelectorAll('.zg-actions-toggle');
            let openMenu = null;

            function closeMenu() {
                if (!openMenu) return;
                openMenu.classList.remove('zg-actions-menu--open', 'zg-actions-menu--up');
                openMenu = null;
            }

            function openForToggle(btn) {
                const inviteId = btn.getAttribute('data-invite-id');
                if (!inviteId) return;

                const menu = document.querySelector('.zg-actions-menu[data-actions-menu-for="' + inviteId + '"]');
                if (!menu) return;

                // If another menu is open, close it
                if (openMenu && openMenu !== menu) {
                    openMenu.classList.remove('zg-actions-menu--open', 'zg-actions-menu--up');
                }

                const isOpen = menu.classList.contains('zg-actions-menu--open');
                if (isOpen) {
                    // Close if already open
                    menu.classList.remove('zg-actions-menu--open', 'zg-actions-menu--up');
                    openMenu = null;
                    return;
                }

                // Open current menu
                menu.classList.add('zg-actions-menu--open');
                menu.classList.remove('zg-actions-menu--up');

                // After opening, check if we're too close to bottom
                const rect = menu.getBoundingClientRect();
                const viewportH = window.innerHeight || document.documentElement.clientHeight;

                // If the menu bottom is near or past the viewport bottom, open upwards instead
                if (rect.bottom > viewportH - 8) {
                    menu.classList.add('zg-actions-menu--up');
                }

                openMenu = menu;
            }

            // Toggle handlers
            toggles.forEach(function(btn) {
                btn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    openForToggle(btn);
                });
            });

            // Close on outside click
            document.addEventListener('click', function(e) {
                if (!openMenu) return;
                const wrapper = openMenu.closest('.zg-actions-wrapper');
                if (wrapper && wrapper.contains(e.target)) {
                    // Click was inside the wrapper (button or menu), ignore
                    return;
                }
                closeMenu();
            });

            // Close on resize (layout changes)
            window.addEventListener('resize', function() {
                closeMenu();
            });

            // Optional: re-check orientation on scroll
            window.addEventListener('scroll', function() {
                if (!openMenu) return;
                const rect = openMenu.getBoundingClientRect();
                const viewportH = window.innerHeight || document.documentElement.clientHeight;

                if (rect.bottom > viewportH - 8 && !openMenu.classList.contains('zg-actions-menu--up')) {
                    openMenu.classList.add('zg-actions-menu--up');
                } else if (rect.bottom < viewportH - 80 && openMenu.classList.contains('zg-actions-menu--up')) {
                    // If we've scrolled enough that there's plenty of room below again, prefer dropdown
                    openMenu.classList.remove('zg-actions-menu--up');
                }
            }, {
                passive: true
            });
        })();

        // Resolve marker color modal
        (function() {
            const modal = document.getElementById('zgResolveColorModal');
            if (!modal) return;

            const grid = document.getElementById('zgResolveColorGrid');
            const colorValue = document.getElementById('zgResolveColorValue');
            const inviteValue = document.getElementById('zgResolveInviteId');
            const exportBtn = document.getElementById('zgResolveColorExportBtn');

            // NOTE: Keep this list in the same order as your Resolve colors
            const COLORS = [{
                    key: 'ResolveColorBlue',
                    label: 'Blue',
                    swatch: '#007fe3'
                },
                {
                    key: 'ResolveColorCyan',
                    label: 'Cyan',
                    swatch: '#00cdcf'
                },
                {
                    key: 'ResolveColorGreen',
                    label: 'Green',
                    swatch: '#00ac00'
                },
                {
                    key: 'ResolveColorYellow',
                    label: 'Yellow',
                    swatch: '#f09d00'
                },
                {
                    key: 'ResolveColorRed',
                    label: 'Red',
                    swatch: '#e02401'
                },
                {
                    key: 'ResolveColorPink',
                    label: 'Pink',
                    swatch: '#fe44c7'
                },
                {
                    key: 'ResolveColorPurple',
                    label: 'Purple',
                    swatch: '#8f13fd'
                },
                {
                    key: 'ResolveColorFuchsia',
                    label: 'Fuchsia',
                    swatch: '#c02e6f'
                },
                {
                    key: 'ResolveColorRose',
                    label: 'Rose',
                    swatch: '#fea0b8'
                },
                {
                    key: 'ResolveColorLavender',
                    label: 'Lavender',
                    swatch: '#a193c8'
                },
                {
                    key: 'ResolveColorSky',
                    label: 'Sky',
                    swatch: '#92e2fd'
                },
                {
                    key: 'ResolveColorMint',
                    label: 'Mint',
                    swatch: '#72db00'
                },
                {
                    key: 'ResolveColorLemon',
                    label: 'Lemon',
                    swatch: '#dce95a'
                },
                {
                    key: 'ResolveColorSand',
                    label: 'Sand',
                    swatch: '#c4915e'
                },
                {
                    key: 'ResolveColorCocoa',
                    label: 'Cocoa',
                    swatch: '#6e5143'
                },
                {
                    key: 'ResolveColorCream',
                    label: 'Cream',
                    swatch: '#f5ebe1'
                },
            ];



            function openModal(inviteId) {
                inviteValue.value = inviteId;
                modal.classList.add('zg-modal--open');
                modal.setAttribute('aria-hidden', 'false');
            }

            function closeModal() {
                modal.classList.remove('zg-modal--open');
                modal.setAttribute('aria-hidden', 'true');
            }

            function renderGrid() {
                grid.innerHTML = '';
                const selected = colorValue.value || 'ResolveColorBlue';

                COLORS.forEach(c => {
                    const tile = document.createElement('div');
                    tile.className = 'zg-color-tile' + (c.key === selected ? ' zg-color-tile--selected' : '');
                    tile.setAttribute('data-color', c.key);

                    const sw = document.createElement('div');
                    sw.className = 'zg-swatch';
                    sw.style.background = c.swatch;

                    const txt = document.createElement('div');
                    txt.innerHTML = `<div class="zg-color-name">${c.label}</div>`;


                    tile.appendChild(sw);
                    tile.appendChild(txt);

                    tile.addEventListener('click', () => {
                        colorValue.value = c.key;
                        renderGrid();
                    });

                    grid.appendChild(tile);
                });
            }

            renderGrid();

            // Open modal from Actions menu button
            document.addEventListener('click', function(e) {
                const btn = e.target.closest('.js-resolve-export');
                if (!btn) return;

                e.preventDefault();
                e.stopPropagation();

                // Close actions dropdown if open (your existing code uses "openMenu")
                // If that variable isn't in scope here, it's fine — the modal still works.

                const inviteId = btn.getAttribute('data-invite-id');
                if (!inviteId) return;

                // Default selection
                colorValue.value = 'ResolveColorBlue';
                renderGrid();

                openModal(inviteId);
            });

            // Close handlers
            modal.addEventListener('click', function(e) {
                if (e.target && e.target.matches('[data-close]')) closeModal();
            });

            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && modal.classList.contains('zg-modal--open')) closeModal();
            });

            // Export
            exportBtn.addEventListener('click', function() {
                const inviteId = inviteValue.value;
                const color = colorValue.value || 'ResolveColorBlue';
                if (!inviteId) return;

                const url = new URL('admin_export_marker_edl_resolve.php', window.location.href);
                url.searchParams.set('invite_id', inviteId);
                url.searchParams.set('color', color);

                window.location.href = url.toString();
                closeModal();
            });
        })();

        // Avid marker color modal
        (function() {
            const modal = document.getElementById('zgAvidColorModal');
            if (!modal) return;

            const grid = document.getElementById('zgAvidColorGrid');
            const colorValue = document.getElementById('zgAvidColorValue');
            const inviteValue = document.getElementById('zgAvidInviteId');
            const exportBtn = document.getElementById('zgAvidColorExportBtn');

            // Keys MUST match Avid color names from your sample (lowercase)
            const COLORS = [{
                    key: 'red',
                    label: 'Red',
                    swatch: '#e02401'
                },
                {
                    key: 'green',
                    label: 'Green',
                    swatch: '#00ac00'
                },
                {
                    key: 'blue',
                    label: 'Blue',
                    swatch: '#007fe3'
                },
                {
                    key: 'cyan',
                    label: 'Cyan',
                    swatch: '#00cdcf'
                },
                {
                    key: 'magenta',
                    label: 'Magenta',
                    swatch: '#fe44c7'
                },
                {
                    key: 'yellow',
                    label: 'Yellow',
                    swatch: '#f09d00'
                },
                {
                    key: 'black',
                    label: 'Black',
                    swatch: '#111111'
                },
                {
                    key: 'white',
                    label: 'White',
                    swatch: '#f5f5f5'
                },
            ];

            function openModal(inviteId) {
                inviteValue.value = inviteId;
                modal.classList.add('zg-modal--open');
                modal.setAttribute('aria-hidden', 'false');
            }

            function closeModal() {
                modal.classList.remove('zg-modal--open');
                modal.setAttribute('aria-hidden', 'true');
            }

            function renderGrid() {
                grid.innerHTML = '';
                const selected = colorValue.value || 'red';

                COLORS.forEach(c => {
                    const tile = document.createElement('div');
                    tile.className = 'zg-color-tile' + (c.key === selected ? ' zg-color-tile--selected' : '');
                    tile.setAttribute('data-color', c.key);

                    const sw = document.createElement('div');
                    sw.className = 'zg-swatch';
                    sw.style.background = c.swatch;

                    const txt = document.createElement('div');
                    txt.innerHTML = `<div class="zg-color-name">${c.label}</div>`;

                    tile.appendChild(sw);
                    tile.appendChild(txt);

                    tile.addEventListener('click', () => {
                        colorValue.value = c.key;
                        renderGrid();
                    });

                    grid.appendChild(tile);
                });
            }

            renderGrid();

            document.addEventListener('click', function(e) {
                const btn = e.target.closest('.js-avid-export');
                if (!btn) return;

                e.preventDefault();
                e.stopPropagation();

                const inviteId = btn.getAttribute('data-invite-id');
                if (!inviteId) return;

                colorValue.value = 'red';
                renderGrid();
                openModal(inviteId);
            });

            modal.addEventListener('click', function(e) {
                if (e.target && e.target.matches('[data-close]')) closeModal();
            });

            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && modal.classList.contains('zg-modal--open')) closeModal();
            });

            exportBtn.addEventListener('click', function() {
                const inviteId = inviteValue.value;
                const color = colorValue.value || 'red';
                if (!inviteId) return;

                const url = new URL('admin_export_marker_edl_avid.php', window.location.href);
                url.searchParams.set('invite_id', inviteId);
                url.searchParams.set('color', color);

                window.location.href = url.toString();
                closeModal();
            });
        })();
    </script>



</body>

</html>