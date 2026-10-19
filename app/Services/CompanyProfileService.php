<?php

namespace App\Services;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Arr;

class CompanyProfileService
{
    private const FIELDS = [
        'legal_name',
        'trading_name',
        'registration_number',
        'tax_number',
        'billing_email',
        'phone',
        'website',
        'address_line_1',
        'address_line_2',
        'city',
        'state_region',
        'postal_code',
        'country',
        'bank_name',
        'bank_account_name',
        'bank_account_number',
        'bank_iban',
        'bank_swift',
        'payment_instructions',
        'invoice_footer',
    ];

    public function current(): array
    {
        $workspace = $this->workspace();
        $stored = is_array($workspace->company_profile) ? $workspace->company_profile : [];

        return $this->normalize($stored, (string) $workspace->name);
    }

    public function save(array $payload, ?User $actor = null): array
    {
        $user = $actor ?: auth()->user();
        abort_unless($user?->is_active && app(AccessControlService::class)->isAdministrator($user), 403);

        $workspace = $this->workspace();
        $profile = $this->normalize($payload, (string) $workspace->name);
        $workspace->update(['company_profile' => $profile]);

        return $profile;
    }

    /**
     * Invoice snapshots intentionally contain only printable company data.
     * Keeping a snapshot on each invoice prevents later Company Setup edits
     * from silently changing the legal identity/payment details of old invoices.
     */
    public function invoiceSnapshot(): array
    {
        return $this->current();
    }

    public function addressLines(?array $profile = null): array
    {
        $profile ??= $this->current();
        $lines = [];

        foreach (['address_line_1', 'address_line_2'] as $key) {
            if (filled($profile[$key] ?? null)) $lines[] = trim((string) $profile[$key]);
        }

        $locality = collect([
            $profile['city'] ?? null,
            $profile['state_region'] ?? null,
            $profile['postal_code'] ?? null,
        ])->filter(fn ($value) => filled($value))->map(fn ($value) => trim((string) $value))->implode(', ');
        if ($locality !== '') $lines[] = $locality;

        if (filled($profile['country'] ?? null)) $lines[] = trim((string) $profile['country']);

        return $lines;
    }

    private function normalize(array $payload, string $workspaceName): array
    {
        $profile = Arr::only($payload, self::FIELDS);
        foreach (self::FIELDS as $field) {
            $profile[$field] = trim((string) ($profile[$field] ?? ''));
        }

        if ($profile['legal_name'] === '') {
            $profile['legal_name'] = trim($workspaceName) ?: 'FlowTrack';
        }

        return $profile;
    }

    private function workspace(): Workspace
    {
        $id = app(SetupContext::class)->workspaceId();
        return Workspace::query()->findOrFail($id);
    }
}
