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
$firstChar = $loggedInName !== null ? mb_substr($loggedInName, 0, 1) : 'U';
$firstChar = function_exists('mb_strtoupper') ? mb_strtoupper($firstChar) : strtoupper($firstChar);

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
</head>

<body class="<?= ($ctx ? 'has-subbar' : '') ?>">

    <header class="zd-topbar">
        <div class="zd-topbar-left">
            <a class="zd-brand" href="/dashboard" title="Zentropa Dailies">
                <img src="/assets/img/zen_logo.png" alt="" class="zd-logo-topbar">
                <span class="zd-brand-text">Zentropa Dailies</span>
            </a>
            <?php
            // --- Topbar links ---
            if ($isSuperuser): ?>
                <a href="/admin/projects" class="zd-toplink <?= str_starts_with($reqUri, '/admin/projects') ? 'active' : '' ?>">Projects</a>
            <?php endif; ?>

            <?php if ($isSuperuser || $isProjectAdmin): ?>
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

                        <span style="display:inline-flex;align-items:center;justify-content:center;width:24px;height:24px;border-radius:999px;background:#18202b;border:1px solid var(--border);font-size:12px">
                            <?= htmlspecialchars($firstChar, ENT_QUOTES, 'UTF-8') ?>
                        </span>
                        <span>Logged in as <?= htmlspecialchars($loggedInName, ENT_QUOTES, 'UTF-8') ?></span>
                        <svg width="14" height="14" viewBox="0 0 24 24" aria-hidden="true">
                            <path fill="currentColor" d="M7 10l5 5 5-5z" />
                        </svg>
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

                <!-- DAYS -->
                <a href="/admin/projects/<?= htmlspecialchars($pid) ?>/days"
                    class="<?= str_starts_with($reqUri, "/admin/projects/$pid/days") ? 'active' : '' ?>">
                    Days
                </a>

                <!-- CLIPS -->
                <?php
                $clipsHref   = "/admin/projects/$pid/clips";
                $clipsActive = str_starts_with($reqUri, "/admin/projects/$pid/clips");
                ?>
                <a href="<?= htmlspecialchars($clipsHref) ?>"
                    class="zd-sub-link <?= $clipsActive ? 'is-active' : '' ?>">
                    Clips
                </a>



                <!-- PLAYER -->
                <a href="/admin/projects/<?= htmlspecialchars($pid) ?>/player?pane=days"
                    class="<?= str_starts_with($reqUri, "/admin/projects/$pid/player") ? 'active' : '' ?>">
                    Player
                </a>

                <!-- MEMBERS -->
                <?php if ($isSuperuser || $isProjectAdmin): ?>
                    <a href="/admin/projects/<?= htmlspecialchars($pid) ?>/members"
                        class="<?= str_starts_with($reqUri, "/admin/projects/$pid/members") ? 'active' : '' ?>">
                        Members
                    </a>
                <?php endif; ?>

                <!-- LEAVE PROJECT -->
                <?php $showLeave = $isSuperuser || (int)($_SESSION['project_access_count'] ?? 0) > 1;
                if ($showLeave): ?>
                    <form method="post" action="/projects/leave" class="zd-subnav-leave">
                        <button class="btn-link" type="submit" title="Leave project">Leave</button>
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

    <footer class="zd-footer">
        Developed by OnionBag and The Oppermann © 2025
    </footer>

    <!-- Render late so modals/overlays don't interfere with layout -->
    <?php __zd_render_section('scripts', $__sections); ?>
</body>

</html>