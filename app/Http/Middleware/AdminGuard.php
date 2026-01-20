<?php

namespace App\Http\Middleware;

use App\Support\Auth;

final class AdminGuard
{
    public function handle(callable $next)
    {
        Auth::startSession();

        if (!Auth::check()) {
            header('Location: /login');
            return;
        }

        $acct = $_SESSION['account'] ?? ($_SESSION['user'] ?? null);
        if (!$acct) {
            http_response_code(403);
            echo "Forbidden";
            exit;
        }

        $isSuper = !empty($acct['is_superuser']);
        $isAdmin = isset($acct['user_role']) && $acct['user_role'] === 'admin';

        // If they are neither, they shouldn't even see the door
        if (!$isSuper && !$isAdmin) {
            header('Location: /dashboard?error=unauthorized');
            exit;
        }

        return $next();
    }

    public function __invoke(callable $next)
    {
        return $this->handle($next);
    }
}
