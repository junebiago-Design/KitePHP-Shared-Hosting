<?php
namespace App\Controllers;

use App\Models\Attachment;
use Core\Auth;
use Core\Controller;
use Core\HttpException;
use Core\Response;
use Core\Session;
use Core\Uploader;

/**
 * The Files library, plus serving uploaded files (show = open in the browser, download = save).
 * Files are streamed by PHP because they live outside the public web folder.
 */
class FileController extends Controller
{
    public function index(): Response
    {
        $page = (int) $this->request->query('page', 1);
        $result = Attachment::paginate(12, $page, [], 'id DESC');
        return $this->view('files/index', ['title' => 'Files'] + $result);
    }

    public function store(): Response
    {
        $up = Uploader::handle('files');

        foreach ($up['saved'] as $meta) {
            Attachment::create($meta + ['post_id' => null, 'user_id' => Auth::id()]);
        }

        $n = count($up['saved']);
        if ($n === 0 && !$up['errors']) {
            $up['errors'][] = 'Choose at least one file to upload.';
        }
        if ($n > 0) {
            Session::flash('success', $n . ' file' . ($n === 1 ? '' : 's') . ' uploaded.');
        }
        if ($err = Uploader::errorText($up)) {
            Session::flash('error', $err);
        }
        return $this->redirect('/files');
    }

    /** Show in the browser (images, PDF, text) or fetch for the preview modal. */
    public function show($id): Response
    {
        return $this->serve($id, true);
    }

    public function download($id): Response
    {
        return $this->serve($id, false);
    }

    public function destroy($id): Response
    {
        $att = Attachment::findOrFail($id);
        $postId = $att->post_id;

        // Library files need files.delete; files on a post can also be removed by people who may edit posts
        if (!Auth::can('files.delete') && !($postId && Auth::can('posts.edit'))) {
            throw new HttpException(403, 'You do not have permission to delete this file.');
        }

        $att->delete();
        Session::flash('success', 'File deleted.');
        return $this->redirect($postId ? '/posts/' . $postId : '/files');
    }

    private function serve($id, bool $wantInline): Response
    {
        $att = Attachment::findOrFail($id);

        // Files attached to a post are as public as the post itself.
        // Library files (no post) need the files.view permission.
        if (!$att->post_id && !Auth::can('files.view')) {
            throw new HttpException(403, 'You do not have permission to open this file.');
        }

        $path = Uploader::path((string) $att->stored_name);
        if (!is_file($path)) {
            throw new HttpException(404, 'This file is missing on the server.');
        }

        $inline = $wantInline && Uploader::isInline((string) $att->ext);
        return Response::file($path, Uploader::servedMime((string) $att->ext), (string) $att->original_name, $inline);
    }
}
