<?php

namespace Tests\Feature;

use Tests\TestCase;
use Tests\Support\OrderPhase5Source;

class OrderListInvoicePaymentActionTest extends TestCase
{
    public function test_order_row_action_opens_the_particular_orders_finance_tab(): void
    {
        $view = file_get_contents(resource_path('views/components/jobs/table.blade.php'));
        $orders = file_get_contents(app_path('Livewire/Orders/Index.php'));
        $jobs = OrderPhase5Source::livewire();

        $this->assertStringContainsString('$canViewFinance = $accessControl->can(auth()->user(), \'finance\', \'view\');', $view);
        $this->assertStringContainsString('@if($canViewFinance)', $view);
        $this->assertStringContainsString('@if($canDeleteOrders)', $view);
        $this->assertStringContainsString('wire:click="openInvoiceAndPayment({{ $job->id }})"', $view);
        $this->assertStringContainsString('<span>Invoice and payment</span>', $view);
        $this->assertStringContainsString('<span>Delete order</span>', $view);

        $this->assertStringContainsString('public function openInvoiceAndPayment(int $id): void', $orders);
        $this->assertStringContainsString("can(\$user, 'finance', 'view')", $orders);
        $this->assertStringContainsString("'open' => \$job->id", $orders);
        $this->assertStringContainsString("'tab' => 'finance'", $orders);
        $this->assertStringContainsString("redirectRoute('jobs.index'", $orders);

        $this->assertStringContainsString("requestedDetailTab === 'finance'", $jobs);
        $this->assertStringContainsString("detailTab = 'finance';", $jobs);
        $this->assertStringContainsString('public function openInvoiceAndPayment(int $id): void', $jobs);
    }
}
