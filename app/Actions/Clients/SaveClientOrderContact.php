<?php

namespace App\Actions\Clients;

use App\Models\Client;
use App\Models\ClientContact;
use Illuminate\Validation\ValidationException;

final class SaveClientOrderContact
{
    public function execute(
        Client $client,
        string $name,
        string $countryCode,
        string $phone,
    ): ClientContact {
        $name = trim($name);
        $countryCode = trim($countryCode);
        $phone = trim($phone);

        if ($name === '' || $phone === '') {
            throw ValidationException::withMessages([
                'shippingContactName' => 'Enter a valid contact name and phone number before saving this contact.',
            ]);
        }

        $fullPhone = trim(collect([$countryCode, $phone])
            ->filter(fn ($part) => filled($part))
            ->implode(' '));

        $existing = ClientContact::query()
            ->where('client_id', $client->id)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->first();

        if ($existing) {
            $existing->update([
                'name' => $name,
                'phone' => $fullPhone !== '' ? $fullPhone : null,
            ]);

            return $existing->refresh();
        }

        $maxSortOrder = ClientContact::query()
            ->where('client_id', $client->id)
            ->max('sort_order');
        $nextSortOrder = $maxSortOrder === null ? 0 : ((int) $maxSortOrder) + 1;

        return ClientContact::query()->create([
            'client_id' => $client->id,
            'name' => $name,
            'job_title' => null,
            'email' => null,
            'phone' => $fullPhone !== '' ? $fullPhone : null,
            'is_primary' => false,
            'sort_order' => $nextSortOrder,
        ]);
    }
}
