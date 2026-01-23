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
        $isGlobalAdmin = isset($acct['user_role']) && $acct['user_role'] === 'admin';

        // NEW: Check if they are an admin for the current project context
        $isProjectAdmin = !empty($_SESSION['current_project_flags']['is_project_admin']);

        // Allow entry if they meet ANY of these three criteria
        if (!$isSuper && !$isGlobalAdmin && !$isProjectAdmin) {
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
