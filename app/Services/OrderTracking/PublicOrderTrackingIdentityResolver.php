<?php

namespace App\Services\OrderTracking;

use App\DTOs\OrderTracking\PublicOrderTrackingCandidate;
use App\DTOs\OrderTracking\PublicOrderTrackingIdentity;
use App\Models\MasterRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Resolves whether an email is genuinely associated with the requested Order.
 *
 * The resolver is intentionally independent from the tracking presenter. It
 * never returns email addresses and never loads unrelated client/supplier/user
 * records. Every lookup is scoped by the already-resolved Order ID.
 */
final class PublicOrderTrackingIdentityResolver
{
    public function resolve(PublicOrderTrackingCandidate $candidate, string $email): PublicOrderTrackingIdentity
    {
        $email = Str::lower(trim($email));
        if ($email === '') {
            return new PublicOrderTrackingIdentity([]);
        }

        $flags = $this->linkedIdentityFlags($candidate->orderId, $email);
        $sources = [];

        if ((bool) ($flags->client_match ?? false)) {
            $sources[] = 'client';
        }
        if ((bool) ($flags->client_contact_match ?? false)) {
            $sources[] = 'client_contact';
        }
        if ((bool) ($flags->billing_contact_match ?? false)) {
            $sources[] = 'billing_contact';
        }
        if ((bool) ($flags->order_user_match ?? false)) {
            $sources[] = 'order_user';
        }
        if ($this->matchesSupplier($candidate, $email)) {
            $sources[] = 'supplier';
        }

        return new PublicOrderTrackingIdentity(array_values(array_unique($sources)));
    }

    private function linkedIdentityFlags(int $orderId, string $email): ?object
    {
        return DB::table('flow_jobs')
            ->where('flow_jobs.id', $orderId)
            ->selectRaw(
                'CASE WHEN EXISTS ('
                .'SELECT 1 FROM clients c '
                .'WHERE c.id = flow_jobs.client_id AND LOWER(TRIM(c.email)) = ?'
                .') THEN 1 ELSE 0 END AS client_match',
                [$email],
            )
            ->selectRaw(
                'CASE WHEN EXISTS ('
                .'SELECT 1 FROM client_contacts cc '
                .'WHERE cc.client_id = flow_jobs.client_id AND LOWER(TRIM(cc.email)) = ?'
                .') THEN 1 ELSE 0 END AS client_contact_match',
                [$email],
            )
            ->selectRaw(
                'CASE WHEN EXISTS ('
                .'SELECT 1 FROM invoices i '
                .'WHERE i.flow_job_id = flow_jobs.id AND LOWER(TRIM(i.billing_contact_email)) = ?'
                .') THEN 1 ELSE 0 END AS billing_contact_match',
                [$email],
            )
            ->selectRaw(
                'CASE WHEN EXISTS ('
                .'SELECT 1 FROM users u '
                .'WHERE u.is_active = 1 AND LOWER(TRIM(u.email)) = ? AND ('
                .'u.id = flow_jobs.owner_id '
                .'OR u.id = flow_jobs.coordinator_id '
                .'OR u.id = flow_jobs.created_by '
                .'OR EXISTS (SELECT 1 FROM flow_job_members fm WHERE fm.flow_job_id = flow_jobs.id AND fm.user_id = u.id) '
                .'OR EXISTS (SELECT 1 FROM tasks t WHERE t.flow_job_id = flow_jobs.id AND t.assignee_id = u.id AND t.deleted_at IS NULL)'
                .')'
                .') THEN 1 ELSE 0 END AS order_user_match',
                [$email],
            )
            ->first();
    }

    private function matchesSupplier(PublicOrderTrackingCandidate $candidate, string $email): bool
    {
        $suppliers = MasterRecord::query()
            ->select(['id', 'metadata'])
            ->where('type', 'supplier')
            ->where('status', 'active')
            ->where(function ($query) use ($candidate): void {
                if ($candidate->supplierId) {
                    $query->whereKey($candidate->supplierId);
                } else {
                    $query->whereRaw('1 = 0');
                }

                $query->orWhereExists(function ($items) use ($candidate): void {
                    $items->selectRaw('1')
                        ->from('flow_job_items')
                        ->whereColumn('flow_job_items.supplier_id', 'master_records.id')
                        ->where('flow_job_items.flow_job_id', $candidate->orderId)
                        ->where('flow_job_items.is_removed', false);
                });
            })
            ->cursor();

        foreach ($suppliers as $supplier) {
            $supplierEmail = Str::lower(trim((string) data_get($supplier->metadata, 'email')));
            if ($supplierEmail !== '' && hash_equals($supplierEmail, $email)) {
                return true;
            }
        }

        return false;
    }
}
