<?php

namespace App\Http\Controllers;

use App\Services\UserEditorService;
use Illuminate\View\View;

class UserCreateController extends Controller
{
    public function __invoke(): View
    {
        abort_unless(app(UserEditorService::class)->canManageAccess(auth()->user()), 403);

        return view('pages.user-create', [
            'title' => 'Create user',
        ]);
    }
}
