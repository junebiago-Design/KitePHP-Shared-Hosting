<?php

namespace App\Controllers;

use App\Models\UserLogView;
use Core\Controller;
use Core\Response;

class UserLogViewController extends Controller
{
    /**
     * Display all user activity logs.
     */
    public function index(): Response
    {
        $logs = UserLogView::allLogs();

        return $this->view('userlog/index', [
            'title' => 'User Activity Logs',
            'logs'  => $logs,
        ]);
    }

    /**
     * Display one user activity log.
     */
    public function show($id): Response
    {
        $log = UserLogView::findOrFail($id);

        return $this->view('userlog/show', [
            'title' => 'User Activity Log',
            'log'   => $log,
        ]);
    }
}