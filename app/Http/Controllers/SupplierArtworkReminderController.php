<?php

namespace App\Http\Controllers;

use App\Services\Email\ModuleEmailControlService;
use App\Services\Reminders\SupplierArtworkReminderService;
use App\Services\Reminders\OrderReminderEventCatalog;
use App\Services\Reminders\OrderReminderRecipients;
use App\Services\SetupContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

final class SupplierArtworkReminderController extends Controller
{
    public function index(SupplierArtworkReminderService $reminders, OrderReminderEventCatalog $events, OrderReminderRecipients $recipients, SetupContext $context)
    {
        $templates = $reminders->list($context->workspaceId())->map(fn ($row) => $this->serialize($row, $reminders))->all();
        $profile = app(\App\Services\CompanyProfileService::class)->current();
        $eventOptions = $events->options($context->workspaceId());
        $orderEmailEnabled = app(ModuleEmailControlService::class)->orderEnabled();
        return view('pages.supplier-artwork-reminders', [
            'reminderTemplates' => $templates,
            'reminderSummary' => \App\Services\Reminders\ReminderOverview::summarize(
                $templates, $eventOptions, $orderEmailEnabled
            ),
            'defaultConfig' => $reminders->defaults(),
            'reminderEvents' => $eventOptions,
            'reminderRecipientTypes' => OrderReminderRecipients::TYPES,
            'reminderRecipientUsers' => $recipients->userOptions($context->workspaceId()),
            'companyName' => $profile['trading_name'] ?: ($profile['legal_name'] ?: 'STEP PROMO'),
            'orderEmailEnabled' => $orderEmailEnabled,
        ]);
    }

