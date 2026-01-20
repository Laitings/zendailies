<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

require __DIR__ . '/admin_auth.php';

// Enforce login and get the current admin row
$admin = zg_require_admin();

// DB handle
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
    $relative = substr($publicPath, strlen('/data/')); // e.g. "zengrabber/movies/file.mp4"
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



if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action  = $_POST['action'] ?? 'create';
    $movieId = isset($_POST['movie_id']) ? (int)$_POST['movie_id'] : 0;

    /**
     * DELETE MOVIE (hard delete)
     * - Cascades to invites, grabs, edl_exports via FK constraints.
     */
    if ($action === 'delete' && $movieId > 0) {
        try {
            // 1) Delete proxy file for this movie
            $stmt = $pdo->prepare("SELECT proxy_path FROM movies WHERE id = :id");
            $stmt->execute([':id' => $movieId]);
            $movieRow = $stmt->fetch();
            if ($movieRow) {
                $zgDeleteStorageFile($movieRow['proxy_path'] ?? null);
            }

            // 2) Delete all grab thumbnails for this movie
            $stmt = $pdo->prepare("SELECT thumbnail_path FROM grabs WHERE movie_id = :id");
            $stmt->execute([':id' => $movieId]);
            $grabRows = $stmt->fetchAll();
            if ($grabRows) {
                foreach ($grabRows as $g) {
                    $zgDeleteStorageFile($g['thumbnail_path'] ?? null);
                }
            }

            // 3) Delete all EDL export files for this movie
            $stmt = $pdo->prepare("SELECT file_path FROM edl_exports WHERE movie_id = :id");
            $stmt->execute([':id' => $movieId]);
            $edlRows = $stmt->fetchAll();
            if ($edlRows) {
                foreach ($edlRows as $e) {
                    $zgDeleteStorageFile($e['file_path'] ?? null);
                }
            }

            // 4) Finally, delete the movie row (FK will cascade invites, grabs, edl_exports)
            $stmt = $pdo->prepare("DELETE FROM movies WHERE id = :id");
            $stmt->execute([':id' => $movieId]);

            header('Location: admin_movies.php');
            exit;
        } catch (Throwable $e) {
            $error = 'Failed to delete movie: ' . $e->getMessage();
        }
    }


    /**
     * ACTIVATE / DEACTIVATE MOVIE
     * - Flips movies.is_active
     * - Flips invite_links.is_active for all invites on that movie
     */
    if (($action === 'deactivate' || $action === 'activate') && $movieId > 0 && $error === null) {
        $isActive = ($action === 'activate') ? 1 : 0;

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("
                UPDATE movies
                SET is_active = :is_active
                WHERE id = :id
            ");
            $stmt->execute([
                ':is_active' => $isActive,
                ':id'        => $movieId,
            ]);

            $stmt2 = $pdo->prepare("
                UPDATE invite_links
                SET is_active = :is_active
                WHERE movie_id = :movie_id
            ");
            $stmt2->execute([
                ':is_active' => $isActive,
                ':movie_id'  => $movieId,
            ]);

            $pdo->commit();

            header('Location: admin_movies.php');
            exit;
        } catch (Throwable $e) {
            $pdo->rollBack();
            $error = 'Failed to change movie status: ' . $e->getMessage();
        }
    }

    /**
     * CREATE MOVIE (existing logic, now under $action === 'create')
     */
    if ($action === 'create' && $error === null) {
        $title      = trim($_POST['title'] ?? '');
        $reel_name  = trim($_POST['reel_name'] ?? '');
        $fpsInput   = trim($_POST['fps'] ?? '');
        $startTcInput = trim($_POST['start_tc'] ?? '');

        // Defaults if nothing can be detected
        $fps        = $fpsInput;
        $start_tc   = $startTcInput;

        // Will be set by ffprobe if possible
        $autoFpsNum = null;
        $autoFpsDen = null;
        $autoTimecode = null;

        $targetPath = null; // full filesystem path to uploaded file


        // Handle optional file upload (store in shared /data storage)
        if (isset($_FILES['proxy_file']) && $_FILES['proxy_file']['error'] !== UPLOAD_ERR_NO_FILE) {
            if ($_FILES['proxy_file']['error'] === UPLOAD_ERR_OK) {

                // Base storage root from env (same as Zendailies), fallback to /var/www/html/data
                $storRoot = rtrim(getenv('ZEN_STOR_DIR') ?: '/var/www/html/data', '/');

                // zengrabber lives under its own folder inside data
                $uploadDir = $storRoot . '/zengrabber/movies';

                if (!is_dir($uploadDir)) {
                    if (!mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
                        $error = 'Failed to create storage directory: ' . $uploadDir;
                    }
                }

                if ($error === null) {
                    $originalName = $_FILES['proxy_file']['name'] ?? 'movie_proxy';
                    $ext          = pathinfo($originalName, PATHINFO_EXTENSION);
                    $baseName     = pathinfo($originalName, PATHINFO_FILENAME);

                    // Make filename safe
                    $safeBase = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $baseName);
                    if ($safeBase === '') {
                        $safeBase = 'movie_proxy';
                    }

                    $filename = $safeBase . '_' . time();
                    if ($ext !== '') {
                        $filename .= '.' . $ext;
                    }

                    $targetPath = $uploadDir . '/' . $filename;

                    if (move_uploaded_file($_FILES['proxy_file']['tmp_name'], $targetPath)) {
                        // Public web path – served by Apache Alias /data -> /var/www/html/data
                        $proxy_path = '/data/zengrabber/movies/' . $filename;
                    } else {
                        $error = 'Failed to move uploaded file.';
                    }
                }
            } else {
                $error = 'File upload error (code ' . (int)$_FILES['proxy_file']['error'] . ').';
            }
        }

        // If upload succeeded and we have a file, try to auto-detect fps + start TC with ffprobe
        if ($error === null && $targetPath !== null && is_file($targetPath)) {
            $ffprobeBin = getenv('FFPROBE_BIN') ?: 'ffprobe';

            $cmd = $ffprobeBin
                . ' -v error -select_streams v:0 '
                . '-show_entries stream=r_frame_rate,avg_frame_rate:stream_tags=timecode:format_tags=timecode '
                . '-of json ' . escapeshellarg($targetPath) . ' 2>&1';

            $json = shell_exec($cmd);

            if ($json) {
                $data = json_decode($json, true);
                if (is_array($data)) {
                    $stream = $data['streams'][0] ?? null;

                    // Prefer r_frame_rate, then avg_frame_rate
                    $rate = null;
                    if (!empty($stream['r_frame_rate']) && $stream['r_frame_rate'] !== '0/0') {
                        $rate = $stream['r_frame_rate'];
                    } elseif (!empty($stream['avg_frame_rate']) && $stream['avg_frame_rate'] !== '0/0') {
                        $rate = $stream['avg_frame_rate'];
                    }

                    if ($rate) {
                        [$n, $d] = array_map('intval', explode('/', $rate, 2));
                        if ($n > 0 && $d > 0) {
                            $autoFpsNum = $n;
                            $autoFpsDen = $d;
                        }
                    }

                    // Timecode: try stream tag first, then format tag
                    $tc = null;
                    if (!empty($stream['tags']['timecode'])) {
                        $tc = $stream['tags']['timecode'];
                    } elseif (!empty($data['format']['tags']['timecode'])) {
                        $tc = $data['format']['tags']['timecode'];
                    }

                    if ($tc && preg_match('/^\d{2}:\d{2}:\d{2}:\d{2}$/', $tc)) {
                        $autoTimecode = $tc;
                    }
                }
            }
        }

        // Only validate further if we have no upload errors
        if ($error === null) {
            if ($title === '') {
                $error = 'Please fill in the title.';
            } elseif (!isset($_FILES['proxy_file']) || $_FILES['proxy_file']['error'] === UPLOAD_ERR_NO_FILE) {
                $error = 'Please upload a proxy movie file.';
            } else {
                // Decide final FPS: if user left it empty, use auto; otherwise parse user value
                if ($fpsInput === '' && $autoFpsNum !== null && $autoFpsDen !== null) {
                    $fps_num = $autoFpsNum;
                    $fps_den = $autoFpsDen;
                    $fps = $autoFpsNum . '/' . $autoFpsDen; // for reference if needed
                } else {
                    // Fallback to manual parsing (same logic as before)
                    $fpsValue = $fpsInput !== '' ? $fpsInput : '25';
                    if (strpos($fpsValue, '/') !== false) {
                        [$n, $d] = explode('/', $fpsValue, 2);
                        $fps_num = max(1, (int)$n);
                        $fps_den = max(1, (int)$d);
                    } else {
                        $floatFps = (float)$fpsValue;
                        if ($floatFps > 0 && abs($floatFps - 23.976) < 0.001) {
                            $fps_num = 24000;
                            $fps_den = 1001;
                        } elseif ($floatFps > 0 && abs($floatFps - 29.97) < 0.001) {
                            $fps_num = 30000;
                            $fps_den = 1001;
                        } else {
                            $fps_num = (int)round($floatFps ?: 25);
                            $fps_den = 1;
                        }
                    }
                }

                // Decide final start TC: if user left it empty, try auto; else use user or default
                if ($startTcInput === '' && $autoTimecode !== null) {
                    $start_tc = $autoTimecode;
                } elseif ($startTcInput === '') {
                    $start_tc = '01:00:00:00';
                } else {
                    $start_tc = $startTcInput;
                }


                try {
                    $stmt = $pdo->prepare("
                        INSERT INTO movies (title, reel_name, fps_num, fps_den, start_tc, proxy_path)
                        VALUES (:title, :reel_name, :fps_num, :fps_den, :start_tc, :proxy_path)
                    ");
                    $stmt->execute([
                        ':title'      => $title,
                        ':reel_name'  => $reel_name !== '' ? $reel_name : 'PROXY',
                        ':fps_num'    => $fps_num,
                        ':fps_den'    => $fps_den,
                        ':start_tc'   => $start_tc,
                        ':proxy_path' => $proxy_path,
                    ]);

                    header('Location: admin_movies.php');
                    exit;
                } catch (Throwable $e) {
                    $error = 'Database error: ' . $e->getMessage();
                }
            }
        }
    }
}


