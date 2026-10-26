<?php

namespace App\Http\Controllers;

use Nit\Master\Attributes\Permission;

class DashboardController extends Controller
{
    #[Permission('dashboard_view', 'View the dashboard')]
    public function __invoke()
    {
        return view('dashboard');
    }
}
