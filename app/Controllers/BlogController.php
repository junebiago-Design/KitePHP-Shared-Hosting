<?php

namespace App\Controllers;

use Core\Controller;
use Core\Response;

class BlogController extends Controller
{
    public function index(): Response
    {
        return $this->view('pages/Blog', [
            'title' => 'Blog',
            'message' => 'Welcome to Blog!',
        ]);
    }
}
