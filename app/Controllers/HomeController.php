<?php
namespace App\Controllers;

use Core\Controller;
use Core\Response;

class HomeController extends Controller
{
    public function index(): Response
    {
        return $this->view('pages/home', ['title' => 'Home']);
    }

    public function about(): Response
    {
        return $this->view('pages/about', ['title' => 'About']);
    }
}
