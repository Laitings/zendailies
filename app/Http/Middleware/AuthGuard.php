<?php

namespace App\Http\Middleware;

use App\Support\Auth;

final class AuthGuard
{

    public function handle(callable $next)
    {
        Auth::startSession();
        if (!Auth::check()) {
            header('Location: /login');
            return;
        }
        return $next();
    }
}

function require_superuser(): void
{
    // TEMP DEBUG — remove after test
    error_log('[DBG] SESSION=' . json_encode($_SESSION, JSON_UNESCAPED_SLASHES));
    echo '<pre>';
    var_dump($_SESSION);
    echo '</pre>';
    exit;

    // Accept either $_SESSION['account'] or $_SESSION['user']
    $acct = $_SESSION['account'] ?? ($_SESSION['user'] ?? null);

    $isSuper = isset($acct['is_superuser']) && (int)$acct['is_superuser'] === 1;

    if (!$isSuper) {
        http_response_code(403);
        echo "Forbidden";
        exit;
    }
}
