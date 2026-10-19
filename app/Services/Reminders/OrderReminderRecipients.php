<?php

namespace App\Services\Reminders;

use App\Models\FlowJob;
use App\Models\Task;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Resolve only recipients related to this order or explicitly selected active workspace users. */
final class OrderReminderRecipients
{
    public const TYPES = [
        'supplier' => 'Supplier assigned to the order product',
        'owner' => 'Order owner',
        'coordinator' => 'Order coordinator',
        'assignee' => 'Completed task assignee',
        'client' => 'Client email from client master data',
        'client_contact' => 'Client primary contact',
        'user' => 'Specific FlowTracker user',
    ];

    public function userOptions(int $workspaceId): array
    {
        return DB::table('users as u')
            ->join('workspace_memberships as m', 'm.user_id', '=', 'u.id')
            ->where('m.workspace_id', $workspaceId)->where('m.status', 'active')
            ->where('u.is_active', true)->whereNotNull('u.email')->where('u.email', '!=', '')
            ->select('u.id', 'u.name', 'u.email')->distinct()->orderBy('u.name')->limit(250)->get()->all();
    }

    public function userAvailable(int $workspaceId, int $userId): bool
    {
        return DB::table('users as u')
            ->join('workspace_memberships as m', 'm.user_id', '=', 'u.id')
            ->where('u.id', $userId)->where('u.is_active', true)
            ->where('m.workspace_id', $workspaceId)->where('m.status', 'active')->exists();
    }

    /** Each row has a stable idempotency key, optional supplier ID, name, email and permitted order lines. */
    public function resolve(Task $task, FlowJob $order, int $workspaceId, array $config): Collection
    {
        $type = (string) ($config['recipient_type'] ?? 'supplier');
        if ($type === 'supplier') {
            $items = DB::table('flow_job_items as i')
                ->leftJoin('master_records as s', function ($join) use ($workspaceId) {
                    $join->on('s.id', '=', 'i.supplier_id')->where('s.workspace_id', $workspaceId)
                        ->where('s.type', 'supplier')->where('s.status', 'active')->whereNull('s.deleted_at');
                })->where('i.flow_job_id', $order->id)->where('i.is_removed', false)
                ->select('i.supplier_id', 'i.product_name', 'i.quantity', 's.name as supplier_name', 's.metadata')->get();
            if ($items->isEmpty() && $order->supplier_id && ! DB::table('flow_job_items')->where('flow_job_id', $order->id)->exists()) {
                $supplier = DB::table('master_records')->where('id', $order->supplier_id)
                    ->where('workspace_id', $workspaceId)->where('type', 'supplier')
                    ->where('status', 'active')->whereNull('deleted_at')->first(['id', 'name', 'metadata']);
                if ($supplier) $items = collect([(object) [
                    'supplier_id' => $supplier->id, 'product_name' => $order->product,
                    'quantity' => $order->quantity, 'supplier_name' => $supplier->name, 'metadata' => $supplier->metadata,
                ]]);
            }
            if ($items->isEmpty()) return collect([$this->missing('supplier', 'No supplier assigned to this order')]);
            return $items->groupBy(fn ($item) => $item->supplier_id ?: 'missing')->map(function ($lines, $key) {
                $first = $lines->first();
                $metadata = is_array($first->metadata) ? $first->metadata : json_decode((string) $first->metadata, true);
                $id = (int) $first->supplier_id;
                return (object) [
                    'key' => $id && $first->supplier_name ? 'supplier:'.$id : 'missing:supplier',
                    'supplier_id' => $id && $first->supplier_name ? $id : null,
                    'name' => (string) ($first->supplier_name ?: 'Supplier unavailable'),
                    'email' => $id && $first->supplier_name ? trim((string) data_get($metadata, 'email')) : '',
                    'lines' => $lines,
                    'reason' => $id && $first->supplier_name ? null : 'Supplier is not assigned or no longer available',
                ];
            })->values();
        }

        if ($type === 'client' || $type === 'client_contact') {
            $client = DB::table('clients')->where('id', $order->client_id)->where('is_active', true)
                ->first(['id', 'name', 'contact_name', 'email']);
            if (! $client) return collect([$this->missing($type, 'Client is not available')]);
            if ($type === 'client_contact') {
                $contact = DB::table('client_contacts')->where('client_id', $client->id)
                    ->where('is_primary', true)->orderBy('id')->first(['id', 'name', 'email']);
                if (! $contact) return collect([$this->missing($type, 'Primary client contact is not available')]);
                return collect([$this->row('contact:'.$contact->id, $contact->name, $contact->email)]);
            }
            return collect([$this->row('client:'.$client->id, $client->contact_name ?: $client->name, $client->email)]);
        }

        $id = match ($type) {
            'owner' => $order->owner_id,
            'coordinator' => $order->coordinator_id,
            'assignee' => $task->assignee_at_completion ?: $task->assignee_id,
            'user' => $config['recipient_user_id'] ?? null,
            default => null,
        };
        if (! $id) return collect([$this->missing($type, 'No '.str_replace('_', ' ', $type).' is assigned')]);
        $user = DB::table('users as u')
            ->join('workspace_memberships as m', 'm.user_id', '=', 'u.id')
            ->where('u.id', $id)->where('u.is_active', true)
            ->where('m.workspace_id', $workspaceId)->where('m.status', 'active')
            ->first(['u.id', 'u.name', 'u.email']);
        if (! $user) return collect([$this->missing($type, 'Selected user is not active in this workspace')]);
        // A named user must have access to this order. Workspace membership alone is not order permission.
        if ($type === 'user') {
            $candidate = \App\Models\User::query()->find($user->id);
            if (! $candidate || ! app(\App\Policies\FlowJobPolicy::class)->view($candidate, $order)) {
                return collect([$this->missing($type, 'Selected user does not have access to this order')]);
            }
        }
        return collect([$this->row('user:'.$user->id, $user->name, $user->email)]);
    }

    private function row(string $key, ?string $name, ?string $email): object
    {
        return (object) ['key' => $key, 'supplier_id' => null, 'name' => (string) $name,
            'email' => trim((string) $email), 'lines' => collect(), 'reason' => null];
    }

    private function missing(string $type, string $reason): object
    {
        return (object) ['key' => 'missing:'.$type, 'supplier_id' => null, 'name' => 'Recipient unavailable',
            'email' => '', 'lines' => collect(), 'reason' => $reason];
    }
}
