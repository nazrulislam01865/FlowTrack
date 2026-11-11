<?php

namespace Tests\Feature;

use App\Livewire\Dashboard\Concerns\ReceivesDashboardFilters;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/** Guard against the null reactive-prop crash in bundled Livewire filter updates. */
final class DashboardFilterHydrationRegressionTest extends TestCase
{
    public function test_valid_filter_event_applies_the_new_range_and_ignores_stale_events(): void
    {
        $component = new DashboardFilterStateFixture();
        $component->receiveDashboardFilters(30, '', '12', 'urgent', 1);
        $this->assertSame(30, $component->rangeDays);
        $this->assertSame('12', $component->teamFilter);
        $this->assertSame('urgent', $component->search);
        $this->assertSame(1, $component->filterGeneration);

        $component->receiveDashboardFilters(7, '', '', '', 0);
        $this->assertSame(30, $component->rangeDays);
        $this->assertSame(1, $component->filterGeneration);
        $this->assertSame(1, $component->skipCount);
    }

    public function test_noop_filter_generation_does_not_rerender_the_panel(): void
    {
        $component = new DashboardFilterStateFixture();
        $component->receiveDashboardFilters(7, '', '', '', 0);
        $this->assertSame(1, $component->skipCount);
    }

    public function test_null_range_is_rejected_without_assigning_it_to_an_integer_property(): void
    {
        $component = new DashboardFilterStateFixture();
        try {
            $component->receiveDashboardFilters(null, '', '', '', 1);
            $this->fail('An invalid range must be rejected.');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
            $this->assertSame(7, $component->rangeDays);
        }
    }

    public function test_release_c_panels_no_longer_use_reactive_filter_hydration(): void
    {
        foreach (['Panel', 'OverviewPanel', 'TaggedComments'] as $component) {
            $source = file_get_contents(app_path('Livewire/Dashboard/'.$component.'.php'));
            $this->assertStringNotContainsString('#[Reactive]', $source);
            $this->assertStringContainsString('ReceivesDashboardFilters', $source);
        }

        $parent = file_get_contents(app_path('Livewire/Dashboard/Index.php'));
        $this->assertStringContainsString("\$this->dispatch('dashboard-filters-updated',", $parent);
        $this->assertStringContainsString('if ($changed) $this->broadcastDashboardFilters();', $parent);
        $catalogue = file_get_contents(app_path('Livewire/Dashboard/CataloguePanel.php'));
        $this->assertStringContainsString('dashboardFiltersAreRelevant(): bool { return false; }', $catalogue);
    }
}

/** No database, dashboard query, or Livewire runtime is required for the data contract. */
final class DashboardFilterStateFixture
{
    use ReceivesDashboardFilters;

    public int $rangeDays = 7;
    public string $clientFilter = '';
    public string $teamFilter = '';
    public string $search = '';
    public int $filterGeneration = 0;
    public int $skipCount = 0;

    public function skipRender(): void
    {
        $this->skipCount++;
    }
}
