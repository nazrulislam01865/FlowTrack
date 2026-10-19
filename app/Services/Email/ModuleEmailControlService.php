<?php

namespace App\Services\Email;

use App\Models\Activity;
use App\Models\MasterRecord;
use App\Models\User;
use App\Services\AccessControlService;
use App\Services\SetupContext;
use App\Services\WorkspaceRefreshService;
use Illuminate\Support\Facades\Cache;

/**
 * Workspace-scoped controls for module email delivery.
 *
 * These switches do not change the configured email provider. They only decide
 * whether Inquiry or Order business emails are allowed to leave FlowTrack.
 * Missing settings intentionally default to enabled so existing installations
 * retain their current behaviour until an administrator changes a switch.
 */
final class ModuleEmailControlService
{
    public const INQUIRY = 'inquiry';
    public const ORDER = 'order';

    private const SETTING_TYPE = 'system_setting';

    /** @var array<string,array{code:string,label:string,description:string}> */
    private const MODULES = [
        self::INQUIRY => [
            'code' => 'INQUIRY_EMAIL_ENABLED',
            'label' => 'Inquiry email service',
            'description' => 'RFQ invitations, reminders, quotation acknowledgements and supplier award notifications.',
        ],
        self::ORDER => [
            'code' => 'ORDER_EMAIL_ENABLED',
            'label' => 'Order email service',
            'description' => 'Order workflow handoffs, invoice emails and payment reminders.',
        ],
    ];

    public function inquiryEnabled(): bool
    {
        return $this->isEnabled(self::INQUIRY);
    }

    public function orderEnabled(): bool
    {
        return $this->isEnabled(self::ORDER);
    }

    public function isEnabled(string $module): bool
    {
        $definition = $this->definition($module);
        $workspaceId = app(SetupContext::class)->workspaceId();

        return Cache::remember(
            $this->cacheKey($workspaceId, $module),
            now()->addMinutes(10),
            function () use ($workspaceId, $definition): bool {
                $record = MasterRecord::query()
                    ->where('workspace_id', $workspaceId)
                    ->where('type', self::SETTING_TYPE)
                    ->where('code', $definition['code'])
                    ->first(['metadata']);

                if (! $record) {
                    return true;
                }

                return filter_var(
                    data_get($record->metadata, 'enabled', true),
                    FILTER_VALIDATE_BOOL,
                    FILTER_NULL_ON_FAILURE,
                ) ?? true;
            },
        );
    }

    /** @return array<int,array{module:string,code:string,label:string,description:string,enabled:bool}> */
    public function settings(): array
    {
        return collect(array_keys(self::MODULES))
            ->map(function (string $module): array {
                $definition = self::MODULES[$module];

                return [
                    'module' => $module,
                    'code' => $definition['code'],
                    'label' => $definition['label'],
                    'description' => $definition['description'],
                    'enabled' => $this->isEnabled($module),
                ];
            })
            ->values()
            ->all();
    }

    public function toggle(string $module, User $actor): bool
    {
        return $this->setEnabled($module, ! $this->isEnabled($module), $actor);
    }

    public function setEnabled(string $module, bool $enabled, User $actor): bool
    {
        abort_unless(app(AccessControlService::class)->isAdministrator($actor), 403);

        $definition = $this->definition($module);
        $workspaceId = app(SetupContext::class)->workspaceId();
        $record = MasterRecord::withTrashed()->firstOrNew([
            'workspace_id' => $workspaceId,
            'type' => self::SETTING_TYPE,
            'code' => $definition['code'],
        ]);

        if ($record->trashed()) {
            $record->restore();
        }

        $record->fill([
            'name' => $definition['label'],
            'description' => $enabled ? 'enabled' : 'disabled',
            'metadata' => [
                'enabled' => $enabled,
                'module' => $module,
                'updated_by' => (int) $actor->id,
            ],
            'status' => 'active',
            'sort_order' => 0,
            'created_by' => $record->exists ? $record->created_by : $actor->id,
        ])->save();

        Cache::forget($this->cacheKey($workspaceId, $module));

        Activity::create([
            'subject_type' => User::class,
            'subject_id' => (int) $actor->id,
            'user_id' => (int) $actor->id,
            'event' => 'access.email_service_changed',
            'description' => $definition['label'].' '.($enabled ? 'enabled' : 'disabled').'.',
            'meta' => [
                'module' => $module,
                'enabled' => $enabled,
                'setting_code' => $definition['code'],
            ],
        ]);

        app(WorkspaceRefreshService::class)->touch('email-service-settings');

        return $enabled;
    }

    /**
     * Resolve the module represented by a provider-neutral message context.
     * This is used as a final delivery guard so already queued module emails are
     * also suppressed when an administrator turns a service off.
     */
    public function moduleForContext(array $context): ?string
    {
        if (array_key_exists('inquiry_id', $context)) {
            return self::INQUIRY;
        }

        if (array_key_exists('order_id', $context) || array_key_exists('flow_job_id', $context)) {
            return self::ORDER;
        }

        $type = strtolower(trim((string) ($context['type'] ?? '')));
        if (str_starts_with($type, 'rfq_') || str_starts_with($type, 'inquiry_')) {
            return self::INQUIRY;
        }

        if (str_starts_with($type, 'order_') || $type === 'payment_reminder') {
            return self::ORDER;
        }

        return null;
    }

    /** @return array{code:string,label:string,description:string} */
    private function definition(string $module): array
    {
        abort_unless(isset(self::MODULES[$module]), 422, 'Unknown email service module.');

        return self::MODULES[$module];
    }

    private function cacheKey(int $workspaceId, string $module): string
    {
        return 'flowtrack:email-service:'.$workspaceId.':'.$module;
    }
}
