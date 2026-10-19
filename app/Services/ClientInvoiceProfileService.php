<?php

namespace App\Services;

use App\Models\Client;

class ClientInvoiceProfileService
{
    public function invoiceSnapshot(?Client $client): array
    {
        if (!$client) {
            return [];
        }

        $billingSameAsOffice = (bool) ($client->billing_same_as_office ?? true);
        $addressLine1 = $billingSameAsOffice
            ? trim((string) ($client->office_address_line1 ?: $client->office_address ?: ''))
            : trim((string) ($client->billing_address_line1 ?? ''));
        $addressLine2 = $billingSameAsOffice
            ? trim((string) ($client->office_suite ?? ''))
            : trim((string) ($client->billing_suite ?? ''));
        $city = $billingSameAsOffice
            ? trim((string) ($client->office_city ?? ''))
            : trim((string) ($client->billing_city ?? ''));
        $state = $billingSameAsOffice
            ? trim((string) ($client->office_state ?? ''))
            : trim((string) ($client->billing_state ?? ''));
        $postalCode = $billingSameAsOffice
            ? trim((string) ($client->office_zip ?? ''))
            : trim((string) ($client->billing_zip ?? ''));
        $country = $billingSameAsOffice
            ? trim((string) ($client->country ?? ''))
            : trim((string) ($client->billing_country ?: $client->country ?: ''));

        return [
            'name' => trim((string) $client->name),
            'legal_name' => trim((string) ($client->legal_business_name ?: $client->name)),
            'code' => trim((string) ($client->code ?? '')),
            'tax_number' => trim((string) ($client->ein_tax_id ?? '')),
            'email' => trim((string) ($client->email ?? '')),
            'phone' => trim((string) ($client->phone ?? '')),
            'website' => trim((string) ($client->website ?? '')),
            'address_line_1' => $addressLine1,
            'address_line_2' => $addressLine2,
            'city' => $city,
            'state_region' => $state,
            'postal_code' => $postalCode,
            'country' => $country,
        ];
    }

    public function addressLines(array $profile): array
    {
        $lines = [];
        foreach (['address_line_1', 'address_line_2'] as $key) {
            if (filled($profile[$key] ?? null)) {
                $lines[] = trim((string) $profile[$key]);
            }
        }

        $locality = collect([
            $profile['city'] ?? null,
            $profile['state_region'] ?? null,
            $profile['postal_code'] ?? null,
        ])->filter(fn ($value) => filled($value))
            ->map(fn ($value) => trim((string) $value))
            ->implode(', ');
        if ($locality !== '') {
            $lines[] = $locality;
        }

        if (filled($profile['country'] ?? null)) {
            $lines[] = trim((string) $profile['country']);
        }

        return $lines;
    }
}
