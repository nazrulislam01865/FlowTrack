<?php

namespace App\Actions\Inquiries;

use App\Models\Client;
use App\Models\User;
use App\Services\NotificationService;

final class CreateInquiryClient
{
    public function handle(array $data, User $actor): Client
    {
        abort_unless($actor->canModule('clients', 'create'), 403);

        $client = Client::create([
            'code' => $this->nextCode(),
            'name' => trim((string) $data['name']),
            'country' => trim((string) ($data['country'] ?? '')) ?: null,
            'contact_name' => trim((string) ($data['contact_name'] ?? '')) ?: null,
            'email' => trim((string) ($data['email'] ?? '')) ?: null,
            'phone' => trim((string) ($data['phone'] ?? '')) ?: null,
            'account_manager_id' => $actor->id,
            'created_by' => $actor->id,
            'preferred_language' => 'English',
            'outstanding_balance' => 0,
            'is_active' => true,
        ]);

        try {
            app(NotificationService::class)->notifyUser(
                $actor,
                'Client created',
                $client->name.' was created from Create Inquiry.',
                'update',
                null,
                null,
                $actor,
            );
        } catch (\Throwable $exception) {
            report($exception);
        }

        return $client;
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
}
