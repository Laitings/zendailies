<?php

use App\Http\Middleware\AuthGuard;
use App\Http\Middleware\AdminGuard;
use App\Http\Controllers\Admin\ProjectMembersController;
use App\Http\Controllers\Admin\ProjectDaysController;
use App\Http\Controllers\Admin\ProjectClipsController;
use App\Http\Controllers\Admin\DayConverterController;
use App\Http\Controllers\Admin\ClipPlayerController;
use App\Http\Controllers\Admin\DayCsvImportController;
use App\Http\Controllers\Admin\PlayerRedirectController;


/** @var \App\Http\Router $router */
/** @var \App\Http\Controllers\AuthController $authController */
/** @var \App\Http\Controllers\HomeController $homeController */
/** @var \App\Http\Controllers\Admin\SuperuserProjectsController $projectsController */

// Instantiate controllers created as instances here:
$projectMembersController = new ProjectMembersController();
$projectDaysController    = new ProjectDaysController();
$projectClipsController   = new ProjectClipsController();
$clipPlayerController    = new ClipPlayerController();
$playerRedirectController = new PlayerRedirectController();

// ---------- Diagnostics ----------
$router->get('/ping', function () {
    header('Content-Type: text/plain; charset=utf-8');
    echo "pong\n";
});

// ---------- Health ----------
$router->get('/health', function () {
    header('Location: /auth/health');
    exit;
});

// ---------- Auth (public) ----------
$router->get('/auth/login',   [$authController, 'showLogin']);
$router->post('/auth/login',  [$authController, 'login']);
$router->post('/auth/logout', [$authController, 'logout']);
$router->get('/auth/logout',  [$authController, 'logout']);
$router->get('/login',        [$authController, 'showLogin']); // alias

// --- DEBUG: env seen by PHP (remove later) ---
$router->get('/debug/env', function () {
    header('Content-Type: text/plain; charset=utf-8');
    $keys = [
        'APP_ENV',
        'APP_DEBUG',
        'TZ',
        'DB_HOST',
        'DB_PORT',
        'DB_NAME',
        'DB_USER',
        'ZEN_STOR_DIR',
        'ZEN_STOR_PUBLIC_BASE',
        'FFMPEG_BIN',
    ];
    foreach ($keys as $k) {
        $v = getenv($k);
        echo $k, '=', ($v === false ? '<not set>' : $v), PHP_EOL;
    }
});


