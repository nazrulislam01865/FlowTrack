# FlowTrack Central Email Service

## Purpose

Outbound email is now a standalone application module. Orders, inquiries, tasks, finance, reports, and future supplier quotation features should **never call a mail provider or Laravel's `Mail` facade directly**. They use `App\Services\Email\EmailService` with the provider-neutral `App\DTOs\Email\EmailMessage` payload.

This keeps business logic independent from SMTP/API vendors. Switching from one SMTP provider to another normally requires only environment changes. Switching to an API provider supported by Laravel changes the selected mailer/configuration, while order/inquiry/task code remains unchanged. A completely custom provider can be added as one `EmailTransport` adapter.

## Architecture

```text
Order / Inquiry / Task / Finance / Report
                  |
                  v
          EmailService (single API)
             /             \
       queued send       sendNow
           |                |
 SendApplicationEmail       |
           \                /
            v              v
        EmailTransport contract
                  |
        LaravelEmailTransport
                  |
      FLOWTRACK_EMAIL_MAILER
                  |
 SMTP / SES / Postmark / Resend / custom Laravel mailer
```

### Main files

- `app/Services/Email/EmailService.php` — only application-facing sending service.
- `app/DTOs/Email/EmailMessage.php` — serializable provider-neutral payload.
- `app/Contracts/Email/EmailTransport.php` — transport contract.
- `app/Services/Email/LaravelEmailTransport.php` — Laravel Mail adapter.
- `app/Jobs/SendApplicationEmail.php` — queued delivery with retries, backoff, rate limiting and telemetry.
- `app/Providers/EmailServiceProvider.php` — module DI bindings and rate limiter.
- `config/flowtrack_email.php` — one central application email configuration.
- `config/mail.php` — Laravel provider/transport credentials.

## Which method to use

### Normal application email: `send()`

Use `send()` by default. It queues the message when `FLOWTRACK_EMAIL_QUEUE_ENABLED=true`, keeping Livewire/web requests fast.

```php
use App\DTOs\Email\EmailMessage;
use App\Services\Email\EmailService;

app(EmailService::class)->send(EmailMessage::view(
    $supplier->email,
    'Quotation request · '.$inquiry->reference_number,
    'emails.inquiries.quotation-request',
    [
        'supplierName' => $supplier->name,
        'inquiry' => $inquiryData,
        'uploadUrl' => $secureUploadUrl,
    ],
    [
        'type' => 'supplier_quotation_request',
        'inquiry_id' => (int) $inquiry->id,
        'supplier_id' => (int) $supplier->id,
    ],
));
```

### Delivery must succeed before updating business state: `sendNow()`

Use this only when a record must not be marked as sent until the provider accepts the email. The existing invoice email and payment reminder intentionally use this path.

```php
app(EmailService::class)->sendNow(EmailMessage::text(
    $recipient,
    $subject,
    $body,
    ['type' => 'important_transactional_message'],
));
```

### Explicit queued send: `queue()`

Use when the call site must always be asynchronous regardless of the default setting.

```php
$trackingId = app(EmailService::class)->queue(
    EmailMessage::html($recipient, $subject, $html)
);
```

## HTML, plain text and Blade templates

```php
EmailMessage::html($to, 'Subject', '<p>Hello</p>');
EmailMessage::text($to, 'Subject', 'Hello');
EmailMessage::view($to, 'Subject', 'emails.orders.created', ['order' => $safeArray]);
```

For queued Blade emails, keep `viewData` serializable. Prefer IDs, arrays, strings, numbers and DTO data rather than closures/resources.

## CC, BCC, Reply-To and attachments

For advanced messages instantiate the DTO directly:

```php
$message = new EmailMessage(
    to: ['buyer@example.com'],
    subject: 'Order documents',
    view: 'emails.orders.documents',
    viewData: ['orderNumber' => $order->displayOrderNumber()],
    cc: ['manager@example.com'],
    replyTo: ['orders@example.com'],
    attachments: [
        // Local/shared filesystem path:
        EmailMessage::attachment($absolutePath, 'invoice.pdf', 'application/pdf'),

        // Or a Laravel storage disk (recommended for queued/horizontal use):
        EmailMessage::storageAttachment('flowtrack_private', 'mail/invoice.pdf', 'invoice.pdf', 'application/pdf'),
    ],
    context: ['type' => 'order_documents', 'order_id' => (int) $order->id],
);
```

Local attachment paths must still exist and be readable when a queued job executes. For horizontal deployments prefer `storageAttachment()` on a shared/object-storage disk. The transport enforces a configurable attachment-size ceiling (`FLOWTRACK_EMAIL_ATTACHMENT_MAX_BYTES`, 15 MiB by default) before delivery.

