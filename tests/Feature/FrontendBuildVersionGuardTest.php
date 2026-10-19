<?php

namespace Tests\Feature;

use App\Support\FrontendBuildVersion;
use Tests\TestCase;

class FrontendBuildVersionGuardTest extends TestCase
{
    public function test_authenticated_layout_tracks_the_vite_manifest_with_a_stable_query_string_asset(): void
    {
        $layout = (string) file_get_contents(resource_path('views/layouts/app.blade.php'));

        $this->assertStringContainsString("asset('js/flowtrack-build-track.js')", $layout);
        $this->assertStringContainsString('FrontendBuildVersion::current()', $layout);
        $this->assertStringContainsString('data-flowtrack-build-track', $layout);
        $this->assertStringContainsString('data-navigate-track', $layout);
    }

    public function test_build_tracker_has_guarded_order_detail_css_recovery(): void
    {
        $tracker = (string) file_get_contents(public_path('js/flowtrack-build-track.js'));

        $this->assertStringContainsString("document.addEventListener('livewire:navigated'", $tracker);
        $this->assertStringContainsString("getPropertyValue('--ft-order-detail-style-contract')", $tracker);
        $this->assertStringContainsString('flowtrack-order-style-recovery', $tracker);
        $this->assertStringContainsString('window.location.reload();', $tracker);
    }

    public function test_build_version_uses_the_vite_manifest_when_available(): void
    {
        $version = FrontendBuildVersion::current();

        $this->assertNotSame('', $version);
        if (is_file(public_path('build/manifest.json')) && ! is_file(public_path('hot'))) {
            $this->assertMatchesRegularExpression('/^[a-f0-9]{20}$/', $version);
        }
    }

    public function test_order_detail_has_a_runtime_style_contract_in_final_stability_layer(): void
    {
        $css = (string) file_get_contents(resource_path('css/modules/orders/detail/permanent-task-grid.css'));

        $this->assertStringContainsString('--ft-order-detail-style-contract: 20260827;', $css);
    }
}