// ---------- Protected (requires login) ----------
$router->group([new AuthGuard], function (\App\Http\Router $r) use (
    $homeController,
    $projectsController,
    $projectMembersController,
    $projectDaysController,
    $projectClipsController,
    $clipPlayerController,
    $playerRedirectController
) {
    // Home
    $r->get('/', [$homeController, 'index']);

    // Superuser admin: Projects
    $r->get('/admin/projects',           [$projectsController, 'index']);
    $r->get('/admin/projects/new',       [$projectsController, 'createForm']);
    $r->post('/admin/projects',          [$projectsController, 'store']);
    $r->get('/admin/projects/{id}/edit', [$projectsController, 'edit']);
    $r->post('/admin/projects/{id}',     [$projectsController, 'update']);

    // ---------- Dashboard + Project context ----------
    $r->get('/dashboard', [\App\Http\Controllers\DashboardController::class, 'index']);

    // Enter/leave a project context
    $r->get('/projects/{projectUuid}/enter', [\App\Http\Controllers\ProjectContextController::class, 'enter']);
    $r->post('/projects/leave',               [\App\Http\Controllers\ProjectContextController::class, 'leave']);

    // Shooting days
    $r->get('/admin/projects/{projectUuid}/days', [$projectDaysController, 'index']);
    // Show create form
    $r->get('/admin/projects/{projectUuid}/days/new', [$projectDaysController, 'createForm']);
    // Handle create
    $r->post('/admin/projects/{projectUuid}/days',    [$projectDaysController, 'store']);
    // Confirm page
    $r->get('/admin/projects/{projectUuid}/days/{dayUuid}/delete', [$projectDaysController, 'deleteConfirm']);
    // Perform delete
    $r->post('/admin/projects/{projectUuid}/days/{dayUuid}/delete', [\App\Http\Controllers\Admin\ProjectDaysController::class, 'destroy']);
    // Edit day
    $r->get('/admin/projects/{projectUuid}/days/{dayUuid}/edit', [\App\Http\Controllers\Admin\ProjectDaysController::class, 'editForm']);
    $r->post('/admin/projects/{projectUuid}/days/{dayUuid}/edit', [\App\Http\Controllers\Admin\ProjectDaysController::class, 'edit']);


    // Clips under a day
    $r->get('/admin/projects/{projectUuid}/days/{dayUuid}/clips', [$projectClipsController, 'index']);
    // Day-only player landing → redirect to first clip (or to /clips if none)
    $r->get('/admin/projects/{projectUuid}/days/{dayUuid}/player', [$playerRedirectController, 'toFirstClip']);

    $r->get('/admin/projects/{projectUuid}/days/{dayUuid}/player/{clipUuid}', [$clipPlayerController, 'show']);

    // Upload form + handler for adding new clips to a day
    $r->get('/admin/projects/{projectUuid}/days/{dayUuid}/clips/upload', [\App\Http\Controllers\Admin\ClipUploadController::class, 'uploadForm']);
    $r->post('/admin/projects/{projectUuid}/days/{dayUuid}/clips/upload', [\App\Http\Controllers\Admin\ClipUploadController::class, 'handleUpload']);
    // Delete a single clip (POST to keep CSRF protection)
    $r->post('/admin/projects/{projectUuid}/days/{dayUuid}/clips/{clipUuid}/delete', [\App\Http\Controllers\Admin\ProjectClipsController::class, 'destroy']);
    // Edit clip
    $r->get('/admin/projects/{projectUuid}/days/{dayUuid}/clips/{clipUuid}/edit', [\App\Http\Controllers\Admin\ProjectClipsController::class, 'editForm']);
    $r->post('/admin/projects/{projectUuid}/days/{dayUuid}/clips/{clipUuid}/edit', [\App\Http\Controllers\Admin\ProjectClipsController::class, 'update']);
    $r->post('/admin/projects/{projectUuid}/days/{dayUuid}/clips/{clipUuid}/quick', [\App\Http\Controllers\Admin\ProjectClipsController::class, 'quickField']);


    // Project member management
    $r->get('/admin/projects/{projectUuid}/members',                      [$projectMembersController, 'members']);
    $r->post('/admin/projects/{projectUuid}/members',                     [$projectMembersController, 'addMember']);
    $r->post('/admin/projects/{projectUuid}/members/{personUuid}',        [$projectMembersController, 'updateMember']);
    $r->post('/admin/projects/{projectUuid}/members/{personUuid}/remove', [$projectMembersController, 'removeMember']);

    // ---------- Admin-only (global admin pages) ----------
    $r->group([new AdminGuard], function (\App\Http\Router $r2) {
        $r2->get('/admin/users',               [\App\Http\Controllers\Admin\UserController::class, 'index']);
        $r2->get('/admin/users/create',        [\App\Http\Controllers\Admin\UserController::class, 'create']);
        $r2->post('/admin/users',              [\App\Http\Controllers\Admin\UserController::class, 'store']);
        $r2->get('/admin/users/{id}/edit',     [\App\Http\Controllers\Admin\UserController::class, 'edit']);
        $r2->post('/admin/users/{id}',         [\App\Http\Controllers\Admin\UserController::class, 'update']);
    });


    // Converter (DIT only) — still inside the outer AuthGuard group
    $r->group([new AdminGuard()], function (\App\Http\Router $r2) {
        $conv = new DayConverterController();

        // Page
        $r2->get('/admin/projects/{projectUuid}/days/{dayUuid}/converter', [$conv, 'index']);

        // Actions
        $r2->post('/admin/projects/{projectUuid}/days/{dayUuid}/converter/poster',         [$conv, 'generatePoster']);
        $r2->post('/admin/projects/{projectUuid}/days/{dayUuid}/converter/posters-bulk',   [$conv, 'generatePostersBulk']);

        // NEW: pull core clip metadata (duration_ms, tc_start, fps)
        $r2->post('/admin/projects/{projectUuid}/days/{dayUuid}/converter/metadata',       [$conv, 'pullMetadata']);

        // Import DaVinci Resolve CSV → update clips for the day
        $r2->post(
            '/admin/projects/{projectUuid}/days/{dayUuid}/clips/import_csv',
            [DayCsvImportController::class, 'importResolveCsv']
        );
    });
});
