<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $legacy = DB::table('master_records')
            ->where('type', 'system_setting')->where('code', 'SUPPLIER_PORTAL_ACCESS_REMINDER')
            ->whereNull('deleted_at')->get(['workspace_id', 'metadata']);
        foreach ($legacy as $setting) {
            $id = (int) $setting->workspace_id;
            $already = DB::table('supplier_artwork_reminder_settings')
                ->where('workspace_id', $id)->get(['draft', 'published'])
                ->contains(function ($row): bool {
                    foreach ([$row->draft, $row->published] as $json) {
                        $config = json_decode((string) $json, true);
                        if (($config['event_key'] ?? '') === 'supplier_portal:access_updated') return true;
                    }
                    return false;
                });
            if ($already) continue;
            $meta = json_decode((string) $setting->metadata, true) ?: [];
            $enabled = (bool) ($meta['enabled'] ?? true);
            $config = [
                'event_key' => 'supplier_portal:access_updated',
                'subject' => (string) ($meta['subject'] ?? 'Your supplier portal access is ready'),
                'preheader' => 'Your supplier dashboard login details',
                'heading' => 'Your supplier portal access is ready',
                'cta' => 'Open Supplier Dashboard',
                'message' => (string) ($meta['message'] ?? 'Your supplier portal account is ready. Sign in using the login email and username below.'),
                'sections' => ['greeting' => true, 'order_summary' => false, 'confirmation' => false,
                    'artwork' => false, 'action' => true, 'support' => true, 'internal' => false],
                'missing_email' => 'skip', 'recipient_type' => 'supplier', 'recipient_user_id' => null, 'delay_minutes' => 0,
            ];
            $now = now();
            DB::table('supplier_artwork_reminder_settings')->insert([
                'workspace_id' => $id, 'name' => 'Supplier Portal Access Email',
                'draft' => json_encode($config, JSON_THROW_ON_ERROR),
                'published' => json_encode($config, JSON_THROW_ON_ERROR),
                'published_at' => $now, 'version' => 1, 'is_active' => $enabled,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Leave user-created reminder configurations intact on rollback.
    }
};
