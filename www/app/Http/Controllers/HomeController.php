<?php

namespace App\Http\Controllers;

use App\Support\View;

final class HomeController
{
    // PURPOSE: render the home/dashboard for authenticated users
    public function index(): void
    {
        // Instead of the relic home.php, redirect to the active dashboard
        header('Location: /dashboard');
        exit;
    }
}
