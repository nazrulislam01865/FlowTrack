# e2a Email Transport for FlowTrack

FlowTrack's central email abstraction now supports `FLOWTRACK_EMAIL_TRANSPORT=e2a`.
All existing application modules (including Inquiry RFQ emails) continue to call
`App\Services\Email\EmailService`; no feature code needs to know about e2a.

## Hosted e2a test setup

1. Create a hosted e2a account at https://e2a.dev/.
2. Create an agent/inbox on the shared `agents.e2a.dev` domain.
3. Create an API key. An agent-scoped key bound to that inbox is preferred for
   application delivery; an account-scoped key also works if it can access the inbox.
4. Add the following to FlowTrack `.env`:

```env
FLOWTRACK_EMAIL_TRANSPORT=e2a
FLOWTRACK_EMAIL_QUEUE_ENABLED=false

E2A_BASE_URL=https://api.e2a.dev
E2A_API_KEY=e2a_agt_REPLACE_ME
E2A_AGENT_EMAIL=flowtrack-test@agents.e2a.dev
E2A_WAIT=sent
E2A_TIMEOUT=25
```

`FLOWTRACK_EMAIL_QUEUE_ENABLED=false` is recommended only for the first smoke
check so errors appear immediately. Re-enable the queue after the test succeeds.

## Refresh Laravel configuration

```bash
php artisan optimize:clear
```

## Smoke test

```bash
php artisan flowtrack:email:test you@example.com
```

The command uses the same central `EmailService` as the rest of FlowTrack.

## Test Inquiry RFQ mail

With the e2a transport enabled, open an Inquiry and invite a supplier from the
RFQ tab. `InquiryRfqEmailService` sends through the central `EmailService`, so
that invitation is delivered through e2a automatically.

## Re-enable queued delivery

```env
FLOWTRACK_EMAIL_QUEUE_ENABLED=true
FLOWTRACK_EMAIL_QUEUE=emails
```

Then run a worker that includes the `emails` queue, for example:

```bash
php artisan queue:work --queue=emails,default --tries=100 --timeout=30
```

On a server, keep the worker under Supervisor/systemd rather than running the
command manually.

## Switching away from e2a

No application logic changes are required. For the existing Laravel transport:

```env
FLOWTRACK_EMAIL_TRANSPORT=laravel
FLOWTRACK_EMAIL_MAILER=smtp
```

Then configure the normal Laravel `MAIL_*` variables for the chosen provider.
