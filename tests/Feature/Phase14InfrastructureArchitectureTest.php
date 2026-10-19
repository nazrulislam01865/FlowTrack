<?php

namespace Tests\Feature;

use Tests\TestCase;

class Phase14InfrastructureArchitectureTest extends TestCase
{
    public function test_horizontal_profile_and_health_boundaries_are_present(): void
    {
        $cache = file_get_contents(config_path('cache.php'));
        $session = file_get_contents(config_path('session.php'));
        $queue = file_get_contents(config_path('queue.php'));
        $bootstrap = file_get_contents(base_path('bootstrap/app.php'));

        $this->assertStringContainsString('FLOWTRACK_HORIZONTAL_SCALING', $cache);
        $this->assertStringContainsString('FLOWTRACK_HORIZONTAL_SCALING', $session);
        $this->assertStringContainsString('FLOWTRACK_HORIZONTAL_SCALING', $queue);
        $this->assertStringContainsString('/health/ready', $bootstrap);
    }

    public function test_shared_storage_and_supervised_process_definitions_are_present(): void
    {
        $filesystems = file_get_contents(config_path('filesystems.php'));
        $workers = file_get_contents(base_path('deploy/flowtrack-workers-horizontal.conf.example'));

        $this->assertStringContainsString('FLOWTRACK_PUBLIC_STORAGE_PATH', $filesystems);
        $this->assertStringContainsString('FLOWTRACK_PRIVATE_STORAGE_PATH', $filesystems);
        $this->assertStringContainsString('--queue=realtime', $workers);
        $this->assertStringContainsString('--queue=notifications', $workers);
        $this->assertStringContainsString('--queue=default', $workers);
    }
}
