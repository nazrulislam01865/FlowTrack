<?php

namespace App\DTOs\Orders;

use App\Models\FlowJob;
use App\Models\MasterRecord;
use App\Services\RemoteAreas\PostalRangeCompiler;
use InvalidArgumentException;

/** A completed negative result is not an unavailable lookup; zero is a valid matched charge. */
final readonly class RemoteAreaResolution
{
    public const MATCHED = 'matched';
    public const NO_MATCH = 'no_match';
    public const NOT_APPLICABLE = 'not_applicable';
    public const UNRESOLVED = 'unresolved';

    public function __construct(
        public string $status,
        public string $key,
        public string $fingerprint,
        public int $workspaceId,
        public ?int $ruleId = null,
        public ?string $name = null,
        public ?string $location = null,
        public ?string $charge = null,
        public ?int $revision = null,
    ) {
        if (! in_array($status, [self::MATCHED, self::NO_MATCH, self::NOT_APPLICABLE, self::UNRESOLVED], true)) {
            throw new InvalidArgumentException('Invalid Remote Area resolution status.');
        }
        if ($workspaceId < 1 || (strlen($key) > 30 && $status !== self::UNRESOLVED)) {
            throw new InvalidArgumentException('Invalid Remote Area lookup identity.');
        }
        if ($status === self::MATCHED && (! $ruleId || $charge === null)) {
            throw new InvalidArgumentException('Matched Remote Area requires a rule and a charge.');
        }
        if (in_array($status, [self::NO_MATCH, self::NOT_APPLICABLE], true) && ($ruleId !== null || $charge !== '0.00')) {
            throw new InvalidArgumentException('Negative Remote Area results require a zero charge and no rule.');
        }
        if ($status === self::UNRESOLVED && ($ruleId !== null || $charge !== null)) {
            throw new InvalidArgumentException('Unresolved Remote Area must not masquerade as no-match.');
        }
    }

    public static function fingerprint(int $workspaceId, ?string $postal): string
    {
        return hash('sha256', 'remote_area_v1|'.$workspaceId.'|'.PostalRangeCompiler::key($postal));
    }

    public static function resolved(int $workspaceId, string $key, int $revision, ?MasterRecord $record): self
    {
        $fingerprint = self::fingerprint($workspaceId, $key);
        if ($key === '') return new self(self::NOT_APPLICABLE, $key, $fingerprint, $workspaceId, charge: '0.00', revision: $revision);
        if ($record === null) return new self(self::NO_MATCH, $key, $fingerprint, $workspaceId, charge: '0.00', revision: $revision);
        $charge = $record->remoteAreaExtraCharge() ?? 0.0;
        if ($charge === null || ! is_finite((float) $charge) || (float) $charge < 0 || (float) $charge >= 10000000000000) {
            return self::unresolved($workspaceId, $key);
        }
        return new self(
            self::MATCHED, $key, $fingerprint, $workspaceId, (int) $record->id,
            trim((string) $record->name), $record->remoteAreaLocationLabel(),
            number_format((float) $charge, 2, '.', ''), $revision,
        );
    }

    public static function unresolved(int $workspaceId, string $key): self
    {
        return new self(self::UNRESOLVED, $key, self::fingerprint($workspaceId, $key), $workspaceId);
    }

    public function complete(): bool
    {
        return $this->status !== self::UNRESOLVED;
    }

    /** @return array<string, mixed> */
    public function columns(): array
    {
        return [
            'remote_area_resolution_status' => $this->status,
            'remote_area_lookup_key' => strlen($this->key) <= 30 ? $this->key : null,
            'remote_area_input_fingerprint' => $this->fingerprint,
            'remote_area_rule_id' => $this->ruleId,
            'remote_area_name_snapshot' => $this->name,
            'remote_area_location_snapshot' => $this->location,
            'remote_area_charge' => $this->charge,
            'remote_area_rule_revision' => $this->revision,
            'remote_area_workspace_id' => $this->workspaceId,
            'remote_area_resolved_at' => $this->complete() ? now() : null,
        ];
    }

    public static function fromOrder(FlowJob $job, int $workspaceId): ?self
    {
        $status = (string) ($job->remote_area_resolution_status ?? '');
        if (! in_array($status, [self::MATCHED, self::NO_MATCH, self::NOT_APPLICABLE], true)) return null;
        $key = PostalRangeCompiler::key($job->shipping_postal_code);
        if ((int) $job->remote_area_workspace_id !== $workspaceId
            || (string) $job->remote_area_lookup_key !== $key
            || ! hash_equals(self::fingerprint($workspaceId, $key), (string) $job->remote_area_input_fingerprint)) return null;

        $charge = (string) ($job->remote_area_charge ?? '');
        if (! preg_match('/^\d{1,13}(?:\.\d{1,2})?$/', $charge)) return null;
        [$whole, $fraction] = array_pad(explode('.', $charge, 2), 2, '');
        $charge = $whole.'.'.str_pad($fraction, 2, '0');
        if ($status === self::MATCHED && (int) $job->remote_area_rule_id < 1) return null;
        if ($status !== self::MATCHED && ((int) $job->remote_area_rule_id !== 0 || (float) $charge !== 0.0)) return null;
        if ($status === self::NOT_APPLICABLE && $key !== '') return null;
        if ($status === self::NO_MATCH && $key === '') return null;
        return new self(
            $status, $key, self::fingerprint($workspaceId, $key), $workspaceId,
            $status === self::MATCHED ? (int) $job->remote_area_rule_id : null,
            $job->remote_area_name_snapshot, $job->remote_area_location_snapshot,
            $charge,
            $job->remote_area_rule_revision === null ? null : (int) $job->remote_area_rule_revision,
        );
    }
}
