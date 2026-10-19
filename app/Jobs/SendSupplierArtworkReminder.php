<?php

namespace App\Jobs;

use App\DTOs\Email\EmailMessage;
use App\Models\FlowJob;
use App\Models\Task;
use App\Services\Email\EmailService;
use App\Services\Email\ModuleEmailControlService;
use App\Services\Reminders\OrderReminderEventCatalog;
use App\Services\Reminders\OrderReminderRecipients;
use App\Services\Reminders\SupplierArtworkReminderService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

final class SendSupplierArtworkReminder implements ShouldQueue, ShouldBeEncrypted
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [30, 120, 300];
    public int $timeout = 45;

    public function __construct(
        public readonly int $taskId,
        public readonly int $workspaceId,
        public readonly string $eventKey = SupplierArtworkReminderService::ARTWORK_EVENT,
        public readonly ?int $templateId = null,
    ) {
        $this->onQueue((string) config('flowtrack_email.queue.name', 'emails'));
        $connection = (string) config('flowtrack_email.queue.connection', '');
        if ($connection !== '') $this->onConnection($connection);
        $this->afterCommit();
    }

    public function handle(SupplierArtworkReminderService $reminders, OrderReminderRecipients $recipients, EmailService $email, ModuleEmailControlService $control): void
    {
        // Pausing or replacing a published template prevents an already queued job from sending.
        $config = $this->templateId
            ? $reminders->publishedTemplate($this->workspaceId, $this->eventKey, $this->templateId)
            : $reminders->published($this->workspaceId, $this->eventKey);
        if (! $config) return;

        $task = Task::query()->whereKey($this->taskId)->whereNotNull('completed_at')
            ->first(['id', 'flow_job_id', 'assignee_id', 'assignee_at_completion', 'completed_at', 'title', 'task_pack_task_id']);
        if (! $task) return;
        $actualKey = app(\App\Services\OrderWorkflowActionService::class)->automationKey($task);
        if (! $actualKey || OrderReminderEventCatalog::eventFor($actualKey) !== $this->eventKey) return;
        $order = FlowJob::query()->whereKey($task->flow_job_id)
            ->first(['id', 'job_number', 'order_number', 'supplier_id', 'client_id', 'owner_id', 'coordinator_id', 'product', 'quantity', 'status']);
        if (! $order || strcasecmp((string) $order->status, 'Cancelled') === 0) return;

        foreach ($recipients->resolve($task, $order, $this->workspaceId, $config) as $recipient) {
            $query = fn () => DB::table('supplier_artwork_reminder_deliveries')
                ->where('task_id', $task->id)->where('recipient_key', $recipient->key);
            $existing = $query()->first(['status', 'updated_at']);
            if ($existing && in_array($existing->status, ['sent', 'skipped'], true)) continue;
            if ($existing && $existing->status === 'sending') {
                if ($existing->updated_at && $existing->updated_at < now()->subSeconds(60)->toDateTimeString()) {
                    $query()->where('status', 'sending')->update([
                        'status' => 'failed', 'reason' => 'Delivery outcome uncertain; verify provider logs before retrying', 'updated_at' => now(),
                    ]);
                }
                continue;
            }
            DB::table('supplier_artwork_reminder_deliveries')->insertOrIgnore([
                'workspace_id' => $this->workspaceId, 'flow_job_id' => $order->id,
                'task_id' => $task->id, 'supplier_id' => $recipient->supplier_id,
                'recipient_key' => $recipient->key, 'supplier_name' => $recipient->name,
                'recipient_email' => filter_var($recipient->email, FILTER_VALIDATE_EMAIL) ? $recipient->email : null,
                'status' => 'queued', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $claimed = $query()->whereIn('status', ['queued', 'failed'])
                ->update(['status' => 'sending', 'updated_at' => now()]);
            if (! $claimed) continue;
            $record = $query();
            if ($recipient->reason || ! filter_var($recipient->email, FILTER_VALIDATE_EMAIL) || ! $control->orderEnabled()) {
                $record->update([
                    'status' => 'skipped', 'reason' => $recipient->reason
                        ?: (! filter_var($recipient->email, FILTER_VALIDATE_EMAIL) ? 'Recipient email is unavailable or invalid' : 'Order email service disabled'),
                    'updated_at' => now(),
                ]);
                continue;
            }
            // Supplier messages include only items assigned to that supplier, never another supplier's products.
            $names = $recipient->lines->pluck('product_name')->filter()->unique();
            $product = $names->isNotEmpty() ? $names->take(4)->implode(', ')
                .($names->count() > 4 ? ' (and '.($names->count() - 4).' more)' : '')
                : (string) $order->product;
            $data = [
                'supplier_name' => $recipient->name, 'recipient_name' => $recipient->name,
                'order_number' => (string) ($order->job_number ?: $order->order_number ?: 'Order-'.$order->id),
                'product_name' => $product,
                'quantity' => (string) ($recipient->lines->isNotEmpty() ? $recipient->lines->sum('quantity') : $order->quantity),
                'artwork_confirmed_date' => $task->completed_at->format('d M Y'),
                'completed_date' => $task->completed_at->format('d M Y'),
                'event_name' => $this->eventKey === SupplierArtworkReminderService::ARTWORK_EVENT
                    ? 'Artwork Confirmed' : (string) $task->title.' Completed',
            ];
            try {
                $trackingId = $email->sendNow(EmailMessage::html(
                    $recipient->email,
                    $reminders->interpolate($config['subject'], $data),
                    $reminders->emailHtml($config, $data),
                    ['type' => 'order_workflow_reminder', 'order_id' => $order->id, 'task_id' => $task->id],
                ));
                $record->update(['status' => 'sent', 'tracking_id' => $trackingId, 'reason' => null,
                    'sent_at' => now(), 'attempts' => DB::raw('attempts + 1'), 'updated_at' => now()]);
            } catch (Throwable $exception) {
                $record->update(['status' => 'failed', 'reason' => 'Email delivery failed; check the configured provider',
                    'attempts' => DB::raw('attempts + 1'), 'updated_at' => now()]);
                throw $exception;
            }
        }
    }
}
