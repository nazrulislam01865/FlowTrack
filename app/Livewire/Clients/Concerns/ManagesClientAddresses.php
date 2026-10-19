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

trait ManagesClientAddresses
{
    public function useSavedAddressForShipping(): void
    {
        if ($this->shippingAddresses === []) {
            $this->shippingAddresses = [$this->blankShippingAddress(true)];
        }

        $index = 0;
        foreach ($this->shippingAddresses as $key => $address) {
            if ((bool) ($address['expanded'] ?? false)) {
                $index = (int) $key;
                break;
            }
        }

        $useBilling = trim($this->billingAddressLine1) !== '';
        $line1 = $useBilling ? $this->billingAddressLine1 : $this->officeAddressLine1;
        if (trim($line1) === '') {
            $this->addError("shippingAddresses.{$index}.address_line1", 'Enter the office or billing address first, then choose Use saved address.');
            return;
        }

        $suite = $useBilling ? $this->billingSuite : $this->officeSuite;
        $city = $useBilling ? $this->billingCity : $this->officeCity;
        $state = $useBilling ? $this->billingState : $this->officeState;
        $zip = $useBilling ? $this->billingZip : $this->officeZip;
        $country = $useBilling ? $this->billingCountry : $this->clientCountry;
        $recipient = trim((string) data_get($this->contacts, '0.name'));

        $this->shippingAddresses[$index] = array_merge(
            $this->shippingAddresses[$index],
            [
                'recipient' => $recipient !== '' ? $recipient : ($this->shippingAddresses[$index]['recipient'] ?? ''),
                'address_line1' => $line1,
                'suite' => $suite,
                'city' => $city,
                'state' => $state,
                'zip' => $zip,
                'country' => $country !== '' ? $country : $this->defaultClientCountry(),
                'expanded' => true,
            ]
        );

        $this->resetValidation("shippingAddresses.{$index}");
    }

    public function addShippingAddress(): void
    {
        foreach ($this->shippingAddresses as &$address) $address['expanded'] = false;
        unset($address);
        $this->shippingAddresses[] = $this->blankShippingAddress(empty($this->shippingAddresses));
    }

    public function duplicateShippingAddress(int $index): void
    {
        abort_unless(isset($this->shippingAddresses[$index]), 404);
        foreach ($this->shippingAddresses as &$address) $address['expanded'] = false;
        unset($address);
        $copy = $this->shippingAddresses[$index];
        $copy['label'] = trim((string) ($copy['label'] ?? '')).' Copy';
        $copy['is_default'] = false;
        $copy['expanded'] = true;
        array_splice($this->shippingAddresses, $index + 1, 0, [$copy]);
    }

    public function removeShippingAddress(int $index): void
    {
        abort_unless(isset($this->shippingAddresses[$index]), 404);
        $wasDefault = (bool) ($this->shippingAddresses[$index]['is_default'] ?? false);
        array_splice($this->shippingAddresses, $index, 1);
        if (!$this->shippingAddresses) $this->shippingAddresses[] = $this->blankShippingAddress(true);
        elseif ($wasDefault) $this->shippingAddresses[0]['is_default'] = true;
    }

    public function toggleShippingAddress(int $index): void
    {
        abort_unless(isset($this->shippingAddresses[$index]), 404);
        $this->shippingAddresses[$index]['expanded'] = !($this->shippingAddresses[$index]['expanded'] ?? false);
    }

    public function editShippingAddress(int $index): void
    {
        abort_unless(isset($this->shippingAddresses[$index]), 404);
        foreach ($this->shippingAddresses as &$address) $address['expanded'] = false;
        unset($address);
        $this->shippingAddresses[$index]['expanded'] = true;
    }

    public function setDefaultShippingAddress(int $index): void
    {
        abort_unless(isset($this->shippingAddresses[$index]), 404);
        foreach ($this->shippingAddresses as $key => &$address) $address['is_default'] = $key === $index;
        unset($address);
    }

    public function toggleSavedShippingAddress(int $index): void
    {
        abort_unless(isset($this->shippingAddresses[$index]), 404);

        $shouldSaveAsDefault = ! (bool) ($this->shippingAddresses[$index]['is_default'] ?? false);
        if ($shouldSaveAsDefault) {
            foreach ($this->shippingAddresses as $key => &$address) {
                $address['is_default'] = $key === $index;
            }
            unset($address);
            return;
        }

        $this->shippingAddresses[$index]['is_default'] = false;
    }

    public function showDifferentBillingAddress(): void { $this->billingSameAsOffice = false; }

    private function blankShippingAddress(bool $default = false): array
    {
        return ['label'=>'','recipient'=>'','address_line1'=>'','suite'=>'','city'=>'','state'=>'','zip'=>'','country'=>$this->defaultClientCountry(),'is_default'=>$default,'expanded'=>true];
    }

    private function defaultClientCountry(): string
    {
        $countries = app(MasterDataService::class)->active('country');
        $default = $countries->first(fn (MasterRecord $record) => (bool) data_get($record->metadata, 'is_default', false));
        return (string) (($default ?? $countries->first())?->name ?? '');
    }

    private function defaultClientCurrency(): string
    {
        $service = app(MasterDataService::class);
        $currencies = $service->active('currency');
        $workspaceCurrency = \App\Models\Workspace::query()->whereKey(app(SetupContext::class)->workspaceId())->value('default_currency');
        $preferred = $currencies->firstWhere('code', strtoupper((string) $workspaceCurrency))
            ?? $currencies->first(fn (MasterRecord $record) => (bool) data_get($record->metadata, 'is_default', false))
            ?? $currencies->first();
        return (string) ($preferred?->code ?? '');
    }

    private function formatAddress(string $line1, string $suite, string $city, string $state, string $zip, string $country): string
    {
        $first = trim(implode(', ', array_filter([$line1, $suite])));
        $second = trim(implode(' ', array_filter([$city ? $city.',' : '', $state, $zip])));
        return trim(implode(', ', array_filter([$first, $second, $country])));
    }
}
