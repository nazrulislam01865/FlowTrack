<?php

namespace App\Actions\Clients;

use App\Models\Client;
use App\Models\ClientContact;
use App\Models\ClientShippingAddress;
use App\Models\MasterRecord;
use App\Models\User;
use App\Services\AccessControlService;
use App\Services\ClientLogoService;
use App\Services\MasterDataService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class SaveClientProfileAction
{
    public function __construct(
        private readonly AccessControlService $access,
        private readonly ClientLogoService $logos,
        private readonly MasterDataService $masterData,
    ) {}

    public function execute(
        User $actor,
        array $data,
        bool $draft = false,
        ?Client $client = null,
        ?UploadedFile $logo = null,
        bool $removeLogo = false,
    ): Client {
        if ($client) {
            $canEdit = $this->access->isAdministrator($actor)
                || $this->access->canEditAll($actor, 'clients')
                || ($this->access->canEditOwn($actor, 'clients') && (int) ($client->account_manager_id ?? 0) === (int) $actor->id);
            abort_unless($canEdit, 403);
        } else {
            abort_unless($this->access->can($actor, 'clients', 'create'), 403);
        }

        $requestedManagerId = filled($data['accountManagerId'] ?? null) ? (int) $data['accountManagerId'] : null;
        $currentManagerId = $client ? (int) ($client->account_manager_id ?? 0) : (int) $actor->id;
        if ($requestedManagerId && $requestedManagerId !== $currentManagerId) {
            abort_unless(
                $this->access->can($actor, 'clients', 'assign') || $this->access->canEditAll($actor, 'clients'),
                403
            );
        }

        $client = DB::transaction(function () use ($actor, $data, $draft, $client): Client {
            $contacts = $this->normalizedContacts($data['contacts'] ?? []);
            $primaryContact = $contacts[0] ?? ['name' => '', 'job_title' => '', 'email' => '', 'phone' => ''];
            $officeAddress = $this->formatAddress(
                $data['officeAddressLine1'] ?? '',
                $data['officeSuite'] ?? '',
                $data['officeCity'] ?? '',
                $data['officeState'] ?? '',
                $data['officeZip'] ?? '',
                $data['clientCountry'] ?? '',
            );

            $payload = [
                'name' => $data['clientName'],
                'legal_business_name' => $data['legalBusinessName'] ?: null,
                'website' => $data['website'] ?: null,
                'country' => $data['clientCountry'] ?: null,
                'preferred_currency' => strtoupper($data['preferredCurrency']),
                'office_address' => $officeAddress ?: null,
                'office_address_line1' => $data['officeAddressLine1'] ?: null,
                'office_suite' => $data['officeSuite'] ?: null,
                'office_city' => $data['officeCity'] ?: null,
                'office_state' => $data['officeState'] ?: null,
                'office_zip' => $data['officeZip'] ?: null,
                'billing_same_as_office' => false,
                'billing_recipient' => $data['billingRecipient'] ?: null,
                'billing_address_line1' => $data['billingAddressLine1'] ?: null,
                'billing_suite' => $data['billingSuite'] ?: null,
                'billing_city' => $data['billingCity'] ?: null,
                'billing_state' => $data['billingState'] ?: null,
                'billing_zip' => $data['billingZip'] ?: null,
                'billing_country' => $data['billingCountry'] ?: null,
                'contact_name' => $primaryContact['name'] ?: null,
                'contact_job_title' => $primaryContact['job_title'] ?: null,
                'email' => $primaryContact['email'] ?: null,
                'phone' => $primaryContact['phone'] ?: null,
                'account_manager_id' => $data['accountManagerId'],
                'preferred_language' => $data['preferredLanguage'] ?: 'English',
                'ein_tax_id' => $data['einTaxId'] ?: null,
                'sales_tax_status' => $data['salesTaxStatus'],
                'payment_terms' => $data['paymentTerms'] ?: null,
                'po_required' => (bool) $data['poRequired'],
                'notes' => $data['notes'] ?: null,
                'is_draft' => $draft,
            ];

            if ($client) {
                $client->update($payload);
            } else {
                $client = Client::create($payload + [
                    'code' => $this->nextCode(),
                    'created_by' => $actor->id,
                    'outstanding_balance' => 0,
                    'is_active' => true,
                ]);
            }

            $client->contacts()->delete();
            foreach ($contacts as $index => $contact) {
                ClientContact::create([
                    'client_id' => $client->id,
                    'name' => $contact['name'] !== '' ? $contact['name'] : ($contact['email'] !== '' ? $contact['email'] : 'Contact '.($index + 1)),
                    'job_title' => $contact['job_title'] ?: null,
                    'email' => $contact['email'] ?: null,
                    'phone' => $contact['phone'] ?: null,
                    'is_primary' => $index === 0,
                    'sort_order' => $index,
                ]);
            }

            $addresses = collect($data['shippingAddresses'] ?? [])
                ->filter(fn ($address) => trim((string) ($address['label'] ?? '')) !== '' || trim((string) ($address['address_line1'] ?? '')) !== '')
                ->values();
            $defaultIndex = $addresses->search(fn ($address) => (bool) ($address['is_default'] ?? false));
            if ($defaultIndex === false && $addresses->isNotEmpty()) $defaultIndex = 0;

            if ($client->exists) $client->shippingAddresses()->delete();
            foreach ($addresses as $index => $address) {
                ClientShippingAddress::create([
                    'client_id' => $client->id,
                    'label' => trim((string) ($address['label'] ?? '')) ?: 'Shipping address '.($index + 1),
                    'recipient' => trim((string) ($address['recipient'] ?? '')) ?: null,
                    'address_line1' => trim((string) ($address['address_line1'] ?? '')),
                    'suite' => trim((string) ($address['suite'] ?? '')) ?: null,
                    'city' => trim((string) ($address['city'] ?? '')),
                    'state' => trim((string) ($address['state'] ?? '')),
                    'zip' => trim((string) ($address['zip'] ?? '')),
                    'country' => trim((string) ($address['country'] ?? '')) ?: $this->defaultCountry(),
                    'is_default' => $index === $defaultIndex,
                    'sort_order' => $index,
                ]);
            }

            return $client->refresh();
        });

        if ($logo) {
            return $this->logos->replace($client, $logo);
        }
        if ($removeLogo && $client->logo_path) {
            return $this->logos->remove($client);
        }

        return $client->refresh();
    }

    private function normalizedContacts(array $contacts): array
    {
        return collect($contacts)
            ->map(fn ($contact) => [
                'name' => trim((string) ($contact['name'] ?? '')),
                'job_title' => trim((string) ($contact['job_title'] ?? '')),
                'email' => mb_strtolower(trim((string) ($contact['email'] ?? ''))),
                'phone' => trim((string) ($contact['phone'] ?? '')),
            ])
            ->filter(fn ($contact) => implode('', $contact) !== '')
            ->values()
            ->all();
    }

    private function formatAddress(string $line1, string $suite, string $city, string $state, string $zip, string $country): string
    {
        $first = trim(implode(', ', array_filter([$line1, $suite])));
        $second = trim(implode(' ', array_filter([$city ? $city.',' : '', $state, $zip])));
        return trim(implode(', ', array_filter([$first, $second, $country])));
    }

    private function nextCode(): string
    {
        $next = (int) Client::max('id') + 1;
        do {
            $code = 'CL-'.str_pad((string) $next, 3, '0', STR_PAD_LEFT);
            $next++;
        } while (Client::where('code', $code)->exists());

        return $code;
    }

    private function defaultCountry(): string
    {
        $countries = $this->masterData->active('country');
        $default = $countries->first(fn (MasterRecord $record) => (bool) data_get($record->metadata, 'is_default', false));
        return (string) (($default ?? $countries->first())?->name ?? '');
    }
}