$movies = $pdo->query("SELECT * FROM movies ORDER BY is_active DESC, created_at DESC")->fetchAll() ?: [];

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <title>Zengrabber · Movies</title>
    <link rel="stylesheet" href="assets/style.css?v=<?= time() ?>">
    <link rel="icon" type="image/png" href="assets/img/zentropa-favicon.png">
</head>

<body class="zg-body">
    <header class="zg-topbar">
        <div class="zg-topbar-left">
            <img src="assets/img/zen_logo.png" alt="Zen" class="zg-logo-img">
            <span class="zg-topbar-title">Zenreview</span>
            <span class="zg-topbar-subtitle">Admin · Movies</span>
        </div>

        <?php include __DIR__ . '/admin_topbar_user.php'; ?>
    </header>


    <main class="zg-main">
        <div>
            <button id="zg-add-movie-toggle" class="zg-btn zg-btn-primary">
                + Add New Movie
            </button>

            <div id="zg-add-movie-panel">
                <section class="zg-card" style="margin-top: 16px; margin-bottom: 0;">
                    <h1 class="zg-card-title">Add New Movie</h1>
                    <?php if ($error): ?>
                        <div class="zg-alert zg-alert-error"><?= htmlspecialchars($error) ?></div>
                    <?php endif; ?>

                    <form method="post" id="movie-form" class="zg-form" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="create">

                        <div class="zg-form-row">
                            <label for="title">Title *</label>
                            <input type="text" id="title" name="title" required placeholder="e.g. My Commercial Project">
                        </div>
                        <div class="zg-form-row" style="flex-direction:row; gap:16px;">
                            <div style="flex:1">
                                <label for="reel_name">Reel Name (default PROXY)</label>
                                <input type="text" id="reel_name" name="reel_name" value="PROXY" style="width:100%">
                            </div>
                            <div style="flex:1">
                                <label for="fps">FPS (leave empty to auto-detect)</label>
                                <input type="text" id="fps" name="fps" placeholder="Auto from file" style="width:100%">
                            </div>
                        </div>
                        <div class="zg-form-row">
                            <label for="start_tc">Start Timecode (leave empty to auto-detect)</label>
                            <input type="text" id="start_tc" name="start_tc" placeholder="Auto from file or 01:00:00:00">
                        </div>

                        <div class="zg-form-row">
                            <label for="proxy_file">Upload proxy file</label>
                            <input type="file" id="proxy_file" name="proxy_file" accept="video/*">
                        </div>

                        <div id="upload-progress" class="zg-upload-progress" style="display:none;">
                            <div class="zg-upload-header">
                                <span id="upload-progress-label" class="zg-upload-label">Uploading…</span>
                            </div>
                            <div class="zg-upload-bar-outer">
                                <div id="upload-progress-bar" class="zg-upload-bar-inner"></div>
                            </div>
                        </div>

                        <div class="zg-form-actions">
                            <button type="submit" class="zg-btn zg-btn-primary">Save Movie</button>
                        </div>
                    </form>
                </section>
            </div>
        </div>

        <section class="zg-card">
            <h2 class="zg-card-title">Existing Movies</h2>
            <?php if (empty($movies)): ?>
                <p class="zg-empty-state">No movies found.</p>
            <?php else: ?>
                <div class="zg-table-wrapper">
                    <table class="zg-table">
                        <thead>
                            <tr>
                                <th style="width:50px">ID</th>
                                <th>Title</th>
                                <th>Reel</th>
                                <th>FPS</th>
                                <th>Start TC</th>
                                <th>Proxy</th>
                                <th>Status</th>
                                <th style="text-align:right">Actions</th>
                            </tr>

                        </thead>
                        <tbody>
                            <?php foreach ($movies as $m): ?>
                                <tr onclick="window.location='admin_invites.php?movie_id=<?= (int)$m['id'] ?>'" style="cursor: pointer;">

                                    <td class="zg-mono">#<?= (int)$m['id'] ?></td>
                                    <td style="font-weight:600"><?= htmlspecialchars($m['title']) ?></td>
                                    <td class="zg-mono"><?= htmlspecialchars($m['reel_name'] ?? '-') ?></td>
                                    <td class="zg-mono"><?= (int)$m['fps_num'] ?>/<?= (int)$m['fps_den'] ?></td>
                                    <td class="zg-mono"><?= htmlspecialchars($m['start_tc']) ?></td>
                                    <?php
                                    $proxyName = basename($m['proxy_path'] ?? '');
                                    ?>
                                    <td class="zg-mono"
                                        title="<?= htmlspecialchars($proxyName) ?>"
                                        style="max-width:200px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; opacity:0.6;">
                                        <?= htmlspecialchars($proxyName) ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($m['is_active'])): ?>
                                            <span style="color:#4ade80; font-size:0.8rem; background:rgba(74,222,128,0.1); padding:2px 6px; border-radius:4px;">Active</span>
                                        <?php else: ?>
                                            <span style="color:#f87171; font-size:0.8rem; background:rgba(248,113,113,0.1); padding:2px 6px; border-radius:4px;">Deactivated</span>
                                        <?php endif; ?>
                                    </td>

                                    <td class="zg-actions-cell" onclick="event.stopPropagation()">
                                        <div class="zg-actions-wrapper">
                                            <button type="button"
                                                class="zg-actions-toggle zg-btn zg-btn-small zg-btn-ghost"
                                                data-movie-id="<?= (int)$m['id'] ?>">
                                                Actions ▾
                                            </button>

                                            <div class="zg-actions-menu" data-actions-menu-for="<?= (int)$m['id'] ?>">
                                                <a href="admin_invites.php?movie_id=<?= (int)$m['id'] ?>"
                                                    class="zg-actions-item">
                                                    Manage Links
                                                </a>

                                                <form method="post" class="zg-actions-item-form">
                                                    <input type="hidden" name="movie_id" value="<?= (int)$m['id'] ?>">
                                                    <input type="hidden" name="action" value="<?= !empty($m['is_active']) ? 'deactivate' : 'activate' ?>">
                                                    <button type="submit" class="zg-actions-item-btn">
                                                        <?= !empty($m['is_active']) ? 'Deactivate' : 'Activate' ?>
                                                    </button>
                                                </form>

                                                <form method="post"
                                                    class="zg-actions-item-form"
                                                    onsubmit="return confirm('Delete this movie and all its grabs/invites/EDL exports? This cannot be undone.');">
                                                    <input type="hidden" name="movie_id" value="<?= (int)$m['id'] ?>">
                                                    <input type="hidden" name="action" value="delete">
                                                    <button type="submit" class="zg-actions-item-btn zg-actions-item-danger">
                                                        Delete Movie
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

    <script>
        document.addEventListener('DOMContentLoaded', function() {

            // Add New Movie Toggle
            const toggleBtn = document.getElementById('zg-add-movie-toggle');
            const panel = document.getElementById('zg-add-movie-panel');

            if (toggleBtn && panel) {
                // Note: We no longer set panel.style.display = 'none' here.
                // The CSS #zg-add-movie-panel { max-height: 0; } handles the initial hidden state.

                toggleBtn.addEventListener('click', () => {
                    // Toggle the class that triggers the CSS transition
                    panel.classList.toggle('zg-open');

                    // Check if open to update text
                    const isOpen = panel.classList.contains('zg-open');
                    toggleBtn.textContent = isOpen ? '− Hide New Movie Form' : '+ Add New Movie';
                });
            }


            // Upload Form Handling
            const form = document.getElementById('movie-form');
            const fileInput = document.getElementById('proxy_file');
            const progressWrap = document.getElementById('upload-progress');
            const progressBar = document.getElementById('upload-progress-bar');
            const progressLabel = document.getElementById('upload-progress-label');

            if (form && fileInput) {
                form.addEventListener('submit', function(e) {
                    if (!fileInput.files || fileInput.files.length === 0) return;

                    e.preventDefault();
                    if (progressWrap) progressWrap.style.display = 'block';
                    if (progressBar) progressBar.style.width = '0%';
                    if (progressLabel) progressLabel.textContent = 'Preparing upload...';

                    const formData = new FormData(form);
                    const xhr = new XMLHttpRequest();
                    const targetUrl = form.getAttribute('action') || window.location.href;

                    xhr.open('POST', targetUrl, true);
                    xhr.upload.addEventListener('progress', function(evt) {
                        if (!evt.lengthComputable) return;
                        const percent = Math.round((evt.loaded / evt.total) * 100);
                        if (progressBar) progressBar.style.width = percent + '%';
                        if (progressLabel) progressLabel.textContent = 'Uploading ' + percent + '%';
                    });
                    xhr.addEventListener('load', function() {
                        if (xhr.status >= 200 && xhr.status < 400) {
                            if (progressLabel) progressLabel.textContent = 'Processing...';
                            window.location.reload();
                        } else {
                            if (progressLabel) progressLabel.textContent = 'Upload failed (' + xhr.status + ').';
                        }
                    });
                    xhr.addEventListener('error', function() {
                        if (progressLabel) progressLabel.textContent = 'Network error.';
                    });
                    xhr.send(formData);
                });
            }

            // Dropdown Menu Logic
            const toggles = document.querySelectorAll('.zg-actions-toggle');
            let openMenu = null;

            function closeMenu() {
                if (!openMenu) return;
                openMenu.classList.remove('zg-actions-menu--open', 'zg-actions-menu--up');
                openMenu = null;
            }

            function openForToggle(btn) {
                const movieId = btn.getAttribute('data-movie-id');
                if (!movieId) return;

                const menu = document.querySelector('.zg-actions-menu[data-actions-menu-for="' + movieId + '"]');
                if (!menu) return;

                if (openMenu && openMenu !== menu) {
                    openMenu.classList.remove('zg-actions-menu--open', 'zg-actions-menu--up');
                }

                const isOpen = menu.classList.contains('zg-actions-menu--open');
                if (isOpen) {
                    menu.classList.remove('zg-actions-menu--open', 'zg-actions-menu--up');
                    openMenu = null;
                    return;
                }

                menu.classList.add('zg-actions-menu--open');
                menu.classList.remove('zg-actions-menu--up');

                const rect = menu.getBoundingClientRect();
                const viewportH = window.innerHeight || document.documentElement.clientHeight;

                if (rect.bottom > viewportH - 8) {
                    menu.classList.add('zg-actions-menu--up');
                }
                openMenu = menu;
            }

            toggles.forEach(function(btn) {
                btn.addEventListener('click', function(e) {
                    e.stopPropagation();
                    openForToggle(btn);
                });
            });

            document.addEventListener('click', function(e) {
                if (!openMenu) return;
                const wrapper = openMenu.closest('.zg-actions-wrapper');
                if (wrapper && wrapper.contains(e.target)) return;
                closeMenu();
            });

            window.addEventListener('resize', closeMenu);
            window.addEventListener('scroll', function() {
                if (!openMenu) return;
                const rect = openMenu.getBoundingClientRect();
                const viewportH = window.innerHeight || document.documentElement.clientHeight;
                if (rect.bottom > viewportH - 8 && !openMenu.classList.contains('zg-actions-menu--up')) {
                    openMenu.classList.add('zg-actions-menu--up');
                } else if (rect.bottom < viewportH - 80 && openMenu.classList.contains('zg-actions-menu--up')) {
                    openMenu.classList.remove('zg-actions-menu--up');
                }
            }, {
                passive: true
            });
        });
    </script>
</body>

</html>