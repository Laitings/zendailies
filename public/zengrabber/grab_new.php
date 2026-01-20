<?php
// zengrabber/grab.php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require __DIR__ . '/config.php';

$pdo   = zg_pdo();
$token = $_GET['t'] ?? '';

if ($token === '') {
    http_response_code(400);
    echo "Missing token.";
    exit;
}

// 1. Validate Token & Fetch Movie Info
$sql = "
    SELECT
        il.*,
        m.id          AS movie_id,
        m.title        AS movie_title,
        m.reel_name   AS movie_reel,
        m.fps_num,
        m.fps_den,
        m.start_tc,
        m.proxy_path,
        a.full_name   AS admin_name
    FROM invite_links il
    JOIN movies m ON m.id = il.movie_id
    LEFT JOIN admins a ON a.id = il.created_by_admin_id
    WHERE il.token = :token
      AND m.is_active = 1
    LIMIT 1
";

$stmt = $pdo->prepare($sql);
$stmt->execute([':token' => $token]);
$invite = $stmt->fetch();

if (!$invite) {
    http_response_code(404);
    echo "Invalid or expired link.";
    exit;
}

// SERVER-SIDE CHECK: Prevent access if finalized or deactivated.
$isFinalized = !empty($invite['is_finalized']);
$isActive    = !empty($invite['is_active']);

