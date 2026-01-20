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
    // Determine the specific reason for denial
    if ($isFinalized) {
        $pageTitle = 'List Finalized';
        $heading   = 'Thank You!';
        $message   = 'Your grab list has been successfully finalized and the technician has been notified.<br>No further changes can be made.';
    } else {
        $pageTitle = 'Link Inactive';
        $heading   = 'Link Inactive';
        $message   = 'This invite link is no longer active.';
    }

    // We return 200 OK now as this is a valid status page, not a permission error
    http_response_code(200);

    $pdfLink = 'export_grabs_pdf.php?t=' . urlencode($token);
?>
    <!DOCTYPE html>
    <html lang="en">

    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?= $pageTitle ?></title>
        <style>
            body {
                font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
                background: #05070b;
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
                background: #10131a;
                border: 1px solid #1f2430;
                border-radius: 12px;
                padding: 40px;
                max-width: 480px;
                width: 90%;
                box-shadow: 0 20px 50px rgba(0, 0, 0, 0.5);
            }

            h1 {
                margin-top: 0;
                margin-bottom: 16px;
                font-size: 1.75rem;
                color: #fff;
            }

            p {
                font-size: 1rem;
                line-height: 1.6;
                color: #94a3b8;
                margin-top: 0;
                margin-bottom: 30px;
            }

            .zg-btn {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                background: #3a82f6;
                /* Accent blue */
                color: #fff;
                font-weight: 600;
                text-decoration: none;
                padding: 12px 24px;
                border-radius: 6px;
                transition: background 0.2s ease;
                border: none;
                cursor: pointer;
                font-size: 0.95rem;
            }

            .zg-btn:hover {
                background: #2563eb;
            }

            .zg-logo {
                width: 48px;
                height: auto;
                margin-bottom: 20px;
                opacity: 0.8;
            }
        </style>
    </head>

    <body>
        <div class="zg-card">
            <img src="assets/img/zen_logo.png" alt="Zenreview" class="zg-logo">
            <h1><?= $heading ?></h1>
            <p><?= $message ?></p>

            <a href="<?= htmlspecialchars($pdfLink) ?>" target="_blank" class="zg-btn">
                Download PDF Report
            </a>
        </div>
    </body>

    </html>
<?php
    exit;
}
// END SERVER-SIDE CHECK

// 2. Update Access Time
$pdo->prepare("UPDATE invite_links SET last_accessed_at = NOW() WHERE id = ?")
    ->execute([$invite['id']]);

// 3. Setup Variables
$movieTitle = $invite['movie_title'];
$fullName   = $invite['full_name'];
$fpsNum     = (int)$invite['fps_num'];
$fpsDen     = (int)$invite['fps_den'];
$startTc    = $invite['start_tc'];
$proxyPath  = $invite['proxy_path'];
// $isFinalized is already set above, but kept here for JS export consistency
$invitedByAdmin = $invite['admin_name'] ?? null;

if ($fpsNum <= 0 || $fpsDen <= 0) {
    $fpsNum = 25;
    $fpsDen = 1;
}

// 4. Fetch Existing Grabs
$grabStmt = $pdo->prepare("
    SELECT id, frame_number, timecode, thumbnail_path, doodle_path, note, created_by_name
    FROM grabs
    WHERE movie_id = :movie_id AND invite_id = :invite_id
    ORDER BY frame_number DESC
");
$grabStmt->execute([':movie_id' => $invite['movie_id'], ':invite_id' => $invite['id']]);
$existingGrabs = $grabStmt->fetchAll() ?: [];

// Prepare JSON for JS
$initialGrabs = array_map(function ($g) {
    return [
        'id'              => (int)$g['id'],
        'frame_number'    => (int)$g['frame_number'],
        'timecode'        => $g['timecode'],
        'thumbnail_url'   => $g['thumbnail_path'],
        'doodle_url'      => $g['doodle_path'],
        'note'            => $g['note'] ?? '',
        'created_by_name' => $g['created_by_name'] ?? 'Anonymous', // Add this
    ];
}, $existingGrabs);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Zenreview · <?= htmlspecialchars($movieTitle, ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="assets/style.css?v=<?= time() ?>">
    <style>
        /* Annotation Layer Styles */
        .zg-video-container {
            position: relative;
            width: 100%;
            background: #000;
            line-height: 0;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        #zg-video {
            width: 100%;
            height: auto;
            display: block;
            pointer-events: none;
        }

        /* Display layer for saved grabs */
        #zg-saved-doodle-overlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            pointer-events: none;
            z-index: 5;
            display: none;
            /* Ensure the PNG stays crisp */
            image-rendering: -webkit-optimize-contrast;
            image-rendering: crisp-edges;
        }

        #zg-canvas-overlay {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            cursor: url('data:image/svg+xml;utf8,<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 10 10"><circle cx="5" cy="5" r="4" fill="%233aa0ff" /></svg>') 5 5, crosshair;
            display: none;
            z-index: 10;
            pointer-events: none;
        }

        .doodle-active #zg-canvas-overlay {
            display: block;
            pointer-events: auto;
        }

        /* Highlight the button when doodle mode is on */
        #btn-doodle.active {
            color: #3aa0ff;
            background: rgba(58, 160, 255, 0.2);
            border-radius: 4px;
        }

        .zg-timeline-marker.has-doodle {
            background: #3aa0ff !important;
            /* Zentropa Blue */
            height: 12px;
            width: 3px;
            top: -2px;
            box-shadow: 0 0 8px rgba(58, 160, 255, 0.8);
        }

        .zg-doodle-indicator {
            position: absolute;
            top: 8px;
            right: 8px;
            background: #3aa0ff;
            color: #fff;
            width: 24px;
            height: 24px;
            border-radius: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.5);
            z-index: 10;
            transition: all 0.2s ease;
            cursor: pointer;
        }

        /* Change to red trash bin on hover */
        .zg-doodle-indicator:hover {
            background: #ff4444;
        }

        /* Swap the icons: Pencil normally, Trash on hover */
        .zg-doodle-indicator .icon-trash {
            display: none;
        }

        .zg-doodle-indicator:hover .icon-pencil {
            display: none;
        }

        .zg-doodle-indicator:hover .icon-trash {
            display: block;
        }

        /* Doodle Toolbar Styles */
        .zg-doodle-toolbar {
            position: absolute;
            top: -60px;
            /* Hidden by default */
            left: 0;
            width: 100%;
            height: 50px;
            background: rgba(19, 21, 27, 0.95);
            border-bottom: 1px solid var(--zg-accent);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 20px;
            z-index: 100;
            transition: top 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            backdrop-filter: blur(10px);
        }

        .doodle-active .zg-doodle-toolbar {
            top: 0;
        }

        .zg-doodle-tool-group {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #fff;
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: uppercase;
        }

        .zg-color-dot {
            width: 20px;
            height: 20px;
            border-radius: 50%;
            cursor: pointer;
            border: 2px solid transparent;
            transition: transform 0.2s;
        }

        .zg-color-dot.active {
            transform: scale(1.2);
            border-color: #fff;
        }

        .zg-doodle-range {
            width: 80px;
            accent-color: var(--zg-accent);
        }

        #btn-doodle-save {
            background-color: #3aa0ff !important;
            color: #fff !important;
            border: none;
            cursor: pointer;
            opacity: 1;
            transition: opacity 0.2s, background-color 0.2s;
        }

        #btn-doodle-save:disabled {
            background-color: #2d333b !important;
            /* Dark charcoal grey */
            color: #5b687a !important;
            cursor: not-allowed;
            opacity: 0.6;
        }

        #btn-doodle-save:hover {
            background: #50b0ff;
            transform: translateY(-1px);
            box-shadow: 0 0 20px rgba(58, 160, 255, 0.5);
        }

        #btn-doodle-save:active {
            transform: translateY(0);
        }

        .zg-grab-note {
            white-space: pre-wrap;
            font-size: 0.75rem;
            /* Smaller text */
            line-height: 1.5;
            color: #94a3b8;
            margin-top: 8px;
            padding: 8px;
            background: rgba(0, 0, 0, 0.2);
            border-radius: 4px;
            max-height: 120px;
            /* Limits vertical growth */
            overflow-y: auto;
            /* Adds scrollbar if content exceeds height */
            border: 1px solid rgba(255, 255, 255, 0.05);
        }

        /* Custom scrollbar for notes to keep the Vibe */
        .zg-grab-note::-webkit-scrollbar {
            width: 4px;
        }

        .zg-grab-note::-webkit-scrollbar-thumb {
            background: var(--zg-accent);
            border-radius: 10px;
        }
    </style>
    <link rel="icon" type="image/png" href="assets/img/zentropa-favicon.png">
</head>

