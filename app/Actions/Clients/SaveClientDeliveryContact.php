<?php

namespace App\Actions\Clients;

use App\Models\Client;
use App\Models\ClientDeliveryContact;
use App\Models\User;
use Illuminate\Validation\ValidationException;

final class SaveClientDeliveryContact
{
    private const MANUAL_TYPES = ['end_customer', 'other_contact'];

    public function execute(
        User $actor,
        Client $client,
        string $contactType,
        string $name,
        string $countryCode,
        string $phone,
    ): ClientDeliveryContact {
        $contactType = trim($contactType);
        $name = trim($name);
        $countryCode = trim($countryCode);
        $phone = trim($phone);

        if (!in_array($contactType, self::MANUAL_TYPES, true) || $name === '' || $phone === '') {
            throw ValidationException::withMessages([
                'shippingContactName' => 'Enter a valid contact name and phone number before saving this contact.',
            ]);
        }

        $existing = ClientDeliveryContact::query()
            ->where('client_id', $client->id)
            ->where('contact_type', $contactType)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->first();

        if ($existing) {
            $existing->update([
                'name' => $name,
                'phone_country_code' => $countryCode !== '' ? $countryCode : null,
                'phone' => $phone,
                'last_used_at' => now(),
            ]);

            return $existing->refresh();
        }

        return ClientDeliveryContact::query()->create([
            'client_id' => $client->id,
            'contact_type' => $contactType,
            'name' => $name,
            'phone_country_code' => $countryCode !== '' ? $countryCode : null,
            'phone' => $phone,
            'created_by' => $actor->id,
            'last_used_at' => now(),
        ]);
    }
}
