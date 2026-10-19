<?php

namespace App\Livewire\CompanySetup;

use App\Livewire\Concerns\RefreshesFromWorkspace;
use App\Services\CompanyProfileService;
use Livewire\Component;

class Index extends Component
{
    use RefreshesFromWorkspace;
    public bool $isEditing = false;

    public string $legalName = '';
    public string $tradingName = '';
    public string $registrationNumber = '';
    public string $taxNumber = '';
    public string $billingEmail = '';
    public string $phone = '';
    public string $website = '';
    public string $addressLine1 = '';
    public string $addressLine2 = '';
    public string $city = '';
    public string $stateRegion = '';
    public string $postalCode = '';
    public string $country = '';
    public string $bankName = '';
    public string $bankAccountName = '';
    public string $bankAccountNumber = '';
    public string $bankIban = '';
    public string $bankSwift = '';
    public string $paymentInstructions = '';
    public string $invoiceFooter = '';

    public function mount(): void
    {
        $this->loadProfile();
    }

    public function beginEditing(): void
    {
        $this->loadProfile();
        $this->resetValidation();
        $this->isEditing = true;
    }

    public function cancelEditing(): void
    {
        $this->loadProfile();
        $this->resetValidation();
        $this->isEditing = false;
    }

    public function save(): void
    {
        abort_unless($this->isEditing, 403);

        $data = $this->validate([
            'legalName' => ['required', 'string', 'max:180'],
            'tradingName' => ['nullable', 'string', 'max:180'],
            'registrationNumber' => ['nullable', 'string', 'max:100'],
            'taxNumber' => ['nullable', 'string', 'max:100'],
            'billingEmail' => ['nullable', 'email', 'max:180'],
            'phone' => ['nullable', 'string', 'max:80'],
            'website' => ['nullable', 'string', 'max:180'],
            'addressLine1' => ['nullable', 'string', 'max:180'],
            'addressLine2' => ['nullable', 'string', 'max:180'],
            'city' => ['nullable', 'string', 'max:100'],
            'stateRegion' => ['nullable', 'string', 'max:100'],
            'postalCode' => ['nullable', 'string', 'max:40'],
            'country' => ['nullable', 'string', 'max:100'],
            'bankName' => ['nullable', 'string', 'max:160'],
            'bankAccountName' => ['nullable', 'string', 'max:160'],
            'bankAccountNumber' => ['nullable', 'string', 'max:120'],
            'bankIban' => ['nullable', 'string', 'max:120'],
            'bankSwift' => ['nullable', 'string', 'max:80'],
            'paymentInstructions' => ['nullable', 'string', 'max:1000'],
            'invoiceFooter' => ['nullable', 'string', 'max:500'],
        ]);

        app(CompanyProfileService::class)->save([
            'legal_name' => $data['legalName'],
            'trading_name' => $data['tradingName'] ?? '',
            'registration_number' => $data['registrationNumber'] ?? '',
            'tax_number' => $data['taxNumber'] ?? '',
            'billing_email' => $data['billingEmail'] ?? '',
            'phone' => $data['phone'] ?? '',
            'website' => $data['website'] ?? '',
            'address_line_1' => $data['addressLine1'] ?? '',
            'address_line_2' => $data['addressLine2'] ?? '',
            'city' => $data['city'] ?? '',
            'state_region' => $data['stateRegion'] ?? '',
            'postal_code' => $data['postalCode'] ?? '',
            'country' => $data['country'] ?? '',
            'bank_name' => $data['bankName'] ?? '',
            'bank_account_name' => $data['bankAccountName'] ?? '',
            'bank_account_number' => $data['bankAccountNumber'] ?? '',
            'bank_iban' => $data['bankIban'] ?? '',
            'bank_swift' => $data['bankSwift'] ?? '',
            'payment_instructions' => $data['paymentInstructions'] ?? '',
            'invoice_footer' => $data['invoiceFooter'] ?? '',
        ], auth()->user());

        $this->loadProfile();
        $this->resetValidation();
        $this->isEditing = false;

        session()->flash('success', 'Company details saved. New invoices will use these details automatically.');
    }

    private function loadProfile(): void
    {
        $profile = app(CompanyProfileService::class)->current();

        $this->legalName = $profile['legal_name'] ?? '';
        $this->tradingName = $profile['trading_name'] ?? '';
        $this->registrationNumber = $profile['registration_number'] ?? '';
        $this->taxNumber = $profile['tax_number'] ?? '';
        $this->billingEmail = $profile['billing_email'] ?? '';
        $this->phone = $profile['phone'] ?? '';
        $this->website = $profile['website'] ?? '';
        $this->addressLine1 = $profile['address_line_1'] ?? '';
        $this->addressLine2 = $profile['address_line_2'] ?? '';
        $this->city = $profile['city'] ?? '';
        $this->stateRegion = $profile['state_region'] ?? '';
        $this->postalCode = $profile['postal_code'] ?? '';
        $this->country = $profile['country'] ?? '';
        $this->bankName = $profile['bank_name'] ?? '';
        $this->bankAccountName = $profile['bank_account_name'] ?? '';
        $this->bankAccountNumber = $profile['bank_account_number'] ?? '';
        $this->bankIban = $profile['bank_iban'] ?? '';
        $this->bankSwift = $profile['bank_swift'] ?? '';
        $this->paymentInstructions = $profile['payment_instructions'] ?? '';
        $this->invoiceFooter = $profile['invoice_footer'] ?? '';
    }

    public function render()
    {
        return view('livewire.company-setup.index');
    }
}
