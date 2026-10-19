<?php

namespace App\Services;

use App\Models\MasterRecord;
use Illuminate\Support\Collection;

/**
 * Small cached facade for Country/State master data used by address editors.
 *
 * MasterDataService already caches active master rows by workspace. Keeping the
 * parent lookup here avoids repeating Country -> State filtering rules across
 * Livewire components and validation services.
 */
final class LocationMasterDataService
{
    public function __construct(private readonly MasterDataService $masterData)
    {
    }

    /** @return Collection<int,MasterRecord> */
    public function countries(): Collection
    {
        return $this->masterData->active('country')->values();
    }

    /** @return Collection<int,MasterRecord> */
    public function statesForCountry(string $country): Collection
    {
        $countryRecord = $this->country($country);
        if (! $countryRecord) {
            return collect();
        }

        return $this->masterData->active('state')
            ->filter(fn (MasterRecord $state): bool => (int) $state->parent_id === (int) $countryRecord->id)
            ->values();
    }

    public function defaultCountryName(): string
    {
        $countries = $this->countries();
        $default = $countries->first(
            fn (MasterRecord $country): bool => filter_var(data_get($country->metadata, 'is_default', false), FILTER_VALIDATE_BOOL),
        );

        return trim((string) (($default ?? $countries->first())?->name ?? ''));
    }

    public function countryExists(string $country): bool
    {
        return $this->country($country) !== null;
    }

    public function stateBelongsToCountry(string $country, string $state): bool
    {
        $state = $this->normalize($state);
        if ($state === '') {
            return false;
        }

        return $this->statesForCountry($country)->contains(
            fn (MasterRecord $record): bool => $this->normalize($record->name) === $state,
        );
    }

    private function country(string $country): ?MasterRecord
    {
        $country = $this->normalize($country);
        if ($country === '') {
            return null;
        }

        return $this->countries()->first(
            fn (MasterRecord $record): bool => $this->normalize($record->name) === $country,
        );
    }

    private function normalize(mixed $value): string
    {
        return mb_strtolower(trim((string) $value));
    }
}
