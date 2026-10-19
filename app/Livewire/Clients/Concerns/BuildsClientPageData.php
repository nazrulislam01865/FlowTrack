<?php

namespace App\Livewire\Clients\Concerns;

use App\Models\Client;
use App\Models\ClientShippingAddress;
use App\Models\ClientContact;
use App\Models\Activity;
use App\Models\FlowJob;
use App\Models\MasterRecord;
use App\Models\User;
use App\Services\ClientService;
use App\Services\DocumentService;
use App\Services\Orders\OrderReadService;
use App\Services\MasterDataService;
use App\Services\SetupContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

trait BuildsClientPageData
{
    private function createPageData(User $user): array
    {
        $service = app(MasterDataService::class);
        $workspaceId = $service->workspaceId();
        $addressOptionsReady = $this->showEdit || $this->createAddressOptionsReady;
        $countries = $addressOptionsReady ? $service->active('country') : collect();
        $currencies = $service->active('currency');
        // Only hydrate state options for countries that are currently visible
        // in the form. Loading every state for every country on every Livewire
        // render is unnecessary and grows linearly with Master Data.
        $stateCountries = collect([$this->clientCountry, $this->billingCountry])
            ->merge(collect($this->shippingAddresses)->pluck('country'))
            ->map(fn ($country) => trim((string) $country))
            ->filter()
            ->unique()
            ->values();

        $states = ! $addressOptionsReady || $stateCountries->isEmpty()
            ? collect()
            : MasterRecord::query()
                ->forWorkspace($workspaceId)
                ->ofType('state')
                ->active()
                ->whereHas('parent', fn ($query) => $query->whereIn('name', $stateCountries->all()))
                ->with('parent:id,name')
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get();

        $countryFlags = $countries->mapWithKeys(function (MasterRecord $country) {
            $flag = (string) data_get($country->metadata, 'flag', '');
            if ($flag === '' && preg_match('/^[A-Z]{2}$/', strtoupper($country->code))) {
                $flag = collect(str_split(strtoupper($country->code)))
                    ->map(fn (string $letter) => mb_chr(127397 + ord($letter), 'UTF-8'))
                    ->implode('');
            }
            return [$country->name => $flag ?: '🌐'];
        })->all();

        return [
            'users' => app(\App\Services\FilterOptionService::class)->options(
                $user,
                'users',
                'client-account-manager',
                '',
                $this->accountManagerId ?: $user->id,
                \App\Services\FilterOptionService::COMPACT_PER_PAGE,
            ),
            'detail' => null,
            'clientCountries' => $countries->pluck('name')->values()->all(),
            'clientCountryFlags' => $countryFlags,
            'clientStatesByCountry' => $states
                ->filter(fn (MasterRecord $state) => $state->parent)
                ->groupBy(fn (MasterRecord $state) => $state->parent->name)
                ->map(fn ($group) => $group->pluck('name')->values()->all())
                ->all(),
            'clientLanguages' => ['English','Chinese','Spanish','French','German','Arabic','Bengali'],
            'clientCurrencies' => $currencies->mapWithKeys(fn (MasterRecord $currency) => [$currency->code => $currency->name])->all(),
            'paymentTermOptions' => ['Net 15','Net 30','Net 45','Net 60','Due on receipt','Prepaid'],
            'clientAddressOptionsReady' => $addressOptionsReady,
            'contacts' => $this->contacts,
        ];
    }
    private function detailPageData(User $user): array
    {
        $detailSectionsReady = array_merge(['addresses' => false], $this->clientDetailSectionsReady ?? []);
        $loadAddresses = $this->clientDetailTab === 'overview' && $detailSectionsReady['addresses'];
        $loadContacts = $this->clientDetailTab === 'contacts';
        $detail = app(ClientService::class)->detailShell($user, (int) $this->selectedClientId, $loadAddresses, $loadContacts);
        $client = $detail['client'];
        $jobQuery = app(OrderReadService::class)->visibleQuery($user)->where('flow_jobs.client_id', $client->id);

        $jobMetrics = null;
        if ($this->clientDetailTab === 'orders') {
            $jobMetrics = (clone $jobQuery)
                ->reorder()
                ->selectRaw("sum(case when completed_at is null and status not in ('Inactive','Cancelled') then 1 else 0 end) as open_count")
                ->selectRaw("sum(case when completed_at is null and status not in ('Inactive','Cancelled') and (needs_attention = 1 or health in ('Needs Attention','At Risk','Delayed','Blocked')) then 1 else 0 end) as attention_count")
                ->selectRaw('sum(case when completed_at is not null then 1 else 0 end) as completed_count')
                ->selectRaw('coalesce(sum(commercial_value), 0) as total_value')
                ->first();
        }

        $documentQuery = app(DocumentService::class)->query($user, ['client' => $client->id]);

        $clientOrders = null;
        $clientDocuments = null;
        $clientActivities = null;
        $clientOrderStatusOptions = collect();
        $clientOrderOwnerOptions = collect();

        if ($this->clientDetailTab === 'orders') {
            $clientOrderStatusOptions = (clone $jobQuery)
                ->reorder()
                ->whereNotNull('status')
                ->where('status', '<>', '')
                ->distinct()
                ->orderBy('status')
                ->pluck('status');

            $clientOrderOwnerOptions = $this->clientOrderOwner !== ''
                ? app(\App\Services\FilterOptionService::class)->options(
                    $user,
                    'users',
                    'client-orders',
                    '',
                    (int) $this->clientOrderOwner,
                    \App\Services\FilterOptionService::COMPACT_PER_PAGE,
                    ['client_id' => (int) $client->id],
                )
                : collect();

            $orders = (clone $jobQuery)
                ->with(['phase:id,name,short_name,color','owner:id,name,profile_image_path'])
                ->when(trim($this->clientOrderSearch) !== '', function ($query) {
                    $search = trim($this->clientOrderSearch);
                    $legacy = preg_replace('/^ORDER-/i', 'JOB-', $search) ?: $search;
                    $query->where(function ($match) use ($search, $legacy) {
                        $match->whereLike('job_number', "%{$search}%")
                            ->orWhereLike('job_number', "%{$legacy}%")
                            ->orWhereLike('order_number', "%{$search}%")
                            ->orWhereLike('title', "%{$search}%")
                            ->orWhereLike('product', "%{$search}%");
                    });
                })
                ->when($this->clientOrderStatus !== '', fn ($query) => $query->where('status', $this->clientOrderStatus))
                ->when($this->clientOrderOwner !== '', fn ($query) => $query->where('owner_id', (int) $this->clientOrderOwner))
                ->when($this->clientOrderRange === '3m', fn ($query) => $query->where('created_at', '>=', now()->subMonths(3)))
                ->when($this->clientOrderRange === '6m', fn ($query) => $query->where('created_at', '>=', now()->subMonths(6)))
                ->when($this->clientOrderRange === '12m', fn ($query) => $query->where('created_at', '>=', now()->subMonths(12)))
                ->reorder()
                ->latest('created_at')
                ->latest('id');

            $clientOrders = $orders->paginate(
                max(1, min($this->clientOrderPerPage, 50)),
                ['flow_jobs.*'],
                'clientOrdersPage'
            );
        } elseif ($this->clientDetailTab === 'documents') {
            $clientDocuments = (clone $documentQuery)
                ->latest('documents.updated_at')
                ->paginate(12, ['documents.*'], 'clientDocumentsPage');
        } elseif ($this->clientDetailTab === 'activity') {
            $visibleJobIds = (clone $jobQuery)->reorder()->pluck('flow_jobs.id');
            $activityQuery = Activity::query()
                ->with('user:id,name,profile_image_path')
                ->where('subject_type', FlowJob::class)
                ->when(
                    $visibleJobIds->isNotEmpty(),
                    fn ($query) => $query->whereIn('subject_id', $visibleJobIds),
                    fn ($query) => $query->whereRaw('1 = 0')
                )
                ->latest('created_at');
            $clientActivities = $activityQuery->paginate(20, ['*'], 'clientActivityPage');
        }

        $pageData = [
            'detail' => $detail,
            'clientDetailSectionsReady' => $detailSectionsReady,
            'clientOrderCount' => (int) ($detail['orderCount'] ?? 0),
            'users' => collect(),
            'clientDetailTab' => $this->clientDetailTab,
            'clientOrders' => $clientOrders,
            'clientDocuments' => $clientDocuments,
            'clientActivities' => $clientActivities,
            'clientOrderStatusOptions' => $clientOrderStatusOptions,
            'clientOrderOwnerOptions' => $clientOrderOwnerOptions,
            'clientDocumentCount' => (clone $documentQuery)->count(),
            'clientOrderMetrics' => [
                'open' => (int) ($jobMetrics?->open_count ?? 0),
                'attention' => (int) ($jobMetrics?->attention_count ?? 0),
                'completed' => (int) ($jobMetrics?->completed_count ?? 0),
                'value' => (float) ($jobMetrics?->total_value ?? 0),
            ],
        ];

        if ($this->showEdit) {
            $formData = $this->createPageData($user);
            unset($formData['detail']);
            $pageData = array_merge($pageData, $formData);
        }

        return $pageData;
    }
    private function clientsListData(User $user): array
    {
        $service = app(ClientService::class);
        $clients = $service->paginate($user, [
            'search' => $this->search,
            'country' => $this->country,
            'manager' => $this->manager,
            'outstanding' => $this->outstanding,
            'quick' => $this->quick,
            'archived' => $this->showArchived,
            'archived_date' => $this->archivedDate,
            'created_by' => $this->createdBy,
        ], $this->perPage);

        $deleteCandidate = $this->showArchived && $this->deleteArchivedClientId
            ? $service->visibleQuery($user)
                ->where('is_active', false)
                ->find($this->deleteArchivedClientId)
            : null;

        return [
            'clients' => $clients,
            'summary' => $service->summary($user),
            'detail' => $this->showClientPreview && $this->selectedClientId ? $service->detail($user, $this->selectedClientId) : null,
            'countryFilterOptions' => $this->showArchived ? collect() : app(\App\Services\FilterOptionService::class)->options($user, 'countries', 'clients', '', $this->country, 5),
            'managerFilterOptions' => $this->showArchived ? collect() : app(\App\Services\FilterOptionService::class)->options($user, 'users', 'clients', '', $this->manager !== '' ? (int) $this->manager : null, 5),
            'createdByFilterOptions' => $this->showArchived ? app(\App\Services\FilterOptionService::class)->options($user, 'users', 'clients', '', $this->createdBy !== '' ? (int) $this->createdBy : null, 5) : collect(),
            'deleteCandidate' => $deleteCandidate,
            'users' => collect(),
        ];
    }
}
