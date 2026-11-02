<?php

namespace App\Services\Orders;

use App\Models\ArtworkHandoffContact;
use App\Services\SetupContext;
use Illuminate\Support\Facades\DB;

/** Preserve handoff recipient addresses without touching existing contact details. */
final class ArtworkHandoffContactCapture
{
    /** Number of external addresses eligible for saving (including existing contacts). */
    public function capture(string $addresses, int $actorId): int
    {
        $emails = collect(preg_split('/[\s,;]+/', trim($addresses)) ?: [])
            ->map(fn ($email) => mb_strtolower(trim((string) $email)))
            ->filter(fn ($email) => strlen($email) <= 254 && filter_var($email, FILTER_VALIDATE_EMAIL))
            ->unique()
            ->take(10)
            ->values();

        if ($emails->isEmpty()) {
            return 0;
        }

        // Internal FlowTracker accounts are never captured as address-book
        // contacts, even when the account is disabled. A single indexed lookup
        // handles every handoff recipient (maximum 10 addresses).
        $internalEmails = DB::table('users')->whereIn('email', $emails->all())
            ->pluck('email')
            ->map(fn ($email) => mb_strtolower(trim((string) $email)))
            ->all();
        $emails = $emails->reject(fn ($email) => in_array($email, $internalEmails, true))->values();
        if ($emails->isEmpty()) {
            return 0;
        }

        $workspaceId = app(SetupContext::class)->workspaceId();
        $timestamp = now();
        // Unique(workspace_id, email) handles duplicates and racing sends.
        // insertOrIgnore never overwrites an existing contact's enriched details.
        DB::table((new ArtworkHandoffContact())->getTable())->insertOrIgnore(
            $emails->map(fn ($email) => [
                'workspace_id' => $workspaceId,
                'email' => $email,
                'created_by' => $actorId,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ])->all()
        );

        return $emails->count();
    }
}
