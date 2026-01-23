<?php

namespace App\Http\Middleware;

use App\Support\Auth;

final class GlobalAdminGuard
{
    public function handle(callable $next)
    {
        Auth::startSession();

        if (!Auth::check()) {
            header('Location: /login');
            exit;
        }

        $sess = $_SESSION;
        $acct = $sess['account'] ?? ($sess['user'] ?? null);

        // Check for superuser status in both common session locations
        $isSuper = !empty($sess['is_superuser']) || !empty($acct['is_superuser']);

        // Check for global admin role
        $isGlobalAdmin = isset($acct['user_role']) && $acct['user_role'] === 'admin';

        if (!$isSuper && !$isGlobalAdmin) {
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