## Provider switching

### SMTP provider -> another SMTP provider

No PHP business code changes. Change only `.env`:

```dotenv
FLOWTRACK_EMAIL_TRANSPORT=laravel
FLOWTRACK_EMAIL_MAILER=smtp

MAIL_SCHEME=smtp
MAIL_HOST=new-provider-smtp-host
MAIL_PORT=587
MAIL_TIMEOUT=10
MAIL_USERNAME=provider-user
MAIL_PASSWORD=provider-secret
MAIL_FROM_ADDRESS=no-reply@your-domain.com
MAIL_FROM_NAME="FlowTrack"
```

Then clear/rebuild Laravel configuration:

```bash
php artisan optimize:clear
php artisan config:cache
php artisan queue:restart
```

### Laravel-supported API mailer

Configure the relevant package/credentials in `config/mail.php` and `config/services.php`, then select it:

```dotenv
FLOWTRACK_EMAIL_MAILER=resend
```

or:

```dotenv
FLOWTRACK_EMAIL_MAILER=postmark
```

or:

```dotenv
FLOWTRACK_EMAIL_MAILER=ses
```

Order/inquiry/task/finance code does not change.

### Provider failover

Laravel's existing `failover` mailer is now environment-driven. After configuring two real mailers, FlowTrack can switch to them without feature-code changes:

```dotenv
FLOWTRACK_EMAIL_MAILER=failover
MAIL_FAILOVER_MAILERS=smtp,postmark
MAIL_FAILOVER_RETRY_AFTER=60
```

Do not keep the `log` mailer as a production fallback: it records the message instead of delivering it and can make a failed real delivery look successful to business logic.

### Completely custom provider API

Create one adapter implementing:

```php
App\Contracts\Email\EmailTransport
```

Add its class to `config/flowtrack_email.php` under `transports`, select that driver using `FLOWTRACK_EMAIL_TRANSPORT`, and leave all feature modules unchanged.

## Queue and performance behavior

Email has a dedicated `emails` queue so slow mail-provider calls cannot starve realtime notifications or normal background work.

The job uses:

- dispatch after database commit;
- encrypted queue payloads so recipients/body data are not stored as plain queue JSON;
- dedicated email queue;
- provider-wide rate limiting;
- a high attempt ceiling so rate-limit releases do not prematurely kill jobs;
- a separate four-exception ceiling for real provider/delivery failures;
- exponential-style backoff of 10, 60 and 180 seconds;
- a 30-second job timeout;
- queue-delay telemetry through FlowTrack's existing `QueueTelemetry`;
- structured success/failure logs with a tracking UUID;
- no email body or full recipient address in structured email logs.

Horizontal Supervisor configuration now contains a separate `flowtrack-worker-emails` pool. The single-worker script also listens to `emails`.

## Recommended production settings

```dotenv
FLOWTRACK_EMAIL_QUEUE_ENABLED=true
FLOWTRACK_EMAIL_QUEUE=emails
FLOWTRACK_EMAIL_QUEUE_TRIES=100
FLOWTRACK_EMAIL_QUEUE_MAX_EXCEPTIONS=4
FLOWTRACK_EMAIL_QUEUE_TIMEOUT=30
FLOWTRACK_EMAIL_RATE_LIMIT_PER_MINUTE=120
FLOWTRACK_EMAIL_ATTACHMENT_MAX_BYTES=15728640
```

For horizontal deployment:

```dotenv
FLOWTRACK_EMAIL_QUEUE_CONNECTION=redis
FLOWTRACK_QUEUE_NAMES=realtime,notifications,emails,default
```

Tune the rate limit to the provider's documented sending quota. Increasing worker count does not bypass the central limiter.

## Verify a provider after changing configuration

After updating `.env` and refreshing Laravel configuration, send one immediate test through the same centralized service used by the application:

```bash
php artisan flowtrack:email:test you@example.com
```

To verify the queue path as well:

```bash
php artisan flowtrack:email:test you@example.com --queue
```

The command returns the same tracking UUID used in FlowTrack's email logs.

## Existing code migrated

The following existing mail paths now use the central service:

1. Order invoice email — `app/Actions/Orders/EmailOrderInvoice.php`.
2. Finance payment reminder — `app/Livewire/Jobs/Concerns/ManagesOrderFinance.php`.

Both remain synchronous because their current business logic records a successful send immediately after delivery. New non-blocking emails should normally use `EmailService::send()`.

## Rule for future development

Do not add these in feature modules:

```php
Mail::send(...);
Mail::html(...);
Mail::raw(...);
```

Use `EmailService` instead. This rule keeps provider migration centralized and prevents feature-level transport coupling.
