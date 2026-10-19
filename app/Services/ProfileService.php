<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

class ProfileService
{
    public function update(User $user, array $data): User
    {
        $user->update($data);
        return $user->refresh();
    }

    public function updateProfileImage(User $user, UploadedFile $image): User
    {
        $oldPath = $user->profile_image_path;
        $path = $image->storePublicly('profile-images/'.$user->id, 'public');

        $user->update(['profile_image_path' => $path]);

        if ($oldPath && $oldPath !== $path) {
            Storage::disk('public')->delete($oldPath);
        }

        return $user->refresh();
    }

    public function removeProfileImage(User $user): User
    {
        $oldPath = $user->profile_image_path;
        $user->update(['profile_image_path' => null]);

        if ($oldPath) {
            Storage::disk('public')->delete($oldPath);
        }

        return $user->refresh();
    }

    public function changePassword(User $user, string $current, string $new): bool
    {
        if (!Hash::check($current, $user->password)) {
            return false;
        }

        $user->update(['password' => $new]);
        return true;
    }
}
