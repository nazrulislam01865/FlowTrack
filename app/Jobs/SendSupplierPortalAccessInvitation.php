<?php

namespace App\Jobs;

use App\DTOs\Email\EmailMessage;
use App\Models\MasterRecord;
use App\Services\Email\EmailService;
use App\Services\Reminders\SupplierPortalAccessReminderService;
use App\Services\SupplierBridge\SyncSupplier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Delivers only the latest supplier login information; never transports passwords. */
final class SendSupplierPortalAccessInvitation implements ShouldQueue, ShouldBeEncrypted
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [30, 120];

    public function __construct(
        public readonly int $workspaceId,
        public readonly int $supplierId,
        public readonly string $recipient,
        public readonly string $username,
        public readonly bool $sendSetupLink,
        public readonly int $templateId,
    ) {
        $this->onQueue((string) config('flowtrack_email.queue.name', 'emails') ?: 'emails');
        $connection = trim((string) config('flowtrack_email.queue.connection', ''));
        if ($connection !== '') {
            $this->onConnection($connection);
        }
    }

    public function handle(SupplierPortalAccessReminderService $reminder, SyncSupplier $portal, EmailService $email): void
    {
        $settings = app(\App\Services\Reminders\SupplierArtworkReminderService::class)->publishedTemplate(
            $this->workspaceId, \App\Services\Reminders\OrderReminderEventCatalog::PORTAL_ACCESS_EVENT, $this->templateId,
        );
        if (! $settings) {
            return;
        }

        $supplier = MasterRecord::query()->forWorkspace($this->workspaceId)->ofType('supplier')
            ->whereKey($this->supplierId)->where('status', 'active')->first(['id', 'name', 'metadata']);
        if (! $supplier || ! data_get($supplier->metadata, 'portal_linked_at')
            || strtolower(trim((string) data_get($supplier->metadata, 'portal_email'))) !== $this->recipient
            || strtolower(trim((string) data_get($supplier->metadata, 'portal_username'))) !== $this->username
            || filter_var($this->recipient, FILTER_VALIDATE_EMAIL) === false) {
            return; // Account changed or removed while the notification was queued.
        }

        $url = $reminder->loginUrl();
        if ($this->sendSetupLink && $this->attempts() <= 1) {
            try {
                // The portal owns reset tokens and emails the one-use setup link itself.
                $portal->sendPasswordReset($this->supplierId);
            } catch (Throwable $exception) {
                Log::warning('flowtrack.supplier_portal.setup_link_failed', [
                    'supplier_id' => $this->supplierId,
                    'exception' => $exception::class,
                ]);
                // Access email still gives the supplier the dashboard's Forgot password option.
            }
        }

        $email->sendNow(EmailMessage::html(
            $this->recipient,
            str_replace(["\r", "\n"], ' ', app(\App\Services\Reminders\SupplierArtworkReminderService::class)->interpolate($settings['subject'], [
                'supplier_name' => (string) $supplier->name, 'recipient_name' => (string) $supplier->name,
                'login_email' => $this->recipient, 'username' => $this->username, 'dashboard_link' => $url,
            ])),
            $reminder->emailHtml((string) $supplier->name, $this->recipient, $this->username, $settings, $url),
            ['type' => 'supplier_portal_access', 'supplier_id' => $this->supplierId],
        ));
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('flowtrack.supplier_portal.access_invitation_failed', [
            'supplier_id' => $this->supplierId,
            'exception' => $exception ? $exception::class : null,
        ]);
    }
}
