<?php

use App\Http\Middleware\AuthGuard;
use App\Http\Middleware\AdminGuard;
use App\Http\Middleware\SuperuserGuard;

use App\Http\Controllers\AuthController;
use App\Http\Controllers\HomeController;

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProjectContextController;

use App\Http\Controllers\Admin\SuperuserProjectsController;
use App\Http\Controllers\Admin\ProjectDaysController;
use App\Http\Controllers\Admin\ProjectClipsController;
use App\Http\Controllers\Admin\ProjectMembersController;
use App\Http\Controllers\Admin\ClipPlayerController;
use App\Http\Controllers\Admin\PlayerRedirectController;
use App\Http\Controllers\Admin\ClipUploadController;
use App\Http\Controllers\Admin\MaintenanceController;
use App\Http\Controllers\Admin\DayConverterController;
use App\Http\Controllers\Admin\DayCsvImportController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\WorkerController;

/** @var \App\Http\Router $router */
/** @var AuthController $authController */
/** @var HomeController $homeController */
/** @var SuperuserProjectsController $projectsController */


// ============================================================
// PUBLIC / DIAGNOSTICS
// ============================================================

// Diagnostics
$router->get('/ping', function () {
    header('Content-Type: text/plain; charset=utf-8');
    echo "pong\n";
});

// Health (redirect to auth health endpoint)
$router->get('/health', function () {
    header('Location: /auth/health');
    exit;
});

// DEBUG: env seen by PHP (remove later)
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


// ============================================================
// AUTH (PUBLIC)
// ============================================================

$router->get('/auth/login',   [$authController, 'showLogin']);
$router->post('/auth/login',  [$authController, 'login']);
$router->post('/auth/logout', [$authController, 'logout']);
$router->get('/auth/logout',  [$authController, 'logout']); // keep if you intentionally support GET logout
$router->get('/login',        [$authController, 'showLogin']); // alias
$router->get('/setup-password',  [$authController, 'showSetupPassword']);
$router->post('/setup-password', [$authController, 'handleSetupPassword']);


// ============================================================
// PROTECTED (REQUIRES LOGIN)
// ============================================================

