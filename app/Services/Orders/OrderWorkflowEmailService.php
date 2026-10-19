<?php

namespace App\Services\Orders;

use App\DTOs\Email\EmailMessage;
use App\Models\Department;
use App\Models\Document;
use App\Models\FlowJob;
use App\Models\Role;
use App\Models\Task;
use App\Models\User;
use App\Services\BrandingService;
use App\Services\CompanyProfileService;
use App\Services\DocumentService;
use App\Services\Email\EmailService;
use App\Services\Email\ModuleEmailControlService;
use App\Services\SecureDocumentStorage;
use App\Services\SetupContext;
use App\Services\TaskService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Internal Order workflow email handoffs.
 *
 * The Order module only composes business context. Actual delivery stays behind
 * the central EmailService, so e2a/SMTP/SES/provider changes never leak into the
 * workflow implementation.
 */
final class OrderWorkflowEmailService
{
    private const PURCHASE_ORDER_HANDOFF = 'NEW_SEND_PO_ARTWORK';
    private const ARTWORK_HANDOFF = 'ART_SEND_ORDER_TEAM';
    private const DELIVERY_ATTEMPTS = 3;

    /** @var array<string,string> */
    private const SOURCE_TASK_KEYS = [
        self::PURCHASE_ORDER_HANDOFF => 'NEW_UPLOAD_PO',
        self::ARTWORK_HANDOFF => 'ART_PREPARE_UPLOAD',
    ];

    /** @var array<string,string> */
    private const SOURCE_TASK_TITLES = [
        'NEW_UPLOAD_PO' => 'upload purchase order',
        'ART_PREPARE_UPLOAD' => 'prepare & upload artwork',
        'ART_CLIENT_ERP_DECISION' => 'client erp / approval',
    ];

    public function __construct(
        private readonly EmailService $email,
        private readonly ModuleEmailControlService $emailControl,
        private readonly SecureDocumentStorage $storage,
        private readonly BrandingService $branding,
        private readonly CompanyProfileService $companyProfile,
    ) {}

    /**
     * Active FlowTrack users that can be suggested by any email recipient picker.
     * Keeping this directory in one place makes Billing behave exactly like the
     * existing Order workflow email templates instead of maintaining a second
     * user-search implementation.
     *
     * @return array<int,array{id:int,name:string,email:string}>
     */
    public function activeSystemUserRecipientOptions(): array
    {
        return $this->activeWorkspaceEmailUsers()
            ->map(fn (User $user) => [
                'id' => (int) $user->id,
                'name' => (string) $user->name,
                'email' => (string) $user->email,
            ])
            ->values()
            ->all();
    }

    /**
     * Lightweight data used by the Order Details/List confirmation modal.
     * Missing recipients/files are returned as empty values so the modal can
     * explain what must be fixed before Send is pressed.
     *
     * @return array<string,mixed>
     */
    public function preview(Task $handoffTask, ?User $actor = null, array $selection = [], bool $includeHtml = true): array
    {
        $key = $this->automationKey($handoffTask);
        if (! in_array($key, [self::PURCHASE_ORDER_HANDOFF, self::ARTWORK_HANDOFF], true)) {
            return [];
        }

        $job = $handoffTask->job ?: FlowJob::query()->find($handoffTask->flow_job_id);
        if (! $job) return [];

        $job->loadMissing(['client', 'owner', 'coordinator', 'items']);
        $actor ??= auth()->user() instanceof User ? auth()->user() : ($job->owner ?: $job->coordinator);
        $emailServiceEnabled = $this->emailControl->orderEnabled();

        $recipientOptions = $this->recipients($job, $key);
        $purchaseOrderSelection = $key === self::PURCHASE_ORDER_HANDOFF
            ? $this->purchaseOrderRecipientSelection($recipientOptions, $selection, false)
            : null;
        $artworkSelection = $key === self::ARTWORK_HANDOFF
            ? $this->artworkRecipientSelection($recipientOptions, $selection, false)
            : null;

        if ($purchaseOrderSelection) {
            $recipientRows = $this->userRecipientRows($purchaseOrderSelection['to_users'])
                ->concat($purchaseOrderSelection['external_to'] ? [$purchaseOrderSelection['external_to']] : [])
                ->values();
            $ccRecipientRows = $this->userRecipientRows($purchaseOrderSelection['cc_users'])
                ->concat($purchaseOrderSelection['external_cc'])
                ->values();
        } elseif ($artworkSelection) {
            $recipientRows = $this->userRecipientRows($artworkSelection['to_users'])
                ->concat($artworkSelection['external_to'])
                ->values();
            $ccRecipientRows = collect();
        } else {
            $recipientRows = collect();
            $ccRecipientRows = collect();
        }
        $documents = $this->sourceDocuments($job, $key);
        $document = $documents->last();
        $subject = $this->subject($job, $key);
        $brand = $this->companyBrand();
        $customerComment = $key === self::ARTWORK_HANDOFF
            ? $this->artworkCustomerComment($selection)
            : '';
        $viewData = ($includeHtml && $document && $actor)
            ? $this->viewData($job, $key, $document, $documents, $actor, $brand, $customerComment)
            : [];
        if ($viewData !== [] && ($purchaseOrderSelection['external_to'] ?? null)) {
            $viewData['team'] = $purchaseOrderSelection['external_to']['name'];
        }
        $previewHtml = $includeHtml && $viewData !== []
            ? view('emails.orders.workflow-handoff', $viewData)->render()
            : '';

        return [
            'key' => $key,
            'team' => $purchaseOrderSelection['external_to']['name'] ?? $this->teamLabel($key),
            'recipients' => $recipientRows->all(),
            'cc_recipients' => $ccRecipientRows->all(),
            'recipient_options' => $recipientOptions->map(fn (User $user) => [
                'id' => (int) $user->id,
                'name' => (string) $user->name,
                'email' => (string) $user->email,
            ])->values()->all(),
            'recipient_count' => $recipientRows->count() + $ccRecipientRows->count(),
            'recipient_source' => $key === self::PURCHASE_ORDER_HANDOFF
                ? 'To / CC email fields — Artwork Team users are suggested when matched'
                : 'To email field — matching active system users are suggested as you type',
            'empty_recipient_message' => $recipientRows->isEmpty()
                ? ($key === self::PURCHASE_ORDER_HANDOFF
                    ? 'Enter a valid email address in To. Artwork Team users are suggested as you type.'
                    : 'Enter one or more valid email addresses in To. Active system users are suggested as you type.')
                : '',
            'business_unit' => $key === self::ARTWORK_HANDOFF ? $this->orderBusinessUnit($job) : null,
            'customer_comment' => $customerComment,
            'subject' => $subject,
            'document_id' => $document?->id,
            'document_name' => $document?->name,
            'document_version' => $document?->version,
            'document_size' => $document?->size,
            'documents' => $documents->map(fn (Document $item) => [
                'id' => (int) $item->id,
                'name' => (string) $item->name,
                'version' => max(1, (int) $item->version),
                'size' => (int) $item->size,
            ])->values()->all(),
            'document_count' => $documents->count(),
            'from_name' => $this->senderName($brand),
            'from_address' => $this->senderAddress(),
            'reply_to' => $actor && filter_var((string) $actor->email, FILTER_VALIDATE_EMAIL) ? (string) $actor->email : '',
            'email_service_enabled' => $emailServiceEnabled,
            'assignment_user_id' => $purchaseOrderSelection ? $purchaseOrderSelection['assignee']?->id : null,
            'assignment_user_name' => $purchaseOrderSelection ? $purchaseOrderSelection['assignee']?->name : null,
            'delivery' => $emailServiceEnabled ? $this->deliveryLabel() : 'Order email service disabled',
            'html' => $previewHtml,
        ];
    }

