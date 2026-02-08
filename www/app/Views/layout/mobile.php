<!DOCTYPE html>
<html lang="en">

<?php
// mobile.php (at the top)

$first = $_SESSION['person']['first_name'] ?? '';
$last  = $_SESSION['person']['last_name']  ?? '';
$full  = $_SESSION['person']['display_name'] ?? $_SESSION['user']['display_name'] ?? 'User';

if (!empty($first) && !empty($last)) {
    // We have both explicit names
    $initials = mb_substr($first, 0, 1) . mb_substr($last, 0, 1);
} else {
    // Fallback: Split the display name by spaces (e.g. "Mads Oppermann" -> "MO")
    $words = explode(' ', trim($full));
    $initials = mb_substr($words[0], 0, 1);
    if (count($words) > 1) {
        $initials .= mb_substr($words[count($words) - 1], 0, 1);
    }
}

$initials = mb_strtoupper($initials);
?>

<head>
    <meta charset="utf-8">
    <title><?= htmlspecialchars($title ?? 'Zentropa Dailies', ENT_QUOTES, 'UTF-8') ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=0, viewport-fit=cover">
    <link rel="icon" type="image/png" href="/assets/img/zentropa-favicon.png">

    <link rel="stylesheet" href="/assets/css/main.css?v=<?= time() ?>">

    <style>
        /* Reset Zentropa Desktop constraints for Mobile */
        html,
        body {
            margin: 0;
            padding: 0;
            background: #0b0c10;
            /* Match your site's dark vibe */
            color: #e5e7eb;
            font-family: 'Inter', sans-serif;
            overflow-x: hidden;
            width: 100%;
        }

        /* Ensure the main content area takes full width */
        .mobile-layout-wrapper {
            display: flex;
            flex-direction: column;
            min-height: 100vh;
        }

        /* Minimalistic Mobile Header */
        .mobile-header {
            display: grid;
            grid-template-columns: 80px 1fr 80px;
            /* Logo area, Title area, Avatar area */
            align-items: center;
            padding: 0 16px;
            background: #18202b;
            border-bottom: 1px solid #2a3342;
            height: 54px;
            box-sizing: border-box;
        }

        .m-header-center {
            text-align: center;
            font-size: 13px;
            font-weight: 700;
            color: #fff;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .m-header-right {
            display: flex;
            justify-content: flex-end;
            align-items: center;
            /* This forces the avatar to the vertical center */
            height: 100%;
        }

        .m-header-left {
            display: flex;
            align-items: center;
            height: 100%;
            /* Ensures it fills the 54px header height */
        }

        .mobile-logo {
            display: block;
            /* This kills the invisible "text" gap at the bottom */
            height: 24px;
            width: auto;
        }

        .m-user-dropdown {
            display: flex;
            align-items: center;
            height: 100%;
        }

        .zd-user-avatar {
            display: flex;
            /* Changed to flex for centering text */
            align-items: center;
            /* Vertical center for initials */
            justify-content: center;
            /* Horizontal center for initials */
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: #1e293b;
            /* Dark slate background */
            border: 1px solid #3b82f6;
            /* Bright blue circle - EASY TO SEE */
            font-size: 11px;
            font-weight: 700;
            color: #ffffff;
            cursor: pointer;
            box-shadow: 0 0 8px rgba(59, 130, 246, 0.3);
            /* Subtle blue glow */
        }
    </style>

    <?php if (isset($sections['head'])) echo $sections['head']; ?>
</head>

<body>
    <div class="mobile-layout-wrapper">
        <header class="mobile-header">
            <div class="m-header-left">
                <a href="/dashboard">
                    <img src="/assets/img/zen_logo.png" alt="Zentropa" class="mobile-logo">
                </a>
            </div>

            <div class="m-header-center">
                <?= htmlspecialchars($project['title'] ?? '') ?>
            </div>

            <div class="m-header-right">
                <div class="m-user-dropdown" style="position: relative;">
                    <button type="button" id="mUserBtn" style="background:none; border:none; padding:0;">
                        <div class="zd-user-avatar" style="width:30px; height:30px; font-size:12px; cursor:pointer;">
                            <?= htmlspecialchars($initials) ?>
                        </div>
                    </button>

                    <div id="mUserMenu" class="m-user-menu" style="display:none; position:absolute; right:0; top:40px; background:#18202b; border:1px solid #2a3342; border-radius:8px; width:140px; z-index:1000; box-shadow: 0 4px 12px rgba(0,0,0,0.5);">
                        <a href="/account/mobile" style="display:block; padding:12px; color:#e5e7eb; text-decoration:none; font-size:14px;">Profile</a>
                        <hr style="border:0; border-top:1px solid #2a3342; margin:0;">
                        <a href="/auth/logout" style="display:block; padding:12px; color:#ef4444; text-decoration:none; font-size:14px;">Log out</a>
                    </div>
                </div>
            </div>
        </header>

        <main>
            <?php if (isset($sections['content'])) echo $sections['content']; ?>
        </main>
    </div>

    <?php if (isset($sections['scripts'])) echo $sections['scripts']; ?>
</body>

<script>
    (function() {
        const btn = document.getElementById('mUserBtn');
        const menu = document.getElementById('mUserMenu');
        if (!btn || !menu) return;

        const toggle = (show) => {
            const isNowOpen = show ?? (menu.style.display !== 'block');
            menu.style.display = isNowOpen ? 'block' : 'none';
        };

        btn.addEventListener('click', (e) => {
            e.stopPropagation();
            toggle();
        });

        // Close if clicking anywhere else
        document.addEventListener('click', () => toggle(false));

        // Prevent menu clicks from closing the menu immediately
        menu.addEventListener('click', (e) => e.stopPropagation());
    })();
</script>

</html>