$router->group([new AuthGuard], function (\App\Http\Router $r) use (
    $homeController,
    $projectsController
) {

    // --------------------------------------------------------
    // USER ACCOUNT / PROFILE (Self-service)
    // --------------------------------------------------------
    $r->get('/account',          [\App\Http\Controllers\AccountController::class, 'index']);
    $r->post('/account',         [\App\Http\Controllers\AccountController::class, 'update']);
    $r->get('/account/security', [\App\Http\Controllers\AccountController::class, 'index']); // For now, points to same panel

    // --------------------------------------------------------
    // HOME
    // --------------------------------------------------------
    $r->get('/', [$homeController, 'index']);


    // --------------------------------------------------------
    // DASHBOARD + PROJECT CONTEXT
    // --------------------------------------------------------
    $r->get('/dashboard', [DashboardController::class, 'index']);

    // Enter/leave a project context
    $r->get('/projects/{projectUuid}/enter', [ProjectContextController::class, 'enter']);
    $r->post('/projects/leave',              [ProjectContextController::class, 'leave']);

    // --------------------------------------------------------
    // PROJECT DAYS
    // --------------------------------------------------------
    $r->get('/admin/projects/{projectUuid}/days',              [ProjectDaysController::class, 'index']);
    $r->get('/admin/projects/{projectUuid}/days/new',          [ProjectDaysController::class, 'createForm']);
    $r->post('/admin/projects/{projectUuid}/days',             [ProjectDaysController::class, 'store']);

    // Day edit
    $r->get('/admin/projects/{projectUuid}/days/{dayUuid}/edit', [ProjectDaysController::class, 'editForm']);
    $r->post('/admin/projects/{projectUuid}/days/{dayUuid}/edit', [ProjectDaysController::class, 'edit']);

    // Day delete confirm + delete
    $r->get('/admin/projects/{projectUuid}/days/{dayUuid}/delete', [ProjectDaysController::class, 'deleteConfirm']);
    $r->post('/admin/projects/{projectUuid}/days/{dayUuid}/delete', [ProjectDaysController::class, 'destroy']);

    // Publish / unpublish
    $r->post('/admin/projects/{projectUuid}/days/{dayUuid}/publish',   [ProjectDaysController::class, 'publish']);
    $r->post('/admin/projects/{projectUuid}/days/{dayUuid}/unpublish', [ProjectDaysController::class, 'unpublish']);


    // --------------------------------------------------------
    // CLIPS — PROJECT LEVEL (all days)
    // --------------------------------------------------------
    $r->get('/admin/projects/{projectUuid}/clips', [ProjectClipsController::class, 'indexProject']);


    // --------------------------------------------------------
    // CLIPS — DAY LEVEL
    // --------------------------------------------------------
    $r->get('/admin/projects/{projectUuid}/days/{dayUuid}/clips', [ProjectClipsController::class, 'index']);

    // Upload clips to day
    $r->get('/admin/projects/{projectUuid}/days/{dayUuid}/clips/upload',  [ClipUploadController::class, 'uploadForm']);
    $r->post('/admin/projects/{projectUuid}/days/{dayUuid}/clips/upload', [ClipUploadController::class, 'handleUpload']);

    // Delete single clip
    $r->post('/admin/projects/{projectUuid}/days/{dayUuid}/clips/{clipUuid}/delete', [ProjectClipsController::class, 'destroy']);

    // Edit clip
    $r->get('/admin/projects/{projectUuid}/days/{dayUuid}/clips/{clipUuid}/edit',  [ProjectClipsController::class, 'editForm']);
    $r->post('/admin/projects/{projectUuid}/days/{dayUuid}/clips/{clipUuid}/edit', [ProjectClipsController::class, 'update']);

    // Clip quick actions
    $r->post('/admin/projects/{projectUuid}/days/{dayUuid}/clips/{clipUuid}/quick',    [ProjectClipsController::class, 'quickField']);
    $r->post('/admin/projects/{projectUuid}/days/{dayUuid}/clips/{clipUuid}/select',   [ProjectClipsController::class, 'quickSelect']);   // ✅ fixed
    $r->post('/admin/projects/{projectUuid}/days/{dayUuid}/clips/{clipUuid}/restrict', [ProjectClipsController::class, 'quick_restrict']);

    // Waveform generation
    $r->post('/admin/projects/{projectUuid}/days/{dayUuid}/converter/waveform', [\App\Http\Controllers\Admin\DayConverterController::class, 'generateWaveform']);
    $r->post('/admin/projects/{projectUuid}/days/{dayUuid}/converter/waveforms-bulk', [\App\Http\Controllers\Admin\DayConverterController::class, 'generateWaveformsBulk']);


    // --------------------------------------------------------
    // PLAYER
    // --------------------------------------------------------
    // Project-level entry → day overview (grid of days)
    $r->get('/admin/projects/{projectUuid}/player', [ClipPlayerController::class, 'overview']);
    $r->get('/admin/projects/{projectUuid}/overview', [ClipPlayerController::class, 'overview']);

    // Day-level player landing → redirect to first clip (or fallback)
    $r->get('/admin/projects/{projectUuid}/days/{dayUuid}/player', [PlayerRedirectController::class, 'toFirstClip']);

    // Player show (GET + POST)
    $r->get('/admin/projects/{projectUuid}/days/{dayUuid}/player/{clipUuid}',  [ClipPlayerController::class, 'show']);
    $r->post('/admin/projects/{projectUuid}/days/{dayUuid}/player/{clipUuid}', [ClipPlayerController::class, 'show']);

    // Poster from time
    $r->post('/admin/projects/{projectUuid}/days/{dayUuid}/clips/{clipUuid}/poster-from-time', [ClipPlayerController::class, 'posterFromTime']);


    // --------------------------------------------------------
    // PROJECT MEMBERS
    // --------------------------------------------------------
    $r->get('/admin/projects/{projectUuid}/members',                      [ProjectMembersController::class, 'members']);
    $r->post('/admin/projects/{projectUuid}/members',                     [ProjectMembersController::class, 'addMember']);
    $r->post('/admin/projects/{projectUuid}/members/{personUuid}',        [ProjectMembersController::class, 'updateMember']);
    $r->post('/admin/projects/{projectUuid}/members/{personUuid}/remove', [ProjectMembersController::class, 'removeMember']);


    // --------------------------------------------------------
    // ADMIN-ONLY (GLOBAL ADMIN PAGES + TOOLS + CONVERTER)
    // --------------------------------------------------------
    $r->group([new AdminGuard], function (\App\Http\Router $r2) {

        // Users
        $r2->get('/admin/users',           [UserController::class, 'index']);
        $r2->get('/admin/users/create',    [UserController::class, 'create']);
        $r2->post('/admin/users',          [UserController::class, 'store']);
        $r2->get('/admin/users/{id}/edit', [UserController::class, 'edit']);
        $r2->post('/admin/users/toggle-status/{id}', [UserController::class, 'toggleStatus']);
        $r2->post('/admin/users/delete/{id}',        [UserController::class, 'destroy']);
        $r2->post('/admin/users/{id}',     [UserController::class, 'update']);
        // Resend Invite
        $r2->get('/admin/users/resend-invite/{id}', [UserController::class, 'sendInvite']);

        // Maintenance / tools
        $r2->get('/admin/tools/backfill-fps', [MaintenanceController::class, 'backfillFps']);

        // Converter (DIT only) — kept behind AdminGuard as you had it
        $r2->get('/admin/projects/{projectUuid}/days/{dayUuid}/converter',                 [DayConverterController::class, 'index']);
        $r2->post('/admin/projects/{projectUuid}/days/{dayUuid}/converter/poster',         [DayConverterController::class, 'generatePoster']);
        $r2->post('/admin/projects/{projectUuid}/days/{dayUuid}/converter/posters-bulk',   [DayConverterController::class, 'generatePostersBulk']);
        $r2->post('/admin/projects/{projectUuid}/days/{dayUuid}/converter/metadata',       [DayConverterController::class, 'pullMetadata']);

        // Import DaVinci Resolve CSV → update clips for the day
        $r2->post('/admin/projects/{projectUuid}/days/{dayUuid}/clips/import_csv', [DayCsvImportController::class, 'importResolveCsv']);

        // Worker & Job Management
        $r2->get('/admin/jobs',                [WorkerController::class, 'index']);
        $r2->get('/admin/jobs/status-summary', [WorkerController::class, 'statusSummary']);
        $r2->post('/admin/jobs/toggle',        [WorkerController::class, 'toggle']);
        $r2->post('/admin/jobs/requeue/{id}',  [WorkerController::class, 'requeue']);
    });


    // --------------------------------------------------------
    // SUPERUSER ADMIN — PROJECTS (global)
    // --------------------------------------------------------
    $r->group([new SuperuserGuard], function (\App\Http\Router $r3) use ($projectsController) {

        // Superuser-only: Projects CRUD (or just create/update—your choice)
        $r3->get('/admin/projects',           [$projectsController, 'index']);
        $r3->get('/admin/projects/new',       [$projectsController, 'createForm']);
        $r3->post('/admin/projects',          [$projectsController, 'store']);
        $r3->get('/admin/projects/{id}/edit', [$projectsController, 'edit']);
        $r3->post('/admin/projects/{id}',     [$projectsController, 'update']);
    });
});
