<?php

namespace App\Http\Controllers;

use App\Support\View;

final class HomeController
{
    // PURPOSE: render the home/dashboard for authenticated users
    public function index(): void
    {
        $user = $_SESSION['user'] ?? null; // ['account_id','person_id','email','is_superuser','mfa_policy']
        View::render('home', ['user' => $user]);
    }
}
