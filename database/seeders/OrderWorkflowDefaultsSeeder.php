<?php

namespace Database\Seeders;

use App\Models\Workflow;
use App\Models\WorkflowTemplate;
use App\Services\OrderWorkflowSetupService;
use App\Services\SetupContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class OrderWorkflowDefaultsSeeder extends Seeder
{
    public function run(): void
    {
        $workspaceId = app(SetupContext::class)->workspaceId();

        $existing = WorkflowTemplate::query()
            ->where('workspace_id', $workspaceId)
            ->where('code', OrderWorkflowSetupService::WORKFLOW_CODE)
            ->first();

        if ($existing) {
            $ready = app(OrderWorkflowSetupService::class)
                ->isReadyForOrderCreation((int) $existing->id);

            if ($ready) {
                $this->command?->info(
                    'FlowTrack Order Workflow already exists and is ready. Nothing changed.'
                );
            } else {
                $this->command?->warn(
                    'ORDER_PROCESS already exists but is incomplete. Seeder did not overwrite it.'
                );
            }

            return;
        }

        DB::transaction(function () use ($workspaceId): void {
            $isDefault = ! WorkflowTemplate::query()
                ->where('workspace_id', $workspaceId)
                ->where('is_default', true)
                ->exists();

            /*
             * Create the runtime/legacy Workflow first so both workflow
             * representations use the same primary key.
             */
            $legacy = Workflow::query()->create([
                'name' => OrderWorkflowSetupService::WORKFLOW_NAME,
                'slug' => 'flowtrack-order-workflow-'.Str::lower(Str::random(6)),
                'description' => 'Fixed seven-stage FlowTrack Order workflow.',
                'is_active' => true,
            ]);

            $template = WorkflowTemplate::query()->create([
                'id' => $legacy->id,
                'workspace_id' => $workspaceId,
                'code' => OrderWorkflowSetupService::WORKFLOW_CODE,
                'name' => OrderWorkflowSetupService::WORKFLOW_NAME,
                'description' => 'Fixed seven-stage Order workflow managed from Workflow Setup.',
                'is_active' => true,
                'is_default' => $isDefault,
                'version' => 1,
                'applies_to' => 'orders',
                'client_availability' => 'all',
            ]);

            /*
             * Uses the real FlowTrack workflow service to create:
             *
             * - New Order
             * - Artwork
             * - Production
             * - QC
             * - Shipment
             * - Billing
             * - Payment
             *
             * It also creates the seven Task Packs and their protected
             * automation-key tasks/document requirements.
             */
            $setup = app(OrderWorkflowSetupService::class);

            $setup->initializeWorkflowTemplate($template);

            if (! $setup->isReadyForOrderCreation((int) $template->id)) {
                throw new RuntimeException(
                    'Order Workflow was created but failed readiness validation.'
                );
            }
        });

        $this->command?->info(
            'FlowTrack Order Workflow and all seven Task Packs created successfully.'
        );
    }
}
