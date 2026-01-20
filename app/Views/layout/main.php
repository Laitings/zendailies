<?php

/** app/Views/layout/main.php
 * Canonical layout with sections: head, precontent, content, sidebar, scripts
 * Expects:
 *   - $title (string, optional)
 *   - $sections (assoc array: ['head'=>..., 'precontent'=>..., 'content'=>..., 'sidebar'=>..., 'scripts'=>...])
 */


if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Helper to render a section from $sections only (no external render_section()).
if (!function_exists('__zd_render_section')) {
    function __zd_render_section(string $name, ?array $sections = null): void
    {
        if (is_array($sections) && array_key_exists($name, $sections)) {
            echo $sections[$name];
        }
    }
}

$__sections = $sections ?? [];
$__hasSidebar = isset($__sections['sidebar']) && trim(strip_tags($__sections['sidebar'])) !== '';

// Auth display (adjust to your session shape if needed)
$accountEmail = $_SESSION['account']['email'] ?? $_SESSION['user']['email'] ?? null;
$displayName  = $_SESSION['person']['display_name']
    ?? $_SESSION['user']['display_name']
    ?? (
        (!empty($_SESSION['person']['first_name']) && !empty($_SESSION['person']['last_name']))
        ? $_SESSION['person']['first_name'] . ' ' . $_SESSION['person']['last_name']
        : null
    );
$loggedInName = $displayName ?: ($accountEmail ?: 'User');
$isSuperuser  = !empty($_SESSION['account']['is_superuser']);
$isLoggedIn   = !empty($accountEmail);

// Safe first-letter avatar
// Get initials from first and last name
$fName = $_SESSION['account']['first_name'] ?? $_SESSION['person']['first_name'] ?? '';
$lName = $_SESSION['account']['last_name'] ?? $_SESSION['person']['last_name'] ?? '';

if (!empty($fName) && !empty($lName)) {
    $initials = mb_substr($fName, 0, 1) . mb_substr($lName, 0, 1);
} else {
    // Fallback if names are missing
    $initials = mb_substr($loggedInName ?? 'U', 0, 1);
}

$initials = mb_strtoupper($initials);

// Current path for active link
$reqUri = $_SERVER['REQUEST_URI'] ?? '/';

// --- MOVED: Compute current project context and flags BEFORE <body> tag ---
$ctx    = $_SESSION['current_project'] ?? null;
$flags  = $_SESSION['current_project_flags'] ?? [];
$puuid  = $ctx['uuid']  ?? null;
$ptitle = $ctx['title'] ?? '';
$isProjectAdmin = !empty($flags['is_project_admin']);
$personUuid     = $_SESSION['person_uuid'] ?? null;

