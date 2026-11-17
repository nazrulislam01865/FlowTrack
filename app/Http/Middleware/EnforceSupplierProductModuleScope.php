<?php

namespace App\Http\Middleware;

use App\Services\SupplierBridge\SupplierProductModuleAccess;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/** Supplier actor sessions are NEVER general-purpose FlowTracker logins. */
final class EnforceSupplierProductModuleScope
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user || ! app(SupplierProductModuleAccess::class)->isPortalActor($user)) {
            return $next($request);
        }
        // A portal user may launch a fresh one-use ticket while an earlier
        // delegated FlowTracker session is still active or has expired.
        // The ticket endpoint independently validates issuer, supplier and TTL.
        if ($request->is('supplier-product/access/*') && $request->isMethod('GET')) {
            return $next($request);
        }
        // A normal, top-level FlowTracker browser tab belongs on the staff
        // hostname. The Product iframe (Sec-Fetch-Dest: iframe) stays here.
        // This also covers an expired delegated session and an open Product tab.
        if ($request->isMethod('GET') && $request->header('Sec-Fetch-Dest') === 'document'
            && $request->acceptsHtml()) {
            $staffOrigin = rtrim((string) config('app.url'), '/');
            $staffHost = parse_url($staffOrigin, PHP_URL_HOST);
            if (is_string($staffHost) && $staffHost !== ''
                && strtolower($staffHost) !== strtolower($request->getHost())) {
                return redirect()->away($staffOrigin.$request->getRequestUri());
            }
        }
        $access = app(SupplierProductModuleAccess::class);
        $actor = $access->actor($user);
        $hash = (string) $request->session()->get('supplier_product_session_hash', '');
        $sessionValid = (int) $request->session()->get('supplier_product_actor_id', 0) === (int) $actor->id
            && (int) $request->session()->get('supplier_product_expires', 0) >= time()
            && (int) $request->session()->get('supplier_product_workspace_id', 0)
                === (int) DB::table('master_records')->where('id', $actor->supplier_id)->value('workspace_id')
            && preg_match('/^[a-f0-9]{64}$/', $hash)
            && DB::table('supplier_product_portal_sessions')->where('session_hash', $hash)
                ->where('supplier_id', $actor->supplier_id)
                ->where('supplier_user_id', $actor->supplier_user_id)
                ->whereNotNull('consumed_at')->whereNull('revoked_at')
                ->where('expires_at', '>', now())->exists();
        if (! $sessionValid || (! $access->can($user, 'products', 'view') && ! $access->can($user, 'categories', 'view'))) {
            return response('Supplier product module session expired or disabled.', 403)
                ->header('Cache-Control', 'private, no-store');
        }

        // Livewire 4 may use cache-busted routes such as
        // /livewire-de29d090/update instead of /livewire/update.
        $livewirePath = $request->path();
        $livewireUpdate = $request->is('livewire/update')
            || preg_match('~^livewire-[a-f0-9]{8}/update$~i', $livewirePath);
        $livewireUpload = $request->is('livewire/upload-file')
            || $request->is('livewire/preview-file/*')
            || preg_match('~^livewire-[a-f0-9]{8}/(?:upload-file|preview-file/[a-zA-Z0-9_-]+)$~i', $livewirePath);
        $livewireScript = $request->is('livewire/livewire.js')
            || $request->is('livewire/livewire.min.js')
            || preg_match('~^livewire-[a-f0-9]{8}/livewire(?:\.min)?\.js$~i', $livewirePath);

        if ($request->is('master-data')) {
            $group = (string) $request->query('group', 'product');
            abort_unless($request->isMethod('GET')
                && in_array($group, ['product', 'product_category'], true), 403);
            abort_unless($access->canModule($user, $group === 'product' ? 'catalog_products' : 'product_categories', 'view'), 403);
        } elseif ($livewireUpdate) {
            abort_unless($request->isMethod('POST'), 403);
            $components = $request->input('components');
            abort_unless(is_array($components) && count($components) >= 1 && count($components) <= 12, 403);
            foreach ($components as $component) {
                $snapshot = json_decode((string) ($component['snapshot'] ?? ''), true);
                abort_unless(($snapshot['memo']['name'] ?? '') === 'master-data.index', 403);
                $group = data_get($snapshot, 'data.group', '');
                // Livewire serialized state can use tuple form: ['product', {...}].
                $group = is_array($group) ? (string) ($group[0] ?? '') : (string) $group;
                abort_unless(in_array($group, ['product', 'product_category'], true), 403);
                $section = $group === 'product' ? 'products' : 'categories';
                abort_unless($access->can($user, $section, 'view'), 403);
                foreach ((array) ($component['updates'] ?? []) as $field => $value) {
                    if ($field === 'group') {
                        abort_unless(in_array($value, ['product', 'product_category'], true), 403);
                        $nextSection = $value === 'product' ? 'products' : 'categories';
                        abort_unless($access->can($user, $nextSection, 'view'), 403);
                    }
                }
            }
        } elseif ($livewireUpload) {
            abort_unless($access->can($user, 'products', 'create') || $access->can($user, 'products', 'edit'), 403);
        } elseif ($livewireScript) {
            abort_unless($request->isMethod('GET'), 403);
        } elseif ($request->is('filter-options/*')) {
            $type = (string) $request->segment(2);
            abort_unless($request->isMethod('GET') && in_array($type, [
                'suppliers', 'products', 'product-categories', 'clients', 'shipment-urgencies', 'currencies',
            ], true), 403);
            if ($type === 'clients') abort_unless($access->permitsSection($user, 'availability', 'view')
                || $access->permitsSection($user, 'client_pricing', 'view'), 403);
        } elseif ($request->is('branding-assets/*')) {
            abort_unless($request->isMethod('GET'), 403);
        } elseif ($request->is('master-data/products/*')) {
            abort_unless($request->isMethod('GET') && $access->can($user, 'products', 'view'), 403);
            if (str_contains($request->path(), '/documents/')) {
                abort_unless($access->can($user, 'documents', 'view'), 403);
            }
            if (str_contains($request->path(), '/options/')) {
                abort_unless($access->can($user, 'options', 'view'), 403);
            }
        } else {
            // This also blocks dashboards, other module URLs, exports, uploads and APIs.
            abort(403, 'Supplier product sessions cannot access other FlowTracker modules.');
        }

        return $next($request);
    }
}
