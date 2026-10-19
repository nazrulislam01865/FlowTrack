<?php

namespace App\Livewire\Profile;

use App\Livewire\Concerns\UsesPagePlaceholder;
use App\Livewire\Concerns\RefreshesFromWorkspace;
use App\Models\User;
use App\Services\AdminService;
use App\Services\AccessControlService;
use App\Services\ProfileService;
use Livewire\Component;
use Livewire\WithFileUploads;

class Index extends Component
{
    use RefreshesFromWorkspace;
    use UsesPagePlaceholder;
    use WithFileUploads;

    public string $name = '';
    public string $email = '';
    public string $locale = 'en';
    public $profileImage = null;
    public string $currentPassword = '';
    public string $newPassword = '';
    public string $newPasswordConfirmation = '';

    public function mount(): void
    {
        $user = auth()->user();
        $this->name = $user->name;
        $this->email = $user->email;
        $this->locale = filled($user->locale) ? (string) $user->locale : 'en';
    }

    public function saveProfile(): void
    {
        $data = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'unique:users,email,'.auth()->id()],
            'locale' => ['required', 'in:en,zh'],
        ]);

        app(ProfileService::class)->update(auth()->user(), $data);
        session()->flash('success', 'Profile updated.');
    }

    public function saveProfileImage()
    {
        $this->validate([
            'profileImage' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:250'],
        ]);

        app(ProfileService::class)->updateProfileImage(auth()->user(), $this->profileImage);
        $this->reset('profileImage');
        session()->flash('success', 'Profile image updated.');

        return $this->redirectRoute('profile');
    }

    public function removeProfileImage()
    {
        app(ProfileService::class)->removeProfileImage(auth()->user());
        $this->reset('profileImage');
        session()->flash('success', 'Profile image removed.');

        return $this->redirectRoute('profile');
    }

    public function changePassword(): void
    {
        $this->validate([
            'currentPassword' => ['required', 'string'],
            'newPassword' => ['required', 'string', 'min:10', 'same:newPasswordConfirmation'],
        ]);

        if (!app(ProfileService::class)->changePassword(auth()->user(), $this->currentPassword, $this->newPassword)) {
            $this->addError('currentPassword', 'Current password is incorrect.');
            return;
        }

        $this->reset(['currentPassword', 'newPassword', 'newPasswordConfirmation']);
        session()->flash('success', 'Password updated.');
    }

    public function render()
    {
        return view('livewire.profile.index', $this->profilePageData());
    }

    private function profilePageData(): array
    {
        /** @var User $user */
        $user = auth()->user();
        $user->loadMissing(['role:id,name', 'department:id,name']);

        return [
            'user' => $user,
            'position' => app(AdminService::class)->positionFor($user),
            'notificationPreferences' => $this->notificationPreferences(),
            'canManageBranding' => app(AccessControlService::class)->isAdministrator($user),
        ];
    }

    private function notificationPreferences(): array
    {
        return [
            ['Task assignments', 'When a task is assigned to you'],
            ['Due date reminders', 'Reminders for approaching due dates'],
            ['Mentions and comments', 'When someone mentions you or comments'],
            ['Approval requests', 'Requests that need your approval'],
            ['Shipment alerts', 'Updates related to shipments'],
            ['Payment reminders', 'Reminders related to payments'],
        ];
    }
}