    /**
     * Send the file-backed handoff synchronously. The workflow task must only be
     * completed after the configured provider has durably accepted the email.
     */
    public function send(Task $handoffTask, User $actor, array $selection = []): string
    {
        app(OrderHoldService::class)->assertNotHeld((int) $handoffTask->flow_job_id);

        $key = $this->automationKey($handoffTask);
        abort_unless(in_array($key, [self::PURCHASE_ORDER_HANDOFF, self::ARTWORK_HANDOFF], true), 422);

        $job = FlowJob::query()
            ->with(['client', 'owner', 'coordinator', 'items'])
            ->findOrFail($handoffTask->flow_job_id);

        $recipientOptions = $this->recipients($job, $key);
        $purchaseOrderSelection = $key === self::PURCHASE_ORDER_HANDOFF
            ? $this->purchaseOrderRecipientSelection($recipientOptions, $selection, true)
            : null;
        $artworkSelection = $key === self::ARTWORK_HANDOFF
            ? $this->artworkRecipientSelection($recipientOptions, $selection, true)
            : null;
        $customerComment = $key === self::ARTWORK_HANDOFF
            ? $this->artworkCustomerComment($selection)
            : '';

        $recipients = $purchaseOrderSelection
            ? $purchaseOrderSelection['to_users']
            : collect($artworkSelection['to_users'] ?? []);
        $ccRecipients = $purchaseOrderSelection ? $purchaseOrderSelection['cc_users'] : collect();
        $externalTo = $purchaseOrderSelection['external_to'] ?? null;
        $externalToRecipients = $purchaseOrderSelection
            ? collect($externalTo ? [$externalTo] : [])
            : collect($artworkSelection['external_to'] ?? []);
        $externalCc = collect($purchaseOrderSelection['external_cc'] ?? []);
        $assignmentRecipient = $purchaseOrderSelection['assignee'] ?? null;
        $toEmails = $recipients->pluck('email')
            ->concat($externalToRecipients->pluck('email'))
            ->filter()->unique(fn ($email) => mb_strtolower((string) $email))->values();
        $ccEmails = $ccRecipients->pluck('email')
            ->concat($externalCc->pluck('email'))
            ->filter()->unique(fn ($email) => mb_strtolower((string) $email))->values();
        // Resolve the downstream task before attempting delivery. Otherwise a
        // malformed workflow could accept the email and then fail assignment,
        // leaving the sender to retry an email that was already delivered.
        $artworkPreparationTask = $assignmentRecipient
            ? $this->artworkPreparationTask($job)
            : null;

        if (! $this->emailControl->orderEnabled()) {
            $trackingId = 'disabled-'.Str::uuid();
            $attachmentLabel = $key === self::PURCHASE_ORDER_HANDOFF ? 'Purchase Order' : 'Artwork';

            $job->activities()->create([
                'user_id' => $actor->id,
                'event' => 'job.workflow_email_skipped',
                'description' => $attachmentLabel.' email handoff was skipped because the Order email service is disabled by an administrator.',
                'meta' => [
                    'task_id' => (int) $handoffTask->id,
                    'tracking_id' => $trackingId,
                    'email_service_disabled' => true,
                    'module' => ModuleEmailControlService::ORDER,
                    'intended_to' => $toEmails->all(),
                    'intended_cc' => $ccEmails->all(),
                    // Keep the same recipient keys as real delivery attempts so
                    // completed skipped handoffs can use the normal Resend path.
                    'to_emails' => $toEmails->all(),
                    'cc_emails' => $ccEmails->all(),
                    'assignment_user_id' => $assignmentRecipient?->id,
                    'customer_comment' => $customerComment !== '' ? $customerComment : null,
                ],
            ]);

            Log::info('flowtrack.order_workflow_email.skipped', [
                'flow_job_id' => (int) $job->id,
                'task_id' => (int) $handoffTask->id,
                'handoff_key' => $key,
                'reason' => 'order_email_service_disabled',
            ]);

            if ($assignmentRecipient && $artworkPreparationTask) {
                $this->assignArtworkPreparationTask($artworkPreparationTask, $assignmentRecipient, $actor);
            }

            return $trackingId;
        }

        if ($toEmails->isEmpty()) {
            $message = $key === self::ARTWORK_HANDOFF
                ? $this->missingOrderTeamRecipientMessage($job)
                : $this->missingArtworkTeamRecipientMessage();

            throw ValidationException::withMessages([
                'orderWorkflowActionEmail' => $message,
            ]);
        }

        $documents = $this->sourceDocuments($job, $key);
        $document = $documents->last();
        if ($documents->isEmpty() || ! $document) {
            $label = $key === self::PURCHASE_ORDER_HANDOFF ? 'Purchase Order' : 'Artwork';
            throw ValidationException::withMessages([
                'orderWorkflowActionEmail' => 'No uploaded '.$label.' file was found. Upload the file in the previous workflow task before sending this email.',
            ]);
        }

        $attachments = $documents->map(function (Document $item) {
            $located = $this->storage->locate((string) $item->path);
            if (! $located) {
                throw ValidationException::withMessages([
                    'orderWorkflowActionEmail' => 'An attachment record exists, but '.$item->name.' cannot be found. Re-upload the artwork set before sending.',
                ]);
            }

            return EmailMessage::storageAttachment(
                (string) $located['disk'],
                (string) $located['path'],
                (string) $item->name,
                filled($item->mime_type) ? (string) $item->mime_type : null,
            );
        })->values()->all();

        $brand = $this->companyBrand();
        $orderNumber = $job->displayOrderNumber();
        $team = $externalTo['name'] ?? $this->teamLabel($key);
        $subject = $this->subject($job, $key);
        $replyTo = filter_var((string) $actor->email, FILTER_VALIDATE_EMAIL) ? [(string) $actor->email] : [];
        $viewData = $this->viewData($job, $key, $document, $documents, $actor, $brand, $customerComment);
        $viewData['team'] = $team;

        $message = new EmailMessage(
            to: $toEmails->all(),
            cc: $ccEmails->all(),
            subject: $subject,
            view: 'emails.orders.workflow-handoff',
            viewData: $viewData,
            replyTo: $replyTo,
            attachments: $attachments,
            context: [
                'type' => $key === self::PURCHASE_ORDER_HANDOFF ? 'order_purchase_order_handoff' : 'order_artwork_handoff',
                'reference' => $orderNumber,
                'flow_job_id' => (int) $job->id,
                'task_id' => (int) $handoffTask->id,
                'document_id' => (int) $document->id,
                'document_ids' => $documents->pluck('id')->map(fn ($id) => (int) $id)->all(),
                'primary_recipient_user_id' => $recipients->first()?->id,
                'external_primary_recipient' => $externalToRecipients->first(),
                'external_to_emails' => $externalToRecipients->pluck('email')->values()->all(),
                'assignment_user_id' => $assignmentRecipient?->id,
                'cc_recipient_user_ids' => $ccRecipients->pluck('id')->map(fn ($id) => (int) $id)->all(),
                'external_cc_emails' => $externalCc->pluck('email')->all(),
                'customer_comment' => $customerComment !== '' ? $customerComment : null,
            ],
        );

        // Workflow handoff email is synchronous because task completion depends
        // on provider acceptance. Retry the same idempotent delivery three times
        // before exposing the manual-send fallback. Reusing one tracking ID is
        // important for providers (such as e2a) that support idempotency keys.
        $trackingId = (string) Str::uuid();
        $attemptsUsed = 0;
        $lastException = null;

        for ($attempt = 1; $attempt <= self::DELIVERY_ATTEMPTS; $attempt++) {
            $attemptsUsed = $attempt;

            try {
                $this->email->deliver($message, $trackingId);
                $lastException = null;
                break;
            } catch (Throwable $exception) {
                $lastException = $exception;

                Log::warning('flowtrack.order_workflow_email.retry', [
                    'tracking_id' => $trackingId,
                    'flow_job_id' => (int) $job->id,
                    'task_id' => (int) $handoffTask->id,
                    'document_id' => (int) $document->id,
                    'attempt' => $attempt,
                    'max_attempts' => self::DELIVERY_ATTEMPTS,
                ]);

                // Small bounded backoff gives transient provider/network errors
                // a chance to recover without making the UI unnecessarily slow.
                if ($attempt < self::DELIVERY_ATTEMPTS) {
                    usleep(250_000 * $attempt);
                }
            }
        }

        if ($lastException) {
            $attachmentLabel = $key === self::PURCHASE_ORDER_HANDOFF ? 'Purchase Order' : 'Artwork';
            $failureEvent = $key === self::ARTWORK_HANDOFF
                ? 'job.artwork_email_failed_to_order_team'
                : 'job.purchase_order_email_failed_to_artwork_team';

            $job->activities()->create([
                'user_id' => $actor->id,
                'event' => $failureEvent,
                'description' => $attachmentLabel.' email delivery failed after '.$attemptsUsed.' attempts.',
                'meta' => [
                    'task_id' => (int) $handoffTask->id,
                    'document_id' => (int) $document->id,
                    'document_ids' => $documents->pluck('id')->map(fn ($id) => (int) $id)->all(),
                    'document_count' => $documents->count(),
                    'to_emails' => $toEmails->values()->all(),
                    'cc_emails' => $ccEmails->values()->all(),
                    'tracking_id' => $trackingId,
                    'delivery_attempts' => $attemptsUsed,
                    'customer_comment' => $customerComment !== '' ? $customerComment : null,
                    'failed_at' => now()->toIso8601String(),
                ],
            ]);

            report($lastException);
            throw $lastException;
        }

        if ($assignmentRecipient && $artworkPreparationTask) {
            $this->assignArtworkPreparationTask($artworkPreparationTask, $assignmentRecipient, $actor);
        }


        $job->activities()->create([
            'user_id' => $actor->id,
            'event' => $key === self::PURCHASE_ORDER_HANDOFF
                ? 'job.purchase_order_emailed_to_artwork_team'
                : 'job.artwork_emailed_to_order_team',
            'description' => ($key === self::PURCHASE_ORDER_HANDOFF ? 'Purchase Order' : 'Artwork')
                .' emailed to '.$team.' with '.$documents->pluck('name')->implode(', ').'.',
            'meta' => [
                'task_id' => (int) $handoffTask->id,
                'document_id' => (int) $document->id,
                'document_ids' => $documents->pluck('id')->map(fn ($id) => (int) $id)->all(),
                'document_count' => $documents->count(),
                'document_version' => (int) ($document->version ?: 1),
                'recipient_count' => $toEmails->count() + $ccEmails->count(),
                'primary_recipient_user_id' => $recipients->first()?->id,
                'external_primary_recipient' => $externalToRecipients->first(),
                'external_to_emails' => $externalToRecipients->pluck('email')->values()->all(),
                'assignment_user_id' => $assignmentRecipient?->id,
                'cc_recipient_user_ids' => $ccRecipients->pluck('id')->map(fn ($id) => (int) $id)->all(),
                'external_cc_emails' => $externalCc->pluck('email')->all(),
                'business_unit' => $key === self::ARTWORK_HANDOFF ? $this->orderBusinessUnit($job) : null,
                'customer_comment' => $customerComment !== '' ? $customerComment : null,
                'tracking_id' => $trackingId,
                'delivery_attempts' => $attemptsUsed,
            ],
        ]);

        return $trackingId;
    }

