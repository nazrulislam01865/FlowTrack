<?php

namespace App\Services\Reminders;

use App\DTOs\Email\EmailMessage;
use App\Jobs\SendSupplierArtworkReminder;
use App\Models\Task;
use App\Services\BrandingService;
use App\Services\CompanyProfileService;
use App\Services\Email\EmailService;
use App\Services\SetupContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Published order workflow completion reminders; a single active template per event. */
final class SupplierArtworkReminderService
{
    public const ARTWORK_EVENT = OrderReminderEventCatalog::ARTWORK_EVENT;
    public const PORTAL_ACCESS_EVENT = OrderReminderEventCatalog::PORTAL_ACCESS_EVENT;
    public const SECTIONS = ['greeting', 'order_summary', 'confirmation', 'artwork', 'action', 'support', 'internal'];
    public const TOKENS = ['supplier_name', 'order_number', 'product_name', 'quantity', 'artwork_confirmed_date', 'event_name', 'completed_date', 'recipient_name', 'login_email', 'username', 'dashboard_link'];

    public function defaults(string $eventKey = self::ARTWORK_EVENT): array
    {
        if ($eventKey === self::PORTAL_ACCESS_EVENT) {
            return [
                'event_key' => $eventKey,
                'subject' => 'Your supplier portal access is ready',
                'preheader' => 'Your supplier dashboard login details',
                'heading' => 'Your supplier portal access is ready',
                'cta' => 'Open Supplier Dashboard',
                'message' => 'Your supplier portal account is ready. Sign in using the login email and username below. For security, your password is not included in this email. Use the secure password-setup email from the portal when provided, or choose Forgot password on the login page.',
                'sections' => ['greeting' => true, 'order_summary' => false, 'confirmation' => false, 'artwork' => false, 'action' => true, 'support' => true, 'internal' => false],
                'missing_email' => 'skip',
                'recipient_type' => 'supplier',
                'recipient_user_id' => null,
                'delay_minutes' => 0,
            ];
        }
        return [
            'event_key' => $eventKey,
            'subject' => $eventKey === self::ARTWORK_EVENT ? 'Artwork confirmed for Order {{order_number}}' : '{{event_name}} for Order {{order_number}}',
            'preheader' => $eventKey === self::ARTWORK_EVENT ? 'Approved artwork is ready for your order.' : 'An order workflow task has been completed.',
            'heading' => $eventKey === self::ARTWORK_EVENT ? 'Artwork confirmed — ready for production' : '{{event_name}}',
            'cta' => $eventKey === self::ARTWORK_EVENT ? 'View Confirmed Artwork' : 'View Order Details',
            'message' => $eventKey === self::ARTWORK_EVENT ? 'The artwork for Order {{order_number}} has been confirmed. Please review the approved artwork and proceed with the next production step.' : '{{event_name}} has been completed for Order {{order_number}}. Please review the order details for the next step.',
            'sections' => ['greeting' => true, 'order_summary' => true, 'confirmation' => $eventKey === self::ARTWORK_EVENT, 'artwork' => $eventKey === self::ARTWORK_EVENT, 'action' => true, 'support' => true, 'internal' => false],
            'missing_email' => 'skip',
            'recipient_type' => 'supplier',
            'recipient_user_id' => null,
            'delay_minutes' => 0,
        ];
    }

    public function list(int $workspaceId): \Illuminate\Support\Collection
    {
        return DB::table('supplier_artwork_reminder_settings')
            ->where('workspace_id', $workspaceId)->orderBy('id')
            ->get(['id', 'name', 'draft', 'published', 'published_at', 'version', 'is_active']);
    }

    public function settings(int $workspaceId, int $id): ?object
    {
        return DB::table('supplier_artwork_reminder_settings')
            ->where('workspace_id', $workspaceId)->where('id', $id)->first();
    }

    /** Cached active published templates by workflow event and template id. */
    private function publishedMap(int $workspaceId): array
    {
        return Cache::remember('flowtrack:supplier-artwork-reminder:v2:'.$workspaceId, now()->addMinutes(5), function () use ($workspaceId) {
            $map = [];
            $rows = DB::table('supplier_artwork_reminder_settings')
                ->where('workspace_id', $workspaceId)->where('is_active', true)
                ->whereNotNull('published')->get(['id', 'published']);
            foreach ($rows as $row) {
                $data = json_decode((string) $row->published, true);
                if (! is_array($data)) continue;
                $event = (string) ($data['event_key'] ?? self::ARTWORK_EVENT);
                $map[$event][(int) $row->id] = array_replace_recursive($this->defaults($event), $data);
            }
            return $map;
        });
    }

