<?php

namespace Tests\Feature;

use App\Services\StoredAssetUrlService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StoredAssetUrlGuardPerformanceTest extends TestCase
{
    public function test_missing_public_assets_do_not_emit_routes_that_will_404(): void
    {
        Storage::fake('public');
        $assets = app(StoredAssetUrlService::class);

        $this->assertNull($assets->profileImageUrl(58, 'profile-images/58/missing.webp'));
        $this->assertNull($assets->clientLogoUrl(12, 'client-logos/12/missing.png'));
        $this->assertNull($assets->productImageUrl(99, 1, 'product-images/1/99/missing.jpg'));
        $this->assertNull($assets->brandingAssetUrl(1, 'logo', 'branding/1/logo/missing.webp'));
    }

    public function test_existing_public_assets_keep_the_same_protected_routes(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('profile-images/58/avatar.webp', 'image');
        Storage::disk('public')->put('client-logos/12/logo.png', 'image');
        Storage::disk('public')->put('product-images/1/99/product.jpg', 'image');
        Storage::disk('public')->put('branding/1/logo/system.webp', 'image');

        $assets = app(StoredAssetUrlService::class);

        $this->assertSame('/profile-images/58/avatar.webp', $assets->profileImageUrl(58, 'profile-images/58/avatar.webp'));
        $this->assertSame('/client-logos/12/logo.png', $assets->clientLogoUrl(12, 'client-logos/12/logo.png'));
        $this->assertSame('/master-data/products/99/image/product.jpg', $assets->productImageUrl(99, 1, 'product-images/1/99/product.jpg'));
        $this->assertSame('/branding-assets/logo/system.webp', $assets->brandingAssetUrl(1, 'logo', 'branding/1/logo/system.webp'));
    }

    public function test_invalid_cross_owner_paths_are_not_exposed(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('profile-images/99/avatar.webp', 'image');
        Storage::disk('public')->put('client-logos/99/logo.png', 'image');

        $assets = app(StoredAssetUrlService::class);

        $this->assertNull($assets->profileImageUrl(58, 'profile-images/99/avatar.webp'));
        $this->assertNull($assets->clientLogoUrl(12, 'client-logos/99/logo.png'));
    }
}
