<?php

namespace App\Providers;

use App\Contracts\Email\EmailTransport;
use App\Services\Email\EmailService;
use App\Services\Email\LaravelEmailTransport;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

final class EmailServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(EmailTransport::class, function ($app): EmailTransport {
            $driver = trim((string) config('flowtrack_email.transport', 'laravel'));
            $class = config('flowtrack_email.transports.'.$driver);

            if (! is_string($class) || $class === '' || ! is_a($class, EmailTransport::class, true)) {
                throw new RuntimeException("Invalid FlowTrack email transport [{$driver}].");
            }

            return $app->make($class);
        });

        $this->app->scoped(EmailService::class);
    }

    public function boot(): void
    {
        RateLimiter::for('flowtrack-email', function (): Limit {
            return Limit::perMinute(max(1, (int) config('flowtrack_email.queue.rate_limit_per_minute', 120)))
                ->by('flowtrack-email-global');
        });
    }
}
