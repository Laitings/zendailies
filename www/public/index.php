<?php

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

/**
 * 1) Autoload
 */
$autoload = __DIR__ . '/../vendor/autoload.php';
if (!is_file($autoload)) {
    http_response_code(500);
    exit('Composer autoload not found (or manual vendor/autoload.php missing).');
}
require $autoload;

/**
 * 2) Load .env BEFORE bootstrap (so bootstrap can read ENV)
 */
$envFile = __DIR__ . '/../.env';
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        [$k, $v] = array_pad(explode('=', $line, 2), 2, '');
        $k = trim($k);
        $v = trim($v);
        if ($k === '') continue;
        putenv("$k=$v");
        $_ENV[$k]    = $v;
        $_SERVER[$k] = $v;
    }
}

/**
 * 3) Bootstrap (sets up DB helper, etc.)
 */
require_once __DIR__ . '/../app/bootstrap.php';

/**
 * 4) Error handling (dev)
 */
if (getenv('APP_DEBUG') === '1') {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);

    set_exception_handler(function ($e) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        echo "ERROR: " . $e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine() . "\n\n" . $e->getTraceAsString();
    });
}

/**
 * 5) Session
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * 6) Build dependencies (Repo → Service → Controllers)
 */

use App\Repositories\AccountRepository;
use App\Services\AuthService;

use App\Http\Controllers\AuthController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\Admin\SuperuserProjectsController;
use App\Repositories\ProjectRepository;

// Repositories
$accountRepo = new AccountRepository();
$projectRepo = new ProjectRepository(db());

// Services
$authService = new AuthService($accountRepo);

// Controllers (inject services/repos)
$authController     = new AuthController($authService);
$homeController     = new HomeController();
$projectsController = new SuperuserProjectsController($projectRepo);

/**
 * 7) Use the real Router and load routes
 */

use App\Http\Router;

$router = new Router();

/**
 * Make controller instances available to the routes file.
 * app/Routes.php will use these variables to register routes.
 */
$__routes_scope = [
    'router'            => $router,
    'authController'    => $authController,
    'homeController'    => $homeController,
    'projectsController' => $projectsController,
];

/**
 * Load the route definitions (kept separate for clarity)
 */
(function (array $__scope) {
    /** @var \App\Http\Router $router */
    $router = $__scope['router'];

    /** @var \App\Http\Controllers\AuthController $authController */
    $authController = $__scope['authController'];

    /** @var \App\Http\Controllers\HomeController $homeController */
    $homeController = $__scope['homeController'];

    /** @var \App\Http\Controllers\SuperuserProjectsController $projectsController */
    $projectsController = $__scope['projectsController'];

    require __DIR__ . '/../app/Routes.php';
})($__routes_scope);

/**
 * 8) Run the router
 */
try {
    $router->run();
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "FATAL: " . $e->getMessage() . "\n\n";
    echo $e->getFile() . ':' . $e->getLine() . "\n\n";
    echo $e->getTraceAsString();
    exit;
}
