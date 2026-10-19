<?php

namespace Tests\Feature;

use Tests\TestCase;

class OrderTaskFlagHydrationRegressionTest extends TestCase
{
    public function test_order_flag_sync_does_not_refresh_the_hydrated_view_model_in_place(): void
    {
        $service = file_get_contents(app_path('Services/OrderTaskFlagService.php'));
        $jobService = $this->jobServiceSource();
        $presenter = file_get_contents(app_path('Support/JobDetailPresenter.php'));

        $syncJobStart = strpos($service, 'public function syncJob(?FlowJob $job)');
        $syncDueStart = strpos($service, 'public function syncDueTransitions', $syncJobStart);
        $syncJob = substr($service, $syncJobStart, $syncDueStart - $syncJobStart);

        $this->assertStringNotContainsString('$job->refresh()', $syncJob);
        $this->assertStringContainsString('$job->fresh()', $syncJob);
        $this->assertStringNotContainsString("setRelation('visibleTaskLinks'", $jobService);
        $this->assertStringNotContainsString('visibleTaskLinks', $presenter);
        $this->assertStringContainsString("relationLoaded('links')", $presenter);
    }
}
