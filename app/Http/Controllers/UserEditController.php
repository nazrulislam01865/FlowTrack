<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\UserEditorService;
use Illuminate\View\View;

class UserEditController extends Controller
{
    public function __invoke(User $user): View
    {
        abort_unless(app(UserEditorService::class)->canEdit(auth()->user(), $user), 403);

        return view('pages.user-edit', [
            'user' => $user,
            'title' => 'Edit user',
            'profileMode' => false,
        ]);
    }
}
