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

    // Optional but recommended for router/IDE compatibility
    public function __invoke(callable $next)
    {
        return $this->handle($next);
    }
}