    public function publishedForEvent(int $workspaceId, string $eventKey): array
    {
        return $this->publishedMap($workspaceId)[$eventKey] ?? [];
    }

    public function published(int $workspaceId, string $eventKey = self::ARTWORK_EVENT): ?array
    {
        return array_values($this->publishedForEvent($workspaceId, $eventKey))[0] ?? null;
    }

    public function publishedTemplate(int $workspaceId, string $eventKey, int $templateId): ?array
    {
        return $this->publishedForEvent($workspaceId, $eventKey)[$templateId] ?? null;
    }

    public function create(int $workspaceId, string $name, string $eventKey = self::ARTWORK_EVENT): object
    {
        $defaults = $this->defaults($eventKey);
        $id = DB::table('supplier_artwork_reminder_settings')->insertGetId([
            'workspace_id' => $workspaceId, 'name' => $name,
            'draft' => json_encode($defaults, JSON_THROW_ON_ERROR),
            'is_active' => false, 'version' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return (object) [
            'id' => (int) $id, 'name' => $name, 'draft' => json_encode($defaults, JSON_THROW_ON_ERROR),
            'published' => null, 'published_at' => null, 'version' => 0, 'is_active' => false,
        ];
    }

    public function save(int $workspaceId, int $id, string $name, array $data, bool $publish): array
    {
        return DB::transaction(function () use ($workspaceId, $id, $name, $data, $publish) {
            // Locking the workspace serializes competing publish requests without an extra query per template.
            DB::table('workspaces')->where('id', $workspaceId)->lockForUpdate()->first(['id']);
            $current = $this->settings($workspaceId, $id);
            if (! $current) abort(404, 'This reminder does not belong to your workspace.');
            $now = now();
            $fields = [
                'name' => $name,
                'draft' => json_encode($data, JSON_THROW_ON_ERROR),
                'updated_at' => $now,
            ];
            if ($publish) {
                // One active rule per event: no duplicate supplier emails from different templates.
                $active = DB::table('supplier_artwork_reminder_settings')
                    ->where('workspace_id', $workspaceId)->where('is_active', true)
                    ->where('id', '!=', $id)->get(['id', 'published']);
                $sameEvent = $active->filter(static function ($row) use ($data) {
                    $published = json_decode((string) $row->published, true);
                    return (string) ($published['event_key'] ?? self::ARTWORK_EVENT) === $data['event_key']
                        && (string) ($published['recipient_type'] ?? 'supplier') === ($data['recipient_type'] ?? 'supplier')
                        && (int) ($published['recipient_user_id'] ?? 0) === (int) ($data['recipient_user_id'] ?? 0);
                })->pluck('id')->all();
                if ($sameEvent) DB::table('supplier_artwork_reminder_settings')
                    ->whereIn('id', $sameEvent)->where('workspace_id', $workspaceId)
                    ->update(['is_active' => false, 'updated_at' => $now]);
                $fields += ['published' => $fields['draft'], 'published_at' => $now,
                    'version' => ((int) $current->version) + 1, 'is_active' => true];
            }
            DB::table('supplier_artwork_reminder_settings')->where('id', $id)->where('workspace_id', $workspaceId)->update($fields);
            if ($publish) DB::afterCommit(fn () => Cache::forget('flowtrack:supplier-artwork-reminder:v2:'.$workspaceId));

            return ['id' => $id, 'name' => $name, 'published' => $publish || $current->published !== null,
                'active' => $publish || (bool) $current->is_active, 'version' => $fields['version'] ?? (int) $current->version,
                'publishedAt' => $publish ? $now->toIso8601String() : $current->published_at];
        });
    }

    public function pause(int $workspaceId, int $id): void
    {
        $changed = DB::table('supplier_artwork_reminder_settings')->where('id', $id)
            ->where('workspace_id', $workspaceId)->where('is_active', true)
            ->update(['is_active' => false, 'updated_at' => now()]);
        if ($changed) Cache::forget('flowtrack:supplier-artwork-reminder:v2:'.$workspaceId);
    }

    /** Dispatch after the successful confirmation commits. Neither missing emails nor mail failures block the order. */
    public function queueForCompletion(Task $task, string $automationKey): void
    {
        $workspaceId = app(SetupContext::class)->workspaceId();
        $eventKey = OrderReminderEventCatalog::eventFor($automationKey);
        $configs = $this->publishedForEvent($workspaceId, $eventKey);
        foreach ($configs as $templateId => $config) {
            $dispatch = SendSupplierArtworkReminder::dispatch((int) $task->id, $workspaceId, $eventKey, (int) $templateId)->afterCommit();
            if (($config['delay_minutes'] ?? 0) > 0) {
                $dispatch->delay(now()->addMinutes((int) $config['delay_minutes']));
            }
        }
    }

    /** Only called after a confirmed order action to show an immediate, nonblocking warning. */
    public function missingSupplierEmailWarning(int $workspaceId, int $flowJobId): ?string
    {
        if (! collect($this->publishedForEvent($workspaceId, self::ARTWORK_EVENT))->contains(fn ($config) => ($config['recipient_type'] ?? 'supplier') === 'supplier')) return null;
        $suppliers = DB::table('flow_job_items as i')
            ->leftJoin('master_records as s', function ($join) use ($workspaceId) {
                $join->on('s.id', '=', 'i.supplier_id')->where('s.workspace_id', '=', $workspaceId)
                    ->where('s.type', '=', 'supplier')->where('s.status', '=', 'active')->whereNull('s.deleted_at');
            })->where('i.flow_job_id', $flowJobId)->where('i.is_removed', false)
            ->select('i.supplier_id', 's.name', 's.metadata')->get()->unique('supplier_id');
        if ($suppliers->isEmpty()) {
            // Legacy orders without item rows may still have an explicit order supplier.
            $legacy = DB::table('flow_jobs as j')->leftJoin('master_records as s', function ($join) use ($workspaceId) {
                $join->on('s.id', '=', 'j.supplier_id')->where('s.workspace_id', '=', $workspaceId)
                    ->where('s.type', '=', 'supplier')->where('s.status', '=', 'active')->whereNull('s.deleted_at');
            })->where('j.id', $flowJobId)->first(['j.supplier_id', 's.name', 's.metadata']);
            if (! $legacy || ! $legacy->supplier_id) return 'Artwork confirmed. No supplier is assigned; the supplier reminder was skipped.';
            $suppliers = collect([$legacy]);
        }

        foreach ($suppliers as $supplier) {
            $metadata = is_array($supplier->metadata) ? $supplier->metadata : json_decode((string) $supplier->metadata, true);
            if (! $supplier->supplier_id || ! $supplier->name || ! filter_var(data_get($metadata, 'email'), FILTER_VALIDATE_EMAIL)) {
                return 'Artwork confirmed. Supplier email is not available for one or more assigned products; those reminders will be skipped.';
            }
        }
        return null;
    }

    public function interpolate(string $text, array $data): string
    {
        return preg_replace_callback('/\{\{([a-z_]+)\}\}/', static fn ($m) =>
            in_array($m[1], self::TOKENS, true) ? (string) ($data[$m[1]] ?? '') : '', $text) ?? '';
    }

    public function sample(): array
    {
        return [
            'supplier_name' => 'Supplier A', 'recipient_name' => 'Supplier A', 'order_number' => 'FT-10245',
            'product_name' => 'Promotional Gift Box', 'quantity' => '250',
            'artwork_confirmed_date' => '08 Oct 2026',
            'event_name' => 'Artwork Confirmed', 'completed_date' => '08 Oct 2026',
        ];
    }

    public function sampleForConfig(array $config): array
    {
        $sample = $this->sampleForEvent((string) ($config['event_key'] ?? self::ARTWORK_EVENT));
        if (($config['event_key'] ?? '') === self::PORTAL_ACCESS_EVENT) {
            return $sample;
        }
        $sample['recipient_name'] = match ($config['recipient_type'] ?? 'supplier') {
            'owner' => 'Order owner', 'coordinator' => 'Order coordinator',
            'assignee' => 'Task assignee', 'client' => 'Client contact',
            'client_contact' => 'Primary client contact', 'user' => 'FlowTracker user',
            default => 'Supplier A',
        };
        return $sample;
    }

    public function sampleForEvent(string $eventKey): array
    {
        $sample = $this->sample();
        if ($eventKey === self::PORTAL_ACCESS_EVENT) {
            return array_merge($sample, [
                'recipient_name' => 'Supplier A', 'event_name' => 'Supplier Portal Access',
                'login_email' => 'supplier@example.com', 'username' => 'supplier.user',
                'dashboard_link' => (string) (config('supplier_bridge.login_url') ?: 'https://supplier.example.com/login'),
            ]);
        }
        if ($eventKey !== self::ARTWORK_EVENT) {
            $parts = explode(':', $eventKey);
            $sample['event_name'] = ucwords(strtolower(str_replace('_', ' ', $parts[1] ?? 'Workflow Task'))).' Completed';
        }
        return $sample;
    }

    public function emailHtml(array $config, array $data, bool $test = false): string
    {
        if (($config['event_key'] ?? '') === self::PORTAL_ACCESS_EVENT) {
            return app(SupplierPortalAccessReminderService::class)->emailHtml(
                (string) ($data['supplier_name'] ?? 'Supplier A'),
                (string) ($data['login_email'] ?? 'supplier@example.com'),
                (string) ($data['username'] ?? 'supplier.user'),
                $config,
                (string) ($data['dashboard_link'] ?? 'https://supplier.example.com/login'),
                $test,
            );
        }
        $sections = $config['sections'] ?? [];
        $artworkEvent = ($config['event_key'] ?? self::ARTWORK_EVENT) === self::ARTWORK_EVENT;
        $profile = app(CompanyProfileService::class)->current();
        $brand = app(BrandingService::class)->current();
        $company = (string) ($profile['trading_name'] ?: $profile['legal_name'] ?: $brand['name'] ?: 'STEP PROMO');
        $logo = (string) ($brand['logo_url'] ?? '');
        $logoHtml = $logo !== '' ? '<img src="'.e($logo).'" alt="'.e($company).'" style="max-width:150px;max-height:44px">' : '<strong>'.e($company).'</strong>';
        $row = static fn (string $label, string $value) => '<tr><td style="padding:6px;color:#708092">'.e($label).'</td><td style="padding:6px;text-align:right"><strong>'.e($value).'</strong></td></tr>';
        $message = nl2br(e($this->interpolate((string) $config['message'], $data)));
        $heading = e($this->interpolate((string) $config['heading'], $data));
        $url = (bool) config('flowtrack.order_tracking_enabled', false) ? route('order.track') : null;
        $parts = [];
        if ($sections['greeting'] ?? true) $parts[] = '<p>Hi '.e($data['recipient_name'] ?? $data['supplier_name'] ?? 'Team').',</p>';
        $parts[] = '<p>'.$message.'</p>';
        if ($sections['order_summary'] ?? true) {
            $parts[] = '<table style="width:100%;border:1px solid #e1e7eb;background:#f8fafb;border-radius:6px">'
                .$row('Order Number', (string) ($data['order_number'] ?? ''))
                .$row('Product', (string) ($data['product_name'] ?? ''))
                .$row('Quantity', (string) ($data['quantity'] ?? '')).'</table>';
        }
        if ($artworkEvent && ($sections['confirmation'] ?? true)) $parts[] = '<p style="padding:10px;background:#ecfbf5;color:#257756"><strong>✓ Artwork Confirmed</strong> · '.e($data['artwork_confirmed_date'] ?? '').'</p>';
        if ($artworkEvent && ($sections['artwork'] ?? true)) $parts[] = '<p>Confirmed artwork: '.($url ? 'Use the order tracking page to verify your supplier email and view approved order information.' : 'Please contact the order team for the approved artwork.').'</p>';
        if (($sections['action'] ?? true) && $url) $parts[] = '<p><a href="'.e($url).'" style="display:inline-block;background:#078777;color:white;text-decoration:none;padding:12px 18px;border-radius:5px">'.e($config['cta']).'</a></p>';
        if ($sections['support'] ?? true) $parts[] = '<p style="border-top:1px solid #e1e7eb;padding-top:12px;font-size:12px">Need help? '.e($profile['billing_email'] ?? '').' '.e($profile['phone'] ?? '').'</p>';
        $preheader = '<div style="display:none;max-height:0;overflow:hidden">'.e($this->interpolate((string) ($config['preheader'] ?? ''), $data)).'</div>';
        $badge = $test ? '<div style="padding:12px;color:#7c5518;background:#fff7e8">TEST EMAIL — no workflow notification was triggered</div>' : '';
        return '<!doctype html><html><body style="font-family:Arial,sans-serif;background:#f2f5f6;color:#243447;padding:20px">'
            .'<div style="max-width:580px;background:white;margin:auto"><div style="background:#0e2a37;color:#fff;padding:18px">'.$logoHtml.'</div>'
            .$preheader.'<div style="padding:26px">'.$badge.'<p style="font-size:11px;color:#748296;letter-spacing:1px">ORDER REMINDER</p>'
            .'<h2 style="color:#1d3343">'.$heading.'</h2>'.implode('', $parts).'</div></div></body></html>';
    }

    public function testSend(string $email, array $config): void
    {
        $sample = $this->sampleForConfig($config);
        app(EmailService::class)->sendNow(EmailMessage::html(
            $email, '[TEST] '.$this->interpolate($config['subject'], $sample),
            $this->emailHtml($config, $sample, true),
            ['type' => ($config['event_key'] ?? '') === self::PORTAL_ACCESS_EVENT ? 'supplier_portal_access_test' : 'order_supplier_artwork_reminder_test'],
        ));
    }
}
