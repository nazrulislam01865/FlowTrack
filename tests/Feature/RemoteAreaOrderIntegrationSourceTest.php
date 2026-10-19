<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Lightweight source-contract coverage for the cross-cutting Remote Area
 * integration. Runtime behavior is covered by the Master Data tests; these
 * assertions guard the important query-free UI and authoritative billing
 * boundaries from accidental regression during future view refactors.
 */
class RemoteAreaOrderIntegrationSourceTest extends TestCase
{
    public function test_order_detail_resolves_remote_area_before_rendering_blade(): void
    {
        $viewService = file_get_contents(app_path('Services/OrderDetailViewService.php'));
        $planning = file_get_contents(resource_path('views/components/jobs/order-detail/planning.blade.php'));
        $shipping = file_get_contents(resource_path('views/components/jobs/order-detail/shipping.blade.php'));

        $this->assertStringContainsString('$remoteArea = $masterData->remoteAreaForPostalCode($job->shipping_postal_code);', $viewService);
        $this->assertStringContainsString("'remoteArea' => \$remoteArea ? [", $viewService);
        $this->assertStringContainsString("'postal_code' => \$masterData->normalizePostalCode((string) \$job->shipping_postal_code)", $viewService);
        $this->assertStringContainsString('@if($remoteArea)', $planning);
        $this->assertStringContainsString('Remote Area', $planning);
        $this->assertLessThan(
            strpos($planning, 'Hand Date'),
            strpos($planning, 'Remote area'),
            'Remote Area must render before Hand Date in Planning & ownership.'
        );
        $this->assertStringNotContainsString('Remote Area', $shipping);

        // Blade must stay presentation-only: no MasterRecord/MasterData query is
        // allowed from either details card, preventing render-time N+1s.
        $this->assertStringNotContainsString('MasterRecord::query()', $planning);
        $this->assertStringNotContainsString('remoteAreaForPostalCode(', $planning);
        $this->assertStringNotContainsString('MasterRecord::query()', $shipping);
        $this->assertStringNotContainsString('remoteAreaForPostalCode(', $shipping);
    }

    public function test_invoice_service_re_resolves_and_applies_system_surcharge_server_side(): void
    {
        $finance = file_get_contents(app_path('Services/OrderFinanceService.php'));

        $this->assertStringContainsString('$lockedJob = FlowJob::query()->whereKey($job->id)->lockForUpdate()->firstOrFail();', $finance);
        $this->assertStringContainsString('$items = $this->applyRemoteAreaSurcharge($lockedJob, $items);', $finance);
        $this->assertStringContainsString('REMOTE_AREA_SURCHARGE_PREFIX', $finance);
        $this->assertStringContainsString('remoteAreaForPostalCode($job->shipping_postal_code)', $finance);
        $this->assertStringContainsString('normalizePostalCode((string) $job->shipping_postal_code)', $finance);
        $this->assertStringContainsString("'unit_price' => round(\$charge, 2)", $finance);
    }

    public function test_remote_area_lookup_uses_request_local_exact_and_range_indexes(): void
    {
        $master = file_get_contents(app_path('Services/MasterDataService.php'));

        $this->assertStringContainsString('private ?array $remoteAreaByPostalCode = null;', $master);
        $this->assertStringContainsString('private ?array $remoteAreaPostalRanges = null;', $master);
        $this->assertStringContainsString('foreach ($this->active(\'remote_area\') as $record)', $master);
        $this->assertStringContainsString('postalCodeFallsWithinRange', $master);
        $this->assertStringContainsString("return \$range['record'];", $master);
    }
}
