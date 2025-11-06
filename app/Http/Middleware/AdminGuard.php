<?php

namespace App\Http\Middleware;

class AdminGuard
{
    public function handle(callable $next)
    {
        // Assumes you already put the logged-in account in session
        $acct = $_SESSION['account'] ?? null;
        if (!$acct) {
            header('Location: /login');
            exit;
        }

        $isSuper = !empty($acct['is_superuser']);
        $isAdmin = isset($acct['user_role']) && $acct['user_role'] === 'admin';

        if (!($isSuper || $isAdmin)) {
            http_response_code(403);
            echo "Forbidden";
            exit;
        }
        return $next();
    }
}
