<?php

namespace App\Controllers;

use Core\Controller;
use Core\Response;

class UtenController extends Controller
{
    public function index(): Response
    {
        return $this->view('pages/Uten', [
            'title' => 'Uten',
            'message' => 'Welcome to Uten!',
        ]);
    }
}
