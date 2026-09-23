<?php
namespace App\Controllers;

use Core\Auth;
use Core\Controller;
use Core\Response;

class DashboardController extends Controller
{
    public function index(): Response
    {
        return $this->view('dashboard/index', [
            'title'       => 'Dashboard',
            'user'        => Auth::user(),
            'permissions' => Auth::permissionList(),   // ['*'] = full access
        ]);
    }
}
