<?php

namespace App\Services\Reminders;

use Illuminate\Support\Facades\DB;

/** Read-only event choices sourced from active Order Workflow Setup / Task Pack Setup. */
final class OrderReminderEventCatalog
{
    public const ARTWORK_EVENT = 'order_task:ART_INTERNAL_REVIEW:confirm';
    public const PORTAL_ACCESS_EVENT = 'supplier_portal:access_updated';

    public static function eventFor(string $automationKey): string
    {
        $automationKey = strtoupper(trim($automationKey));
        return 'order_task:'.$automationKey.':'.($automationKey === 'ART_INTERNAL_REVIEW' ? 'confirm' : 'completed');
    }

    public function options(int $workspaceId): array
    {
        $rows = DB::table('workflow_templates as w')
            ->join('workflow_phases as p', 'p.workflow_template_id', '=', 'w.id')
            ->join('task_packs as pk', 'pk.id', '=', 'p.task_pack_id')
            ->join('task_pack_items as i', 'i.task_pack_id', '=', 'pk.id')
            ->where('w.workspace_id', $workspaceId)->where('w.applies_to', 'orders')
            ->where('w.is_active', true)->where('p.is_active', true)
            ->where('pk.workspace_id', $workspaceId)->where('pk.is_active', true)
            ->where('pk.is_snapshot', false)
            ->whereNotNull('i.automation_key')->where('i.automation_key', '!=', '')
            ->orderBy('w.name')->orderBy('p.sequence')->orderBy('i.sort_order')
            ->get(['w.name as workflow', 'p.name as stage', 'i.title as task', 'i.automation_key as key']);

        $options = [];
        foreach ($rows as $row) {
            $key = strtoupper(trim((string) $row->key));
            // Only stable action keys; never use an arbitrary user-editable task title as an event ID.
            if (! preg_match('/^[A-Z][A-Z0-9_]{1,70}$/D', $key)) continue;
            $event = self::eventFor($key);
            if (isset($options[$event])) continue; // Same action may appear in several workflow versions.
            $options[$event] = [
                'key' => $event, 'module' => 'Order', 'workflow' => (string) $row->workflow,
                'stage' => (string) $row->stage, 'task' => (string) $row->task,
                'action' => $key === 'ART_INTERNAL_REVIEW' ? 'Confirm' : 'Task completed',
            ];
        }

        // Existing installations may have preconfigured artwork reminders before Workflow Setup was populated.
        $options[self::ARTWORK_EVENT] ??= [
            'key' => self::ARTWORK_EVENT, 'module' => 'Order',
            'workflow' => 'FlowTrack Order Workflow', 'stage' => 'Artwork',
            'task' => 'Internal Artwork Review', 'action' => 'Confirm',
        ];
        $options[self::PORTAL_ACCESS_EVENT] = [
            'key' => self::PORTAL_ACCESS_EVENT, 'module' => 'Supplier',
            'workflow' => 'Supplier Portal', 'stage' => 'Portal Access',
            'task' => 'Dashboard account created or credentials changed',
            'action' => 'Successful account save',
        ];
        return array_values($options);
    }

    public function supports(int $workspaceId, string $eventKey): bool
    {
        foreach ($this->options($workspaceId) as $option) {
            if ($option['key'] === $eventKey) return true;
        }
        return false;
    }
}
