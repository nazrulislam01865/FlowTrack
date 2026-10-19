<?php

namespace Tests\Feature;

use Tests\TestCase;

class Phase15ReleaseHardeningArchitectureTest extends TestCase
{
    public function test_final_release_boundaries_are_present_and_obsolete_assets_are_absent(): void
    {
        $root = base_path();

        foreach ([
            '.github/workflows/flowtrack-ci.yml',
            'config/observability.php',
            'app/Services/Observability/OperationsMetrics.php',
            'resources/js/core/browser-api.js',
            'quality/phase15-legacy-exceptions.json',
            'scripts/quality/phase15-release-hardening.php',
            'docs/ENTERPRISE_READINESS_REPORT.md',
        ] as $relative) {
            $this->assertFileExists($root.'/'.$relative);
        }

        foreach ([
            'resources/views/welcome.blade.php',
            'resources/js/compatibility/browser-bridge.js',
            'scripts/split-flowtrack-css.mjs',
            'resources/css/generated/flowtrack-01.css',
            'resources/css/generated/flowtrack-02.css',
            'resources/css/generated/flowtrack-03.css',
            'resources/css/generated/flowtrack-04.css',
        ] as $relative) {
            $this->assertFileDoesNotExist($root.'/'.$relative);
        }

        $vite = file_get_contents($root.'/vite.config.js');
        $this->assertStringContainsString("'resources/css/app.css'", $vite);
        $this->assertStringNotContainsString('splitFlowtrackCss', $vite);
    }

    public function test_legacy_exception_register_is_shrinking_only(): void
    {
        $register = json_decode(file_get_contents(base_path('quality/phase15-legacy-exceptions.json')), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('frozen_minimal_compatibility_exception_set', $register['policy']);
        $this->assertNotEmpty($register['legacy_services']);
        $this->assertSame([], $register['legacy_css']);

        foreach ($register['legacy_css'] as $entry) {
            $path = base_path($entry['file']);
            if (! is_file($path)) continue;
            $this->assertLessThanOrEqual($entry['bytes'], filesize($path));
            $this->assertLessThanOrEqual($entry['important'], substr_count(file_get_contents($path), '!important'));
        }
    }
}
