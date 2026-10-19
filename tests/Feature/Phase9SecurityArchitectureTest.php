<?php

namespace Tests\Feature;

use Tests\TestCase;

class Phase9SecurityArchitectureTest extends TestCase
{
    public function test_application_models_do_not_use_unrestricted_guarded_assignment(): void
    {
        foreach (glob(app_path('Models/*.php')) ?: [] as $path) {
            $source = file_get_contents($path);
            $this->assertStringNotContainsString('protected $guarded = []', $source, basename($path));
            $this->assertStringNotContainsString('protected $guarded=[]', $source, basename($path));
            $this->assertStringContainsString('protected $fillable', $source, basename($path));
        }
    }

    public function test_security_headers_are_registered_and_csp_is_report_only(): void
    {
        $middleware = file_get_contents(app_path('Http/Middleware/SecurityHeaders.php'));
        $bootstrap = file_get_contents(base_path('bootstrap/app.php'));

        $this->assertStringContainsString('Content-Security-Policy-Report-Only', $middleware);
        $this->assertStringContainsString('Strict-Transport-Security', $middleware);
        $this->assertStringContainsString("environment('production')", $middleware);
        $this->assertStringContainsString('isSecure()', $middleware);
        $this->assertStringContainsString('SecurityHeaders::class', $bootstrap);
    }

    public function test_policies_delegate_to_access_control_service(): void
    {
        foreach (['FlowJobPolicy', 'InquiryPolicy', 'TaskPolicy', 'InquiryTaskPolicy', 'DocumentPolicy', 'ClientPolicy'] as $policy) {
            $source = file_get_contents(app_path("Policies/{$policy}.php"));
            $this->assertStringContainsString('AccessControlService', $source);
        }
    }
}