if ($isFinalized || !$isActive) {
    if ($isFinalized) {
        $pageTitle = 'List Finalized';
        $heading   = 'Thank You!';
        $message   = 'Your grab list has been successfully finalized.<br>No further changes can be made.';
    } else {
        $pageTitle = 'Link Inactive';
        $heading   = 'Link Inactive';
        $message   = 'This invite link is no longer active.';
    }
    http_response_code(200);
    $pdfLink = 'export_grabs_pdf.php?t=' . urlencode($token);
?>
    <!DOCTYPE html>
    <html lang="en">

    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?= $pageTitle ?></title>
        <link href="https://fonts.googleapis.com/css2?family=Rajdhani:wght@500;600;700&display=swap" rel="stylesheet">
        <style>
            body {
                font-family: 'Rajdhani', sans-serif;
                background: radial-gradient(circle at center, #1b203a 0%, #05070b 100%);
                color: #e5ecf5;
                display: flex;
                flex-direction: column;
                justify-content: center;
                align-items: center;
                height: 100vh;
                margin: 0;
                text-align: center;
            }

            .zg-card {
                background: rgba(16, 19, 26, 0.9);
                border: 1px solid #bc13fe;
                box-shadow: 0 0 30px rgba(188, 19, 254, 0.2);
                border-radius: 16px;
                padding: 40px;
                max-width: 480px;
                width: 90%;
            }

            h1 {
                color: #fff;
                text-shadow: 0 0 10px #bc13fe;
                margin-bottom: 20px;
                text-transform: uppercase;
            }

            .zg-btn {
                display: inline-flex;
                background: linear-gradient(90deg, #bc13fe, #7a0cd2);
                color: #fff;
                text-decoration: none;
                padding: 12px 30px;
                border-radius: 50px;
                font-weight: 700;
                text-transform: uppercase;
                letter-spacing: 1px;
                box-shadow: 0 0 15px rgba(188, 19, 254, 0.4);
            }
        </style>
    </head>

    <body>
        <div class="zg-card">
            <h1><?= $heading ?></h1>
            <p><?= $message ?></p>
            <a href="<?= htmlspecialchars($pdfLink) ?>" target="_blank" class="zg-btn">Download PDF Report</a>
        </div>
    </body>

    </html>
<?php
    exit;
}

// 2. Update Access Time
$pdo->prepare("UPDATE invite_links SET last_accessed_at = NOW() WHERE id = ?")->execute([$invite['id']]);

// 3. Setup Variables
$movieTitle = $invite['movie_title'];
$fullName   = $invite['full_name'];
$fpsNum     = (int)$invite['fps_num'];
$fpsDen     = (int)$invite['fps_den'];
$startTc    = $invite['start_tc'];
$proxyPath  = $invite['proxy_path'];
$invitedByAdmin = $invite['admin_name'] ?? null;

if ($fpsNum <= 0 || $fpsDen <= 0) {
    $fpsNum = 25;
    $fpsDen = 1;
}

// 4. Fetch Existing Grabs
$grabStmt = $pdo->prepare("SELECT id, frame_number, timecode, thumbnail_path, note FROM grabs WHERE movie_id = :movie_id AND invite_id = :invite_id ORDER BY frame_number DESC");
$grabStmt->execute([':movie_id' => $invite['movie_id'], ':invite_id' => $invite['id']]);
$existingGrabs = $grabStmt->fetchAll() ?: [];

$initialGrabs = array_map(function ($g) {
    return [
        'id' => (int)$g['id'],
        'frame_number' => (int)$g['frame_number'],
        'timecode' => $g['timecode'],
        'thumbnail_url' => $g['thumbnail_path'],
        'note' => $g['note'] ?? '',
    ];
}, $existingGrabs);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Frame Catcher · <?= htmlspecialchars($movieTitle) ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Rajdhani:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --neon-blue: #00f3ff;
            --neon-purple: #bc13fe;
            --bg-dark: #05070b;
            --panel-bg: rgba(13, 17, 26, 0.85);
            --border-color: #2e354f;
            --text-main: #e5ecf5;
        }

        body {
            font-family: 'Rajdhani', sans-serif;
            background-color: var(--bg-dark);
            background-image: radial-gradient(circle at 50% 30%, #1a2035 0%, #000000 90%);
            color: var(--text-main);
            margin: 0;
            height: 100vh;
            overflow: hidden;
            display: flex;
            flex-direction: column;
        }

        /* --- Header --- */
        .zg-topbar {
            text-align: center;
            padding: 15px 0;
            flex-shrink: 0;
        }

        .neon-title {
            font-size: 2.2rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 4px;
            color: #fff;
            text-shadow:
                0 0 5px var(--neon-purple),
                0 0 10px var(--neon-purple),
                0 0 20px var(--neon-purple);
            margin: 0;
            position: relative;
            display: inline-block;
        }

        .neon-title::after {
            content: '';
            display: block;
            width: 60%;
            height: 2px;
            background: var(--neon-blue);
            margin: 5px auto 0;
            box-shadow: 0 0 8px var(--neon-blue);
        }

        /* --- Main Layout --- */
        .zg-main-full {
            flex: 1;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
            overflow: hidden;
        }

        .frame-catcher-container {
            display: flex;
            width: 100%;
            max-width: 1400px;
            height: 85vh;
            background: rgba(10, 10, 10, 0.6);
            border: 1px solid var(--border-color);
            border-top: 1px solid rgba(0, 243, 255, 0.3);
            border-radius: 20px;
            box-shadow: 0 0 40px rgba(0, 0, 0, 0.8), inset 0 0 20px rgba(0, 243, 255, 0.05);
            backdrop-filter: blur(10px);
            overflow: hidden;
        }

        /* --- Left Side: Player --- */
        .zg-pane-player {
            flex: 3;
            display: flex;
            flex-direction: column;
            padding: 30px;
            border-right: 1px solid var(--border-color);
            position: relative;
        }

        /* Video Container with Blue Glow */
        .zg-player-shell {
            position: relative;
            width: 100%;
            border-radius: 12px;
            overflow: hidden;
            border: 2px solid var(--neon-blue);
            box-shadow: 0 0 25px rgba(0, 243, 255, 0.2);
            background: #000;
            margin-bottom: 20px;
            flex-grow: 1;
            display: flex;
            align-items: center;
            background: #000;
        }

        .zg-video {
            width: 100%;
            height: 100%;
            object-fit: contain;
            display: block;
        }

        /* Controls Area */
        .zg-controls-area {
            background: rgba(20, 25, 40, 0.8);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 16px;
            padding: 15px 25px;
            box-shadow: inset 0 0 15px rgba(0, 0, 0, 0.5);
        }

        /* Timeline Slider */
        .zg-timeline-wrapper {
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .zg-seek {
            -webkit-appearance: none;
            width: 100%;
            height: 6px;
            background: #222;
            border-radius: 3px;
            outline: none;
            cursor: pointer;
            box-shadow: inset 0 1px 3px rgba(0, 0, 0, 0.8);
        }

        .zg-seek::-webkit-slider-thumb {
            -webkit-appearance: none;
            width: 16px;
            height: 16px;
            border-radius: 50%;
            background: var(--neon-blue);
            cursor: pointer;
            box-shadow: 0 0 10px var(--neon-blue);
        }

        .zg-tc-display {
            font-family: monospace;
            font-size: 1.1rem;
            color: var(--neon-blue);
            text-shadow: 0 0 5px rgba(0, 243, 255, 0.5);
            min-width: 100px;
        }

        /* Buttons Row */
        .zg-btn-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .zg-transport {
            display: flex;
            gap: 10px;
        }

        .zg-icon-btn {
            background: transparent;
            border: 1px solid rgba(255, 255, 255, 0.2);
            color: #aaa;
            border-radius: 6px;
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.2s;
        }

        .zg-icon-btn:hover:not(:disabled) {
            color: #fff;
            border-color: #fff;
            background: rgba(255, 255, 255, 0.1);
        }

        .zg-icon-btn:disabled {
            opacity: 0.3;
            cursor: default;
        }

        /* The Big Grab Button */
        .zg-grab-main-btn {
            background: linear-gradient(135deg, rgba(0, 243, 255, 0.2), rgba(188, 19, 254, 0.2));
            border: 2px solid var(--neon-blue);
            color: #fff;
            font-family: 'Rajdhani', sans-serif;
            font-weight: 700;
            font-size: 1.1rem;
            text-transform: uppercase;
            padding: 10px 40px;
            border-radius: 30px;
            cursor: pointer;
            box-shadow: 0 0 15px rgba(0, 243, 255, 0.3), inset 0 0 10px rgba(0, 243, 255, 0.1);
            transition: 0.2s;
            letter-spacing: 1px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .zg-grab-main-btn:hover:not(:disabled) {
            background: linear-gradient(135deg, var(--neon-blue), var(--neon-purple));
            box-shadow: 0 0 25px rgba(0, 243, 255, 0.6);
            border-color: #fff;
            text-shadow: 0 1px 2px rgba(0, 0, 0, 0.5);
        }

        /* --- Right Side: Grabs --- */
        .zg-pane-list {
            flex: 1.2;
            min-width: 320px;
            display: flex;
            flex-direction: column;
            background: rgba(0, 0, 0, 0.2);
            padding: 20px;
        }

        .zg-pane-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }

        .zg-header-title {
            color: var(--neon-blue);
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            font-size: 1.1rem;
        }

        .zg-grab-scroll-area {
            flex: 1;
            overflow-y: auto;
            padding-right: 5px;
        }

        /* Scrollbar styling */
        .zg-grab-scroll-area::-webkit-scrollbar {
            width: 6px;
        }

        .zg-grab-scroll-area::-webkit-scrollbar-thumb {
            background: #333;
            border-radius: 3px;
        }

        .zg-grab-scroll-area::-webkit-scrollbar-thumb:hover {
            background: var(--neon-purple);
        }

        /* Grid for grabs */
        .zg-grab-grid {
            list-style: none;
            padding: 0;
            margin: 0;
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            /* 2 Columns like the image */
            gap: 15px;
        }

        .zg-grab-card {
            background: rgba(255, 255, 255, 0.05);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 8px;
            overflow: hidden;
            transition: 0.2s;
            position: relative;
        }

        .zg-grab-card:hover {
            border-color: var(--neon-purple);
            box-shadow: 0 0 10px rgba(188, 19, 254, 0.3);
        }

        .zg-grab-media {
            position: relative;
            width: 100%;
            padding-top: 56.25%;
            /* 16:9 Aspect Ratio */
            cursor: pointer;
        }

        .zg-grab-thumb {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .zg-grab-info {
            padding: 8px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.85rem;
            background: rgba(0, 0, 0, 0.4);
        }

        .zg-grab-tc {
            font-family: monospace;
            color: #aaa;
        }

        .zg-btn-icon-danger {
            background: none;
            border: none;
            color: #ff4d4d;
            cursor: pointer;
            opacity: 0.6;
        }

        .zg-btn-icon-danger:hover {
            opacity: 1;
        }

        /* Helpers */
        .zg-d-none {
            display: none !important;
        }

        .zg-mirror {
            transform: scaleX(-1);
        }

        .zg-flash-new {
            animation: flash 1s ease-out;
        }

        @keyframes flash {
            0% {
                box-shadow: 0 0 0 0 var(--neon-blue);
                border-color: #fff;
            }

            100% {
                box-shadow: 0 0 20px 0 transparent;
                border-color: rgba(255, 255, 255, 0.1);
            }
        }

        /* Responsive */
        @media (max-width: 900px) {
            .frame-catcher-container {
                flex-direction: column;
                height: auto;
                overflow: visible;
            }

            .zg-pane-player {
                border-right: none;
                border-bottom: 1px solid #333;
            }

            .zg-grab-grid {
                grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
            }

            .zg-main-full {
                height: auto;
                overflow-y: auto;
                display: block;
            }
        }

        /* Toast */
        #zg-grab-toast {
            position: absolute;
            top: 30px;
            right: 30px;
            background: var(--neon-blue);
            color: #000;
            padding: 8px 16px;
            border-radius: 4px;
            font-weight: 700;
            opacity: 0;
            transition: 0.3s;
            pointer-events: none;
            z-index: 100;
            box-shadow: 0 0 15px var(--neon-blue);
        }
    </style>
</head>

<body class="zg-body">

    <header class="zg-topbar">
        <h1 class="neon-title">Frame Catcher</h1>
        <div style="font-size:0.9rem; color:#888; margin-top:5px; text-transform:uppercase; letter-spacing:1px;">
            <?= htmlspecialchars($movieTitle) ?>
        </div>
    </header>

    <main class="zg-main-full">
        <div class="frame-catcher-container">

            <div class="zg-pane-player">

                <div class="zg-player-shell" oncontextmenu="return false;">
                    <video id="zg-video" class="zg-video" src="<?= htmlspecialchars($proxyPath) ?>" preload="metadata" crossorigin="anonymous"></video>
                    <div id="zg-grab-toast">GRAB SAVED</div>
                </div>

                <div class="zg-controls-area">
                    <div class="zg-timeline-wrapper">
                        <span class="zg-tc-display" id="tc-display">--:--:--:--</span>
                        <input type="range" id="zg-seek" class="zg-seek" min="0" max="1000" value="0" disabled>
                        <span id="zg-speed-indicator" style="font-size:0.8rem; font-weight:bold; color:var(--neon-purple); width:30px; text-align:right;">1x</span>
                    </div>

                    <div class="zg-btn-row">
                        <div class="zg-transport">
                            <button id="btn-play-rev" class="zg-icon-btn" title="Play Reverse (J)">
                                <svg id="icon-rev-play" class="zg-mirror" width="18" height="18" fill="currentColor" viewBox="0 0 16 16">
                                    <path d="M4 4l8 4-8 4V4z" />
                                </svg>
                                <svg id="icon-rev-pause" class="zg-d-none" width="18" height="18" fill="currentColor" viewBox="0 0 16 16">
                                    <path d="M5 3.5h6A1.5 1.5 0 0 1 12.5 5v6a1.5 1.5 0 0 1-1.5 1.5h-6A1.5 1.5 0 0 1 3.5 11v-6A1.5 1.5 0 0 1 5 3.5z" />
                                </svg>
                            </button>
                            <button id="btn-back-frame" class="zg-icon-btn" title="Back 1 Frame">
                                <svg width="18" height="18" fill="currentColor" viewBox="0 0 16 16">
                                    <path d="M10 12.796V3.204L4.519 8 10 12.796zm-.659.753-5.48-4.796a1 1 0 0 1 0-1.506l5.48-4.796A1 1 0 0 1 11 3.204v9.592a1 1 0 0 1-1.659.753z" />
                                </svg>
                            </button>

                            <button id="btn-grab" class="zg-grab-main-btn" title="Grab Frame (G)">
                                <svg width="20" height="20" fill="currentColor" viewBox="0 0 16 16">
                                    <path d="M15 12a1 1 0 0 1-1 1H2a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1h1.172a3 3 0 0 0 2.12-.879l.83-.828A1 1 0 0 1 6.827 3h2.344a1 1 0 0 1 .707.293l.828.828A3 3 0 0 0 12.828 5H14a1 1 0 0 1 1 1v6zM2 4a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2h-1.172a2 2 0 0 1-1.414-.586l-.828-.828A2 2 0 0 0 9.172 2H6.828a2 2 0 0 0-1.414.586l-.828.828A2 2 0 0 1 3.172 4H2z" />
                                    <path d="M8 11a2.5 2.5 0 1 1 0-5 2.5 2.5 0 0 1 0 5zm0 1a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7z" />
                                </svg>
                                Grab Frame
                            </button>

                            <button id="btn-fwd-frame" class="zg-icon-btn" title="Fwd 1 Frame">
                                <svg width="18" height="18" fill="currentColor" viewBox="0 0 16 16">
                                    <path d="M4 12.796V3.204L9.481 8 4 12.796zm.659.753 5.48-4.796a1 1 0 0 0 0-1.506L4.66 2.451C4.215 2.062 3.5 2.382 3.5 2.97v10.06c0 .588.715.908 1.159.519z" />
                                </svg>
                            </button>
                            <button id="btn-play-fwd" class="zg-icon-btn" title="Play Forward (L)">
                                <svg id="icon-fwd-play" width="18" height="18" fill="currentColor" viewBox="0 0 16 16">
                                    <path d="M4 4l8 4-8 4V4z" />
                                </svg>
                                <svg id="icon-fwd-pause" class="zg-d-none" width="18" height="18" fill="currentColor" viewBox="0 0 16 16">
                                    <path d="M5 3.5h6A1.5 1.5 0 0 1 12.5 5v6a1.5 1.5 0 0 1-1.5 1.5h-6A1.5 1.5 0 0 1 3.5 11v-6A1.5 1.5 0 0 1 5 3.5z" />
                                </svg>
                            </button>
                        </div>

                        <div style="display:flex; gap:10px; align-items:center;">
                            <div style="display:flex; align-items:center;">
                                <button id="btn-mute" class="zg-icon-btn" style="border:none;" title="Mute (M)">
                                    <svg id="icon-vol-on" width="18" height="18" fill="currentColor" viewBox="0 0 16 16">
                                        <path d="M11.536 14.01A8.473 8.473 0 0 0 14.026 8a8.473 8.473 0 0 0-2.49-6.01l-.708.707A7.476 7.476 0 0 1 13.025 8c0 2.071-.84 3.946-2.197 5.303l.708.707z" />
                                        <path d="M10.121 12.596A6.48 6.48 0 0 0 12.025 8a6.48 6.48 0 0 0-1.904-4.596l-.707.707A5.483 5.483 0 0 1 11.025 8a5.483 5.483 0 0 1-1.61 3.89l.706.706z" />
                                        <path d="M8.707 11.182A4.486 4.486 0 0 0 10.025 8a4.486 4.486 0 0 0-1.318-3.182L8 5.525A3.489 3.489 0 0 1 8.99 8 3.49 3.49 0 0 1 8 10.475l.707.707zM6.717 3.55A.5.5 0 0 1 7 4v8a.5.5 0 0 1-.812.39L3.825 10.5H1.5A.5.5 0 0 1 1 10V6a.5.5 0 0 1 .5-.5h2.325l2.363-1.89a.5.5 0 0 1 .529-.06z" />
                                    </svg>
                                    <svg id="icon-vol-off" class="zg-d-none" width="18" height="18" fill="currentColor" viewBox="0 0 16 16">
                                        <path d="M6.717 3.55A.5.5 0 0 1 7 4v8a.5.5 0 0 1-.812.39L3.825 10.5H1.5A.5.5 0 0 1 1 10V6a.5.5 0 0 1 .5-.5h2.325l2.363-1.89a.5.5 0 0 1 .529-.06zm7.137 2.096a.5.5 0 0 1 0 .708L12.207 8l1.647 1.646a.5.5 0 0 1-.708.708L11.5 8.707l-1.646 1.647a.5.5 0 0 1-.708-.708L10.793 8 9.146 6.354a.5.5 0 1 1 .708-.708L11.5 7.293l1.646-1.647a.5.5 0 0 1 .708 0z" />
                                    </svg>
                                </button>
                                <input type="range" id="zg-volume" min="0" max="100" value="100" style="width:60px; margin-left:5px;">
                            </div>
                            <button id="btn-fullscreen" class="zg-icon-btn" title="Fullscreen (F)">
                                <svg width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
                                    <path d="M1.5 1a.5.5 0 0 0-.5.5v4a.5.5 0 0 1-1 0v-4A1.5 1.5 0 0 1 1.5 0h4a.5.5 0 0 1 0 1h-4zM10 .5a.5.5 0 0 1 .5-.5h4A1.5 1.5 0 0 1 16 1.5v4a.5.5 0 0 1-1 0v-4a.5.5 0 0 0-.5-.5h-4a.5.5 0 0 1-.5-.5zM.5 10a.5.5 0 0 1 .5.5v4a.5.5 0 0 0 .5.5h4a.5.5 0 0 1 0 1h-4A1.5 1.5 0 0 1 0 14.5v-4a.5.5 0 0 1 .5-.5zm15 0a.5.5 0 0 1 .5.5v4a1.5 1.5 0 0 1-1.5 1.5h-4a.5.5 0 0 1 0-1h4a.5.5 0 0 0 .5-.5v-4a.5.5 0 0 1 .5-.5z" />
                                </svg>
                            </button>
                        </div>
                    </div>

                    <div style="margin-top:15px; text-align:center; font-size:0.75rem; color:#666;">
                        SHORTCUTS: SPACE (Play) • J/L (Rev/Fwd) • ←/→ (Frame) • G (Grab)
                    </div>
                </div>
            </div>

            <div class="zg-pane-list" id="pane-list">
                <div class="zg-pane-header">
                    <span class="zg-header-title">Grabbed Stills</span>
                    <div>
                        <span id="zg-items-count" style="font-size:0.8rem; color:#888; margin-right:10px;">0 items</span>
                        <a id="zg-pdf-btn" href="export_grabs_pdf.php?t=<?= urlencode($token) ?>" target="_blank" style="color:#aaa; text-decoration:none; font-size:0.8rem; border:1px solid #444; padding:2px 8px; border-radius:4px;">PDF</a>
                    </div>
                </div>

                <div class="zg-grab-scroll-area">
                    <ul id="zg-grab-grid" class="zg-grab-grid">
                        <li id="zg-empty-msg" style="grid-column:1/-1; text-align:center; padding:30px; color:#555;">
                            📷<br>No grabs yet
                        </li>
                    </ul>
                </div>

                <div style="margin-top:20px; text-align:center;">
                    <?php if ($isFinalized): ?>
                        <div style="color: #fca5a5; border: 1px solid #7f1d1d; background: #450a0a; padding: 10px; border-radius: 6px; font-size: 0.9rem;">
                            🔒 List Finalized
                        </div>
                    <?php else: ?>
                        <button type="button" id="btn-finalize" class="zg-icon-btn" style="width:100%; height:auto; padding:10px; font-size:0.9rem; text-transform:uppercase; color:var(--neon-blue); border-color:rgba(0,243,255,0.3);">
                            Finalize List
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>

    <div id="zg-intro-modal" style="position:fixed; inset:0; background:rgba(0,0,0,0.8); display:none; align-items:center; justify-content:center; z-index:9998; backdrop-filter:blur(5px);">
        <div style="background:#0d111a; border:1px solid var(--neon-blue); padding:30px; width:90%; max-width:500px; border-radius:12px; box-shadow:0 0 30px rgba(0,243,255,0.2);">
            <h3 class="neon-title" style="font-size:1.5rem; display:block; text-align:left; margin-bottom:15px;">Welcome</h3>
            <p style="color:#ccc; line-height:1.6;">Use the player to scrub through the movie.<br>Press <strong>G</strong> or the central <strong>GRAB FRAME</strong> button to capture stills.</p>
            <button id="zg-intro-dismiss" class="zg-grab-main-btn" style="width:100%; justify-content:center; margin-top:20px;">Start Grabbing</button>
        </div>
    </div>

    <div id="zg-finalize-modal" style="position:fixed; inset:0; background:rgba(0,0,0,0.9); display:none; align-items:center; justify-content:center; z-index:9999;">
        <div style="background:#1a1020; border:1px solid var(--neon-purple); padding:30px; width:90%; max-width:400px; border-radius:12px; text-align:center;">
            <h3 style="color:#fff; margin-top:0;">Finalize List?</h3>
            <p style="color:#aaa;">You cannot add or remove grabs after this.</p>
            <div style="display:flex; justify-content:center; gap:10px; margin-top:20px;">
                <button id="btn-finalize-cancel" class="zg-icon-btn" style="width:auto; padding:0 20px;">Cancel</button>
                <button id="btn-finalize-confirm" class="zg-grab-main-btn" style="background:var(--neon-purple); border-color:var(--neon-purple);">Confirm</button>
            </div>
        </div>
    </div>

    <button id="btn-rewind" style="display:none;"></button>
    <button id="btn-ffwd" style="display:none;"></button>

    <script>
        (function() {
            const token = <?= json_encode($token) ?>;
            const initialGrabs = <?= json_encode($initialGrabs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
            const fpsNum = <?= (int)$fpsNum ?>;
            const fpsDen = <?= (int)$fpsDen ?>;
            const fps = fpsNum / fpsDen;
            const startTcStr = <?= json_encode($startTc) ?>;
            const isFinalized = <?= $isFinalized ? 'true' : 'false' ?>;

            // Flags & Elements
            const isPlaybackDisabled = isFinalized;
            const video = document.getElementById('zg-video');
            const tcDisplay = document.getElementById('tc-display');
            const btnPlayRev = document.getElementById('btn-play-rev');
            const btnPlayFwd = document.getElementById('btn-play-fwd');
            const btnBack = document.getElementById('btn-back-frame');
            const btnFwd = document.getElementById('btn-fwd-frame');
            const btnGrab = document.getElementById('btn-grab');
            const btnFullscreen = document.getElementById('btn-fullscreen');
            const btnMute = document.getElementById('btn-mute');
            const seek = document.getElementById('zg-seek');
            const grabGrid = document.getElementById('zg-grab-grid');
            const emptyMsg = document.getElementById('zg-empty-msg');
            const grabToast = document.getElementById('zg-grab-toast');
            const vol = document.getElementById('zg-volume');
            const speedIndicator = document.getElementById('zg-speed-indicator');
            const itemsCountEl = document.getElementById('zg-items-count');
            const pdfBtn = document.getElementById('zg-pdf-btn');

            // Icons
            const iconRevPlay = document.getElementById('icon-rev-play');
            const iconRevPause = document.getElementById('icon-rev-pause');
            const iconFwdPlay = document.getElementById('icon-fwd-play');
            const iconFwdPause = document.getElementById('icon-fwd-pause');
            const iconVolOn = document.getElementById('icon-vol-on');
            const iconVolOff = document.getElementById('icon-vol-off');

            // Modals
            const introModal = document.getElementById('zg-intro-modal');
            const introDismiss = document.getElementById('zg-intro-dismiss');
            const btnFinalize = document.getElementById('btn-finalize');
            const finalizeModal = document.getElementById('zg-finalize-modal');
            const btnFinalizeCancel = document.getElementById('btn-finalize-cancel');
            const btnFinalizeConfirm = document.getElementById('btn-finalize-confirm');

            // Logic vars
            const speedSteps = [1, 2, 4, 8, 16];
            let speedIndex = 0;
            let playMode = 'paused';
            let rewindRafId = null;
            let tcRafId = null;
            let grabToastTimeout = null;
            let grabs = Array.isArray(initialGrabs) ? [...initialGrabs] : [];

            // --- Helpers ---
            function pad2(n) {
                return n < 10 ? '0' + n : '' + n;
            }

            function tcToFrames(tc, fps) {
                const parts = tc.split(':');
                if (parts.length !== 4) return 0;
                return (((parseInt(parts[0]) * 60 + parseInt(parts[1])) * 60) + parseInt(parts[2])) * fps + parseInt(parts[3]);
            }

            function framesToTc(totalFrames, fps) {
                const f = totalFrames % fps;
                const s = Math.floor((totalFrames - f) / fps);
                return pad2(Math.floor(s / 3600)) + ':' + pad2(Math.floor((s % 3600) / 60)) + ':' + pad2(s % 60) + ':' + pad2(f);
            }
            const startFrames = tcToFrames(startTcStr, fps);

            function updateUi() {
                if (itemsCountEl) itemsCountEl.textContent = grabs.length + ' items';
                if (pdfBtn) pdfBtn.style.display = grabs.length > 0 ? 'inline-block' : 'none';
            }

            function updateIcons() {
                if (iconRevPlay) iconRevPlay.classList.toggle('zg-d-none', playMode === 'rev');
                if (iconRevPause) iconRevPause.classList.toggle('zg-d-none', playMode !== 'rev');
                if (iconFwdPlay) iconFwdPlay.classList.toggle('zg-d-none', playMode === 'fwd');
                if (iconFwdPause) iconFwdPause.classList.toggle('zg-d-none', playMode !== 'fwd');
            }

            function stopAll() {
                video.pause();
                stopRewindLoop();
                video.playbackRate = 1;
                playMode = 'paused';
                speedIndex = 0;
                if (speedIndicator) speedIndicator.textContent = '1x';
                updateIcons();
            }

            // --- Playback Logic ---
            function handlePlayFwd() {
                if (isPlaybackDisabled) return;
                if (playMode !== 'fwd') {
                    stopRewindLoop();
                    playMode = 'fwd';
                    speedIndex = 0;
                    video.playbackRate = 1;
                    video.play();
                } else {
                    speedIndex = (speedIndex + 1) % speedSteps.length;
                    video.playbackRate = speedSteps[speedIndex];
                }
                if (speedIndicator) speedIndicator.textContent = video.playbackRate + 'x';
                updateIcons();
            }

            function handlePlayRev() {
                if (isPlaybackDisabled) return;
                if (playMode !== 'rev') {
                    video.pause();
                    playMode = 'rev';
                    speedIndex = 0;
                    startRewindLoop();
                } else {
                    speedIndex = (speedIndex + 1) % speedSteps.length;
                }
                const s = speedSteps[speedIndex];
                if (speedIndicator) speedIndicator.textContent = '-' + s + 'x';
                updateIcons();
            }

            function startRewindLoop() {
                if (rewindRafId !== null) return;
                let lastTime = performance.now();
                const stepLoop = (now) => {
                    if (playMode !== 'rev') {
                        rewindRafId = null;
                        return;
                    }
                    const dt = (now - lastTime) / 1000;
                    lastTime = now;
                    const multiplier = speedSteps[speedIndex] || 1;
                    video.currentTime = Math.max(0, video.currentTime - (multiplier * dt));
                    if (video.currentTime <= 0) stopAll();
                    else rewindRafId = requestAnimationFrame(stepLoop);
                };
                rewindRafId = requestAnimationFrame(stepLoop);
            }

            function stopRewindLoop() {
                if (rewindRafId) {
                    cancelAnimationFrame(rewindRafId);
                    rewindRafId = null;
                }
            }

            function updateTc() {
                if (!video.duration) return;
                if (isFinite(video.currentTime)) {
                    const currentFrames = Math.round(video.currentTime * fps);
                    tcDisplay.textContent = framesToTc(startFrames + currentFrames, fps);
                    seek.value = (video.currentTime / video.duration) * 1000;
                }
            }

            // --- Event Listeners ---
            if (btnPlayFwd) btnPlayFwd.addEventListener('click', handlePlayFwd);
            if (btnPlayRev) btnPlayRev.addEventListener('click', handlePlayRev);
            if (btnBack) btnBack.addEventListener('click', () => {
                if (!isPlaybackDisabled) {
                    stopAll();
                    video.currentTime -= (1 / fps);
                }
            });
            if (btnFwd) btnFwd.addEventListener('click', () => {
                if (!isPlaybackDisabled) {
                    stopAll();
                    video.currentTime += (1 / fps);
                }
            });

            video.addEventListener('timeupdate', updateTc);
            video.addEventListener('play', () => {
                if (playMode !== 'rev') playMode = 'fwd';
                updateIcons();
            });
            video.addEventListener('pause', () => {
                if (playMode !== 'rev') {
                    playMode = 'paused';
                    updateIcons();
                }
            });

            seek.addEventListener('input', () => {
                if (isPlaybackDisabled) return;
                stopAll();
                video.currentTime = (parseInt(seek.value) / 1000) * video.duration;
                updateTc();
            });

            // --- Volume ---
            function updateVolIcon() {
                const isMuted = video.muted || video.volume === 0;
                iconVolOn.classList.toggle('zg-d-none', isMuted);
                iconVolOff.classList.toggle('zg-d-none', !isMuted);
            }
            vol.addEventListener('input', () => {
                video.volume = vol.value / 100;
                video.muted = (video.volume === 0);
                updateVolIcon();
            });
            btnMute.addEventListener('click', () => {
                video.muted = !video.muted;
                vol.value = video.muted ? 0 : video.volume * 100;
                updateVolIcon();
            });

            // --- Fullscreen ---
            btnFullscreen.addEventListener('click', () => {
                if (document.fullscreenElement) document.exitFullscreen();
                else document.querySelector('.zg-player-shell').requestFullscreen();
            });

            // --- Grabbing Logic ---
            function renderGrabs(flashId = null) {
                grabGrid.innerHTML = '';
                if (!grabs.length) {
                    if (emptyMsg) {
                        emptyMsg.style.display = 'block';
                        grabGrid.appendChild(emptyMsg);
                    }
                } else {
                    if (emptyMsg) emptyMsg.style.display = 'none';
                    // Sort descending by frame
                    const sorted = [...grabs].sort((a, b) => b.frame_number - a.frame_number);
                    sorted.forEach((g, i) => {
                        const li = document.createElement('li');
                        li.className = 'zg-grab-card';
                        if (g.id === flashId) li.classList.add('zg-flash-new');

                        const media = document.createElement('div');
                        media.className = 'zg-grab-media';
                        media.onclick = () => {
                            if (isPlaybackDisabled) return;
                            stopAll();
                            video.currentTime = Math.max(0, (g.frame_number - startFrames) / fps);
                        };

                        media.innerHTML = `<img src="${g.thumbnail_url}" class="zg-grab-thumb">`;

                        const info = document.createElement('div');
                        info.className = 'zg-grab-info';
                        info.innerHTML = `<span class="zg-grab-tc">${g.timecode}</span>`;

                        if (!isFinalized) {
                            const del = document.createElement('button');
                            del.className = 'zg-btn-icon-danger';
                            del.innerHTML = '<svg width="14" height="14" fill="currentColor" viewBox="0 0 16 16"><path d="M5.5 5.5A.5.5 0 0 1 6 6v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5zm2.5 0a.5.5 0 0 1 .5.5v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5zm3 .5a.5.5 0 0 0-1 0v6a.5.5 0 0 0 1 0V6z"/><path fill-rule="evenodd" d="M14.5 3a1 1 0 0 1-1 1H13v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V4h-.5a1 1 0 0 1-1-1V2a1 1 0 0 1 1-1H6a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1h3.5a1 1 0 0 1 1 1v1zM4.118 4 4 4.059V13a1 1 0 0 0 1 1h6a1 1 0 0 0 1-1V4.059L11.882 4H4.118zM2.5 3V2h11v1h-11z"/></svg>';
                            del.onclick = (e) => {
                                e.stopPropagation();
                                deleteGrab(g.id);
                            };
                            info.appendChild(del);
                        }

                        li.appendChild(media);
                        li.appendChild(info);
                        grabGrid.appendChild(li);
                    });
                }
                updateUi();
            }

            // Existing deleteGrab function from your system? 
            // If not present in original script block, we must define a basic one or assume it's global.
            // Based on your original code, `deleteGrab` wasn't fully defined in the provided snippet 
            // but referenced. I'll add the fetch logic here to be safe.
            window.deleteGrab = async function(id) {
                if (!confirm('Delete this grab?')) return;
                try {
                    const fd = new FormData();
                    fd.append('token', token);
                    fd.append('grab_id', id);
                    const res = await fetch('api_grab_delete.php', {
                        method: 'POST',
                        body: fd
                    }); // Assuming this endpoint exists
                    const j = await res.json();
                    if (j.ok) {
                        grabs = grabs.filter(x => x.id !== id);
                        renderGrabs();
                    }
                } catch (e) {
                    console.error(e);
                }
            };

            async function performGrab() {
                if (isFinalized || isPlaybackDisabled || !video.videoWidth) return;
                const c = document.createElement('canvas');
                const scale = Math.min(1, 640 / video.videoWidth);
                c.width = video.videoWidth * scale;
                c.height = video.videoHeight * scale;
                c.getContext('2d').drawImage(video, 0, 0, c.width, c.height);
                const totF = startFrames + Math.round(video.currentTime * fps);

                const oldBtn = btnGrab.innerHTML;
                btnGrab.textContent = 'SAVING...';
                btnGrab.disabled = true;

                try {
                    const fd = new FormData();
                    fd.append('token', token);
                    fd.append('frame_number', totF);
                    fd.append('timecode', framesToTc(totF, fps));
                    fd.append('image_data', c.toDataURL('image/jpeg', 0.8));
                    const res = await fetch('api_grab_create.php', {
                        method: 'POST',
                        body: fd
                    });
                    const j = await res.json();
                    if (j.ok && j.grab) {
                        grabs.push(j.grab);
                        renderGrabs(j.grab.id);
                        if (grabToast) {
                            grabToast.style.opacity = '1';
                            if (grabToastTimeout) clearTimeout(grabToastTimeout);
                            grabToastTimeout = setTimeout(() => grabToast.style.opacity = '0', 1500);
                        }
                    }
                } catch (e) {
                    console.error(e);
                } finally {
                    btnGrab.innerHTML = oldBtn;
                    btnGrab.disabled = false;
                }
            }
            btnGrab.addEventListener('click', performGrab);

            // --- Keyboard Shortcuts ---
            document.addEventListener('keydown', (e) => {
                if (isPlaybackDisabled) return;
                const tag = (e.target.tagName || '').toUpperCase();
                if (tag === 'INPUT' || tag === 'TEXTAREA') return;

                switch (e.code) {
                    case 'Space':
                        e.preventDefault();
                        (playMode === 'paused') ? handlePlayFwd(): stopAll();
                        break;
                    case 'KeyK':
                        e.preventDefault();
                        stopAll();
                        break;
                    case 'KeyL':
                        e.preventDefault();
                        handlePlayFwd();
                        break;
                    case 'KeyJ':
                        e.preventDefault();
                        handlePlayRev();
                        break;
                    case 'KeyG':
                        e.preventDefault();
                        performGrab();
                        break;
                    case 'KeyM':
                        e.preventDefault();
                        btnMute.click();
                        break;
                    case 'KeyF':
                        e.preventDefault();
                        btnFullscreen.click();
                        break;
                    case 'ArrowLeft':
                        e.preventDefault();
                        stopAll();
                        video.currentTime -= (1 / fps);
                        break;
                    case 'ArrowRight':
                        e.preventDefault();
                        stopAll();
                        video.currentTime += (1 / fps);
                        break;
                }
            });

            // --- Modals Logic ---
            if (!isFinalized && !isPlaybackDisabled) {
                if (introModal) {
                    introModal.style.display = 'flex';
                    introDismiss.onclick = () => introModal.style.display = 'none';
                }
                if (btnFinalize) {
                    btnFinalize.onclick = () => finalizeModal.style.display = 'flex';
                    btnFinalizeCancel.onclick = () => finalizeModal.style.display = 'none';
                    btnFinalizeConfirm.onclick = async () => {
                        btnFinalizeConfirm.disabled = true;
                        try {
                            const fd = new FormData();
                            fd.append('token', token);
                            const res = await fetch('api_finalize_invite.php', {
                                method: 'POST',
                                body: fd
                            });
                            const j = await res.json();
                            if (j.ok) window.location.reload();
                        } catch (e) {
                            console.error(e);
                            btnFinalizeConfirm.disabled = false;
                        }
                    };
                }
            }

            renderGrabs();
        })();
    </script>
</body>

</html>