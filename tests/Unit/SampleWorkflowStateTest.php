<?php

namespace Tests\Unit;

use App\Models\Task;
use App\Services\Orders\SampleWorkflowService;
use PHPUnit\Framework\TestCase;

final class SampleWorkflowStateTest extends TestCase
{
    public function test_one_conditional_task_exposes_each_sample_handoff_without_new_task_rows(): void
    {
        $statuses = [
            'Ready' => ['request', 'Request sample'],
            'Sample: Supplier Preparing' => ['supplier_prepare', 'View request'],
            'Sample: Awaiting Receipt' => ['flow_receive', 'Confirm sample received'],
            'Sample: Awaiting Decision' => ['flow_decision', 'Record sample decision'],
            'Sample: Supplier Revision' => ['supplier_revision', 'View revision'],
            'Sample: Awaiting Shipment' => ['flow_ship', 'Record sample shipment'],
            'Completed' => ['approved', 'View sample details'],
        ];

        foreach ($statuses as $status => [$state, $action]) {
            $task = new Task();
            $task->status = $status;
            self::assertSame($state, SampleWorkflowService::state($task));
            self::assertSame($action, SampleWorkflowService::presentation($task)['action']);
        }
    }

    public function test_supplier_only_prepares_sample_or_sends_images_and_does_not_handle_shipping(): void
    {
        $task = new Task();
        $task->status = 'Sample: Supplier Preparing';
        self::assertSame('Prepare physical sample', SampleWorkflowService::supplierPresentation($task, ['kind' => 'physical'])['title']);
        self::assertSame('Send sample images by email', SampleWorkflowService::supplierPresentation($task, ['kind' => 'images'])['title']);

        $task->status = 'Sample: Awaiting Shipment';
        self::assertSame('Sample approved', SampleWorkflowService::supplierPresentation($task, ['kind' => 'physical'])['title']);
        self::assertSame('Onward shipment managed in FlowTracker Main ERP', SampleWorkflowService::supplierPresentation($task, ['kind' => 'physical'])['description']);
    }
}
