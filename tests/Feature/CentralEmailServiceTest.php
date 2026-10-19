<?php

namespace Tests\Feature;

use App\Contracts\Email\EmailTransport;
use App\DTOs\Email\EmailMessage;
use App\Jobs\SendApplicationEmail;
use App\Services\Email\EmailService;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class CentralEmailServiceTest extends TestCase
{
    public function test_send_now_uses_the_provider_neutral_transport(): void
    {
        $message = EmailMessage::text('buyer@example.com', 'Test subject', 'Hello');
        $transport = Mockery::mock(EmailTransport::class);
        $transport->shouldReceive('send')->once()->with($message, Mockery::type('string'));
        $this->app->instance(EmailTransport::class, $transport);
        $this->app->forgetInstance(EmailService::class);

        $trackingId = app(EmailService::class)->sendNow($message);

        $this->assertNotSame('', $trackingId);
    }

    public function test_queue_uses_dedicated_email_job(): void
    {
        Queue::fake();
        $message = EmailMessage::html('buyer@example.com', 'Queued subject', '<p>Hello</p>');

        $trackingId = app(EmailService::class)->queue($message);

        Queue::assertPushed(SendApplicationEmail::class, fn (SendApplicationEmail $job): bool =>
            $job->trackingId === $trackingId
            && $job->message->subject === 'Queued subject'
            && $job->queue === 'emails'
        );
    }

    public function test_feature_modules_do_not_call_mail_facade_directly(): void
    {
        $paths = [
            app_path('Actions/Orders/EmailOrderInvoice.php'),
            app_path('Livewire/Jobs/Concerns/ManagesOrderFinance.php'),
        ];

        foreach ($paths as $path) {
            $source = file_get_contents($path);
            $this->assertStringContainsString('EmailService', $source);
            $this->assertStringNotContainsString('Mail::', $source);
            $this->assertStringNotContainsString('Facades\\Mail', $source);
        }
    }
}
