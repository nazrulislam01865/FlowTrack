<?php

namespace App\Services\Email;

use App\Contracts\Email\EmailTransport;
use App\DTOs\Email\EmailMessage;
use App\Exceptions\EmailDeliveryException;
use App\Jobs\SendApplicationEmail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * The single application entry point for outbound email.
 *
 * Use send() for normal application email (queued by default), sendNow() only
 * when the current transaction/UI must know that the provider accepted the
 * message before business state is updated, and queue() for explicit async use.
 */
final class EmailService
{
    public function __construct(
        private readonly EmailTransport $transport,
        private readonly ModuleEmailControlService $moduleControl,
    ) {
    }

    public function send(EmailMessage $message): string
    {
        return (bool) config('flowtrack_email.queue.enabled', true)
            ? $this->queue($message)
            : $this->sendNow($message);
    }

    public function queue(EmailMessage $message): string
    {
        $trackingId = (string) Str::uuid();
        SendApplicationEmail::dispatch($message, $trackingId);

        Log::info('flowtrack.email.queued', $this->logContext($message, $trackingId));

        return $trackingId;
    }

    public function sendNow(EmailMessage $message): string
    {
        $trackingId = (string) Str::uuid();
        $this->deliver($message, $trackingId);

        return $trackingId;
    }

    /** @internal Called by the queue job so queued and synchronous mail share one delivery path. */
    public function deliver(EmailMessage $message, string $trackingId): void
    {
        $startedAt = hrtime(true);
        $module = $this->moduleControl->moduleForContext($message->context);

        if ($module !== null && ! $this->moduleControl->isEnabled($module)) {
            Log::info('flowtrack.email.suppressed', array_merge(
                $this->logContext($message, $trackingId),
                ['module' => $module, 'reason' => 'module_email_service_disabled'],
            ));

            return;
        }

        try {
            $this->transport->send($message, $trackingId);

            Log::info('flowtrack.email.sent', array_merge(
                $this->logContext($message, $trackingId),
                ['duration_ms' => round((hrtime(true) - $startedAt) / 1_000_000, 2)],
            ));
        } catch (Throwable $exception) {
            Log::error('flowtrack.email.failed', array_merge(
                $this->logContext($message, $trackingId),
                [
                    'exception' => $exception::class,
                    'error' => $this->safeError($exception->getMessage()),
                    'duration_ms' => round((hrtime(true) - $startedAt) / 1_000_000, 2),
                ],
            ));

            throw EmailDeliveryException::forTrackingId($trackingId, $exception);
        }
    }

    /** @return array<string,mixed> */
    private function logContext(EmailMessage $message, string $trackingId): array
    {
        // Deliberately avoid logging body text or full recipient addresses.
        return [
            'tracking_id' => $trackingId,
            'transport' => (string) config('flowtrack_email.transport', 'laravel'),
            'mailer' => (string) config('flowtrack_email.mailer', config('mail.default')),
            'recipient_count' => count($message->to),
            'cc_count' => count($message->cc),
            'bcc_count' => count($message->bcc),
            'attachment_count' => count($message->attachments),
            'subject_hash' => hash('sha256', $message->subject),
            'context' => $this->safeContext($message->context),
        ];
    }

    /** @return array<string,int|float|string|bool|null> */
    private function safeContext(array $context): array
    {
        $safe = [];
        foreach ($context as $key => $value) {
            $key = (string) $key;
            $allowedKey = $key === 'type'
                || $key === 'reference'
                || str_ends_with($key, '_id');

            if (! $allowedKey || (! is_scalar($value) && $value !== null)) {
                continue;
            }

            $safe[$key] = is_string($value) ? mb_substr($value, 0, 120) : $value;
        }

        return $safe;
    }

    private function safeError(string $message): string
    {
        $redacted = preg_replace(
            '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i',
            '[redacted-email]',
            $message,
        ) ?? 'Email transport error';

        return mb_substr($redacted, 0, 500);
    }
}
