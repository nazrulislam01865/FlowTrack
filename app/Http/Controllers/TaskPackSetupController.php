<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

class TaskPackSetupController extends Controller
{
    public function index(): View
    {
        return view('pages.task-pack-setup');
    }

    public function create(): View
    {
        return view('pages.task-pack-form', ['taskPackId' => null]);
    }

    public function edit(int $taskPack): View
    {
        return view('pages.task-pack-form', ['taskPackId' => $taskPack]);
    }
}
