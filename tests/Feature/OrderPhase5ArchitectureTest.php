<?php

namespace Tests\Feature;

use Tests\Support\OrderPhase5Source;
use Tests\TestCase;

class OrderPhase5ArchitectureTest extends TestCase
{
    public function test_jobs_index_remains_a_small_compatibility_coordinator(): void
    {
        $index = file_get_contents(app_path('Livewire/Jobs/Index.php'));

        $this->assertLessThanOrEqual(500, substr_count($index, "\n") + 1);
        $this->assertStringContainsString("#[Url(as: 'open', history: true)]", $index);
        $this->assertStringContainsString("#[Url(as: 'task', history: true)]", $index);
        $this->assertStringContainsString("#[Url(as: 'comment', history: true)]", $index);
        $this->assertStringContainsString('use ManagesOrderCreation;', $index);
        $this->assertStringContainsString('use ManagesOrderTasks;', $index);
        $this->assertStringContainsString('use BuildsOrderPageData;', $index);
    }

    public function test_phase5_order_actions_and_queries_do_not_depend_on_livewire(): void
    {
        foreach ([app_path('Actions/Orders'), app_path('Queries/Orders')] as $directory) {
            foreach (glob($directory.'/*.php') ?: [] as $file) {
                $source = file_get_contents($file);
                $this->assertStringNotContainsString('App\\Livewire\\', $source, $file);
                $this->assertDoesNotMatchRegularExpression('/\\bLivewire\\\\/', $source, $file);
            }
        }
    }

    public function test_existing_order_source_contracts_remain_discoverable_after_decomposition(): void
    {
        $source = OrderPhase5Source::livewire();

        foreach ([
            'public function createJob',
            'public function openJob',
            'public function updateJobOwner',
            'public function addOrderTask',
            'public function uploadJobDocuments',
            'public function createInvoice',
            'public function addJobComment',
        ] as $contract) {
            $this->assertStringContainsString($contract, $source);
        }
    }
}
