<?php

namespace App\Controllers;

use Core\Controller;
use Core\Response;

class TestController extends Controller
{
    public function index(): Response
    {
        return $this->view('pages/Test', [
            'title' => 'Test',
            'message' => 'Welcome to Test!',
        ]);
    }
}
