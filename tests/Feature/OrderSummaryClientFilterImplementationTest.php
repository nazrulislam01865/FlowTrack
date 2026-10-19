<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

class OrderSummaryClientFilterImplementationTest extends TestCase
{
    public function test_order_summary_supports_multi_client_checkbox_filtering_and_export(): void
    {
        $root = dirname(__DIR__, 2);
        $component = file_get_contents($root.'/app/Livewire/Reports/OrderSummary.php');
        $service = file_get_contents($root.'/app/Services/OrderSummaryReportService.php');
        $view = file_get_contents($root.'/resources/views/livewire/reports/order-summary.blade.php');
        $clientFilter = file_get_contents($root.'/resources/views/components/reports/client-checkbox-filter.blade.php');
        $export = file_get_contents($root.'/app/Http/Controllers/OrderSummaryExportController.php');

        self::assertStringContainsString('public array $clientIds = [];', $component);
        self::assertStringContainsString("'client_ids' => \$this->normalizedClientIds()", $component);
        self::assertStringContainsString("'clientOptions' => \$service->clientOptions(\$user)", $component);
        self::assertStringContainsString('clearClientFilter', $component);

        self::assertStringContainsString('public function clientOptions(User $user): Collection', $service);
        self::assertStringContainsString("whereIn('flow_jobs.client_id', \$clientIds)", $service);
        self::assertStringContainsString('normalizeClientIds', $service);

        self::assertStringContainsString('<x-reports.client-checkbox-filter', $view);
        self::assertStringContainsString('wire:model.live="clientIds"', $clientFilter);
        self::assertStringContainsString('Leave all unchecked to show every client.', $clientFilter);

        self::assertStringContainsString("'client_ids' => ['nullable', 'array', 'max:500']", $export);
        self::assertStringContainsString("'client_ids.*' => ['integer', 'min:1', 'distinct']", $export);
    }
}
