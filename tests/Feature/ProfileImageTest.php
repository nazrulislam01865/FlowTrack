<?php

namespace Tests\Feature;

use App\Livewire\Profile\Index;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class ProfileImageTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_upload_and_remove_profile_image(): void
    {
        Storage::fake('public');
        $user = User::factory()->create(['is_super_admin' => true, 'is_active' => true]);
        $image = UploadedFile::fake()->createWithContent('avatar.png', $this->tinyPng());

        Livewire::actingAs($user)
            ->test(Index::class)
            ->set('profileImage', $image)
            ->call('saveProfileImage')
            ->assertHasNoErrors()
            ->assertRedirect(route('profile'));

        $user->refresh();
        $this->assertNotNull($user->profile_image_path);
        $storedPath = $user->profile_image_path;
        Storage::disk('public')->assertExists($storedPath);

        Livewire::actingAs($user)
            ->test(Index::class)
            ->call('removeProfileImage')
            ->assertRedirect(route('profile'));

        $user->refresh();
        $this->assertNull($user->profile_image_path);
        Storage::disk('public')->assertMissing($storedPath);
    }

    public function test_profile_image_is_served_without_a_public_storage_symlink(): void
    {
        Storage::fake('public');
        $user = User::factory()->create(['is_super_admin' => true, 'is_active' => true]);
        $path = 'profile-images/'.$user->id.'/avatar.png';

        Storage::disk('public')->put($path, $this->tinyPng());
        $user->update(['profile_image_path' => $path]);

        $response = $this->actingAs($user)
            ->get(route('profile-images.show', [
                'user' => $user->id,
                'filename' => 'avatar.png',
            ], false))
            ->assertOk();

        $this->assertCacheControlDirectives($response, ['private', 'max-age=31536000', 'immutable']);
    }

    public function test_profile_image_is_limited_to_two_megabytes(): void
    {
        Storage::fake('public');
        $user = User::factory()->create(['is_super_admin' => true, 'is_active' => true]);
        $image = UploadedFile::fake()->createWithContent('too-large.png', $this->tinyPng().str_repeat('x', 2_200 * 1024));

        Livewire::actingAs($user)
            ->test(Index::class)
            ->set('profileImage', $image)
            ->call('saveProfileImage')
            ->assertHasErrors(['profileImage' => 'max']);

        $this->assertNull($user->fresh()->profile_image_path);
    }

    private function tinyPng(): string
    {
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl2kwsAAAAASUVORK5CYII=');
    }
}