    /**
     * Persistent delivery state for the completed Artwork -> Order Team handoff.
     * The task lifecycle and email delivery lifecycle are intentionally separate:
     * a task can be completed manually after an outage while its email remains
     * failed and therefore retryable.
     *
     * @return array{status:?string,label:?string,to_emails:array<int,string>,attempts:int,tracking_id:string,customer_comment:string,comment_history:array<int,array{id:int,comment:string,created_at:mixed}>,resendable:bool}
     */
    public function artworkHandoffDeliveryStatus(Task $handoffTask): array
    {
        if ($this->automationKey($handoffTask) !== self::ARTWORK_HANDOFF) {
            return ['status' => null, 'label' => null, 'to_emails' => [], 'attempts' => 0, 'tracking_id' => '', 'customer_comment' => '', 'comment_history' => [], 'resendable' => false];
        }

        $job = $handoffTask->job ?: FlowJob::query()->find($handoffTask->flow_job_id);
        if (! $job) {
            return ['status' => null, 'label' => null, 'to_emails' => [], 'attempts' => 0, 'tracking_id' => '', 'customer_comment' => '', 'comment_history' => [], 'resendable' => false];
        }

        $activities = $job->relationLoaded('workflowEmailActivities')
            ? collect($job->getRelation('workflowEmailActivities'))
            : $job->workflowEmailActivities()->get();

        $taskActivities = $activities
            ->filter(fn ($activity) => (int) data_get($activity->meta, 'task_id', 0) === (int) $handoffTask->id)
            ->sortByDesc('id')
            ->values();

        $latest = $taskActivities->first();
        $commentHistory = $taskActivities
            ->filter(fn ($activity) => in_array((string) $activity->event, [
                'job.artwork_emailed_to_order_team',
                'job.workflow_email_skipped',
            ], true))
            ->map(fn ($activity) => [
                'id' => (int) $activity->id,
                'comment' => trim((string) data_get($activity->meta, 'customer_comment', '')),
                'created_at' => $activity->created_at,
            ])
            ->filter(fn (array $entry) => $entry['comment'] !== '')
            ->values()
            ->all();

        if (! $latest) {
            return ['status' => null, 'label' => null, 'to_emails' => [], 'attempts' => 0, 'tracking_id' => '', 'customer_comment' => '', 'comment_history' => [], 'resendable' => false];
        }

        $event = (string) $latest->event;
        $status = match ($event) {
            'job.artwork_email_failed_to_order_team' => 'failed',
            'job.artwork_emailed_to_order_team' => 'sent',
            'job.workflow_email_skipped' => 'not_sent',
            default => null,
        };
        $label = match ($status) {
            'failed' => 'Failed',
            'sent' => 'Sent',
            'not_sent' => 'Not Sent',
            default => null,
        };

        // Older skipped-delivery rows used intended_to; newer rows also persist
        // to_emails. Supporting both keeps already-completed tasks retryable.
        $savedTo = data_get($latest->meta, 'to_emails', []);
        if (empty($savedTo)) {
            $savedTo = data_get($latest->meta, 'intended_to', []);
        }
        $toEmails = collect($savedTo)
            ->map(fn ($email) => mb_strtolower(trim((string) $email)))
            ->filter(fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL) !== false)
            ->unique()
            ->values()
            ->all();

