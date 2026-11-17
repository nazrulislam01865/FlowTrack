<?php

namespace App\Http\Controllers\SupplierBridge;

use App\Models\MasterRecord;
use App\Models\User;
use App\Models\WorkspaceMembership;
use App\Services\SupplierBridge\SupplierProductModuleAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Signed server-to-server portal launch; never trusts permissions from the portal request. */
final class SupplierProductModuleSession extends Controller
{
    use VerifySupplierProductApi;

    public function issue(Request $request): JsonResponse
    {
        $data = $this->signedProductApi($request);
        $validated = validator($data, [
            'event_id' => ['required', 'uuid'],
            'supplier_id' => ['required', 'integer', 'min:1'],
            'supplier_user_id' => ['required', 'integer', 'min:1'],
            'supplier_user_name' => ['required', 'string', 'max:120'],
            'group' => ['required', 'in:product,product_category'],
        ])->validate();
        $id = (int) $validated['supplier_id'];
        $supplier = MasterRecord::query()->ofType('supplier')->where('status', 'active')->findOrFail($id);
        // Source operation UUID is single-use even within the normal five-minute HMAC window.
        abort_unless(\Illuminate\Support\Facades\Cache::add('supplier-product-request:'.$validated['event_id'], 1, now()->addMinutes(5)), 409);
        $policy = app(SupplierProductModuleAccess::class)->policy((int) $supplier->workspace_id);
        $permissions = $policy['permissions'];
        $section = $validated['group'] === 'product' ? 'products' : 'categories';
        abort_unless($policy['enabled'] && in_array('view', $permissions[$section], true), 403);
        $base = rtrim((string) config('supplier_bridge.product_module_url'), '/');
        $scheme = parse_url($base, PHP_URL_SCHEME);
        $host = parse_url($base, PHP_URL_HOST);
        abort_unless($base !== '' && is_string($host) && $host !== ''
            && ! str_contains($base, '@') && ! str_contains($base, '?') && ! str_contains($base, '#')
            && ($scheme === 'https' || ($scheme === 'http' && app()->environment(['local', 'testing'])
                && in_array($host, ['localhost', '127.0.0.1'], true))), 503, 'Product module origin not configured.');

        // Browser cookies are host-scoped, not port-scoped. Never issue a
        // delegated session on the hostname used by normal FlowTracker staff.
        $adminHost = parse_url((string) config('app.url'), PHP_URL_HOST);
        abort_unless(strtolower((string) $host) !== strtolower((string) $adminHost), 503,
            'Product module requires a dedicated hostname, separate from FlowTracker administration.');

        $ticket = Str::random(64);
        $sessionHash = hash('sha256', $ticket);
        DB::table('supplier_product_portal_sessions')->insert([
            'supplier_id' => $id,
            'supplier_user_id' => (int) $validated['supplier_user_id'],
            'requested_group' => $validated['group'],
            'session_hash' => $sessionHash,
            'expires_at' => now()->addSeconds(45),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        // The ticket is one-use, bound to a FlowTracker-known supplier and user identity.
        return response()->json(['launch_url' => $base.'/supplier-product/access/'. $ticket], 200)
            ->header('Cache-Control', 'no-store, private');
    }

    public function access(Request $request, string $ticket): RedirectResponse
    {
        // Never redeem a supplier login against the ordinary FlowTracker origin:
        // the delegated session belongs only to the dedicated product host.
        $configuredOrigin = rtrim((string) config('supplier_bridge.product_module_url'), '/');
        abort_unless($configuredOrigin !== ''
            && hash_equals($configuredOrigin, rtrim($request->getSchemeAndHttpHost(), '/')), 403);
        $adminHost = parse_url((string) config('app.url'), PHP_URL_HOST);
        abort_unless(strtolower((string) $request->getHost()) !== strtolower((string) $adminHost), 403,
            'Supplier Product sessions must not use the normal FlowTracker hostname.');
        abort_unless(preg_match('/^[A-Za-z0-9]{64}$/', $ticket), 404);
        $session = DB::transaction(function () use ($ticket): ?object {
            $row = DB::table('supplier_product_portal_sessions')
                ->where('session_hash', hash('sha256', $ticket))->lockForUpdate()->first();
            if (! $row || $row->consumed_at !== null || $row->revoked_at !== null
                || strtotime((string) $row->expires_at) <= time()) return null;
            DB::table('supplier_product_portal_sessions')->where('id', $row->id)
                ->update(['consumed_at' => now(), 'expires_at' => now()->addMinutes(20), 'updated_at' => now()]);
            return $row;
        });
        abort_unless($session, 403, 'Product module link expired. Open it again from the Supplier Portal.');
        $supplier = MasterRecord::query()->ofType('supplier')->where('status', 'active')
            ->findOrFail((int) $session->supplier_id);
        $policy = app(SupplierProductModuleAccess::class)->policy((int) $supplier->workspace_id);
        $permissions = $policy['permissions'];
        $section = $session->requested_group === 'product' ? 'products' : 'categories';
        abort_unless($policy['enabled'] && in_array('view', $permissions[$section], true), 403);
        $create = $request->query('create') === '1';
        if ($create) abort_unless(in_array('create', $permissions[$section], true), 403);

        $actor = DB::transaction(function () use ($supplier, $session): User {
            // Serialize first-time actor creation by locking the existing
            // supplier record; two launch requests must not create duplicates.
            DB::table('master_records')->where('id', $supplier->id)->lockForUpdate()->first();
            $link = DB::table('supplier_product_portal_actors')
                ->where('supplier_id', $supplier->id)
                ->where('supplier_user_id', $session->supplier_user_id)
                ->lockForUpdate()->first();
            if ($link) return User::query()->where('is_active', true)->findOrFail($link->flowtrack_user_id);
            $user = User::create([
                'name' => 'Supplier portal · '.$supplier->name,
                'email' => 'supplier-product-actor-'.(int)$supplier->id.'-'.(int)$session->supplier_user_id.'@supplier.invalid',
                'password' => Str::random(80),
                'role_id' => null, 'is_super_admin' => false, 'is_active' => true,
            ]);
            WorkspaceMembership::query()->updateOrCreate([
                'workspace_id' => $supplier->workspace_id, 'user_id' => $user->id,
            ], ['role_id' => null, 'status' => 'active', 'joined_at' => now()]);
            DB::table('supplier_product_portal_actors')->insert([
                'supplier_id' => $supplier->id, 'supplier_user_id' => $session->supplier_user_id,
                'flowtrack_user_id' => $user->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            return $user;
        });

        Auth::login($actor, false);
        $request->session()->regenerate();
        $request->session()->put('supplier_product_actor_id', (int) DB::table('supplier_product_portal_actors')
            ->where('flowtrack_user_id', $actor->id)->value('id'));
        $request->session()->put('supplier_product_session_hash', hash('sha256', $ticket));
        $request->session()->put('supplier_product_expires', now()->addMinutes(20)->timestamp);
        $request->session()->put('supplier_product_workspace_id', (int) $supplier->workspace_id);
        // Same original Livewire module and pages as the ordinary FlowTracker user.
        $response = redirect()->to('/master-data?'.http_build_query([
            'group' => $session->requested_group,
        ] + ($create ? ['create' => '1'] : [])));
        $response->headers->set('Cache-Control', 'private, no-store');
        return $response;
    }

    public function revoke(Request $request): JsonResponse
    {
        $data = $this->signedProductApi($request);
        $validated = validator($data, [
            'event_id' => ['required', 'uuid'],
            'supplier_id' => ['required', 'integer', 'min:1'],
            'supplier_user_id' => ['required', 'integer', 'min:1'],
        ])->validate();
        DB::table('supplier_product_portal_sessions')
            ->where('supplier_id', $validated['supplier_id'])
            ->where('supplier_user_id', $validated['supplier_user_id'])
            ->whereNull('revoked_at')->update(['revoked_at' => now(), 'updated_at' => now()]);
        return response()->json(['revoked' => true]);
    }
}
