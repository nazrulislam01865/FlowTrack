<?php

namespace App\Console\Commands;

use App\DTOs\Email\EmailMessage;
use App\Services\Email\EmailService;
use Illuminate\Console\Command;
use Throwable;

final class TestEmailDelivery extends Command
{
    protected $signature = 'flowtrack:email:test {recipient : Email address that should receive the test} {--queue : Queue the test instead of delivering immediately}';

    protected $description = 'Test the centralized FlowTrack email service using the currently configured provider';

    public function handle(EmailService $email): int
    {
        try {
            $message = EmailMessage::text(
                (string) $this->argument('recipient'),
                'FlowTrack email service test',
                'FlowTrack centralized email delivery is configured and working.',
                ['type' => 'email_service_test'],
            );

            $trackingId = $this->option('queue')
                ? $email->queue($message)
                : $email->sendNow($message);

            $this->info(($this->option('queue') ? 'Email queued.' : 'Email accepted by the configured mailer.').' Tracking ID: '.$trackingId);
            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());
            return self::FAILURE;
        }
    }
}