<body class="zg-body">

    <header class="zg-topbar">
        <div class="zg-topbar-left">
            <img src="assets/img/zen_logo.png" alt="Zen" class="zg-logo-img">
            <span class="zg-topbar-title" style="margin-left:12px;">Zenreview</span>
            <span class="zg-topbar-subtitle">
                · <?= htmlspecialchars($movieTitle, ENT_QUOTES, 'UTF-8') ?>
            </span>
        </div>
    </header>

    <main class="zg-main-full">

        <div class="zg-split-container">

            <div class="zg-pane-list" id="pane-list">

                <div class="zg-pane-header">
                    <h2 class="zg-card-title" style="margin: 0; font-size: 0.95rem; text-transform:uppercase; letter-spacing:0.05em; color:var(--zg-text-muted);">
                        Captured Grabs
                    </h2>
                    <div style="display:flex; align-items:center; gap:10px;">
                        <span
                            id="zg-items-count"
                            class="zg-text-muted"
                            style="font-size:0.8rem;">
                            <?= count($initialGrabs) ?> items
                        </span>

                        <a
                            id="zg-pdf-btn"
                            href="export_grabs_pdf.php?t=<?= urlencode($token) ?>"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="zg-btn zg-btn-ghost"
                            style="padding: 4px 10px; font-size:0.8rem;<?= count($initialGrabs) ? '' : 'display:none;' ?>">
                            Download PDF
                        </a>

                    </div>
                </div>


                <div class="zg-grab-scroll-area">
                    <ul id="zg-grab-grid" class="zg-grab-grid">
                        <li class="zg-empty-state" id="zg-empty-msg" style="padding: 40px 20px; text-align:center; grid-column: 1 / -1;">
                            <div style="opacity:0.5; font-size: 2rem; margin-bottom:10px;">📷</div>
                            No grabs captured yet.<br>
                            <span style="font-size:0.85rem">Use the player to grab frames.</span>
                        </li>
                    </ul>
                </div>

                <div class="zg-pane-footer">
                    <?php if ($isFinalized): ?>
                        <div class="zg-alert zg-alert-error" style="width:100%; text-align:center; margin:0;">
                            🔒 List Finalized
                        </div>
                        <span class="zg-help">No further changes can be made.</span>
                    <?php else: ?>
                        <button type="button" id="btn-finalize" class="zg-btn zg-btn-ghost" style="width:100%; border-color: rgba(58,160,255,0.3);">
                            Finalize List
                        </button>
                        <span class="zg-help">Click when you are done grabbing.</span>
                    <?php endif; ?>
                </div>

            </div>

            <div class="zg-resizer" id="drag-handle"></div>

            <div class="zg-pane-player">
                <div class="zg-player-content">

                    <div style="display:flex; justify-content:space-between; align-items: flex-end; margin-bottom: 16px;">
                        <div>
                            <h1 class="zg-card-title" style="margin:0; font-size: 1.5rem;">
                                <?= htmlspecialchars($movieTitle, ENT_QUOTES, 'UTF-8') ?>
                            </h1>
                        </div>
                        <div style="text-align:right;">
                            <span class="zg-text-muted" style="font-size: 0.85rem;">Current User:</span>
                            <span id="zr-current-user-display" style="font-weight:600; font-size:0.9rem; margin-left: 4px; color: var(--zg-accent);">
                                <?= htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8') ?>
                            </span>
                            <button id="zr-switch-user" class="zg-btn-ghost" style="padding: 2px 8px; font-size: 0.65rem; margin-left: 8px; color: var(--zg-accent); border: 1px solid rgba(58,160,255,0.3); height: auto; background: transparent; cursor: pointer; border-radius: 4px; transition: all 0.2s;">
                                Switch User
                            </button>

                            <?php if ($invitedByAdmin): ?>
                                <div class="zg-text-muted" style="font-size:0.8rem; margin-top:2px;">
                                    Invited by <?= htmlspecialchars($invitedByAdmin, ENT_QUOTES, 'UTF-8') ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="zg-player-shell" oncontextmenu="return false;">
                        <div class="zg-video-container" id="video-wrap">
                            <div class="zg-doodle-toolbar" id="doodle-toolbar">
                                <div class="zg-doodle-tool-group">
                                    <span>Color</span>
                                    <div class="zg-color-dot active" data-color="#3aa0ff" style="background: #3aa0ff;" title="Zentropa Blue"></div>
                                    <div class="zg-color-dot" data-color="#ff4444" style="background: #ff4444;" title="Red"></div>
                                    <div class="zg-color-dot" data-color="#44ff44" style="background: #44ff44;" title="Green"></div>
                                    <div class="zg-color-dot" data-color="#ffea00" style="background: #ffea00;" title="Yellow"></div>
                                    <div class="zg-color-dot" data-color="#ff9100" style="background: #ff9100;" title="Orange"></div>
                                    <div class="zg-color-dot" data-color="#ff00ff" style="background: #ff00ff;" title="Magenta"></div>
                                    <div class="zg-color-dot" data-color="#ffffff" style="background: #ffffff;" title="White"></div>
                                </div>

                                <div class="zg-resizer-v" style="width:1px; height:20px; background:rgba(255,255,255,0.1);"></div>

                                <div class="zg-doodle-tool-group">
                                    <span>Size</span>
                                    <input type="range" id="doodle-size" class="zg-doodle-range" min="1" max="20" value="3">
                                </div>

                                <div class="zg-resizer-v" style="width:1px; height:20px; background:rgba(255,255,255,0.1);"></div>

                                <div class="zg-doodle-tool-group">
                                    <span>Opacity</span>
                                    <input type="range" id="doodle-opacity" class="zg-doodle-range" min="10" max="100" value="100">
                                </div>
                                <div class="zg-doodle-tool-group">
                                    <button id="btn-undo" class="zg-icon-btn" title="Undo (Ctrl+Z)" style="width:30px; height:30px; padding:4px;">
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32" fill="currentColor">
                                            <path d="M12 10V6L4 13l8 7v-4c5.5 0 10 4.5 10 10 0-5.5-4.5-10-10-10z" />
                                        </svg>
                                    </button>
                                    <button id="btn-redo" class="zg-icon-btn" title="Redo (Ctrl+Y)" style="width:30px; height:30px; padding:4px;">
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32" fill="currentColor" style="transform: scaleX(-1);">
                                            <path d="M12 10V6L4 13l8 7v-4c5.5 0 10 4.5 10 10 0-5.5-4.5-10-10-10z" />
                                        </svg>
                                    </button>
                                    <button id="btn-clear" class="zg-icon-btn" title="Clear All (C)" style="width:30px; height:30px; padding:4px; color:var(--zg-danger);">
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32" fill="currentColor">
                                            <path d="M12 4h8v2h-8V4zM8 8v2h16V8H8zm2 4v14h12V12H10zm3 2h2v10h-2V14zm4 0h2v10h-2V14z" />
                                        </svg>
                                    </button>
                                    <div class="zr-doodle-tool-group">
                                        <button id="btn-doodle-save" class="zr-btn zr-btn-primary" style="padding: 4px 12px; font-size: 0.8rem; height: 32px;">
                                            Save & Grab
                                        </button>
                                    </div>
                                </div>
                            </div>
                            <video
                                id="zg-video"
                                class="zg-video"
                                src="stream_movie.php?t=<?= urlencode($token) ?>"
                                preload="metadata"
                                crossorigin="anonymous"></video>
                            <img id="zg-saved-doodle-overlay" src="" alt="Grab Overlay">
                            <canvas id="zg-canvas-overlay"></canvas>
                        </div>


                        <!-- Timeline + seekbar -->
                        <div class="zg-timeline">
                            <div class="zg-timeline-inner">
                                <div id="zg-timeline-markers" class="zg-timeline-markers"></div>
                                <input
                                    type="range"
                                    id="zg-seek"
                                    class="zg-seek"
                                    min="0"
                                    max="1000"
                                    value="0">
                            </div>
                        </div>

                        <div class="zg-controls">
                            <button id="btn-back-frame" class="zg-icon-btn" title="Back 1 frame (Left Arrow)">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 32 32" fill="currentColor">
                                    <path d="M6 16L18 8v16L6 16zm16-8h4v16h-4V8z" />
                                </svg>
                            </button>

                            <button id="btn-rewind" class="zg-icon-btn" title="Rewind (J)">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 32 32" fill="currentColor">
                                    <path d="M28 8v16L16 16l12-8zm-12 0v16L4 16l12-8z" />
                                </svg>
                            </button>

                            <button id="btn-play-rev" class="zg-icon-btn" title="Play Reverse">
                                <svg id="icon-rev-play" class="zg-mirror" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 32 32" fill="currentColor">
                                    <path d="M8 6l18 10L8 26V6z" />
                                </svg>
                                <svg id="icon-rev-pause" class="zg-d-none" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 32 32" fill="currentColor">
                                    <path d="M8 6h5v20H8V6zm11 0h5v20h-5V6z" />
                                </svg>
                            </button>

                            <button id="btn-play-fwd" class="zg-icon-btn" title="Play Forward">
                                <svg id="icon-fwd-play" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 32 32" fill="currentColor">
                                    <path d="M8 6l18 10L8 26V6z" />
                                </svg>
                                <svg id="icon-fwd-pause" class="zg-d-none" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 32 32" fill="currentColor">
                                    <path d="M8 6h5v20H8V6zm11 0h5v20h-5V6z" />
                                </svg>
                            </button>

                            <button id="btn-ffwd" class="zg-icon-btn" title="Fast Forward (L)">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 32 32" fill="currentColor">
                                    <path d="M4 8v16l12-8L4 8zm12 0v16l12-8-12-8z" />
                                </svg>
                            </button>

                            <button id="btn-fwd-frame" class="zg-icon-btn" title="Forward 1 frame (Right Arrow)">
                                <svg class="zg-mirror" xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 32 32" fill="currentColor">
                                    <path d="M6 16L18 8v16L6 16zm16-8h4v16h-4V8z" />
                                </svg>
                            </button>

                            <div class="zg-tc-display">
                                <span class="zg-tc-label">TC</span>
                                <span class="zg-tc-value" id="tc-display">--:--:--:--</span>
                                <span id="zg-speed-indicator" style="margin-left:8px; font-size:0.8rem; font-weight:600; color:var(--zg-accent);">
                                    1x
                                </span>
                            </div>

                            <!-- previous/next grab buttons (moved to the right of TC) -->
                            <button id="btn-prev-grab" class="zg-icon-btn" title="Jump to previous grab">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 32 32" fill="currentColor">
                                    <path d="M6 4h4v24H6V4zm6 12l14-12v24L12 16z" />
                                </svg>
                            </button>

                            <button id="btn-next-grab" class="zg-icon-btn" title="Jump to next grab">
                                <svg class="zg-mirror" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 32 32" fill="currentColor">
                                    <path d="M6 4h4v24H6V4zm6 12l14-12v24L12 16z" />
                                </svg>
                            </button>


                            <div class="zg-volume-wrapper" style="display:flex; align-items:center; gap:6px; margin-left:12px;">
                                <button id="btn-mute" class="zg-icon-btn" style="width:24px; height:24px;" title="Mute (M)">
                                    <svg id="icon-vol-on" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 32 32" fill="currentColor">
                                        <path d="M2 12h6l8-8v24l-8-8H2z" />
                                        <path d="M20 11v2c2 1 2 5 0 6v2c4-2 4-8 0-10z" />
                                        <path d="M24 8v2c3.5 2 3.5 10 0 12v2c5.5-3 5.5-13 0-16z" />
                                    </svg>
                                    <svg id="icon-vol-off" class="zg-d-none" xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 32 32" fill="currentColor" style="color:var(--zg-danger);">
                                        <path d="M2 12h6l8-8v24l-8-8H2z" />
                                        <path d="M23 16l-6-6 1.4-1.4 6 6 6-6 1.4 1.4-6 6 6 6-1.4 1.4-6-6-6 6-1.4-1.4 6-6z" />
                                    </svg>
                                </button>
                                <input type="range" id="zg-volume" min="0" max="100" value="100" style="width:70px;">
                            </div>

                            <div class="zg-spacer"></div>

                            <button id="btn-fullscreen" class="zg-icon-btn" title="Fullscreen (F)">
                                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 32 32" fill="currentColor">
                                    <path d="M4 4h10v4H8v6H4V4zm24 0H18v4h6v6h4V4zM4 28h10v-4H8v-6H4v10zm24 0H18v-4h6v-6h4v10z" />
                                </svg>
                            </button>

                            <button id="btn-doodle" class="zg-icon-btn" title="Toggle Doodle Mode (D)">
                                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 32 32" fill="currentColor">
                                    <path d="M25.3 4.7c-1.6-1.6-4.1-1.6-5.7 0L4 20.3V28h7.7l15.6-15.6c1.6-1.6 1.6-4.1 0-5.7l-2-2zM9.7 26H6v-3.7l12-12L21.7 14 9.7 26zm14-14L21.7 10 24 7.7l2.3 2.3L24 12z" />
                                </svg>
                            </button>
                            <button id="zg-delete-doodle-main" class="zg-icon-btn" title="Delete current doodle" style="display:none; color:var(--zg-danger);">
                                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 32 32" fill="currentColor">
                                    <path d="M12 4h8v2h-8V4zM8 8v2h16V8H8zm2 4v14h12V12H10zm3 2h2v10h-2V14zm4 0h2v10h-2V14z" />
                                </svg>
                            </button>
                            <!-- WIDER GRAB BUTTON WITH TEXT -->
                            <button id="btn-grab" class="zg-icon-btn zg-icon-primary zg-btn-grab" title="Grab frame (G)">
                                <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 32 32" fill="currentColor">
                                    <path d="M16 10a6 6 0 100 12 6 6 0 000-12zm0 10a4 4 0 110-8 4 4 0 010 8zM4 8h7l3-3h4l3 3h7v18H4V8z" />
                                </svg>
                                <span class="zg-btn-grab-label">Grab</span>
                            </button>
                        </div>


                        <div id="zg-grab-toast">
                            Grab saved
                        </div>
                    </div>

                    <!-- Comment panel -->
                    <div id="zg-comment-panel" class="zg-comment-panel" style="display:none;">
                        <div class="zg-comment-thumb-wrap">
                            <img id="zg-comment-thumb" class="zg-comment-thumb" src="" alt="Grab thumbnail">
                        </div>
                        <div class="zg-comment-body">
                            <div class="zg-comment-header">
                                <span class="zg-comment-label">Comment for grab</span>
                                <span id="zg-comment-tc" class="zg-comment-tc">--:--:--:--</span>
                            </div>
                            <textarea
                                id="zg-comment-text"
                                class="zg-comment-text"
                                placeholder="Type your comment here… (saved per grab)">
                            </textarea>
                            <div class="zg-comment-actions">
                                <button id="zg-comment-save" class="zg-btn zg-btn-primary">Save comment</button>
                                <button id="zg-comment-reply" class="zg-btn zg-btn-ghost" style="margin-left: 8px; border-color: var(--zg-accent); color: var(--zg-accent);">
                                    Add Reply
                                </button>
                                <span id="zg-comment-status" class="zg-comment-status"></span>
                            </div>
                        </div>
                    </div>

                    <!-- Keyboard shortcuts card stays below this -->

                    <div style="margin-top: 20px; padding: 20px; background:#05070b; border-radius:10px; border: 1px solid #1f2430; box-shadow: 0 4px 12px rgba(0,0,0,0.3);">
                        <h4 style="margin:0 0 16px; font-size:0.8rem; font-weight:700; text-transform:uppercase; color:#5b687a; letter-spacing:0.1em;">Keyboard Shortcuts</h4>
                        <div style="display:grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap:16px; font-size:0.9rem; color:#e5ecf5;">
                            <div style="display:flex; align-items:center;"><span class="key-badge">K</span> Pause</div>
                            <div style="display:flex; align-items:center;"><span class="key-badge">L</span> Play Fwd / + speed</div>
                            <div style="display:flex; align-items:center;"><span class="key-badge">J</span> Play Rev / - speed</div>
                            <div style="display:flex; align-items:center;"><span class="key-badge">G</span> Grab Frame</div>
                            <div style="display:flex; align-items:center;"><span class="key-badge">D</span> Doodle Mode</div>
                            <div style="display:flex; align-items:center;"><span class="key-badge">Ctrl</span> + <span class="key-badge">Z</span> Undo Doodle</div>
                            <div style="display:flex; align-items:center;"><span class="key-badge">C</span> Clear All Doodles</div>
                            <div style="display:flex; align-items:center;"><span class="key-badge">M</span> Mute</div>
                            <div style="display:flex; align-items:center;"><span class="key-badge">F</span> Fullscreen</div>
                            <div style="display:flex; align-items:center;"><span class="key-badge">←</span> Step Back</div>
                            <div style="display:flex; align-items:center;"><span class="key-badge">→</span> Step Fwd</div>
                        </div>
                        <style>
                            .key-badge {
                                display: inline-flex;
                                align-items: center;
                                justify-content: center;
                                min-width: 28px;
                                height: 28px;
                                padding: 0 8px;
                                background: #1f2430;
                                border: 1px solid #363c4e;
                                border-radius: 6px;
                                color: #fff;
                                font-family: var(--zg-mono);
                                font-weight: 700;
                                font-size: 0.85rem;
                                margin-right: 10px;
                                box-shadow: 0 2px 0 rgba(0, 0, 0, 0.4);
                            }
                        </style>
                    </div>

                </div>
            </div>

        </div>

    </main>
    <!-- Intro modal: shown first time per invite token -->
    <div id="zg-intro-modal" style="position: fixed; inset: 0; background: rgba(0,0,0,0.85); display: none; align-items: center; justify-content: center; z-index: 9998; backdrop-filter: blur(4px);">
        <div style="background: #10131a; border-radius: 12px; border: 1px solid var(--zg-accent); padding: 24px; max-width: 550px; width: 90%; box-shadow: 0 20px 50px rgba(0,0,0,0.9);">
            <div style="display:flex; align-items:center; margin-bottom: 16px; gap: 12px;">
                <img src="assets/img/zen_logo.png" alt="Zen" style="width:32px; height:auto;">
                <h3 class="zg-card-title" style="margin:0; font-size:1.2rem; letter-spacing: 0.05em;">Welcome to Zenreview</h3>
            </div>

            <p class="zg-help" style="margin-top:0; margin-bottom: 16px; line-height:1.6; color: #e5ecf5;">
                Review and annotate movie frames with precision. Use the player to find your frame, then use the tools below to collaborate.
            </p>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                <div>
                    <h4 style="margin:0 0 8px; font-size:0.75rem; text-transform:uppercase; color:var(--zg-accent);">Capture</h4>
                    <ul class="zg-help" style="margin:0; padding:0 0 0 18px; font-size:0.85rem; line-height:1.5;">
                        <li><strong>G</strong> – Grab current frame</li>
                        <li><strong>Space</strong> – Play / Pause</li>
                        <li><strong>← / →</strong> – Step frames</li>
                    </ul>
                </div>
                <div>
                    <h4 style="margin:0 0 8px; font-size:0.75rem; text-transform:uppercase; color:var(--zg-accent);">Annotate</h4>
                    <ul class="zg-help" style="margin:0; padding:0 0 0 18px; font-size:0.85rem; line-height:1.5;">
                        <li><strong>D</strong> – Open Doodle Tools</li>
                        <li><strong>Ctrl + Z</strong> – Undo drawing</li>
                        <li><strong>C</strong> – Clear all doodles</li>
                    </ul>
                </div>
            </div>

            <p id="zr-pro-tip-box" class="zg-help" style="margin:0 0 20px; padding: 14px 16px; background: rgba(58,160,255,0.1); border-radius: 6px; border-left: 3px solid var(--zg-accent); font-size: 0.85rem; min-height: 60px; line-height: 1.5; display: block;">
            </p>

            <div style="display:flex; justify-content:flex-end; gap:10px;">
                <button type="button" id="zg-intro-dismiss" class="zg-btn zg-btn-primary" style="padding: 10px 24px;">
                    Start Reviewing
                </button>
            </div>
        </div>
    </div>

    <div id="zr-name-modal" style="position: fixed; inset: 0; background: rgba(0,0,0,0.9); display: none; align-items: center; justify-content: center; z-index: 10001; backdrop-filter: blur(8px);">
        <div style="background: #10131a; border-radius: 12px; border: 1px solid var(--zg-accent); padding: 30px; max-width: 400px; width: 90%; text-align: center; box-shadow: 0 20px 50px rgba(0,0,0,0.9);">
            <img src="assets/img/zen_logo.png" alt="Zen" style="width:40px; margin-bottom: 20px;">
            <h3 class="zg-card-title" style="margin-top:0; margin-bottom: 8px;">Who is reviewing?</h3>
            <p class="zg-help" style="margin-bottom: 20px;">Enter your name to label your grabs and doodles.</p>

            <input type="text" id="zr-nickname-input" class="zg-comment-text" style="height: 45px; text-align: center; font-size: 1.1rem; margin-bottom: 20px;" placeholder="e.g. Melissa" maxlength="30">

            <button type="button" id="zr-nickname-save" class="zg-btn zg-btn-primary" style="width: 100%; height: 45px; font-weight: bold;">
                Enter Review Session
            </button>
        </div>
    </div>

    <div id="zr-confirm-modal" style="position: fixed; inset: 0; background: rgba(0,0,0,0.8); display: none; align-items: center; justify-content: center; z-index: 10000; backdrop-filter: blur(4px);">
        <div style="background: #10131a; border-radius: 12px; border: 1px solid var(--zg-accent); padding: 24px; max-width: 400px; width: 90%; box-shadow: 0 20px 50px rgba(0,0,0,0.9);">
            <h3 id="zr-confirm-title" class="zg-card-title" style="margin-top:0; margin-bottom: 12px; font-size:1.1rem;">Confirm Action</h3>
            <p id="zr-confirm-msg" class="zg-help" style="margin-bottom: 24px; color: #e5ecf5; line-height: 1.5;">Are you sure you want to proceed?</p>
            <div style="display:flex; justify-content:flex-end; gap:12px;">
                <button type="button" id="zr-confirm-cancel" class="zg-btn zg-btn-ghost">Cancel</button>
                <button type="button" id="zr-confirm-ok" class="zg-btn zg-btn-primary" style="background:#ff4444 !important;">Delete</button>
            </div>
        </div>
    </div>

    <div id="zg-finalize-modal" style="position: fixed; inset: 0; background: rgba(0,0,0,0.7); display: none; align-items: center; justify-content: center; z-index: 9999;">
        <div style="background: #10131a; border-radius: 10px; border: 1px solid rgba(58,160,255,0.7); padding: 18px 20px 14px; max-width: 420px; width: 90%; box-shadow: 0 18px 40px rgba(0,0,0,0.8);">
            <h3 class="zg-card-title" style="margin-top:0; margin-bottom: 8px; font-size:1rem;">Finalize grab list?</h3>
            <p class="zg-help" style="margin-top:0; margin-bottom: 12px;">
                When you finalize, you won&rsquo;t be able to add or delete grabs on this link.<br><br>
                If you&rsquo;re not finished yet, press Cancel.
            </p>
            <div style="display:flex; justify-content:flex-end; gap:8px;">
                <button type="button" id="btn-finalize-cancel" class="zg-btn zg-btn-ghost">Cancel</button>
                <button type="button" id="btn-finalize-confirm" class="zg-btn zg-btn-primary">Finalize</button>
            </div>
        </div>
    </div>

    <div id="zr-reply-modal" style="position: fixed; inset: 0; background: rgba(0,0,0,0.85); display: none; align-items: center; justify-content: center; z-index: 10002; backdrop-filter: blur(4px);">
        <div style="background: #10131a; border-radius: 12px; border: 1px solid var(--zg-accent); padding: 24px; max-width: 450px; width: 90%; box-shadow: 0 20px 50px rgba(0,0,0,0.9);">
            <h3 class="zg-card-title" style="margin-top:0; margin-bottom: 8px; font-size:1rem;">Reply to Grab</h3>
            <p class="zg-help" id="zr-reply-context" style="margin-bottom: 16px; font-size: 0.8rem;"></p>

            <textarea id="zr-reply-input" class="zg-comment-text" style="height: 100px; margin-bottom: 20px; font-size: 0.9rem;" placeholder="Write your reply..."></textarea>

            <div style="display:flex; justify-content:flex-end; gap:12px;">
                <button type="button" id="zr-reply-cancel" class="zg-btn zg-btn-ghost">Cancel</button>
                <button type="button" id="zr-reply-send" class="zg-btn zg-btn-primary">Post Reply</button>
            </div>
        </div>
    </div>

    <script>
        (function() {
            const token = <?= json_encode($token) ?>;
            const initialGrabs = <?= json_encode($initialGrabs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
            const fpsNum = <?= (int)$fpsNum ?>;
            const fpsDen = <?= (int)$fpsDen ?>;
            const fps = fpsNum / fpsDen;
            const startTcStr = <?= json_encode($startTc) ?>;
            const isFinalized = <?= $isFinalized ? 'true' : 'false' ?>;

            // Doodle tool bar
            const doodleSizeInput = document.getElementById('doodle-size');
            const doodleOpacityInput = document.getElementById('doodle-opacity');
            const colorDots = document.querySelectorAll('.zg-color-dot');

            let currentDoodleColor = "#3aa0ff";

            // Client-side playback/interaction control flag
            const isPlaybackDisabled = isFinalized;

            const resizer = document.getElementById('drag-handle');
            const leftPane = document.getElementById('pane-list');
            const video = document.getElementById('zg-video');
            const shell = document.querySelector('.zg-player-shell');
            const tcDisplay = document.getElementById('tc-display');
            const savedDoodleOverlay = document.getElementById('zg-saved-doodle-overlay');

            const btnPlayRev = document.getElementById('btn-play-rev');
            const btnPlayFwd = document.getElementById('btn-play-fwd');
            const btnRewind = document.getElementById('btn-rewind');
            const btnFfwd = document.getElementById('btn-ffwd');
            const btnBack = document.getElementById('btn-back-frame');
            const btnFwd = document.getElementById('btn-fwd-frame');
            const btnPrevGrab = document.getElementById('btn-prev-grab');
            const btnNextGrab = document.getElementById('btn-next-grab');
            const btnGrab = document.getElementById('btn-grab');
            const btnDoodle = document.getElementById('btn-doodle');
            const canvasOverlay = document.getElementById('zg-canvas-overlay');
            const ctxOverlay = canvasOverlay.getContext('2d');
            let isDoodling = false;
            let isDoodleMode = false;
            let doodleHistory = [];
            let redoHistory = []; // Add this
            let currentStrokePoints = []; //
            const maxHistory = 20;

            const btnUndo = document.getElementById('btn-undo');
            const btnRedo = document.getElementById('btn-redo');
            const btnClear = document.getElementById('btn-clear');
            const btnDoodleSave = document.getElementById('btn-doodle-save');

            if (btnDoodleSave) {
                btnDoodleSave.addEventListener('click', (e) => {
                    e.stopPropagation();
                    // Trigger the same logic as the main Grab button
                    performGrab();
                });
            }
            const btnFullscreen = document.getElementById('btn-fullscreen');
            const btnMute = document.getElementById('btn-mute');

            const iconRevPlay = document.getElementById('icon-rev-play');
            const iconRevPause = document.getElementById('icon-rev-pause');
            const iconFwdPlay = document.getElementById('icon-fwd-play');
            const iconFwdPause = document.getElementById('icon-fwd-pause');
            const iconVolOn = document.getElementById('icon-vol-on');
            const iconVolOff = document.getElementById('icon-vol-off');

            const grabGrid = document.getElementById('zg-grab-grid');
            const emptyMsg = document.getElementById('zg-empty-msg');
            const seek = document.getElementById('zg-seek');
            const timelineMarkersEl = document.getElementById('zg-timeline-markers');
            const grabToast = document.getElementById('zg-grab-toast');
            const vol = document.getElementById('zg-volume');
            const speedIndicator = document.getElementById('zg-speed-indicator');
            const commentPanel = document.getElementById('zg-comment-panel');
            const commentThumb = document.getElementById('zg-comment-thumb');
            const commentTc = document.getElementById('zg-comment-tc');
            const commentText = document.getElementById('zg-comment-text');
            const btnDeleteDoodle = document.getElementById('zg-delete-doodle');
            const commentSave = document.getElementById('zg-comment-save'); // Add this line
            const commentStatus = document.getElementById('zg-comment-status');
            const btnFinalize = document.getElementById('btn-finalize');
            const itemsCountEl = document.getElementById('zg-items-count');
            const pdfBtn = document.getElementById('zg-pdf-btn');

            const finalizeModal = document.getElementById('zg-finalize-modal');
            const btnFinalizeCancel = document.getElementById('btn-finalize-cancel');
            const btnFinalizeConfirm = document.getElementById('btn-finalize-confirm');

            const doodleToolbar = document.getElementById('doodle-toolbar');

            // Prevent any clicks within the toolbar from reaching the video player
            doodleToolbar.addEventListener('click', (e) => {
                e.stopPropagation();
            });

            // Also stop mousedown to prevent accidental "drags" triggering play
            doodleToolbar.addEventListener('mousedown', (e) => {
                e.stopPropagation();
            });

            // Intro modal elements
            const introModal = document.getElementById('zg-intro-modal');
            const introDismiss = document.getElementById('zg-intro-dismiss');

            const speedSteps = [1, 2, 4, 8, 16, 32];
            let speedIndex = 0;
            let playMode = 'paused';
            let rewindRafId = null;
            let grabToastTimeout = null;
            let idleTimer = null;
            let grabs = Array.isArray(initialGrabs) ? [...initialGrabs] : [];
            let tcRafId = null;
            let activeGrabId = null;

            function getGrabById(id) {
                return grabs.find(g => Number(g.id) === Number(id)) || null;
            }

            function updateCommentPanelForGrab(grabOrId) {
                if (!commentPanel || !commentThumb || !commentText || !commentTc) return;

                const grab = (typeof grabOrId === 'object') ?
                    grabOrId :
                    getGrabById(grabOrId);

                if (!grab) return;

                activeGrabId = grab.id;

                commentThumb.src = grab.thumbnail_url || '';
                commentTc.textContent = grab.timecode || '';
                commentText.value = grab.note || '';

                if (commentStatus) {
                    commentStatus.textContent = '';
                }

                commentPanel.style.display = 'flex';
                // Show or hide the delete doodle button based on if a doodle exists
                if (grab.doodle_url) {
                    btnDeleteDoodle.style.display = 'inline-flex';
                } else {
                    btnDeleteDoodle.style.display = 'none';
                }
            }


            function updateHeaderUi() {
                if (itemsCountEl) {
                    const n = grabs.length;
                    itemsCountEl.textContent = n + (n === 1 ? ' item' : ' items');
                }
                if (pdfBtn) {
                    pdfBtn.style.display = grabs.length > 0 ? 'inline-flex' : 'none';
                }
            }

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

            // --- NEW ICON LOGIC (Class based) ---
            function updateIcons() {
                // Reset: show Plays, hide Pauses
                if (iconRevPlay) iconRevPlay.classList.remove('zg-d-none');
                if (iconRevPause) iconRevPause.classList.add('zg-d-none');
                if (iconFwdPlay) iconFwdPlay.classList.remove('zg-d-none');
                if (iconFwdPause) iconFwdPause.classList.add('zg-d-none');

                if (playMode === 'rev') {
                    // Reversing: Hide Play Rev, Show Pause Rev
                    if (iconRevPlay) iconRevPlay.classList.add('zg-d-none');
                    if (iconRevPause) iconRevPause.classList.remove('zg-d-none');
                } else if (playMode === 'fwd') {
                    // Forwarding: Hide Play Fwd, Show Pause Fwd
                    if (iconFwdPlay) iconFwdPlay.classList.add('zg-d-none');
                    if (iconFwdPause) iconFwdPause.classList.remove('zg-d-none');
                }
            }

            function updateVolumeIcon() {
                if (video.muted || video.volume === 0) {
                    if (iconVolOn) iconVolOn.classList.add('zg-d-none');
                    if (iconVolOff) iconVolOff.classList.remove('zg-d-none');
                } else {
                    if (iconVolOn) iconVolOn.classList.remove('zg-d-none');
                    if (iconVolOff) iconVolOff.classList.add('zg-d-none');
                }
            }

            function resetIdle() {
                if (shell) shell.classList.remove('zg-idle');
                if (idleTimer) clearTimeout(idleTimer);
                if (document.fullscreenElement && playMode !== 'paused') {
                    idleTimer = setTimeout(() => {
                        if (shell) shell.classList.add('zg-idle');
                    }, 2500);
                }
            }

            function stopAll() {
                video.pause();
                stopRewindLoop();
                video.playbackRate = 1;
                playMode = 'paused';
                speedIndex = 0;
                updateSpeedIndicator();
                updateIcons();
            }

            function handleL() {
                if (isPlaybackDisabled) return; // <-- Playback check
                if (playMode !== 'fwd') {
                    stopRewindLoop();
                    playMode = 'fwd';
                    speedIndex = 0;
                    video.playbackRate = 1;
                    video.play();
                } else {
                    speedIndex++;
                    if (speedIndex >= speedSteps.length) speedIndex = speedSteps.length - 1;
                    video.playbackRate = speedSteps[speedIndex];
                }
                updateSpeedIndicator();
                updateIcons();
                resetIdle();
            }

            function handleJ() {
                if (isPlaybackDisabled) return; // <-- Playback check
                if (playMode !== 'rev') {
                    video.pause();
                    playMode = 'rev';
                    speedIndex = 0;
                    startRewindLoop();
                } else {
                    speedIndex++;
                    if (speedIndex >= speedSteps.length) speedIndex = speedSteps.length - 1;
                }
                updateSpeedIndicator();
                updateIcons();
                resetIdle();
            }

            function handleK() {
                stopAll();
                resetIdle();
            }

            function togglePlaySpace() {
                if (isPlaybackDisabled) return; // <-- Playback check
                if (playMode === 'paused') handleL();
                else handleK();
            }

            function handleMute() {
                video.muted = !video.muted;
                if (video.muted) vol.value = 0;
                else vol.value = video.volume * 100;
                updateVolumeIcon();
            }

            function handleVolUp() {
                if (isPlaybackDisabled) return; // <-- Playback check
                if (video.muted) video.muted = false;
                let v = Math.min(100, (video.volume * 100) + 10);
                video.volume = v / 100;
                vol.value = v;
                updateVolumeIcon();
            }

            function handleVolDown() {
                if (isPlaybackDisabled) return; // <-- Playback check
                let v = Math.max(0, (video.volume * 100) - 10);
                video.volume = v / 100;
                vol.value = v;
                updateVolumeIcon();
            }

            function startRewindLoop() {
                if (rewindRafId !== null) return;
                let lastTime = performance.now();
                const stepLoop = (now) => {
                    if (playMode !== 'rev' || isPlaybackDisabled) { // <-- Playback check
                        rewindRafId = null;
                        return;
                    }
                    const dt = (now - lastTime) / 1000;
                    lastTime = now;
                    const multiplier = speedSteps[speedIndex] || 1;
                    const subtract = multiplier * dt;
                    video.currentTime = Math.max(0, video.currentTime - subtract);
                    updateTcDisplay();
                    updateSeek();
                    if (video.currentTime <= 0) stopAll();
                    else rewindRafId = requestAnimationFrame(stepLoop);
                };
                rewindRafId = requestAnimationFrame(stepLoop);
            }

            function stopRewindLoop() {
                if (rewindRafId !== null) {
                    cancelAnimationFrame(rewindRafId);
                    rewindRafId = null;
                }
            }

            function updateSpeedIndicator() {
                if (!speedIndicator) return;
                let s = speedSteps[speedIndex] || 1;
                if (playMode === 'paused') {
                    speedIndicator.textContent = 'Paused';
                    speedIndicator.style.opacity = '0.5';
                } else if (playMode === 'rev') {
                    speedIndicator.textContent = '-' + s + 'x';
                    speedIndicator.style.opacity = '1';
                } else {
                    speedIndicator.textContent = s + 'x';
                    speedIndicator.style.opacity = '1';
                }
            }

            let isResizing = false;
            if (resizer) {
                resizer.addEventListener('mousedown', function(e) {
                    isResizing = true;
                    resizer.classList.add('is-resizing');
                    document.body.style.cursor = 'col-resize';
                    document.body.style.userSelect = 'none';
                });
                document.addEventListener('mousemove', function(e) {
                    if (!isResizing) return;
                    const newWidth = e.clientX - 16;
                    if (newWidth > 200 && newWidth < window.innerWidth * 0.8) {
                        leftPane.style.width = newWidth + 'px';
                    }
                });
                document.addEventListener('mouseup', function() {
                    if (isResizing) {
                        isResizing = false;
                        resizer.classList.remove('is-resizing');
                        document.body.style.cursor = '';
                        document.body.style.userSelect = '';
                    }
                });
            }

            if (shell) {
                shell.addEventListener('mousemove', resetIdle);
                shell.addEventListener('click', (e) => {
                    if (
                        e.target.closest('.zg-controls') ||
                        e.target.closest('.zg-grab-badge') ||
                        e.target.closest('.zg-timeline') // <-- NEW: don't toggle on timeline clicks
                    ) {
                        return;
                    }
                    togglePlaySpace();
                });
            }

            function setVolumeFromSlider() {
                if (!vol || !video) return;
                const v = Math.min(100, Math.max(0, parseInt(vol.value || '100', 10)));
                video.volume = v / 100;
                video.muted = (v === 0);
                updateVolumeIcon();
            }
            if (vol) {
                vol.addEventListener('input', setVolumeFromSlider);
                setVolumeFromSlider();
            }
            if (btnMute) btnMute.addEventListener('click', handleMute);

            function tcLoop() {
                updateTcDisplay();
                updateSeek();
                tcRafId = requestAnimationFrame(tcLoop);
            }

            function startTcLoop() {
                if (tcRafId === null) tcRafId = requestAnimationFrame(tcLoop);
            }

            function stopTcLoop() {
                if (tcRafId !== null) {
                    cancelAnimationFrame(tcRafId);
                    tcRafId = null;
                }
            }

            function updateTcDisplay() {
                if (!isFinite(video.currentTime)) {
                    tcDisplay.textContent = '--:--:--:--';
                    return;
                }
                const currentFrames = Math.round(video.currentTime * fps);
                const absoluteFrame = startFrames + currentFrames;
                tcDisplay.textContent = framesToTc(absoluteFrame, fps);

                // --- CHECK FOR EXISTING DOODLE ---
                // Check if any of our grabs match this specific frame
                const matchingGrab = grabs.find(g => Number(g.frame_number) === absoluteFrame);
                const mainDeleteBtn = document.getElementById('zg-delete-doodle-main'); //

                if (matchingGrab) {
                    // Important: Set the global activeGrabId so performGrab() knows to UPDATE
                    activeGrabId = matchingGrab.id;

                    // Show the delete button ONLY if a doodle actually exists for this frame
                    if (mainDeleteBtn) {
                        mainDeleteBtn.style.display = matchingGrab.doodle_url ? 'inline-flex' : 'none';
                    }

                    // Only show overlay if a doodle actually exists for this frame and we aren't drawing
                    if (!isDoodleMode && matchingGrab.doodle_url) {
                        savedDoodleOverlay.src = matchingGrab.doodle_url;
                        savedDoodleOverlay.style.display = 'block';
                    } else {
                        savedDoodleOverlay.style.display = 'none';
                    }
                } else {
                    // Only clear the active selection if we aren't in the middle of doodling
                    if (!isDoodleMode) {
                        activeGrabId = null;
                        savedDoodleOverlay.style.display = 'none';
                        // Hide delete button if no grab exists on this frame
                        if (mainDeleteBtn) {
                            mainDeleteBtn.style.display = 'none';
                        }
                    }
                }
            }

            function updateSeek() {
                if (!seek) return;
                if (!isFinite(video.duration) || video.duration <= 0 || isPlaybackDisabled) {
                    seek.disabled = true;
                    return;
                }
                seek.disabled = false;
                seek.value = String(Math.round((video.currentTime / video.duration) * 1000));
            }


            video.addEventListener('timeupdate', () => {
                updateTcDisplay();
                updateSeek();
            });
            video.addEventListener('loadedmetadata', () => {
                updateTcDisplay();
                updateSeek();
                refreshTimelineMarkers();
                if (isPlaybackDisabled) { // If finalized, disable video element interaction immediately
                    video.controls = false;
                    video.removeAttribute('controls');
                    video.style.pointerEvents = 'none';
                    stopAll();
                }
            });


            video.addEventListener('play', () => {
                if (isPlaybackDisabled) { // <-- Playback check
                    video.pause();
                    stopAll();
                    return;
                }
                if (playMode !== 'rev') playMode = 'fwd';
                updateIcons();
                startTcLoop();
                resetIdle();
            });

            video.addEventListener('pause', () => {
                if (playMode !== 'rev') {
                    playMode = 'paused';
                    updateIcons();
                    stopTcLoop();
                }
                updateTcDisplay();
                updateSeek();
                if (shell) shell.classList.remove('zg-idle');
            });

            video.addEventListener('ended', stopAll);

            if (btnPlayRev) btnPlayRev.addEventListener('click', () => {
                if (isPlaybackDisabled) return; // <-- Playback check
                if (playMode === 'rev') handleK();
                else handleJ();
            });
            if (btnPlayFwd) btnPlayFwd.addEventListener('click', () => {
                if (isPlaybackDisabled) return; // <-- Playback check
                if (playMode === 'fwd') handleK();
                else handleL();
            });

            if (btnRewind) btnRewind.addEventListener('click', handleJ);
            if (btnFfwd) btnFfwd.addEventListener('click', handleL);

            btnBack.addEventListener('click', () => {
                if (isPlaybackDisabled) return; // <-- Playback check
                stopAll();
                video.currentTime = Math.max(0, video.currentTime - (1 / fps));
            });
            btnFwd.addEventListener('click', () => {
                if (isPlaybackDisabled) return; // <-- Playback check
                stopAll();
                video.currentTime += (1 / fps);
            });

            function focusGrabInList(grabId) {
                activeGrabId = grabId;

                const el = grabGrid.querySelector(`[data-id="${grabId}"]`);
                if (!el) return;

                grabGrid.querySelectorAll('.zg-grab-active')
                    .forEach(x => x.classList.remove('zg-grab-active'));

                el.classList.add('zg-grab-active');

                el.scrollIntoView({
                    behavior: 'smooth',
                    block: 'center'
                });
            }


            function jumpToGrab(direction) {
                if (!grabs.length || !fps || !isFinite(video.duration)) return;

                const sorted = [...grabs].sort((a, b) => a.frame_number - b.frame_number);
                const currentFrames = startFrames + Math.round(video.currentTime * fps);

                let target = null;

                if (direction === 'prev') {
                    // last grab strictly before current frame; wrap to last if none
                    for (let i = sorted.length - 1; i >= 0; i--) {
                        if (sorted[i].frame_number < currentFrames) {
                            target = sorted[i];
                            break;
                        }
                    }
                    if (!target) {
                        target = sorted[sorted.length - 1];
                    }
                } else if (direction === 'next') {
                    // first grab strictly after current frame; wrap to first if none
                    for (let i = 0; i < sorted.length; i++) {
                        if (sorted[i].frame_number > currentFrames) {
                            target = sorted[i];
                            break;
                        }
                    }
                    if (!target) {
                        target = sorted[0];
                    }
                }

                if (!target) return;

                stopAll();
                const relFrames = target.frame_number - startFrames;
                const seconds = Math.max(0, relFrames / fps);
                video.currentTime = seconds;
                updateTcDisplay();
                updateSeek();

                focusGrabInList(target.id);
                updateCommentPanelForGrab(target);
            }

            if (btnPrevGrab) {
                btnPrevGrab.addEventListener('click', () => {
                    if (isPlaybackDisabled) return;
                    jumpToGrab('prev');
                });
            }

            if (btnNextGrab) {
                btnNextGrab.addEventListener('click', () => {
                    if (isPlaybackDisabled) return;
                    jumpToGrab('next');
                });
            }


            // Disable buttons if finalized
            if (isPlaybackDisabled) {
                [
                    btnPlayRev,
                    btnPlayFwd,
                    btnRewind,
                    btnFfwd,
                    btnBack,
                    btnFwd,
                    btnPrevGrab,
                    btnNextGrab,
                    btnGrab,
                    btnFullscreen,
                    btnMute
                ].forEach(btn => {
                    if (btn) btn.disabled = true;
                });
            }



            if (btnFullscreen) {
                btnFullscreen.addEventListener('click', () => {
                    if (isPlaybackDisabled) return; // <-- Playback check
                    const el = document.querySelector('.zg-player-shell') || video;
                    if (!document.fullscreenElement) {
                        if (el.requestFullscreen) el.requestFullscreen().catch(() => {});
                    } else {
                        if (document.exitFullscreen) document.exitFullscreen().catch(() => {});
                    }
                });
            }

            if (seek) {
                seek.addEventListener('input', () => {
                    if (isPlaybackDisabled) return; // <-- Playback check
                    stopAll();
                    if (!isFinite(video.duration) || video.duration <= 0) return;
                    video.currentTime = (parseInt(seek.value, 10) / 1000) * video.duration;
                    updateTcDisplay();
                    updateSeek();
                });
            }


            document.addEventListener('keydown', (e) => {
                const tag = (e.target.tagName || '').toUpperCase();
                const isTypingField = (tag === 'INPUT' && e.target.type !== 'range') || tag === 'TEXTAREA' || e.target.isContentEditable;
                if (isTypingField || isPlaybackDisabled) return; // <-- Playback check for keyboard

                switch (e.code) {
                    case 'Space':
                        e.preventDefault();
                        togglePlaySpace();
                        break;
                    case 'KeyK':
                        e.preventDefault();
                        handleK();
                        break;
                    case 'KeyL':
                        e.preventDefault();
                        handleL();
                        break;
                    case 'KeyJ':
                        e.preventDefault();
                        handleJ();
                        break;
                    case 'KeyM':
                        e.preventDefault();
                        handleMute();
                        break;
                    case 'ArrowUp':
                        e.preventDefault();
                        handleVolUp();
                        break;
                    case 'ArrowDown':
                        e.preventDefault();
                        handleVolDown();
                        break;
                    case 'KeyF':
                        e.preventDefault();
                        btnFullscreen?.click();
                        break;
                    case 'KeyG':
                        e.preventDefault();
                        performGrab();
                        break;
                    case 'KeyD':
                        e.preventDefault();
                        btnDoodle.click();
                        break;
                    case 'KeyC':
                        if (isDoodleMode) {
                            e.preventDefault();
                            ctxOverlay.clearRect(0, 0, canvasOverlay.width, canvasOverlay.height);
                            doodleHistory = []; // Reset history on clear
                        }
                        break;
                    case 'KeyZ':
                        if (isDoodleMode && (e.ctrlKey || e.metaKey)) {
                            e.preventDefault();
                            if (e.shiftKey) redoDoodle(); // Ctrl+Shift+Z
                            else undoDoodle(); // Ctrl+Z
                        }
                        break;
                    case 'KeyY':
                        if (isDoodleMode && (e.ctrlKey || e.metaKey)) {
                            e.preventDefault();
                            redoDoodle(); // Ctrl+Y
                        }
                        break;
                    case 'ArrowLeft':
                        e.preventDefault();
                        stopAll();
                        video.currentTime = Math.max(0, video.currentTime - (1 / fps));
                        break;
                    case 'ArrowRight':
                        e.preventDefault();
                        stopAll();
                        video.currentTime += (1 / fps);
                        break;
                    case 'Escape':
                        if (introModal.style.display === 'flex') {
                            introModal.style.display = 'none';
                        }
                        if (finalizeModal.style.display === 'flex') {
                            finalizeModal.style.display = 'none';
                        }
                        // Also useful for closing the doodle mode quickly
                        if (isDoodleMode) {
                            btnDoodle.click();
                        }
                        break;
                }
            });

            // zengrabber/grab.php

            function showGrabToast() {
                if (!grabToast || !shell) return;

                // Do NOT wake the UI: just add a dedicated toast state on the shell
                shell.classList.add('zg-toast-visible');

                // Inline styles are optional now; CSS drives the main effect
                grabToast.style.opacity = '1';
                grabToast.style.transform = 'translateY(0)';

                if (grabToastTimeout) clearTimeout(grabToastTimeout);
                grabToastTimeout = setTimeout(() => {
                    grabToast.style.opacity = '';
                    grabToast.style.transform = '';
                    shell.classList.remove('zg-toast-visible');
                }, 900);
            }

            let currentUserNickname = localStorage.getItem('zr_user_name') || '';

            function checkIdentity() {
                const nameModal = document.getElementById('zr-name-modal');
                const nameInput = document.getElementById('zr-nickname-input');
                const nameSave = document.getElementById('zr-nickname-save');
                const userDisplay = document.getElementById('zr-current-user-display');
                const switchBtn = document.getElementById('zr-switch-user'); //

                // Helper to open the naming modal
                const openNamingModal = () => {
                    nameModal.style.display = 'flex';
                    nameInput.value = currentUserNickname; // Pre-fill with existing name for editing
                    nameInput.focus();
                    nameInput.select();
                };

                // 1. Setup the "Switch" button listener
                if (switchBtn) {
                    switchBtn.onclick = (e) => {
                        e.stopPropagation();
                        openNamingModal();
                    };
                }

                // 2. If we already have a name, update the header immediately
                if (currentUserNickname && userDisplay) {
                    userDisplay.textContent = currentUserNickname;
                }

                // 3. If no name exists and it's not finalized, force the modal
                if (!currentUserNickname && !isPlaybackDisabled) {
                    openNamingModal();
                }

                // 4. Handle Saving the Name
                const saveName = () => {
                    const val = nameInput.value.trim();
                    if (val.length >= 2) {
                        currentUserNickname = val;
                        localStorage.setItem('zr_user_name', val);
                        nameModal.style.display = 'none';

                        if (userDisplay) {
                            userDisplay.textContent = val;
                        }
                        showGrabToast();
                    }
                };

                nameSave.onclick = saveName;
                nameInput.onkeydown = (e) => {
                    if (e.key === 'Enter') saveName();
                    // Allow escaping out if they already have a name saved
                    if (e.key === 'Escape' && currentUserNickname) {
                        nameModal.style.display = 'none';
                    }
                };
            }

            // Call this right after setupIntroModal();
            checkIdentity();

            function refreshTimelineMarkers() {
                try {
                    if (!timelineMarkersEl || !video || !fps) return;

                    timelineMarkersEl.innerHTML = '';

                    if (!grabs || !grabs.length) return;
                    if (!isFinite(video.duration) || video.duration <= 0) return;

                    const duration = video.duration;

                    grabs.forEach((g) => {
                        const gf = Number(g.frame_number);
                        if (!Number.isFinite(gf)) return;

                        const relFrames = gf - startFrames;
                        if (!Number.isFinite(relFrames) || relFrames < 0) return;

                        const seconds = relFrames / fps;
                        if (!Number.isFinite(seconds) || seconds < 0) return;

                        const pct = Math.min(100, Math.max(0, (seconds / duration) * 100));

                        const mark = document.createElement('div');
                        mark.className = 'zg-timeline-marker' + (g.doodle_url ? ' has-doodle' : '');
                        mark.style.left = pct + '%';
                        if (g.doodle_url) {
                            mark.title = "Frame has annotation";
                        }
                        timelineMarkersEl.appendChild(mark);
                    });
                } catch (e) {
                    console.error('refreshTimelineMarkers error:', e);
                }
            }


            function renderGrabs(targetId = null, flashNew = false) {
                grabGrid.innerHTML = '';
                if (!grabs.length) {
                    if (emptyMsg) {
                        emptyMsg.style.display = 'block';
                        grabGrid.appendChild(emptyMsg);
                    }
                    return;
                }
                if (emptyMsg) emptyMsg.style.display = 'none';

                grabs.sort((a, b) => b.frame_number - a.frame_number);

                grabs.forEach((g, idx) => {
                    const li = document.createElement('li');
                    li.className = 'zg-grab-card';
                    li.dataset.id = g.id;

                    if (activeGrabId !== null && Number(g.id) === Number(activeGrabId)) {
                        li.classList.add('zg-grab-active');
                    }

                    const media = document.createElement('div');
                    media.className = 'zg-grab-media';
                    const img = document.createElement('img');
                    img.className = 'zg-grab-thumb';
                    img.src = g.thumbnail_url;
                    const badge = document.createElement('div');
                    badge.className = 'zg-grab-badge';
                    badge.textContent = '#' + (grabs.length - idx);
                    media.appendChild(img);
                    media.appendChild(badge);

                    // Add Doodle Indicator Badge
                    // Add Doodle Indicator with Quick-Delete
                    if (g.doodle_url) {
                        const dBadge = document.createElement('div');
                        dBadge.className = 'zg-doodle-indicator';
                        dBadge.title = "";

                        // Using simple SVG/Text for icons
                        dBadge.innerHTML = `
                            <span class="icon-pencil">✎</span>
                            <span class="icon-trash" style="font-size: 12px;">🗑</span>
                        `;

                        // Add the click listener specifically for this badge
                        dBadge.addEventListener('click', (e) => {
                            e.stopPropagation(); // Don't trigger the "Jump to Frame" click
                            deleteDoodleOnly(g.id);
                        });

                        media.appendChild(dBadge);
                    }

                    media.addEventListener('click', () => {
                        if (!fps || !video || isPlaybackDisabled) return; // <-- Playback check
                        stopAll();
                        const grabFrames = Number(g.frame_number) || 0;
                        const relativeFrames = grabFrames - startFrames;
                        video.currentTime = Math.max(0, relativeFrames / fps);
                        updateTcDisplay();
                        updateSeek();
                        focusGrabInList(g.id);
                        updateCommentPanelForGrab(g);
                    });

                    const info = document.createElement('div');
                    info.className = 'zg-grab-info';

                    const header = document.createElement('div');
                    header.className = 'zg-grab-header';
                    // Horizontal layout to keep TC/Name and Trash on one line
                    header.style.display = 'flex';
                    header.style.flexDirection = 'row';
                    header.style.justifyContent = 'space-between';
                    header.style.alignItems = 'flex-start';

                    // Create a sub-container for the text metadata
                    const textStack = document.createElement('div');
                    textStack.style.display = 'flex';
                    textStack.style.flexDirection = 'column';

                    const tc = document.createElement('span');
                    tc.className = 'zg-grab-tc';
                    tc.textContent = g.timecode;
                    textStack.appendChild(tc);

                    const author = document.createElement('div');
                    author.className = 'zg-grab-author';
                    author.style.cssText = 'font-size: 0.7rem; color: var(--zg-accent); opacity: 0.8; margin-top: 2px; line-height: 1;';
                    author.textContent = g.created_by_name || 'Anonymous';
                    textStack.appendChild(author);

                    header.appendChild(textStack); // Add text to the left

                    // Add the delete button to the right
                    if (!isFinalized) {
                        const delBtn = document.createElement('button');
                        delBtn.className = 'zg-btn-icon-danger';
                        delBtn.style.marginTop = '2px'; // Align slightly with the TC top
                        delBtn.innerHTML = '<svg width="14" height="14" fill="currentColor" viewBox="0 0 16 16"><path d="M5.5 5.5A.5.5 0 0 1 6 6v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5zm2.5 0a.5.5 0 0 1 .5.5v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5zm3 .5a.5.5 0 0 0-1 0v6a.5.5 0 0 0 1 0V6z"/><path fill-rule="evenodd" d="M14.5 3a1 1 0 0 1-1 1H13v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V4h-.5a1 1 0 0 1-1-1V2a1 1 0 0 1 1-1H6a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1h3.5a1 1 0 0 1 1 1v1zM4.118 4 4 4.059V13a1 1 0 0 0 1 1h6a1 1 0 0 0 1-1V4.059L11.882 4H4.118zM2.5 3V2h11v1h-11z"/></svg>';
                        delBtn.onclick = () => deleteGrab(g.id);
                        header.appendChild(delBtn);
                    }

                    info.appendChild(header);
                    if (g.note) {
                        const note = document.createElement('div');
                        note.className = 'zg-grab-note';
                        note.textContent = g.note;
                        info.appendChild(note);
                    }
                    li.appendChild(media);
                    li.appendChild(info);
                    grabGrid.appendChild(li);
                });

                if (targetId && flashNew) {
                    setTimeout(() => {
                        const el = grabGrid.querySelector(`[data-id="${targetId}"]`);
                        if (el) {
                            el.scrollIntoView({
                                behavior: 'smooth',
                                block: 'center'
                            });
                            el.classList.add('zg-flash-new');
                        }
                    }, 50);
                }
                // Keep header counter + PDF button in sync
                updateHeaderUi();
                refreshTimelineMarkers();

            }

            const btnReply = document.getElementById('zg-comment-reply');

            const replyModal = document.getElementById('zr-reply-modal');
            const replyInput = document.getElementById('zr-reply-input');
            const btnReplyCancel = document.getElementById('zr-reply-cancel');
            const btnReplySend = document.getElementById('zr-reply-send');
            const replyContext = document.getElementById('zr-reply-context');

            if (btnReply && !isFinalized) {
                btnReply.addEventListener('click', () => {
                    if (!activeGrabId) return;
                    const grab = getGrabById(activeGrabId);

                    // Show context (who you are replying as)
                    replyContext.textContent = `Replying as ${currentUserNickname} to TC ${grab.timecode}`;
                    replyInput.value = '';
                    replyModal.style.display = 'flex';
                    replyInput.focus();
                });
            }

            // Modal controls
            btnReplyCancel.onclick = () => {
                replyModal.style.display = 'none';
            };

            btnReplySend.onclick = async () => {
                const text = replyInput.value.trim();
                if (!text || !activeGrabId) return;

                // Use 24h clock for the timestamp
                const now = new Date();
                const timestamp = now.getHours().toString().padStart(2, '0') + ':' +
                    now.getMinutes().toString().padStart(2, '0');

                const formattedReply = `\n\n[${timestamp}] ${currentUserNickname}: ${text}`;
                const grab = getGrabById(activeGrabId);
                const updatedNote = (grab.note || '') + formattedReply;

                // Send to API
                const fd = new FormData();
                fd.append('token', token);
                fd.append('grab_id', activeGrabId);
                fd.append('note', updatedNote);

                try {
                    const res = await fetch('api_grab_update_note.php', {
                        method: 'POST',
                        body: fd
                    });
                    const j = await res.json();
                    if (j.ok) {
                        grab.note = updatedNote;
                        commentText.value = updatedNote;
                        renderGrabs(activeGrabId, false);
                        replyModal.style.display = 'none';
                        showGrabToast();
                    }
                } catch (e) {
                    console.error('Reply failed', e);
                }
            };

            // Keyboard support for the modal
            replyInput.onkeydown = (e) => {
                if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
                    btnReplySend.click();
                }
                if (e.key === 'Escape') {
                    btnReplyCancel.click();
                }
            };

            async function deleteDoodleOnly(grabId) {
                const g = getGrabById(grabId);
                if (!g) return;

                // Use our custom modal instead of window.confirm
                const confirmed = await zrConfirm('Delete Doodle?', 'This will permanently remove the drawing layer from this frame.');
                if (!confirmed) return;

                const fd = new FormData();
                fd.append('token', token);
                fd.append('grab_id', grabId);

                // Capture clean frame logic
                const currentFrames = Math.round(video.currentTime * fps);
                if (Number(g.frame_number) === (startFrames + currentFrames)) {
                    const c = document.createElement('canvas');
                    const scale = Math.min(1, 640 / video.videoWidth);
                    c.width = video.videoWidth * scale;
                    c.height = video.videoHeight * scale;
                    const ctx = c.getContext('2d');
                    ctx.drawImage(video, 0, 0, c.width, c.height);
                    fd.append('clean_image_data', c.toDataURL('image/jpeg', 0.8));
                }

                try {
                    const res = await fetch('api_grab_delete_doodle.php', {
                        method: 'POST',
                        body: fd
                    });
                    const j = await res.json();

                    if (j.ok) {
                        if (g) {
                            g.doodle_url = null;
                            if (j.thumbnail_url) {
                                g.thumbnail_url = j.thumbnail_url + '?v=' + Date.now();
                            }
                        }
                        renderGrabs();
                        updateTcDisplay(); // Refresh buttons/overlays
                        showGrabToast();
                    }
                } catch (e) {
                    console.error('Error deleting doodle:', e);
                }
            }

            async function deleteGrab(grabId) {
                if (isFinalized || isPlaybackDisabled) return;

                // Look up grab
                const idx = grabs.findIndex(g => Number(g.id) === Number(grabId));
                if (idx === -1) return;

                // Save current state in case we need to revert
                const previous = [...grabs];

                // If active grab is removed, clear active selection
                if (activeGrabId !== null && Number(activeGrabId) === Number(grabId)) {
                    activeGrabId = null;
                }

                // Remove immediately from UI (optimistic)
                grabs.splice(idx, 1);
                renderGrabs();

                try {
                    const fd = new FormData();
                    fd.append('token', token);
                    // If we are currently viewing a grab, tell the API to overwrite it
                    if (activeGrabId) {
                        fd.append('grab_id', activeGrabId);
                    }
                    fd.append('grab_id', grabId);

                    const res = await fetch('api_grab_delete.php', {
                        method: 'POST',
                        body: fd
                    });
                    const json = await res.json();

                    if (!json.ok) {
                        console.error('Delete failed:', json.error);
                        grabs = previous;
                        renderGrabs();
                    }
                } catch (err) {
                    console.error('Network error during delete:', err);
                    grabs = previous;
                    renderGrabs();
                }
            }



            async function performGrab() {
                if (isFinalized || isPlaybackDisabled || !video.videoWidth) return;

                // 1. Create High-Res Doodle PNG (Matches Video Resolution)
                const fullCanvas = document.createElement('canvas');
                fullCanvas.width = video.videoWidth;
                fullCanvas.height = video.videoHeight;
                const fullCtx = fullCanvas.getContext('2d');

                // 2. Create Low-Res Thumbnail
                const c = document.createElement('canvas');
                const scale = Math.min(1, 640 / video.videoWidth);
                c.width = video.videoWidth * scale;
                c.height = video.videoHeight * scale;
                const ctx = c.getContext('2d');
                ctx.drawImage(video, 0, 0, c.width, c.height);

                let doodleData = null;
                if (isDoodleMode) {
                    // 1. Draw doodle on the HIGH-RES canvas for the transparent PNG
                    fullCtx.drawImage(canvasOverlay, 0, 0, fullCanvas.width, fullCanvas.height);
                    doodleData = fullCanvas.toDataURL('image/png');

                    // 2. Draw doodle on the THUMBNAIL canvas so it's visible in the sidebar list
                    ctx.drawImage(canvasOverlay, 0, 0, c.width, c.height);

                    // Reset doodle mode and clear the drawing surface
                    isDoodleMode = false;
                    document.getElementById('video-wrap').classList.remove('doodle-active');
                    btnDoodle.classList.remove('active');
                    ctxOverlay.clearRect(0, 0, canvasOverlay.width, canvasOverlay.height);
                    doodleHistory = [];
                }

                const curF = Math.round(video.currentTime * fps);
                const totF = startFrames + curF;

                const oldHtml = btnGrab.innerHTML;
                btnGrab.innerHTML = '...';
                btnGrab.disabled = true;

                try {
                    const fd = new FormData();
                    fd.append('token', token);
                    fd.append('frame_number', totF);
                    fd.append('timecode', framesToTc(totF, fps));
                    fd.append('image_data', c.toDataURL('image/jpeg', 0.8));
                    fd.append('created_by_name', currentUserNickname || 'Anonymous');
                    if (doodleData) {
                        fd.append('doodle_data', doodleData);
                    }

                    const res = await fetch('api_grab_create.php', {
                        method: 'POST',
                        body: fd
                    });
                    const j = await res.json();
                    if (j.ok && j.grab) {
                        const idx = grabs.findIndex(x => Number(x.id) === Number(j.grab.id));
                        if (idx !== -1) {
                            // Update existing
                            grabs[idx] = j.grab;
                        } else {
                            // Add new
                            grabs.push(j.grab);
                        }

                        renderGrabs(j.grab.id, true);

                        // Force UI to show the new doodle overlay immediately
                        activeGrabId = j.grab.id;
                        updateTcDisplay();
                        updateCommentPanelForGrab(j.grab);

                        showGrabToast();
                    }
                } catch (e) {
                    console.error(e);
                } finally {
                    btnGrab.innerHTML = oldHtml;
                    btnGrab.disabled = false;
                }
            }

            btnGrab.addEventListener('click', (e) => {
                e.stopPropagation(); // Stop bubbling so the shell doesn't toggle play
                performGrab();
            });

            function applyDoodleStyles() {
                if (!ctxOverlay) return;
                ctxOverlay.globalAlpha = doodleOpacityInput.value / 100; // Active line transparency
                ctxOverlay.strokeStyle = currentDoodleColor;
                ctxOverlay.lineWidth = doodleSizeInput.value;
                ctxOverlay.lineJoin = 'round';
                ctxOverlay.lineCap = 'round';
            }

            // Listen for slider changes
            doodleSizeInput.addEventListener('input', applyDoodleStyles);
            doodleOpacityInput.addEventListener('input', applyDoodleStyles);

            // Listen for color changes
            colorDots.forEach(dot => {
                dot.addEventListener('click', (e) => {
                    e.stopPropagation();
                    colorDots.forEach(d => d.classList.remove('active'));
                    dot.classList.add('active');
                    currentDoodleColor = dot.dataset.color;
                    applyDoodleStyles();
                });
            });

            // Doodle Toggle Logic
            btnDoodle.addEventListener('click', (e) => {
                e.stopPropagation();
                isDoodleMode = !isDoodleMode;

                // This toggle triggers the CSS slide-down for the toolbar
                document.getElementById('video-wrap').classList.toggle('doodle-active', isDoodleMode);
                btnDoodle.classList.toggle('active', isDoodleMode);

                if (isDoodleMode) {
                    stopAll();

                    // Hide any existing saved doodles while drawing a new one
                    if (savedDoodleOverlay) savedDoodleOverlay.style.display = 'none';

                    // Sync the drawing surface to the video's current visual dimensions
                    const rect = video.getBoundingClientRect();
                    canvasOverlay.width = rect.width;
                    canvasOverlay.height = rect.height;

                    // Apply styles from the sliding toolbar
                    applyDoodleStyles();
                    updateSaveButtonState();
                } else {
                    // Clear the scratchpad and history when closing the tools
                    ctxOverlay.clearRect(0, 0, canvasOverlay.width, canvasOverlay.height);
                    doodleHistory = [];
                    updateSaveButtonState();
                }
            });

            // Drawing Events
            canvasOverlay.addEventListener('mousedown', (e) => {
                e.stopPropagation();
                isDoodling = true;

                // Start a new stroke and record the first point
                currentStrokePoints = [{
                    x: e.offsetX,
                    y: e.offsetY
                }];

                applyDoodleStyles();
            });

            canvasOverlay.addEventListener('mousemove', (e) => {
                if (!isDoodling) return;

                currentStrokePoints.push({
                    x: e.offsetX,
                    y: e.offsetY
                });

                // 1. Clear the canvas
                ctxOverlay.clearRect(0, 0, canvasOverlay.width, canvasOverlay.height);

                // 2. Redraw History at FULL opacity
                // We temporarily reset alpha to 1 so the background doesn't get more transparent
                ctxOverlay.save();
                ctxOverlay.globalAlpha = 1.0;
                if (doodleHistory.length > 0) {
                    const img = new Image();
                    img.src = doodleHistory[doodleHistory.length - 1];
                    ctxOverlay.drawImage(img, 0, 0);
                }
                ctxOverlay.restore(); // Restore back to the user's selected opacity

                // 3. Draw the current stroke with the active opacity
                ctxOverlay.beginPath();
                ctxOverlay.moveTo(currentStrokePoints[0].x, currentStrokePoints[0].y);
                for (let i = 1; i < currentStrokePoints.length; i++) {
                    ctxOverlay.lineTo(currentStrokePoints[i].x, currentStrokePoints[i].y);
                }
                ctxOverlay.stroke();
            });

            // Prevent simple clicks on the canvas from triggering the player shell's togglePlay
            canvasOverlay.addEventListener('click', (e) => {
                e.stopPropagation();
            });

            window.addEventListener('mouseup', () => {
                if (isDoodling) {
                    isDoodling = false;
                    saveDoodleState(); // This saves the full canvas including the new smooth stroke
                    currentStrokePoints = [];
                }
            });

            function saveDoodleState() {
                if (doodleHistory.length >= maxHistory) doodleHistory.shift();
                doodleHistory.push(canvasOverlay.toDataURL());
                redoHistory = [];

                // Enable the save button now that there is content
                updateSaveButtonState();
            }

            function updateSaveButtonState() {
                if (!btnDoodleSave) return;
                // Button is disabled if history is empty
                btnDoodleSave.disabled = (doodleHistory.length === 0);
            }

            function undoDoodle() {
                if (doodleHistory.length === 0) return;

                // Move the current state to the redo stack
                const currentState = doodleHistory.pop();
                redoHistory.push(currentState);

                ctxOverlay.clearRect(0, 0, canvasOverlay.width, canvasOverlay.height);

                // Draw the previous state if it exists
                if (doodleHistory.length > 0) {
                    const img = new Image();
                    img.src = doodleHistory[doodleHistory.length - 1];
                    img.onload = () => {
                        ctxOverlay.save();
                        ctxOverlay.clearRect(0, 0, canvasOverlay.width, canvasOverlay.height);
                        ctxOverlay.globalAlpha = 1.0;
                        ctxOverlay.drawImage(img, 0, 0);
                        ctxOverlay.restore();
                    };
                }

                // Update button state after popping from history
                updateSaveButtonState();
            }

            function redoDoodle() {
                if (redoHistory.length === 0) return;

                const nextState = redoHistory.pop();
                doodleHistory.push(nextState);

                const img = new Image();
                img.src = nextState;
                img.onload = () => {
                    ctxOverlay.save();
                    ctxOverlay.clearRect(0, 0, canvasOverlay.width, canvasOverlay.height);
                    ctxOverlay.globalAlpha = 1.0;
                    ctxOverlay.drawImage(img, 0, 0);
                    ctxOverlay.restore();
                };

                // Update button state after pushing back to history
                updateSaveButtonState();
            }

            // Button listeners
            btnUndo.addEventListener('click', (e) => {
                e.stopPropagation();
                undoDoodle();
            });
            btnRedo.addEventListener('click', (e) => {
                e.stopPropagation();
                redoDoodle();
            });
            btnClear.addEventListener('click', (e) => {
                e.stopPropagation();
                ctxOverlay.clearRect(0, 0, canvasOverlay.width, canvasOverlay.height);
                doodleHistory = [];
                redoHistory = [];
                // Immediately grey out the button as the canvas is now empty
                updateSaveButtonState();
            });

            // Point the comment panel button to our unified helper
            const btnDeleteMain = document.getElementById('zg-delete-doodle-main');
            if (btnDeleteMain) {
                btnDeleteMain.addEventListener('click', (e) => {
                    e.stopPropagation();
                    if (activeGrabId) {
                        deleteDoodleOnly(activeGrabId);
                    }
                });
            }

            async function saveComment() {
                if (!commentText || !commentSave || !activeGrabId) return;
                if (isFinalized) return; // no edits after finalize

                const note = commentText.value.trim();
                const originalLabel = commentSave.textContent;
                commentSave.disabled = true;
                commentSave.textContent = 'Saving…';
                if (commentStatus) commentStatus.textContent = '';

                try {
                    const fd = new FormData();
                    fd.append('token', token);
                    fd.append('grab_id', activeGrabId);
                    fd.append('note', note);

                    const res = await fetch('api_grab_update_note.php', {
                        method: 'POST',
                        body: fd
                    });
                    const j = await res.json();

                    if (!j.ok) {
                        console.error('Could not save comment', j.error || '');
                        if (commentStatus) commentStatus.textContent = 'Could not save';
                        return;
                    }

                    // Replace the local grab data with the fresh data from the server
                    const idx = grabs.findIndex(x => Number(x.id) === Number(activeGrabId));
                    if (idx !== -1 && j.grab) {
                        grabs[idx] = j.grab;
                    }
                    renderGrabs(activeGrabId, false);

                    if (commentStatus) commentStatus.textContent = 'Saved';
                } catch (e) {
                    console.error(e);
                    if (commentStatus) commentStatus.textContent = 'Error saving';
                } finally {
                    commentSave.disabled = false;
                    commentSave.textContent = originalLabel;
                    setTimeout(() => {
                        if (commentStatus && commentStatus.textContent === 'Saved') {
                            commentStatus.textContent = '';
                        }
                    }, 2000);
                }
            }

            if (commentSave && !isFinalized) {
                commentSave.addEventListener('click', saveComment);
            }

            if (!isFinalized && btnFinalize && finalizeModal) {
                btnFinalize.addEventListener('click', () => {
                    finalizeModal.style.display = 'flex';
                });

                btnFinalizeCancel.addEventListener('click', () => {
                    finalizeModal.style.display = 'none';
                });
                btnFinalizeConfirm.addEventListener('click', async () => {
                    btnFinalizeConfirm.disabled = true;
                    btnFinalizeConfirm.textContent = 'Finalizing...';
                    try {
                        const fd = new FormData();
                        fd.append('token', token);
                        const res = await fetch('api_finalize_invite.php', {
                            method: 'POST',
                            body: fd
                        });
                        const json = await res.json();
                        if (!json.ok) {
                            // Use a message box instead of alert()
                            // alert('Could not finalize.');
                            console.error('Could not finalize.');
                            return;
                        }
                        window.location.reload();
                    } catch (err) {
                        // Use a message box instead of alert()
                        // alert('Network error');
                        console.error('Network error during finalization:', err);
                    } finally {
                        btnFinalizeConfirm.disabled = false;
                    }
                });
            }

            /**
             * Custom Promise-based confirmation modal
             * @param {string} title
             * @param {string} message
             * @returns {Promise<boolean>}
             */
            function zrConfirm(title, message) {
                return new Promise((resolve) => {
                    const modal = document.getElementById('zr-confirm-modal');
                    const titleEl = document.getElementById('zr-confirm-title');
                    const msgEl = document.getElementById('zr-confirm-msg');
                    const btnOk = document.getElementById('zr-confirm-ok');
                    const btnCancel = document.getElementById('zr-confirm-cancel');

                    titleEl.textContent = title;
                    msgEl.textContent = message;
                    modal.style.display = 'flex';

                    const cleanup = (result) => {
                        modal.style.display = 'none';
                        window.removeEventListener('keydown', handleKey);
                        resolve(result);
                    };

                    const handleKey = (e) => {
                        if (e.key === 'Enter') {
                            e.preventDefault();
                            cleanup(true);
                        }
                        if (e.key === 'Escape') {
                            e.preventDefault();
                            cleanup(false);
                        }
                    };

                    btnOk.onclick = () => cleanup(true);
                    btnCancel.onclick = () => cleanup(false);
                    window.addEventListener('keydown', handleKey);
                });
            }

            // --- Intro modal: show every time UNLESS finalized ---
            function setupIntroModal() {
                if (!introModal || !introDismiss) return;

                // 1. Define our pool of Zentropa Review Pro Tips
                const tips = [
                    "<strong style='color:var(--zg-accent); margin-right:6px;'>Pro Tip:</strong> Doodles are saved as high-res transparent PNGs. You can update a doodle by drawing over an existing grab and hitting <strong>Save</strong> again.",
                    "<strong style='color:var(--zg-accent); margin-right:6px;'>Pro Tip:</strong> Use <strong>Ctrl + Z</strong> and <strong>Ctrl + Y</strong> to quickly undo or redo your last drawing strokes.",
                    "<strong style='color:var(--zg-accent); margin-right:6px;'>Pro Tip:</strong> You can change the brush <strong>Opacity</strong> to use colors as highlighters over the footage.",
                    "<strong style='color:var(--zg-accent); margin-right:6px;'>Pro Tip:</strong> Click any grab in the sidebar to jump the player exactly to that frame for a closer look.",
                    "<strong style='color:var(--zg-accent); margin-right:6px;'>Pro Tip:</strong> Finalizing the list is permanent. Make sure you've added all your notes before hitting the button.",
                    "<strong style='color:var(--zg-accent); margin-right:6px;'>Pro Tip:</strong> You can delete just the doodle layer of a grab without deleting your text comment using the <strong>Delete Doodle</strong> button."
                ];

                // 2. Pick a random tip
                const randomTip = tips[Math.floor(Math.random() * tips.length)];
                const tipBox = document.getElementById('zr-pro-tip-box');
                if (tipBox) {
                    tipBox.innerHTML = randomTip;
                }

                // 3. Show every time (removed localStorage check per your request)
                if (!isPlaybackDisabled) {
                    introModal.style.display = 'flex';
                }

                introDismiss.addEventListener('click', () => {
                    introModal.style.display = 'none';
                });
            }
            setupIntroModal();


            document.addEventListener('contextmenu', event => event.preventDefault());

            updateIcons();
            renderGrabs();
        })();
    </script>

</body>

</html>