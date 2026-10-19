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
use App\Services\MasterDataService;
use App\Services\SetupContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

trait ManagesClientProfile
{
    private function clientProfileRules(bool $draft, bool $requireShipping, bool $strictMasterData = true): array
    {
        $workspaceId = app(MasterDataService::class)->workspaceId();
        $countryExists = fn () => Rule::exists('master_records', 'name')->where(fn ($query) => $query
            ->where('workspace_id', $workspaceId)
            ->where('type', 'country')
            ->where('status', 'active')
            ->whereNull('deleted_at'));
        $currencyExists = Rule::exists('master_records', 'code')->where(fn ($query) => $query
            ->where('workspace_id', $workspaceId)
            ->where('type', 'currency')
            ->where('status', 'active')
            ->whereNull('deleted_at'));
        $stateExistsForCountry = function (string $country) use ($workspaceId) {
            $countryId = MasterRecord::query()
                ->forWorkspace($workspaceId)
                ->ofType('country')
                ->active()
                ->where('name', $country)
                ->value('id');

            return Rule::exists('master_records', 'name')->where(fn ($query) => $query
                ->where('workspace_id', $workspaceId)
                ->where('type', 'state')
                ->where('status', 'active')
                ->where('parent_id', $countryId ?: 0)
                ->whereNull('deleted_at'));
        };
        $hasStatesForCountry = function (string $country) use ($workspaceId): bool {
            $countryId = MasterRecord::query()
                ->forWorkspace($workspaceId)
                ->ofType('country')
                ->active()
                ->where('name', $country)
                ->value('id');

            return $countryId && MasterRecord::query()
                ->forWorkspace($workspaceId)
                ->ofType('state')
                ->active()
                ->where('parent_id', $countryId)
                ->exists();
        };

        $countryRule = fn () => $strictMasterData ? [$countryExists()] : [];
        $currencyRule = $strictMasterData ? [$currencyExists] : [];
        $stateRule = fn (string $country) => $strictMasterData ? [$stateExistsForCountry($country)] : [];

        $rules = [
            'clientName' => ['required','string','max:255'],
            'clientLogoUpload' => ['nullable','image','mimes:jpg,jpeg,png,webp','max:5120'],
            'legalBusinessName' => ['nullable','string','max:255'],
            'website' => ['nullable','string','max:255'],
            // Creation is strict against Master Data. Editing intentionally allows
            // legacy values that may pre-date the current Country/Currency master
            // records; otherwise a perfectly valid existing client can never be
            // saved after the profile form was expanded.
            'clientCountry' => array_merge(['required','string','max:120'], $countryRule()),
            'preferredCurrency' => array_merge(['required','string','max:40'], $currencyRule),
            'contacts' => [$draft ? 'array' : 'required','array','min:1','max:20'],
            'contacts.*.name' => [$draft ? 'nullable' : 'required','string','max:255'],
            'contacts.*.job_title' => ['nullable','string','max:255'],
            'contacts.*.email' => [$draft ? 'nullable' : 'required','email:rfc','max:255','distinct:ignore_case'],
            'contacts.*.phone' => ['nullable','string','max:60'],
            'accountManagerId' => [$draft ? 'nullable' : 'required','nullable','exists:users,id'],
            'preferredLanguage' => ['nullable','string','max:50'],
            'officeAddressLine1' => ['nullable','string','max:255'],
            'officeSuite' => ['nullable','string','max:120'],
            'officeCity' => ['nullable','string','max:120'],
            'officeState' => ['nullable','string','max:120'],
            'officeZip' => ['nullable','string','max:30'],
            'billingRecipient' => [$draft ? 'nullable' : 'required','string','max:255'],
            'billingAddressLine1' => ['nullable','string','max:255'],
            'billingSuite' => ['nullable','string','max:120'],
            'billingCity' => ['nullable','string','max:120'],
            'billingState' => array_merge(['nullable','string','max:120'], $stateRule($this->billingCountry)),
            'billingZip' => ['nullable','string','max:30'],
            'billingCountry' => array_merge(['nullable','string','max:120'], $countryRule()),
            'einTaxId' => ['nullable','string','max:80'],
            'salesTaxStatus' => ['required','in:taxable,tax_exempt'],
            'paymentTerms' => ['nullable','string','max:60'],
            'poRequired' => ['boolean'],
            'notes' => ['nullable','string','max:5000'],
            'shippingAddresses' => $requireShipping ? ['required','array','min:1','max:20'] : ['array','max:20'],
            'shippingAddresses.*.label' => ['nullable','string','max:255'],
            'shippingAddresses.*.recipient' => ['nullable','string','max:255'],
            'shippingAddresses.*.address_line1' => ['nullable','string','max:255'],
            'shippingAddresses.*.suite' => ['nullable','string','max:120'],
            'shippingAddresses.*.city' => ['nullable','string','max:120'],
            'shippingAddresses.*.state' => ['nullable','string','max:120'],
            'shippingAddresses.*.zip' => ['nullable','string','max:30'],
            'shippingAddresses.*.country' => array_merge(['nullable','string','max:120'], $countryRule()),
            'shippingAddresses.*.is_default' => ['boolean'],
        ];

        if (!$draft) {
            foreach ($this->shippingAddresses as $index => $address) {
                $hasContent = collect(['label','recipient','address_line1','suite','city','state','zip'])
                    ->contains(fn (string $field) => trim((string) ($address[$field] ?? '')) !== '');
                if (!$requireShipping && !$hasContent) continue;

                $requiredShippingFields = ['address_line1','city','zip'];
                if ($requireShipping) {
                    $requiredShippingFields[] = 'recipient';
                }
                foreach ($requiredShippingFields as $field) {
                    $rules["shippingAddresses.{$index}.{$field}"] = ['required','string','max:255'];
                }
                $shippingCountry = (string) ($address['country'] ?? '');
                $rules["shippingAddresses.{$index}.country"] = array_merge(['required','string','max:120'], $countryRule());
                $rules["shippingAddresses.{$index}.state"] = array_merge(
                    [$hasStatesForCountry($shippingCountry) ? 'required' : 'nullable','string','max:120'],
                    $stateRule($shippingCountry)
                );
            }
            foreach (['billingRecipient','billingAddressLine1','billingCity','billingZip'] as $field) {
                $rules[$field] = ['required','string','max:255'];
            }
            $rules['billingCountry'] = array_merge(['required','string','max:120'], $countryRule());
            $rules['billingState'] = array_merge(
                [$hasStatesForCountry($this->billingCountry) ? 'required' : 'nullable','string','max:120'],
                $stateRule($this->billingCountry)
            );
        }

        return $rules;
    }

    private function clientProfileValidationMessages(): array
    {
        return [
            'contacts.*.email.required' => 'Contact email is required.',
            'contacts.*.email.email' => 'Enter a valid email address.',
            'contacts.*.email.distinct' => 'Each contact must use a unique email address.',
            'shippingAddresses.*.recipient.required' => 'Recipient name is required.',
            'shippingAddresses.*.address_line1.required' => 'Address line 1 is required.',
            'shippingAddresses.*.city.required' => 'City is required.',
            'shippingAddresses.*.state.required' => 'State is required.',
            'shippingAddresses.*.zip.required' => 'ZIP / postal code is required.',
            'shippingAddresses.*.country.required' => 'Country / region is required.',
            'billingRecipient.required' => 'Recipient name is required.',
            'billingAddressLine1.required' => 'Address line 1 is required.',
            'billingCity.required' => 'City is required.',
            'billingState.required' => 'State is required.',
            'billingZip.required' => 'ZIP / postal code is required.',
            'billingCountry.required' => 'Country / region is required.',
        ];
    }
}
