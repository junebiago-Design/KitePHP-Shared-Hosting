<?php
namespace App\Controllers;

use App\Models\Post;
use Core\Controller;
use Core\Response;

class ApiController extends Controller
{
    public function index(): Response
    {
        return $this->json(['data' => Post::all('id DESC')]);
    }

    public function store(): Response
    {
        $post = Post::create($this->validate([
            'title' => 'required|min:3|max:150',
            'body'  => 'required',
        ]));
        return $this->json(['data' => $post], 201);
    }
}