// Fallback: recompute project-admin once if not cached but we know project/person
if (!$isSuperuser && !$isProjectAdmin && $puuid && $personUuid) {
    try {
        $repo = new \App\Repositories\ProjectRepository(\App\Support\DB::pdo());
        $isProjectAdmin = $repo->isProjectAdmin($personUuid, $puuid);
        $_SESSION['current_project_flags']['is_project_admin'] = (bool)$isProjectAdmin;
    } catch (\Throwable $e) {
        // ignore
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="preload" as="font" type="font/woff2" href="/assets/fonts/Inter-Variable.woff2" crossorigin>
    <title><?= htmlspecialchars($title ?? 'Zentropa Dailies', ENT_QUOTES, 'UTF-8') ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/png" href="/assets/img/zentropa-favicon.png">
    <link rel="stylesheet" href="/assets/css/main.css">


    <?php __zd_render_section('head', $__sections); ?>

    <style>
        /* === OPTICAL ALIGNMENT & BRANDING === */
        .zd-brand {
            display: flex !important;
            align-items: center;
            text-decoration: none;
            line-height: 1;
            height: 100%;
            gap: 12px;
        }

        .zd-logo-topbar {
            display: block;
            height: 35px !important;
            width: auto;
            margin: 0;
        }

        .zd-brand-text {
            font-family: 'Inter', sans-serif;
            font-weight: 700;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: #9ca3af;
            display: block;
            line-height: 1;
            margin: 0;
            position: relative;
            top: -1px;
        }

        .zd-topbar-left {
            display: flex;
            align-items: center;
            height: 100%;
        }

        .zd-toplink {
            font-family: 'Inter', sans-serif;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            font-weight: 600;
            color: #9ca3af;
            text-decoration: none;
            margin-left: 2rem;
            transition: color 0.2s ease;
            -webkit-font-smoothing: antialiased;
        }

        .zd-toplink:hover {
            color: #ffffff;
        }

        .zd-toplink.active {
            color: #60a5fa;
        }

        .zd-sub-link {
            font-family: 'Inter', sans-serif;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            font-weight: 600;
            color: #9ca3af;
            text-decoration: none;
            padding: 0.5rem 0;
            margin-right: 1.5rem;
            transition: color 0.2s ease;
        }

        .zd-sub-link:hover {
            color: #e5e7eb;
        }

        .zd-sub-link.active,
        .zd-sub-link.is-active {
            color: #60a5fa;
        }

        button.zd-sub-link {
            background: transparent;
            border: none;
            cursor: pointer;
            padding: 0.5rem 0;
            font-family: 'Inter', sans-serif;
            font-size: 14px;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            font-weight: 600;
            color: #9ca3af;
            vertical-align: baseline;
        }

        .zd-proj-title {
            font-family: 'Inter', sans-serif;
            font-weight: 700;
            font-size: 13px;
            text-transform: uppercase !important;
            letter-spacing: 0.06em;
            color: #d6d6d6 !important;
            line-height: 1;
        }

        /* === GLOBAL USER SECTION (Always Active) === */
        .zd-user-btn {
            display: flex;
            align-items: center;
            gap: 10px;
            background: transparent;
            border: none;
            cursor: pointer;
            padding: 4px 8px;
            color: #9ca3af;
            transition: color 0.2s ease;
        }

        .zd-user-btn:hover {
            color: #ffffff;
        }

        .zd-user-avatar {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            background: #18202b;
            border: 1px solid rgba(255, 255, 255, 0.1);
            font-size: 12px;
            font-weight: 700;
            color: #60a5fa;
            flex-shrink: 0;
        }

        .zd-user-label {
            font-size: 13px;
            font-weight: 500;
        }

        .zd-user-name {
            font-weight: 700;
            color: #e5e7eb;
        }

        .zd-user-arrow {
            display: flex;
            align-items: center;
            opacity: 0.6;
        }

        /* === RESPONSIVE COLLAPSE === */
        @media (max-width: 900px) {
            .hide-on-narrow {
                display: none !important;
            }

            .zd-user-btn {
                gap: 4px;
            }

            .zd-topbar {
                padding: 0 10px;
            }

            .zd-brand {
                gap: 0;
            }

            .zd-proj-title {
                font-size: 11px;
                max-width: 150px;
                overflow: hidden;
                text-overflow: ellipsis;
                white-space: nowrap;
            }
        }

        /* --- MOBILE PLAYER LAYOUT --- */
        @media (max-width: 900px) {

            body.is-mobile-player .zd-topbar,
            body.is-mobile-player .zd-subbar,
            body.is-mobile-player .zd-footer {
                display: none !important;
            }

            body.is-mobile-player .zd-shell,
            body.is-mobile-player .zd-main,
            body.is-mobile-player .zd-container {
                padding: 0 !important;
                margin: 0 !important;
                max-width: none !important;
            }
        }

        /* --- ADD THIS TO FORCE THE ROW TO COOPERATE --- */
        .zd-users-table tbody tr {
            position: relative;
            /* Allows z-index to work on children */
        }

        /* --- ZD Pro Actions Dropdown --- */
        .zd-pro-menu {
            position: relative;
            display: inline-block;
            margin-right: 12px;
        }

        .zd-pro-trigger {
            background: var(--zd-bg-input);
            border: 1px solid var(--zd-border-subtle);
            color: var(--zd-text-muted);
            padding: 4px 8px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            display: flex;
            align-items: center;
            gap: 5px;
        }

        .zd-pro-content {
            display: none;
            position: absolute;
            right: 0;
            top: 100%;
            margin-top: 5px;
            background: #1c1e26;
            border: 1px solid var(--zd-border-subtle);
            border-radius: 6px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.6);
            z-index: 99999;
            min-width: 160px;
            padding: 4px 0;
        }

        .zd-pro-menu.drop-up .zd-pro-content {
            top: auto;
            bottom: 100%;
            margin-bottom: 5px;
        }

        .zd-pro-item {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 8px 16px;
            color: var(--zd-text-main);
            text-decoration: none;
            font-size: 13px;
            border: none;
            background: transparent;
            width: 100%;
            box-sizing: border-box;
            text-align: left;
            cursor: pointer;
        }

        .zd-pro-item:hover {
            background: var(--zd-accent);
            color: #fff;
        }

        .zd-pro-icon {
            width: 14px;
            height: 14px;
            filter: brightness(0) invert(1);
            opacity: 0.8;
        }

        .zd-pro-content.is-active {
            display: block;
        }

        /* --- ICON VISIBILITY FIX --- */
        .zd-action-icon {
            width: 14px;
            height: 14px;
            display: inline-block;
            /* This makes dark icons visible on your dark theme */
            filter: brightness(0) invert(1);
            opacity: 0.8;
        }

        .zd-action-item:hover .zd-action-icon {
            opacity: 1;
        }

        .zd-actions-content.is-active {
            display: block;
            animation: zdFadeIn 0.15s ease-out;
        }

        @keyframes zdFadeIn {
            from {
                opacity: 0;
                transform: translateY(-5px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
    </style>

</head>

<?php
// Compute body class based on project context and mobile player status
$isPlayerPage = str_contains($reqUri, '/player/');
$bodyClass = $ctx ? 'has-subbar' : '';

if ($isMobile && $isPlayerPage) {
    $bodyClass .= ' is-mobile-player';
}
?>

<body class="<?= trim($bodyClass) ?>">

    <?php
    // * Sessions info debug. Remove slash to display current session info
    // echo '<pre style="color:white; background:black;">';
    // print_r($_SESSION);
    // echo '</pre>';
    ?>

    <header class="zd-topbar">
        <div class="zd-topbar-left">
            <a class="zd-brand" href="/dashboard" title="Zentropa Dailies">
                <img src="/assets/img/zen_logo.png" alt="" class="zd-logo-topbar">
                <span class="zd-brand-text hide-on-narrow">Zentropa Dailies</span>
            </a>
            <?php
            // --- Topbar links ---
            if ($isSuperuser): ?>
                <a href="/admin/projects" class="zd-toplink <?= (str_starts_with($reqUri, '/admin/projects') && empty($ctx)) ? 'active' : '' ?>">Projects</a>
            <?php endif; ?>

            <?php
            // Check session account directly to be safe
            $userRole = $_SESSION['account']['user_role'] ?? 'regular';
            $isGlobalAdmin = ($userRole === 'admin' || !empty($_SESSION['account']['is_superuser']));

            if ($isGlobalAdmin):
            ?>
                <a href="/admin/users" class="zd-toplink <?= str_starts_with($reqUri, '/admin/users') ? 'active' : '' ?>">Users</a>
            <?php endif; ?>

        </div>

        <div class="zd-topbar-center">
            <?php if ($ctx): ?>
                <div class="zd-proj-title" title="<?= htmlspecialchars($puuid) ?>">
                    <?= htmlspecialchars($ptitle) ?>
                </div>
            <?php endif; ?>
        </div> <!-- CLOSE .zd-topbar-center -->

        <div class="zd-topbar-right">
            <?php if ($isLoggedIn): ?>
                <div class="zd-user">
                    <button class="zd-user-btn" id="zdUserBtn" type="button" aria-haspopup="menu" aria-expanded="false" aria-controls="zdUserMenu">

                        <span class="zd-user-avatar">
                            <?= htmlspecialchars($initials, ENT_QUOTES, 'UTF-8') ?>
                        </span>

                        <span class="zd-user-label hide-on-narrow">
                            Logged in as <span class="zd-user-name"><?= htmlspecialchars($loggedInName, ENT_QUOTES, 'UTF-8') ?></span>
                        </span>

                        <span class="zd-user-arrow">
                            <svg width="14" height="14" viewBox="0 0 24 24" aria-hidden="true">
                                <path fill="currentColor" d="M7 10l5 5 5-5z" />
                            </svg>
                        </span>

                    </button>
                    <div class="zd-user-menu" id="zdUserMenu" role="menu" aria-labelledby="zdUserBtn">
                        <a href="/account" role="menuitem">Profile / Settings</a>
                        <a href="/account/security" role="menuitem">Security</a>
                        <hr style="border:0;border-top:1px solid var(--border);margin:6px 0">
                        <a href="/auth/logout" role=" menuitem">Log out</a>
                    </div>
                </div>
            <?php else: ?>
                <a class="zd-user-btn" href="/login">Log in</a>
            <?php endif; ?>
        </div>
    </header>

    <?php
    static $subbarRendered = false;
    if ($ctx && !$subbarRendered):
        $subbarRendered = true;
    ?>
        <div class="zd-subbar" role="navigation" aria-label="Project links">
            <nav class="zd-subnav">

                <?php
                // Resolve project UUID for subnav links
                $pid = $puuid
                    ?? ($project['project_uuid'] ?? ($project_uuid ?? null));

                // Resolve day if available (clips/index.php pages set $day_uuid)
                $dayId = $day_uuid ?? null;
                ?>

                <a href="/admin/projects/<?= htmlspecialchars($pid) ?>/days"
                    class="zd-sub-link <?= str_starts_with($reqUri, "/admin/projects/$pid/days") ? 'active' : '' ?>">
                    Days
                </a>

                <?php
                $clipsHref   = "/admin/projects/$pid/clips";
                $clipsActive = str_starts_with($reqUri, "/admin/projects/$pid/clips");
                ?>
                <a href="<?= htmlspecialchars($clipsHref) ?>"
                    class="zd-sub-link <?= $clipsActive ? 'is-active' : '' ?>">
                    Clips
                </a>

                <a href="/admin/projects/<?= htmlspecialchars($pid) ?>/player?pane=days"
                    class="zd-sub-link <?= str_starts_with($reqUri, "/admin/projects/$pid/player") ? 'active' : '' ?>">
                    Player
                </a>

                <?php if ($isSuperuser || $isProjectAdmin): ?>
                    <a href="/admin/projects/<?= htmlspecialchars($pid) ?>/members"
                        class="zd-sub-link <?= str_starts_with($reqUri, "/admin/projects/$pid/members") ? 'active' : '' ?>">
                        Members
                    </a>
                <?php endif; ?>

                <?php $showLeave = $isSuperuser || (int)($_SESSION['project_access_count'] ?? 0) > 1;
                if ($showLeave): ?>
                    <form method="post" action="/projects/leave" class="zd-subnav-leave">
                        <button class="zd-sub-link" type="submit" title="Leave project">Leave</button>
                    </form>
                <?php endif; ?>

            </nav>

        </div>
    <?php endif; ?>

    <?php if (!empty($__sections['precontent'])): ?>
        <div class="zd-pre">
            <?php __zd_render_section('precontent', $__sections); ?>
        </div>
    <?php endif; ?>




    <div class="zd-shell">
        <main class="zd-main" role="main">
            <div class="zd-container">
                <?php __zd_render_section('content', $__sections); ?>
            </div>
        </main>

    </div>

    <script>
        (function() {
            const btn = document.getElementById('zdUserBtn');
            const menu = document.getElementById('zdUserMenu');
            if (!btn || !menu) return;
            const toggle = (open) => {
                const isOpen = open ?? menu.style.display !== 'block';
                menu.style.display = isOpen ? 'block' : 'none';
                btn.setAttribute('aria-expanded', String(isOpen));
            };
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                toggle();
            });
            menu.addEventListener('click', (e) => e.stopPropagation());
            document.addEventListener('click', () => {
                if (menu.style.display === 'block') toggle(false);
            });
            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape') toggle(false);
            });
        })();
    </script>

    <script>
        /* --- REPLACE THE DROPDOWN LISTENER WITH THIS --- */
        document.addEventListener('click', function(e) {
            const trigger = e.target.closest('.zd-pro-trigger'); // Watching for zd-pro-
            const allMenus = document.querySelectorAll('.zd-pro-content');
            const allContainers = document.querySelectorAll('.zd-pro-menu');

            if (trigger) {
                e.preventDefault();
                const container = trigger.closest('.zd-pro-menu');
                const currentMenu = trigger.nextElementSibling;

                // Smart logic for drop-up vs drop-down
                const rect = trigger.getBoundingClientRect();
                if (window.innerHeight - rect.bottom < 150) {
                    container.classList.add('drop-up');
                } else {
                    container.classList.remove('drop-up');
                }

                allMenus.forEach((menu, index) => {
                    if (menu !== currentMenu) {
                        menu.classList.remove('is-active');
                        allContainers[index].classList.remove('drop-up');
                    }
                });
                currentMenu.classList.toggle('is-active');
            } else {
                if (!e.target.closest('.zd-pro-item')) {
                    allMenus.forEach(menu => menu.classList.remove('is-active'));
                    allContainers.forEach(container => container.classList.remove('drop-up'));
                }
            }
        });
    </script>

    <footer class="zd-footer">
        Developed by OnionBag and The Oppermann © 2025
    </footer>

    <!-- Render late so modals/overlays don't interfere with layout -->
    <?php __zd_render_section('scripts', $__sections); ?>


</body>

</html>