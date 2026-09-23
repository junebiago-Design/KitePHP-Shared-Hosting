<?php
namespace App\Controllers;

use App\Models\Attachment;
use App\Models\Post;
use Core\Auth;
use Core\Controller;
use Core\Response;
use Core\Session;
use Core\Uploader;

class PostController extends Controller
{
    private const RULES = [
        'title' => 'required|min:3|max:150',
        'body'  => 'required|max:50000',      // Markdown and/or HTML tags
    ];

    public function index(): Response
    {
        $page = (int) $this->request->query('page', 1);
        $result = Post::paginate(5, $page, [], 'id DESC');
        return $this->view('posts/index', ['title' => 'Posts'] + $result);
    }

    public function create(): Response
    {
        return $this->view('posts/form', ['title' => 'New post', 'post' => null, 'attachments' => []]);
    }

    public function store(): Response
    {
        $post = Post::create($this->validate(self::RULES));
        $this->finish($post, 'Post created.');
        return $this->redirect('/posts/' . $post->id);
    }

    public function show($id): Response
    {
        $post = Post::findOrFail($id);
        return $this->view('posts/show', [
            'title'       => $post->title,
            'post'        => $post,
            'attachments' => Attachment::forPost($post->id),
        ]);
    }

    public function edit($id): Response
    {
        $post = Post::findOrFail($id);
        return $this->view('posts/form', [
            'title'       => 'Edit post',
            'post'        => $post,
            'attachments' => Attachment::forPost($post->id),
        ]);
    }

    public function update($id): Response
    {
        $post = Post::findOrFail($id);
        $post->update($this->validate(self::RULES));
        $this->finish($post, 'Post updated.');
        return $this->redirect('/posts/' . $post->id);
    }

    public function destroy($id): Response
    {
        $post = Post::findOrFail($id);
        Attachment::deleteForPost($post->id);      // remove the files from disk too
        $post->delete();
        Session::flash('success', 'Post deleted.');
        return $this->redirect('/posts');
    }

    /** Saves any files chosen in the form and sets the flash messages. */
    private function finish(Post $post, string $message): void
    {
        $saved = 0;
        $error = null;

        if (Auth::can('files.upload')) {
            $up = Uploader::handle('files');
            foreach ($up['saved'] as $meta) {
                Attachment::create($meta + ['post_id' => $post->id, 'user_id' => Auth::id()]);
            }
            $saved = count($up['saved']);
            $error = Uploader::errorText($up);
        }

        Session::flash('success', $message . ($saved ? ' ' . $saved . ' file' . ($saved === 1 ? '' : 's') . ' attached.' : ''));
        if ($error) {
            Session::flash('error', 'The post was saved, but: ' . $error);
        }
    }
    
    public function guide($id): Response
    {
        $post = Post::findOrFail($id);
        return $this->view('posts/guide', [
            'title'       => $post->title,
            'post'        => $post,
            'attachments' => Attachment::forPost($post->id),
        ]);
    }

}
