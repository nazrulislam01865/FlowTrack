<?php

namespace App\Http\Controllers\FlowTrack;

use App\Http\Controllers\Controller;
use App\Http\Requests\FlowTrack\StoreClientRequest;
use App\Models\User;
use App\Modules\OrderFlow\Application\Actions\CreateClientAction;
use App\Modules\OrderFlow\Business\Services\FlowAuthorizationService;
use App\Modules\OrderFlow\Data\Models\Client;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ClientController extends Controller
{
    public function index(Request $request, FlowAuthorizationService $authorization): Response
    {
        $user = $request->user();
        $workspaceId = $user->currentWorkspaceId();
        $search = (string) $request->query('search', '');
        $country = (string) $request->query('country', '');

        abort_unless($authorization->allows($user, 'clients', 'view'), 403);
        $canManage = $authorization->allows($user, 'clients', 'create')
            || $authorization->allows($user, 'clients', 'edit_all')
            || $authorization->allows($user, 'clients', 'edit_own')
            || $authorization->allows($user, 'clients', 'manage');
        $canViewFinancials = $authorization->allows($user, 'invoice', 'view')
            && $authorization->canViewSensitiveField($user, 'invoice_amount');
        $canViewContacts = $authorization->canViewSensitiveField($user, 'client_contact_details');
        $canAssign = $authorization->allows($user, 'clients', 'assign');

        $baseQuery = $authorization->applyClientVisibility(Client::query(), $user);

        $countries = (clone $baseQuery)
            ->whereNotNull('country')
            ->distinct()
            ->orderBy('country')
            ->pluck('country');

        $clients = $baseQuery
            ->with('accountManager:id,name,initials')
            ->withCount([
                'jobs as active_jobs_count' => fn (Builder $query) => $authorization
                    ->applyJobVisibility($query, $user)
                    ->where('status', '!=', 'completed'),
                'jobs as attention_jobs_count' => fn (Builder $query) => $authorization
                    ->applyJobVisibility($query, $user)
                    ->where('attention_required', true),
            ])
            ->when($search, fn (Builder $query) => $query->where(function (Builder $nested) use ($search, $canViewContacts): void {
                $nested->where('name', 'like', "%{$search}%")
                    ->orWhere('country', 'like', "%{$search}%")
                    ->when($canViewContacts, fn (Builder $contact) => $contact->orWhere('contact_name', 'like', "%{$search}%"));
            }))
            ->when($country, fn (Builder $query) => $query->where('country', $country))
            ->orderBy('name')
            ->get();

        if (! $canViewFinancials) {
            $clients->each->makeHidden(['outstanding_balance']);
        }
        if (! $canViewContacts) {
            $clients->each->makeHidden(['contact_name', 'email', 'phone', 'notes']);
        }

        return Inertia::render('flowtrack/Clients/Index', [
            'clients' => $clients,
            'users' => $canAssign
                ? User::query()->whereHas('workspaceMemberships', fn (Builder $query) => $query
                    ->where('workspace_id', $workspaceId)
                    ->where('status', 'active'))->orderBy('name')->get(['id', 'name', 'initials'])
                : [],
            'countries' => $countries,
            'filters' => compact('search', 'country'),
            'canManage' => $canManage,
            'canViewFinancials' => $canViewFinancials,
        ]);
    }

    public function store(StoreClientRequest $request, CreateClientAction $action, FlowAuthorizationService $authorization): RedirectResponse
    {
        $data = $request->validated();
        $this->authorizeSensitiveData($request, $authorization, $data);
        if (! empty($data['account_manager_id']) && (int) $data['account_manager_id'] !== (int) $request->user()->id) {
            abort_unless($authorization->allows($request->user(), 'clients', 'assign'), 403);
        }
        $action->execute($request->user()->currentWorkspaceId(), $data);

        return back()->with('success', 'Client created successfully.');
    }

    public function update(StoreClientRequest $request, Client $client, FlowAuthorizationService $authorization): RedirectResponse
    {
        abort_unless($client->workspace_id === $request->user()->currentWorkspaceId(), 404);
        $data = $request->validated();
        $this->authorizeSensitiveData($request, $authorization, $data, $client);
        if (array_key_exists('account_manager_id', $data) && (int) ($data['account_manager_id'] ?? 0) !== (int) ($client->account_manager_id ?? 0)) {
            abort_unless($authorization->allows($request->user(), 'clients', 'assign', $client), 403);
        }
        $client->update($data);

        return back()->with('success', 'Client updated successfully.');
    }

    public function destroy(Request $request, Client $client, FlowAuthorizationService $authorization): RedirectResponse
    {
        abort_unless($client->workspace_id === $request->user()->currentWorkspaceId(), 404);
        abort_unless($authorization->allows($request->user(), 'clients', 'delete', $client), 403);

        DB::transaction(function () use ($client): void {
            $client->jobs()->with(['tasks', 'documents'])->get()->each(function ($job): void {
                $job->tasks()->delete();
                $job->documents()->delete();
                $job->delete();
            });
            $client->delete();
        });

        return back()->with('success', 'Client and its related Jobs were deleted successfully.');
    }

    private function authorizeSensitiveData(Request $request, FlowAuthorizationService $authorization, array $data, ?Client $client = null): void
    {
        $contactFields = ['contact_name', 'email', 'phone', 'notes'];
        $contactChanged = collect($contactFields)->contains(function (string $field) use ($data, $client): bool {
            if (! array_key_exists($field, $data)) return false;
            return $client === null ? filled($data[$field]) : (string) ($data[$field] ?? '') !== (string) ($client->{$field} ?? '');
        });
        if ($contactChanged) {
            abort_unless($authorization->canViewSensitiveField($request->user(), 'client_contact_details'), 403);
        }

        if (array_key_exists('outstanding_balance', $data)) {
            $changed = $client === null
                ? (float) ($data['outstanding_balance'] ?? 0) !== 0.0
                : (float) ($data['outstanding_balance'] ?? 0) !== (float) $client->outstanding_balance;
            if ($changed) {
                abort_unless(
                    $authorization->allows($request->user(), 'invoice', 'view')
                    && $authorization->canViewSensitiveField($request->user(), 'invoice_amount'),
                    403,
                );
            }
        }
    }

}