        return [
            'status' => $status,
            'label' => $label,
            'to_emails' => $toEmails,
            'attempts' => (int) data_get($latest->meta, 'delivery_attempts', 0),
            'tracking_id' => (string) data_get($latest->meta, 'tracking_id', ''),
            'customer_comment' => trim((string) data_get($latest->meta, 'customer_comment', '')),
            'comment_history' => $commentHistory,
            'resendable' => $toEmails !== [],
        ];
    }

    public function resendCompletedArtworkHandoff(Task $handoffTask, User $actor): string
    {
        abort_unless($this->automationKey($handoffTask) === self::ARTWORK_HANDOFF, 422, 'This task does not support artwork email resend.');
        abort_unless($handoffTask->completed_at || strcasecmp(trim((string) $handoffTask->status), 'Completed') === 0, 422, 'Only a completed artwork handoff can be resent from this action.');
        abort_unless($this->emailControl->orderEnabled(), 422, 'Order email sending is currently disabled. Enable it before resending the artwork.');

        $delivery = $this->artworkHandoffDeliveryStatus($handoffTask);
        abort_unless(
            in_array((string) ($delivery['status'] ?? ''), ['failed', 'sent', 'not_sent'], true),
            422,
            'No previous artwork email delivery was found for this completed task.'
        );

        $toEmails = collect($delivery['to_emails'] ?? [])->filter()->values();
        abort_if($toEmails->isEmpty(), 422, 'This completed artwork handoff has no saved recipients to resend to.');

        return $this->send($handoffTask, $actor, [
            'to_emails' => $toEmails->implode(', '),
            'customer_comment' => (string) ($delivery['customer_comment'] ?? ''),
            'is_resend' => true,
        ]);
    }


    /** @return Collection<int,User> */
    private function recipients(FlowJob $job, string $handoffKey): Collection
    {
        return match ($handoffKey) {
            self::PURCHASE_ORDER_HANDOFF => $this->artworkTeamMembers(),
            self::ARTWORK_HANDOFF => $this->orderTeamRecipientCandidates(),
            default => collect(),
        };
    }

    /**
     * Resolve the Purchase Order To/CC email fields and the optional internal
     * Artwork task assignee. Both fields accept normal email addresses. When
     * an address matches an active Artwork Team user, that user is resolved as
     * an internal recipient; the To match also owns Prepare & Upload Artwork.
     * External addresses remain valid email recipients but are never assigned
     * an internal FlowTrack task.
     *
     * Legacy ID/external fields are still accepted so an already-open modal or
     * saved failure marker from an older frontend does not break mid-handoff.
     *
     * @return array{type:string,to_users:Collection<int,User>,cc_users:Collection<int,User>,external_to:?array{name:string,email:string,external:bool},external_cc:Collection<int,array{name:string,email:string,external:bool}>,assignee:?User}
     */
    private function purchaseOrderRecipientSelection(Collection $candidates, array $selection, bool $required): array
    {
        $candidateById = $candidates->keyBy(fn (User $user) => (int) $user->id);
        $candidateByEmail = $candidates->keyBy(
            fn (User $user) => mb_strtolower(trim((string) $user->email))
        );

        // New Gmail-style field: one freely-entered email address. Fall back to
        // the previous selector payload so in-flight browser sessions remain safe.
        $toEmail = mb_strtolower(trim((string) ($selection['to_email'] ?? '')));
        $legacyType = ($selection['recipient_type'] ?? 'team') === 'external' ? 'external' : 'team';
        $legacyAssignee = null;
        if ($toEmail === '') {
            if ($legacyType === 'external') {
                $toEmail = mb_strtolower(trim((string) ($selection['external_to_email'] ?? '')));
                $legacyAssignee = $candidateById->get((int) ($selection['assignee_user_id'] ?? 0));
            } else {
                $legacyPrimary = $candidateById->get((int) ($selection['to_user_id'] ?? 0));
                if ($legacyPrimary) {
                    $toEmail = mb_strtolower(trim((string) $legacyPrimary->email));
                }
            }
        }

        if ($required && ($toEmail === '' || filter_var($toEmail, FILTER_VALIDATE_EMAIL) === false)) {
            throw ValidationException::withMessages([
                'orderWorkflowActionPayload.to_email' => 'Enter a valid email address in To.',
            ]);
        }

        $primary = $toEmail !== '' && filter_var($toEmail, FILTER_VALIDATE_EMAIL)
            ? $candidateByEmail->get($toEmail)
            : null;
        $externalTo = $toEmail !== '' && filter_var($toEmail, FILTER_VALIDATE_EMAIL) && ! $primary
            ? [
                'name' => trim((string) ($selection['external_to_name'] ?? '')) ?: 'External recipient',
                'email' => $toEmail,
                'external' => true,
            ]
            : null;

        // One CC field accepts any number of comma/semicolon/space-separated
        // addresses. Known Artwork Team users are resolved internally; all other
        // valid addresses are treated as external CC recipients.
        $ccInput = trim((string) ($selection['cc_emails'] ?? ''));
        if ($ccInput === '') {
            $legacyCcEmails = collect($selection['cc_user_ids'] ?? [])
                ->map(fn ($id) => $candidateById->get((int) $id)?->email)
                ->filter()
                ->concat(preg_split('/[\s,;]+/', trim((string) ($selection['external_cc_emails'] ?? ''))) ?: [])
                ->filter()
                ->implode(', ');
            $ccInput = trim($legacyCcEmails);
        }

        $ccEmails = collect($ccInput === '' ? [] : preg_split('/[\s,;]+/', $ccInput))
            ->map(fn ($email) => mb_strtolower(trim((string) $email)))
            ->filter()
            ->unique()
            ->reject(fn ($email) => $toEmail !== '' && $email === $toEmail)
            ->values();

        $invalidCc = $ccEmails->first(fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL) === false);
        if ($required && $invalidCc) {
            throw ValidationException::withMessages([
                'orderWorkflowActionPayload.cc_emails' => $invalidCc.' is not a valid CC email address.',
            ]);
        }
        if ($required && $ccEmails->count() > 10) {
            throw ValidationException::withMessages([
                'orderWorkflowActionPayload.cc_emails' => 'Add no more than 10 CC email addresses.',
            ]);
        }

        $ccUsers = $ccEmails
            ->map(fn ($email) => $candidateByEmail->get($email))
            ->filter()
            ->unique(fn (User $user) => (int) $user->id)
            ->values();
        $ccUserEmails = $ccUsers
            ->pluck('email')
            ->map(fn ($email) => mb_strtolower(trim((string) $email)))
            ->filter()
            ->unique();
        $externalCc = $ccEmails
            ->filter(fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL) && ! $ccUserEmails->contains($email))
            ->map(fn ($email) => ['name' => 'External CC', 'email' => $email, 'external' => true])
            ->values();

        return [
            'type' => $primary ? 'team' : 'external',
            'to_users' => $primary ? collect([$primary]) : collect(),
            'cc_users' => $ccUsers,
            'external_to' => $externalTo,
            'external_cc' => $externalCc,
            'assignee' => $primary ?: $legacyAssignee,
        ];
    }

    /**
     * Resolve the Artwork -> Order Team To field. The field accepts multiple
     * comma/semicolon/space-separated email addresses. Matching active Order
     * Team users are resolved as internal recipients; any other valid address
     * is still allowed as an external recipient. There is intentionally no CC
     * field for this handoff.
     *
     * @return array{to_users:Collection<int,User>,external_to:Collection<int,array{name:string,email:string,external:bool}>}
     */
    private function artworkCustomerComment(array $selection): string
    {
        $comment = trim((string) ($selection['customer_comment'] ?? ''));

        // The field is optional. The HTML maxlength keeps normal UI input
        // bounded, and this server-side limit protects direct requests without
        // turning an omitted comment into a validation requirement.
        return mb_substr($comment, 0, 2000);
    }

    private function artworkRecipientSelection(Collection $candidates, array $selection, bool $required): array
    {
        $candidateByEmail = $candidates->keyBy(
            fn (User $user) => mb_strtolower(trim((string) $user->email))
        );

        $toInput = trim((string) ($selection['to_emails'] ?? ''));
        if ($toInput === '') {
            // Compatibility for an already-open browser that used the singular
            // To field before this handoff was changed to support many people.
            $toInput = trim((string) ($selection['to_email'] ?? ''));
        }

        $toEmails = collect($toInput === '' ? [] : preg_split('/[\s,;]+/', $toInput))
            ->map(fn ($email) => mb_strtolower(trim((string) $email)))
            ->filter()
            ->unique()
            ->values();

        if ($required && $toEmails->isEmpty()) {
            throw ValidationException::withMessages([
                'orderWorkflowActionPayload.to_emails' => 'Enter at least one email address in To.',
            ]);
        }

        $invalidTo = $toEmails->first(fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL) === false);
        if ($required && $invalidTo) {
            throw ValidationException::withMessages([
                'orderWorkflowActionPayload.to_emails' => $invalidTo.' is not a valid To email address.',
            ]);
        }

        if ($required && $toEmails->count() > 10) {
            throw ValidationException::withMessages([
                'orderWorkflowActionPayload.to_emails' => 'Add no more than 10 To email addresses.',
            ]);
        }

        $toUsers = $toEmails
            ->map(fn ($email) => $candidateByEmail->get($email))
            ->filter()
            ->unique(fn (User $user) => (int) $user->id)
            ->values();
        $internalEmails = $toUsers
            ->pluck('email')
            ->map(fn ($email) => mb_strtolower(trim((string) $email)))
            ->filter()
            ->unique();
        $externalTo = $toEmails
            ->filter(fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL) && ! $internalEmails->contains($email))
            ->map(fn ($email) => ['name' => 'External recipient', 'email' => $email, 'external' => true])
            ->values();

        return [
            'to_users' => $toUsers,
            'external_to' => $externalTo,
        ];
    }

    /** @return Collection<int,array{id:int,name:string,email:string,external:bool}> */
    private function userRecipientRows(Collection $users): Collection
    {
        return $users->map(fn (User $user) => [
            'id' => (int) $user->id,
            'name' => (string) $user->name,
            'email' => (string) $user->email,
            'external' => false,
        ])->values();
    }

    private function artworkPreparationTask(FlowJob $job): Task
    {
        $task = $this->tasksForAutomationKey($job, 'ART_PREPARE_UPLOAD')->first();
        if (! $task) {
            throw ValidationException::withMessages([
                'orderWorkflowActionEmail' => 'The Prepare & Upload Artwork task is not configured for this Order.',
            ]);
        }

        return $task;
    }

    private function assignArtworkPreparationTask(Task $task, User $recipient, User $actor): void
    {
        app(TaskService::class)->assignFromWorkflowHandoff($task, $recipient, $actor);
    }

    /**
     * Purchase Order -> Artwork Team
     *
     * Artwork Team membership is owned by Administration > Users & role
     * assignments, not by runtime task assignment. Every active user in the
     * current workspace who is configured as part of the Artwork Team receives
     * the Purchase Order handoff.
     *
     * The user list can represent that team either through an "Artwork Team" /
     * "Artwork" role or through the Artwork/Design department. Department
     * aliases preserve older installations that still use the seeded "Design"
     * / "DES" department. Duplicate email addresses are removed.
     *
     * @return Collection<int,User>
     */
    private function artworkTeamMembers(): Collection
    {
        $workspaceId = app(SetupContext::class)->workspaceId();

        $departmentIds = Department::query()
            ->where('is_active', true)
            ->get(['id', 'name', 'code'])
            ->filter(function (Department $department): bool {
                return collect([$department->name, $department->code])
                    ->filter(fn ($value) => filled($value))
                    ->contains(function ($value): bool {
                        return in_array($this->normalizeTeamIdentity((string) $value), [
                            'artwork',
                            'artworkteam',
                            'design',
                            'designteam',
                            'des',
                        ], true);
                    });
            })
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $roleIds = Role::query()
            ->where('workspace_id', $workspaceId)
            ->where('is_active', true)
            ->get(['id', 'name', 'slug', 'code'])
            ->filter(function (Role $role): bool {
                return collect([$role->name, $role->slug, $role->code])
                    ->filter(fn ($value) => filled($value))
                    ->contains(fn ($value) => in_array($this->normalizeTeamIdentity((string) $value), ['artworkteam', 'artwork'], true));
            })
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($departmentIds->isEmpty() && $roleIds->isEmpty()) return collect();

        return User::query()
            ->where('is_active', true)
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->where(function ($query) use ($departmentIds, $roleIds, $workspaceId): void {
                if ($departmentIds->isNotEmpty()) {
                    // users.department_id is what Administration > Users & role
                    // assignments displays. workspace_memberships.department_id
                    // remains a compatibility path for migrated accounts.
                    $query->whereIn('department_id', $departmentIds->all())
                        ->orWhereHas('workspaceMemberships', function ($memberships) use ($departmentIds, $workspaceId): void {
                            $memberships
                                ->where('workspace_id', $workspaceId)
                                ->whereIn('department_id', $departmentIds->all());
                        });
                }

                if ($roleIds->isNotEmpty()) {
                    $method = $departmentIds->isNotEmpty() ? 'orWhereHas' : 'whereHas';
                    $query->{$method}('roles', fn ($roles) => $roles->whereIn('roles.id', $roleIds->all()));

                    $query->orWhereIn('role_id', $roleIds->all());
                }
            })
            ->whereHas('workspaceMemberships', function ($query) use ($workspaceId): void {
                $query
                    ->where('workspace_id', $workspaceId)
                    ->where('status', 'active');
            })
            ->with(['department:id,name,code', 'roles:id,name,slug,code'])
            ->orderBy('name')
            ->get()
            ->filter(fn (User $user) => $this->isDeliverableUser($user))
            ->unique(fn (User $user) => mb_strtolower(trim((string) $user->email)))
            ->values();
    }

    /**
     * Artwork -> Order Team recipient picker.
     *
     * The sender chooses the actual Order Team recipients in the To field, so
     * the autocomplete must expose the same active system-user directory style
     * used elsewhere in FlowTrack instead of becoming empty when a legacy role
     * or department name does not exactly match "Order Team". External email
     * addresses are still accepted by artworkRecipientSelection().
     *
     * @return Collection<int,User>
     */
    private function orderTeamRecipientCandidates(): Collection
    {
        return $this->activeWorkspaceEmailUsers();
    }

    /** @return Collection<int,User> */
    private function activeWorkspaceEmailUsers(): Collection
    {
        $workspaceId = app(SetupContext::class)->workspaceId();

        return User::query()
            ->where('is_active', true)
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->whereHas('workspaceMemberships', function ($query) use ($workspaceId): void {
                $query
                    ->where('workspace_id', $workspaceId)
                    ->where('status', 'active');
            })
            ->with(['department:id,name,code', 'roles:id,name,slug,code'])
            ->orderBy('name')
            ->get()
            ->filter(fn (User $user) => $this->isDeliverableUser($user))
            ->unique(fn (User $user) => mb_strtolower(trim((string) $user->email)))
            ->values();
    }

    private function normalizeTeamIdentity(string $value): string
    {
        return mb_strtolower((string) preg_replace('/[^a-z0-9]+/i', '', trim($value)));
    }

    /**
     * Resolve the Order business unit from its client. Users & role assignments
     * stores business-unit availability on the active workspace membership as
     * iid, nep or both. A person marked both must receive handoffs for either
     * client, while an IID-only/NEP-only person only receives matching Orders.
     */
    private function orderBusinessUnit(FlowJob $job): ?string
    {
        $job->loadMissing('client');

        $code = $this->normalizeTeamIdentity((string) ($job->client?->code ?? ''));
        if ($code === 'iid') return 'iid';
        if ($code === 'nep') return 'nep';

        // Compatibility fallback for legacy client rows whose code may be blank
        // but whose display name still contains the business-unit identifier.
        $name = $this->normalizeTeamIdentity((string) ($job->client?->name ?? ''));
        if (str_contains($name, 'iid')) return 'iid';
        if (str_contains($name, 'nep')) return 'nep';

        return null;
    }

    private function recipientSourceLabel(string $key, ?FlowJob $job = null): string
    {
        if ($key === self::PURCHASE_ORDER_HANDOFF) {
            return 'Users & role assignments — Artwork Team users';
        }

        return 'Active FlowTrack users in the current workspace — choose one or more Order Team recipients';
    }

    private function missingArtworkTeamRecipientMessage(): string
    {
        return 'No active Artwork Team email address could be resolved from Users & role assignments. '
            .'Assign an active user with a valid email address to the Artwork Team role or Artwork/Design department.';
    }

    private function missingOrderTeamRecipientMessage(FlowJob $job): string
    {
        return 'No active system user with a valid email address is available in this workspace. Add or reactivate a user in Users & role assignments, or enter an external email address manually.';
    }

    /** @return Collection<int,Document> */
    private function sourceDocuments(FlowJob $job, string $handoffKey): Collection
    {
        $sourceKey = self::SOURCE_TASK_KEYS[$handoffKey] ?? null;
        if (! $sourceKey) return collect();

        $sourceTaskIds = $this->tasksForAutomationKey($job, $sourceKey)->pluck('id');
        if ($sourceTaskIds->isEmpty()) return collect();

        $documents = Document::query()
            ->where('flow_job_id', $job->id)
            ->whereIn('task_id', $sourceTaskIds)
            ->orderBy('id')
            ->get();

        if ($documents->isEmpty()) return collect();
        if ($sourceKey !== 'ART_PREPARE_UPLOAD') return $documents->values();

        $sourceTasks = $this->tasksForAutomationKey($job, $sourceKey);
        return $sourceTasks
            ->flatMap(fn (Task $sourceTask) => app(DocumentService::class)->currentArtworkDocuments(
                $sourceTask,
                $documents->where('task_id', $sourceTask->id)->values(),
            ))
            ->sortBy('id')
            ->values();
    }

    /** @return Collection<int,Task> */
    private function tasksForAutomationKey(FlowJob $job, string $automationKey): Collection
    {
        return Task::query()
            ->where('flow_job_id', $job->id)
            ->with(['setupTemplate.defaultDepartment', 'setupTemplate.defaultAssignee', 'assignee.department'])
            ->orderBy('id')
            ->get()
            ->filter(fn (Task $task) => $this->automationKey($task) === $automationKey)
            ->values();
    }

    private function automationKey(Task $task): ?string
    {
        $task->loadMissing('setupTemplate');
        $key = trim((string) ($task->setupTemplate?->automation_key ?? ''));
        if ($key !== '') return $key;

        $title = mb_strtolower(trim((string) $task->title));
        foreach (self::SOURCE_TASK_TITLES as $candidateKey => $candidateTitle) {
            if ($title === mb_strtolower($candidateTitle)) return $candidateKey;
        }
        if ($title === 'prepare and upload artwork') return 'ART_PREPARE_UPLOAD';

        if ($title === 'send purchase order to artwork team') return self::PURCHASE_ORDER_HANDOFF;
        if ($title === 'send artwork to order team') return self::ARTWORK_HANDOFF;

        return null;
    }

    private function isDeliverableUser(mixed $user): bool
    {
        return $user instanceof User
            && (bool) $user->is_active
            && filter_var(trim((string) $user->email), FILTER_VALIDATE_EMAIL) !== false;
    }

    private function teamLabel(string $key): string
    {
        return $key === self::PURCHASE_ORDER_HANDOFF ? 'Artwork Team' : 'Order Team';
    }

    private function subject(FlowJob $job, string $key): string
    {
        $orderNumber = $job->displayOrderNumber();

        return $key === self::PURCHASE_ORDER_HANDOFF
            ? 'Purchase Order ready — '.$orderNumber
            : 'Artwork ready — '.$orderNumber;
    }

    /** @return array<string,mixed> */
    /** @param Collection<int,Document> $documents */
    private function viewData(FlowJob $job, string $key, Document $document, Collection $documents, User $actor, array $brand, string $customerComment = ''): array
    {
        return [
            'brand' => $brand,
            'job' => $job,
            'team' => $this->teamLabel($key),
            'handoffType' => $key === self::PURCHASE_ORDER_HANDOFF ? 'purchase_order' : 'artwork',
            'document' => $document,
            'documents' => $documents,
            'sentBy' => $actor,
            'orderNumber' => $job->displayOrderNumber(),
            'productSummary' => $this->productSummary($job),
            'customerComment' => $key === self::ARTWORK_HANDOFF ? $customerComment : '',
        ];
    }

    private function senderAddress(): string
    {
        if (mb_strtolower((string) config('flowtrack_email.transport', 'laravel')) === 'e2a') {
            return trim((string) config('flowtrack_email.e2a.agent_email'));
        }

        return trim((string) config('mail.from.address'));
    }

    /** @param array<string,mixed> $brand */
    private function senderName(array $brand): string
    {
        if (mb_strtolower((string) config('flowtrack_email.transport', 'laravel')) === 'e2a') {
            return '';
        }

        $configured = trim((string) config('mail.from.name'));
        if ($configured !== '' && ! in_array(mb_strtolower($configured), ['laravel', 'flowtrack'], true)) {
            return $configured;
        }

        return trim((string) ($brand['name'] ?? '')) ?: 'Company';
    }

    private function deliveryLabel(): string
    {
        $transport = mb_strtolower(trim((string) config('flowtrack_email.transport', 'laravel')));
        if ($transport === 'e2a') return 'E2A email service';

        $mailer = trim((string) config('flowtrack_email.mailer', config('mail.default')));
        return $mailer !== '' ? strtoupper($mailer).' mailer' : 'Configured email service';
    }

    private function productSummary(FlowJob $job): string
    {
        $items = $job->relationLoaded('items') ? $job->items : $job->items()->get();
        $names = $items
            ->filter(fn ($item) => ! ($item->is_removed ?? false))
            ->map(fn ($item) => trim((string) ($item->product_name ?? '')))
            ->filter()
            ->unique()
            ->values();

        if ($names->isEmpty()) return trim((string) ($job->product ?? '')) ?: 'Order products';
        if ($names->count() <= 2) return $names->implode(', ');

        return $names->take(2)->implode(', ').' +'.($names->count() - 2).' more';
    }

    /** @return array<string,mixed> */
    private function companyBrand(): array
    {
        $branding = $this->branding->current();
        $profile = $this->companyProfile->current();
        $tradingName = trim((string) ($profile['trading_name'] ?? ''));
        $legalName = trim((string) ($profile['legal_name'] ?? ''));
        $displayName = $tradingName !== ''
            ? $tradingName
            : ($legalName !== '' ? $legalName : trim((string) ($branding['name'] ?? '')));

        return array_merge($branding, [
            'name' => $displayName !== '' ? $displayName : 'Company',
            'legal_name' => $legalName,
            'trading_name' => $tradingName,
            'registration_number' => trim((string) ($profile['registration_number'] ?? '')),
            'tax_number' => trim((string) ($profile['tax_number'] ?? '')),
            'billing_email' => trim((string) ($profile['billing_email'] ?? '')),
            'phone' => trim((string) ($profile['phone'] ?? '')),
            'website' => trim((string) ($profile['website'] ?? '')),
            'address_lines' => $this->companyProfile->addressLines($profile),
        ]);
    }
}
