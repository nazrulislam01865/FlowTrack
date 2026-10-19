<?php

namespace Tests\Feature;

use Tests\TestCase;

class InquirySingleFilterBehaviorTest extends TestCase
{
    public function test_inquiry_list_has_explicit_clear_filter_action_and_exclusive_filter_logic(): void
    {
        $view = $this->inquiryViewSource();
        $component = $this->inquiryLivewireSource();
        $reset = file_get_contents(resource_path('views/components/ui/filter-reset.blade.php'));
        $css = $this->compatibilityCss('flowtrack-inquiries.css');

        $this->assertStringContainsString('<x-ui.filter-reset', $view);
        $this->assertStringContainsString('action="clearFilters"', $view);
        $this->assertStringContainsString('label="Clear"', $view);
        $this->assertStringContainsString(':disabled="! $inquiryAnyFilterActive"', $view);
        $this->assertStringContainsString('wire:click="{{ $action }}"', $reset);
        $this->assertStringContainsString('@disabled($disabled)', $reset);
        $this->assertStringContainsString('public function clearFilters(): void', $component);
        $this->assertStringContainsString("\$this->clearListFiltersExcept('search');", $component);
        $this->assertStringContainsString("\$this->clearListFiltersExcept('status');", $component);
        $this->assertStringContainsString("\$this->clearListFiltersExcept('client');", $component);
        $this->assertStringContainsString("\$this->clearListFiltersExcept('hideCompleted');", $component);
        $this->assertStringContainsString("\$this->clearListFiltersExcept('quick');", $component);
        $this->assertStringContainsString('private function clearListFiltersExcept(string $except): void', $component);
        $this->assertStringContainsString('.ft-inquiry-clear-filter', $css);
    }
}
