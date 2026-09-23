<?php

namespace App\Controllers;

use Core\Controller;
use Core\Response;

class PugakController extends Controller
{
    public function index(): Response
    {
        return $this->view('pages/Pugak', [
            'title' => 'Pugak',
            'message' => 'Welcome to Pugak!',
        ]);
    }
}
