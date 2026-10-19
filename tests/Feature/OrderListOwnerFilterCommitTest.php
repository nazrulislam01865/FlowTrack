<?php

namespace Tests\Feature;

use Tests\TestCase;

class OrderListOwnerFilterCommitTest extends TestCase
{
    public function test_order_list_owner_filter_uses_explicit_server_commit_action(): void
    {
        $filters = file_get_contents(resource_path('views/components/orders/list/filters.blade.php'));
        $component = file_get_contents(app_path('Livewire/Orders/Index.php'));
        $searchSelect = file_get_contents(resource_path('views/components/ui/search-select.blade.php'));
        $prototype = file_get_contents(app_path('Services/OrderListPrototypeService.php'));

        $this->assertStringContainsString('property="owner"', $filters);
        $this->assertStringContainsString('action="applyOwnerFilter"', $filters);
        $this->assertStringContainsString('public function applyOwnerFilter(', $component);
        $this->assertStringContainsString("abort_unless(\$property === 'owner', 422);", $component);
        $this->assertStringContainsString("\$this->owner = \$this->normalizeNumericFilter", $component);
        $this->assertStringContainsString("'owner_id' => \$this->filterId(\$this->owner)", $component);
        $this->assertStringContainsString("\$this->positiveInt(\$filters['owner_id'] ?? null)", $prototype);

        // Action-backed selectors commit immediately in the click transaction;
        // generic property-backed selectors retain their existing nextTick path.
        $this->assertStringContainsString('Promise.resolve($wire.call(@js($action)', $searchSelect);
        $this->assertStringContainsString('$nextTick(() => Promise.resolve($wire.set(@js($property)', $searchSelect);
    }
}
