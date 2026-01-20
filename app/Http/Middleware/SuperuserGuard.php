<?php

namespace App\Http\Middleware;

use App\Support\Auth;

final class SuperuserGuard
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

        $isSuper = !empty($acct['is_superuser']) || (isset($acct['is_superuser']) && (int)$acct['is_superuser'] === 1);

        if (!$isSuper) {
            http_response_code(403);
            echo "Forbidden";
            exit;
        }

        return $next();
    }

    public function __invoke(callable $next)
    {
        return $this->handle($next);
    }
}
