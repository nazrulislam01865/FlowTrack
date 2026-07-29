<?php

namespace App\Http\Controllers\FlowTrack;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\OrderFlow\Application\Presenters\CurrentUserPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class TemplateProfileController extends Controller
{
    public function update(Request $request, CurrentUserPresenter $presenter): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique(User::class)->ignore($user->id)],
            'phone' => ['nullable', 'string', 'max:40'],
            'locale' => ['required', Rule::in(['en', 'zh'])],
            'default_landing_page' => ['required', Rule::in(['dashboard', 'mywork', 'jobs', 'clients', 'board', 'documents', 'reports'])],
            'default_task_view' => ['required', Rule::in(['list', 'board'])],
            'notification_preferences' => ['required', 'array'],
            'notification_preferences.task_assignments' => ['required', 'boolean'],
            'notification_preferences.due_date_reminders' => ['required', 'boolean'],
            'notification_preferences.mentions_comments' => ['required', 'boolean'],
            'notification_preferences.workflow_updates' => ['required', 'boolean'],
            'notification_preferences.shipment_alerts' => ['required', 'boolean'],
            'notification_preferences.payment_reminders' => ['required', 'boolean'],
        ]);

        $user->fill([
            'name' => $data['name'],
            'initials' => $this->initials($data['name']),
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'locale' => $data['locale'],
            'default_landing_page' => $data['default_landing_page'],
            'default_task_view' => $data['default_task_view'],
            'notification_preferences' => $data['notification_preferences'],
        ]);

        if ($user->isDirty('email')) {
            // FlowTrack is an administrator-managed internal workspace. Keep the
            // currently authenticated user usable after an intentional email update.
            $user->email_verified_at = now();
        }

        $user->save();

        return response()->json([
            'message' => 'Profile updated successfully.',
            'currentUser' => $presenter->toArray($user->fresh()),
        ]);
    }

    public function updatePassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'current_password:web'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $request->user()->update(['password' => $data['password']]);

        return response()->json(['message' => 'Password updated successfully.']);
    }

    public function updateAvatar(Request $request, CurrentUserPresenter $presenter): JsonResponse
    {
        $data = $request->validate([
            'avatar' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $oldPath = $user->avatar_path;
        $path = $data['avatar']->store('profile-avatars', 'public');

        $user->update(['avatar_path' => $path]);

        if ($oldPath && $oldPath !== $path) {
            Storage::disk('public')->delete($oldPath);
        }

        return response()->json([
            'message' => 'Profile photo updated successfully.',
            'currentUser' => $presenter->toArray($user->fresh()),
        ]);
    }

    public function destroyAvatar(Request $request, CurrentUserPresenter $presenter): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $oldPath = $user->avatar_path;

        $user->update(['avatar_path' => null]);

        if ($oldPath) {
            Storage::disk('public')->delete($oldPath);
        }

        return response()->json([
            'message' => 'Profile photo removed successfully.',
            'currentUser' => $presenter->toArray($user->fresh()),
        ]);
    }

    private function initials(string $name): string
    {
        return Str::of($name)
            ->explode(' ')
            ->filter()
            ->take(2)
            ->map(fn (string $part): string => Str::upper(Str::substr($part, 0, 1)))
            ->implode('');
    }
}
