<?php

namespace Tests\Feature;

use Tests\TestCase;

class Phase11OperationalListBoundsTest extends TestCase
{
    public function test_high_traffic_operational_lists_are_paginated_or_window_bounded(): void
    {
        $contracts = [
            'Services/OrderListPrototypeService.php' => '->paginate(',
            'Services/LegacyInquiryService.php' => '->paginate(max(1, min(50, $perPage))',
            'Services/MyWorkService.php' => '$groupsQuery->paginate(',
            'Livewire/Documents/Index.php' => '->paginate(max(10, min(100, $this->perPage))',
            'Services/ClientService.php' => '->paginate($perPage)',
            'Services/MasterDataService.php' => '->paginate($perPage',
            'Services/NotificationService.php' => '->paginate($perPage',
        ];

        foreach ($contracts as $relative => $needle) {
            $this->assertStringContainsString($needle, file_get_contents(app_path($relative)), $relative);
        }
    }

    public function test_remote_selectors_keep_their_hard_page_bound(): void
    {
        $source = file_get_contents(app_path('Services/FilterOptionService.php'));
        $this->assertStringContainsString('MAX_PER_PAGE = 20', $source);
        $this->assertStringContainsString('MAX_SELECTED = 100', $source);
        $this->assertStringContainsString('->limit($limit)', $source);
    }
}