    private function serialize(object $row, SupplierArtworkReminderService $reminders): array
    {
        $draft = $row->draft ? json_decode($row->draft, true) : null;
        $published = $row->published ? json_decode($row->published, true) : null;
        $eventKey = (string) (($draft['event_key'] ?? $published['event_key'] ?? null) ?: SupplierArtworkReminderService::ARTWORK_EVENT);
        return [
            'id' => (int) $row->id,
            'name' => (string) $row->name,
            'config' => array_replace_recursive($reminders->defaults($eventKey), is_array($draft) ? $draft : (is_array($published) ? $published : [])),
            'published' => is_array($published),
            'active' => (bool) $row->is_active,
            'version' => (int) $row->version,
            'publishedAt' => $row->published_at,
        ];
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'event_key' => ['required', 'string', 'max:100'],
            'subject' => ['required', 'string', 'max:'.($request->input('event_key') === OrderReminderEventCatalog::PORTAL_ACCESS_EVENT ? 120 : 100)],
            'preheader' => ['nullable', 'string', 'max:150'],
            'heading' => ['required', 'string', 'max:100'],
            'cta' => ['required', 'string', 'max:50'],
            'message' => ['required', 'string', 'max:'.($request->input('event_key') === OrderReminderEventCatalog::PORTAL_ACCESS_EVENT ? 2000 : 500)],
            'sections' => ['required', 'array'],
            'sections.*' => ['required', 'boolean'],
            'missing_email' => ['required', Rule::in(['skip'])],
            'recipient_type' => ['required', Rule::in(array_keys(OrderReminderRecipients::TYPES))],
            'recipient_user_id' => ['nullable', 'integer', 'min:1'],
            'delay_minutes' => ['required', 'integer', 'min:0', 'max:43200'],
        ]);
        foreach (['subject', 'preheader', 'heading', 'cta', 'message'] as $key) {
            $value = (string) ($data[$key] ?? '');
            preg_match_all('/\{\{\s*([^{}]+)\s*\}\}/', $value, $matches);
            if ($key === 'subject' && preg_match('/[\r\n]/', $value)) {
                throw ValidationException::withMessages(['subject' => 'The email subject must be a single line.']);
            }
            foreach ($matches[1] as $token) {
                if (! in_array(trim($token), SupplierArtworkReminderService::TOKENS, true)) {
                    throw ValidationException::withMessages([$key => 'Unsupported variable: '.trim($token)]);
                }
            }
        }
        $data['sections'] = collect(SupplierArtworkReminderService::SECTIONS)
            ->mapWithKeys(fn ($key) => [$key => (bool) ($data['sections'][$key] ?? false)])
            ->all();
        $data['sections']['internal'] = false;
        $data['recipient_user_id'] = $data['recipient_type'] === 'user' ? ($data['recipient_user_id'] ?? null) : null;
        if ($data['recipient_type'] === 'user' && ! $data['recipient_user_id']) {
            throw ValidationException::withMessages(['recipient_user_id' => 'Select an active FlowTracker user.']);
        }
        $data['preheader'] = $data['preheader'] ?? '';
        if ($data['event_key'] === OrderReminderEventCatalog::PORTAL_ACCESS_EVENT) {
            if ($data['recipient_type'] !== 'supplier') {
                throw ValidationException::withMessages(['recipient_type' => 'Supplier portal access can only be emailed to the supplier portal login email.']);
            }
            $data['sections']['order_summary'] = false;
            $data['sections']['confirmation'] = false;
            $data['sections']['artwork'] = false;
        }
        return $data;
    }

    public function create(Request $request, SupplierArtworkReminderService $reminders, OrderReminderEventCatalog $events, SetupContext $context): JsonResponse
    {
        $validated = $request->validate(['name' => ['required', 'string', 'max:120'], 'event_key' => ['required', 'string', 'max:100']]);
        $name = trim($validated['name']);
        if ($name === '') throw ValidationException::withMessages(['name' => 'Enter a reminder name.']);
        if (! $events->supports($context->workspaceId(), $validated['event_key'])) {
            throw ValidationException::withMessages(['event_key' => 'Select a supported active workflow event.']);
        }
        $row = $reminders->create($context->workspaceId(), $name, $validated['event_key']);
        return response()->json(['message' => 'Reminder created as a draft.', 'reminder' => $this->serialize($row, $reminders)], 201);
    }

    public function save(Request $request, SupplierArtworkReminderService $reminders, OrderReminderEventCatalog $events, SetupContext $context): JsonResponse
    {
        $base = $request->validate([
            'id' => ['required', 'integer', 'min:1'],
            'name' => ['required', 'string', 'max:120'],
            'publish' => ['required', 'boolean'],
        ]);
        $name = trim($base['name']);
        if ($name === '') throw ValidationException::withMessages(['name' => 'Enter a reminder name.']);
        $data = $this->validated($request);
        if (! $events->supports($context->workspaceId(), $data['event_key'])) {
            throw ValidationException::withMessages(['event_key' => 'This workflow event is no longer active in Workflow Setup.']);
        }
        if ($data['recipient_type'] === 'user' && ! app(OrderReminderRecipients::class)->userAvailable($context->workspaceId(), (int) $data['recipient_user_id'])) {
            throw ValidationException::withMessages(['recipient_user_id' => 'The selected user is not active in this workspace.']);
        }
        $publish = $request->boolean('publish');
        $result = $reminders->save($context->workspaceId(), (int) $base['id'], $name, $data, $publish);
        return response()->json(['message' => $publish ? 'Reminder published and activated.' : 'Draft saved.',
            'reminder' => [
                'id' => (int) $base['id'], 'name' => $name, 'config' => $data,
                'published' => $result['published'], 'active' => $result['active'],
                'version' => $result['version'], 'publishedAt' => $result['publishedAt'],
            ]] + $result);
    }

    public function pause(Request $request, SupplierArtworkReminderService $reminders, SetupContext $context): JsonResponse
    {
        $validated = $request->validate(['id' => ['required', 'integer', 'min:1']]);
        if (! $reminders->settings($context->workspaceId(), (int) $validated['id'])) abort(404);
        $reminders->pause($context->workspaceId(), (int) $validated['id']);
        return response()->json(['message' => 'Reminder paused. No supplier emails will be queued from this reminder.']);
    }

    public function preview(Request $request, SupplierArtworkReminderService $reminders): JsonResponse
    {
        $data = $this->validated($request);
        $sample = $reminders->sampleForConfig($data);
        return response()->json(['html' => $reminders->emailHtml($data, $sample),
            'subject' => $reminders->interpolate($data['subject'], $sample)]);
    }

    public function test(Request $request, SupplierArtworkReminderService $reminders, ModuleEmailControlService $emailControl): JsonResponse
    {
        $validated = $request->validate([
            'recipient' => ['required', 'email:rfc', 'max:254'],
            'published' => ['required', 'boolean'],
            'id' => ['required', 'integer', 'min:1'],
            'config' => ['required_if:published,false', 'array'],
        ]);
        $row = $reminders->settings(app(SetupContext::class)->workspaceId(), (int) $validated['id']);
        if (! $row) abort(404);
        $config = $request->boolean('published') ? json_decode((string) $row->published, true) : null;
        if ($request->boolean('published') && ! is_array($config)) abort(422, 'Publish this reminder before testing its published version.');
        $config ??= $this->validated(new Request($validated['config'] ?? []));
        if (($config['event_key'] ?? '') !== OrderReminderEventCatalog::PORTAL_ACCESS_EVENT) {
            abort_unless($emailControl->orderEnabled(), 422, 'Order email service is disabled in System Settings.');
        }
        try {
            $reminders->testSend($validated['recipient'], $config);
        } catch (Throwable $exception) {
            report($exception);
            return response()->json(['message' => 'Test email could not be sent. Check the centralized email provider.'], 503);
        }
        return response()->json(['message' => 'Test email accepted by the configured email provider.']);
    }

    public function history(Request $request, SetupContext $context): JsonResponse
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(['sent', 'failed', 'skipped', 'queued', 'sending'])],
            'event_key' => ['nullable', 'string', 'max:100'],
        ]);
        $selectedEvent = (string) ($data['event_key'] ?? '');
        if ($selectedEvent !== '' && ! app(OrderReminderEventCatalog::class)->supports($context->workspaceId(), $selectedEvent)) {
            throw ValidationException::withMessages(['event_key' => 'Invalid workflow event filter.']);
        }
        // Supplier portal email delivery is audited by the central mail transport, not the order-task delivery table.
        if ($selectedEvent === OrderReminderEventCatalog::PORTAL_ACCESS_EVENT) {
            return response()->json(['data' => [], 'next_page_url' => null]);
        }
        $automationKey = $selectedEvent !== '' ? explode(':', $selectedEvent)[1] : null;
        $rows = DB::table('supplier_artwork_reminder_deliveries as d')
            ->join('flow_jobs as j', 'j.id', '=', 'd.flow_job_id')
            ->join('tasks as t', 't.id', '=', 'd.task_id')
            ->leftJoin('task_pack_items as i', 'i.id', '=', 't.task_pack_task_id')
            ->where('d.workspace_id', $context->workspaceId())
            ->when($automationKey, fn ($query) => $query->where('i.automation_key', $automationKey))
            ->when($data['status'] ?? null, fn ($query, $status) => $query->where('d.status', $status))
            ->when($data['q'] ?? null, fn ($query, $text) => $query->where(function ($sub) use ($text) {
                $sub->where('j.job_number', 'like', '%'.$text.'%')
                    ->orWhere('d.supplier_name', 'like', '%'.$text.'%');
            }))
            ->orderByDesc('d.id')
            ->select('d.id', 'd.supplier_name', 'd.recipient_email', 'd.status', 'd.reason', 'd.created_at', 'd.sent_at', 'j.job_number', 'j.product', 't.title as task_title', 'i.automation_key')
            ->simplePaginate(20);
        return response()->json($rows);
    }
}
