<?php

namespace App\Services\Reminders;

use App\Jobs\SendSupplierPortalAccessInvitation;
use App\Models\MasterRecord;

/** Supplier portal notification through the shared published reminder wizard. */
final class SupplierPortalAccessReminderService
{
    /** Published Supplier Portal reminder is managed only through Create Reminder. */
    public function settings(int $workspaceId): array
    {
        $rule = app(SupplierArtworkReminderService::class)
            ->published($workspaceId, OrderReminderEventCatalog::PORTAL_ACCESS_EVENT);
        return $rule ? ['enabled' => true] + $rule : ['enabled' => false];
    }

    /** Called only after the signed Supplier Portal API accepts account changes. */
    public function queue(MasterRecord $supplier, string $email, string $username, bool $sendSetupLink): void
    {
        $workspaceId = (int) $supplier->workspace_id;
        $rules = app(SupplierArtworkReminderService::class)
            ->publishedForEvent($workspaceId, OrderReminderEventCatalog::PORTAL_ACCESS_EVENT);
        if (! $rules) {
            return;
        }
        // Reminder save enforces one active published supplier-access rule.
        $templateId = (int) array_key_first($rules);
        $config = $rules[$templateId];
        $job = SendSupplierPortalAccessInvitation::dispatch(
            $workspaceId, (int) $supplier->id,
            strtolower(trim($email)), strtolower(trim($username)),
            $sendSetupLink, $templateId,
        )->afterCommit();
        if (($config['delay_minutes'] ?? 0) > 0) {
            $job->delay(now()->addMinutes((int) $config['delay_minutes']));
        }
    }

    public function loginUrl(): string
    {
        // The bridge API address may be private: deployments can configure the public login separately.
        $configured = trim((string) config('supplier_bridge.login_url'));
        $url = $configured !== '' ? $configured : rtrim((string) config('supplier_bridge.url'), '/').'/login';
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);
        if (! is_string($host) || $host === '' || ! in_array($scheme, ['http', 'https'], true)) {
            throw new \RuntimeException('Configure the public Supplier Portal login URL before sending access emails.');
        }
        if ($scheme !== 'https' && ! (app()->environment(['local', 'testing']) && in_array($host, ['localhost', '127.0.0.1', '::1'], true))) {
            throw new \RuntimeException('Supplier Portal login URL must use HTTPS in production.');
        }
        if (parse_url($url, PHP_URL_USER) !== null || parse_url($url, PHP_URL_PASS) !== null || parse_url($url, PHP_URL_FRAGMENT) !== null) {
            throw new \RuntimeException('Supplier Portal login URL contains unsupported URL components.');
        }
        return $url;
    }

    public function emailHtml(string $supplierName, string $email, string $username, array $settings, string $url, bool $test = false): string
    {
        $data = [
            'supplier_name' => $supplierName, 'recipient_name' => $supplierName,
            'login_email' => $email, 'username' => $username, 'dashboard_link' => $url,
        ];
        $render = app(SupplierArtworkReminderService::class);
        $sections = $settings['sections'] ?? [];
        $message = nl2br(e($render->interpolate((string) ($settings['message'] ?? ''), $data)));
        $heading = e($render->interpolate((string) ($settings['heading'] ?? 'Supplier Portal Access'), $data));
        $urlEscaped = e($url);
        $testBanner = $test ? '<p style="background:#fff7e8;padding:12px">TEST EMAIL — no supplier account was created or changed.</p>' : '';
        return '<!doctype html><html><body style="font-family:Arial,sans-serif;background:#f3f6f8;padding:24px;color:#21384a">'
            .'<div style="display:none;max-height:0;overflow:hidden">'.e($render->interpolate((string) ($settings['preheader'] ?? ''), $data)).'</div>'
            .'<div style="max-width:560px;margin:auto;background:white;border:1px solid #dfe8ec;border-radius:8px;padding:28px">'
            .$testBanner.'<h2 style="margin:0 0 16px">'.$heading.'</h2>'
            .(($sections['greeting'] ?? true) ? '<p>Hello '.e($supplierName).',</p>' : '')
            .'<p style="line-height:1.7">'.$message.'</p>'
            .'<p><strong>Login email:</strong> '.e($email).'<br><strong>Username:</strong> '.e($username).'</p>'
            .(($sections['action'] ?? true) ? '<p><a href="'.$urlEscaped.'" style="display:inline-block;background:#078777;color:white;text-decoration:none;padding:12px 20px;border-radius:5px">'.e($settings['cta'] ?? 'Open Supplier Dashboard').'</a></p>' : '')
            .'<p style="font-size:12px;color:#637487">Dashboard link: <a href="'.$urlEscaped.'">'.$urlEscaped.'</a></p>'
            .(($sections['support'] ?? true) ? '<p style="font-size:12px;color:#637487">For help, contact your FlowTracker team.</p>' : '')
            .'<p style="font-size:12px;color:#637487">For security, passwords are never included in access emails.</p>'
            .'</div></body></html>';
    }
}
