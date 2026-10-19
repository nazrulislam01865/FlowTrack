<?php

namespace App\Support;

use App\Models\InquiryRfqInvitation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Query-free presentation layer for Inquiry Details > RFQ.
 *
 * The RFQ service/query layer owns data access. This presenter only converts the
 * already-loaded default suppliers and invitation models into the stable row
 * contract consumed by the prototype-matched Blade components. Keeping search,
 * status filtering and pagination here avoids business/presentation branching in
 * the view and makes the RFQ workspace easy to reuse or test independently.
 */
final class InquiryRfqPresenter
{
    private const PER_PAGE = 5;

    /**
     * @param Collection<int,array<string,mixed>> $defaultSuppliers
     * @param Collection<int,InquiryRfqInvitation> $invitations
     * @param array<int,int|string> $selectedSupplierIds
     * @return array<string,mixed>
     */
    public static function workspace(
        Collection $defaultSuppliers,
        Collection $invitations,
        bool $emailEnabled,
        string $search = '',
        string $emailStatus = 'all',
        array $selectedSupplierIds = [],
        int $page = 1,
    ): array {
        $rows = $defaultSuppliers
            ->map(fn (array $supplier): array => self::defaultSupplierRow($supplier, $emailEnabled))
            ->concat($invitations->map(fn (InquiryRfqInvitation $invitation): array => self::invitationRow($invitation, $emailEnabled)))
            ->values();

        $failedCount = $rows->where('email_status_key', 'failed')->count();
        $totalParticipants = $rows->count();
        $search = mb_strtolower(trim($search));
        $emailStatus = self::normaliseEmailStatusFilter($emailStatus);

        $filtered = $rows
            ->filter(function (array $row) use ($search, $emailStatus): bool {
                if ($emailStatus !== 'all' && $row['email_status_key'] !== $emailStatus) {
                    return false;
                }

                if ($search === '') {
                    return true;
                }

                $haystack = mb_strtolower(implode(' ', [
                    (string) $row['supplier_name'],
                    (string) ($row['supplier_short_code'] ?? ''),
                    (string) $row['email'],
                    (string) $row['email_status_label'],
                    (string) $row['rfq_status_label'],
                ]));

                return str_contains($haystack, $search);
            })
            ->values();

        $total = $filtered->count();
        $lastPage = max(1, (int) ceil($total / self::PER_PAGE));
        $currentPage = max(1, min($page, $lastPage));
        $visibleRows = $filtered
            ->slice(($currentPage - 1) * self::PER_PAGE, self::PER_PAGE)
            ->values();

        $selectableIds = $rows
            ->where('selectable', true)
            ->pluck('supplier_id')
            ->map(fn ($id) => (int) $id)
            ->values();
        $selectedIds = collect($selectedSupplierIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0 && $selectableIds->contains($id))
            ->unique()
            ->values();
        $visibleSelectableIds = $visibleRows
            ->where('selectable', true)
            ->pluck('supplier_id')
            ->map(fn ($id) => (int) $id)
            ->values();

        $first = $total > 0 ? (($currentPage - 1) * self::PER_PAGE) + 1 : 0;
        $last = $total > 0 ? min($currentPage * self::PER_PAGE, $total) : 0;

        return [
            'rows' => $visibleRows,
            'total' => $total,
            'total_participants' => $totalParticipants,
            'first' => $first,
            'last' => $last,
            'current_page' => $currentPage,
            'last_page' => $lastPage,
            'has_previous' => $currentPage > 1,
            'has_next' => $currentPage < $lastPage,
            'selected_ids' => $selectedIds->all(),
            'selected_count' => $selectedIds->count(),
            'selectable_visible_ids' => $visibleSelectableIds->all(),
            'all_visible_selected' => $visibleSelectableIds->isNotEmpty()
                && $visibleSelectableIds->every(fn (int $id): bool => $selectedIds->contains($id)),
            'failed_count' => $failedCount,
            'email_enabled' => $emailEnabled,
            'filter' => $emailStatus,
            'filter_options' => self::emailStatusFilters(),
        ];
    }

    /** @return array<string,string> */
    public static function emailStatusFilters(): array
    {
        return [
            'all' => 'All email statuses',
            'ready' => 'Ready to send',
            'sent' => 'Sent',
            'failed' => 'Failed',
            'missing' => 'Email not set',
            'queued' => 'Queued',
            'disabled' => 'Email disabled',
        ];
    }

    private static function normaliseEmailStatusFilter(string $status): string
    {
        $status = strtolower(trim($status));
        return array_key_exists($status, self::emailStatusFilters()) ? $status : 'all';
    }

    /** @param array<string,mixed> $supplier @return array<string,mixed> */
    private static function defaultSupplierRow(array $supplier, bool $emailEnabled): array
    {
        $email = trim((string) ($supplier['email'] ?? ''));
        $emailReady = (bool) ($supplier['email_ready'] ?? false);
        $isActive = (bool) ($supplier['invitable'] ?? false);

        if (! $isActive) {
            $emailStatusKey = 'disabled';
            $emailStatusLabel = (string) ($supplier['unavailable_reason'] ?? 'Unavailable');
            $emailTone = 'neutral';
        } elseif (! $emailReady) {
            $emailStatusKey = 'missing';
            $emailStatusLabel = 'Email not set';
            $emailTone = 'warning';
        } elseif (! $emailEnabled) {
            $emailStatusKey = 'disabled';
            $emailStatusLabel = 'Email disabled';
            $emailTone = 'neutral';
        } else {
            $emailStatusKey = 'ready';
            $emailStatusLabel = 'Ready to send';
            $emailTone = 'success';
        }

        return [
            'supplier_id' => (int) ($supplier['id'] ?? 0),
            'invitation_id' => null,
            'supplier_name' => (string) ($supplier['name'] ?? 'Supplier'),
            'supplier_short_code' => (string) ($supplier['short_code'] ?? ($supplier['name'] ?? 'Supplier')),
            'initials' => self::initials((string) ($supplier['name'] ?? 'Supplier')),
            'email' => $email,
            'email_ready' => $emailReady,
            'email_status_key' => $emailStatusKey,
            'email_status_label' => $emailStatusLabel,
            'email_status_detail' => null,
            'email_tone' => $emailTone,
            'rfq_status_label' => 'Not invited',
            'last_activity_at' => null,
            'action' => self::actionForDefault($emailReady, $isActive, $emailEnabled),
            'selectable' => $emailReady && $isActive && $emailEnabled,
            'is_default' => true,
        ];
    }

    /** @return array<string,mixed> */
    private static function invitationRow(InquiryRfqInvitation $invitation, bool $emailEnabled): array
    {
        $email = $invitation->supplierEmail();
        $emailReady = filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
        $rawEmailStatus = strtolower(trim((string) $invitation->email_status));

        [$emailStatusKey, $emailStatusLabel, $emailTone, $emailStatusDetail] = match ($rawEmailStatus) {
            'delivered' => ['sent', 'Sent', 'success', null],
            'failed' => ['failed', 'Failed', 'danger', null],
            'sending', 'queued' => ['queued', 'Queued', 'neutral', null],
            'no email' => $emailReady && $emailEnabled
                ? ['ready', 'Ready to send', 'success', null]
                : ($emailReady
                    ? ['disabled', 'Email disabled', 'neutral', null]
                    : ['missing', 'Email not set', 'warning', null]),
            'email disabled' => $emailReady && $emailEnabled
                ? ['ready', 'Ready to send', 'success', null]
                : ($emailReady
                    ? ['disabled', 'Email disabled', 'neutral', null]
                    : ['missing', 'Email not set', 'warning', null]),
            'draft', 'pending', '' => $emailReady && $emailEnabled
                ? ['ready', 'Ready to send', 'success', null]
                : ($emailReady
                    ? ['disabled', 'Email disabled', 'neutral', null]
                    : ['missing', 'Email not set', 'warning', null]),
            default => ['neutral', Str::headline((string) $invitation->email_status), 'neutral', null],
        };

        $rfqStatus = match (true) {
            (bool) $invitation->awarded_at => 'Selected',
            (bool) $invitation->rejected_at => 'Not selected',
            $invitation->quote_status === 'submitted' => 'Quote received',
            $invitation->interest_status === 'interested' => 'Interested',
            $invitation->interest_status === 'declined' => 'Declined',
            $rawEmailStatus === 'failed' => 'Email failed',
            in_array($rawEmailStatus, ['sending', 'queued'], true) => 'Sending',
            $rawEmailStatus === 'delivered' => 'Invited',
            default => 'Not invited',
        };

        $lastActivity = collect([
            $invitation->quote_submitted_at,
            $invitation->interest_at,
            $invitation->awarded_at,
            $invitation->rejected_at,
            $invitation->invited_at,
            $invitation->updated_at,
        ])
            ->filter()
            ->map(fn ($value) => $value instanceof Carbon ? $value : Carbon::parse($value))
            ->sortDesc()
            ->first();

        $action = self::actionForInvitation($invitation, $emailReady, $emailEnabled, $emailStatusKey);
        $terminal = (bool) $invitation->awarded_at || (bool) $invitation->rejected_at || $invitation->quote_status === 'submitted';

        return [
            'supplier_id' => (int) $invitation->supplier_id,
            'invitation_id' => (int) $invitation->id,
            'supplier_name' => (string) ($invitation->supplier?->name ?: 'Supplier'),
            'supplier_short_code' => $invitation->supplier?->supplierShortCode() ?: 'Supplier',
            'initials' => self::initials((string) ($invitation->supplier?->name ?: 'Supplier')),
            'email' => $email,
            'email_ready' => $emailReady,
            'email_status_key' => $emailStatusKey,
            'email_status_label' => $emailStatusLabel,
            'email_status_detail' => $emailStatusDetail,
            'email_tone' => $emailTone,
            'rfq_status_label' => $rfqStatus,
            'last_activity_at' => $lastActivity,
            'action' => $action,
            'selectable' => $emailReady
                && $emailEnabled
                && ! $terminal
                && $emailStatusKey !== 'queued',
            'is_default' => false,
        ];
    }

    /** @return array{type:string,label:string,tone:string,disabled:bool} */
    private static function actionForDefault(bool $emailReady, bool $isActive, bool $emailEnabled): array
    {
        if (! $isActive) return ['type' => 'disabled', 'label' => 'Unavailable', 'tone' => 'neutral', 'disabled' => true];
        if (! $emailReady) return ['type' => 'setup_email', 'label' => 'Set up email', 'tone' => 'warning', 'disabled' => false];
        if (! $emailEnabled) return ['type' => 'disabled', 'label' => 'Email disabled', 'tone' => 'neutral', 'disabled' => true];
        return ['type' => 'send', 'label' => 'Send email', 'tone' => 'success', 'disabled' => false];
    }

    /** @return array{type:string,label:string,tone:string,disabled:bool} */
    private static function actionForInvitation(
        InquiryRfqInvitation $invitation,
        bool $emailReady,
        bool $emailEnabled,
        string $emailStatusKey,
    ): array {
        if ($invitation->quote_status === 'submitted') {
            return ['type' => 'view_response', 'label' => 'View response', 'tone' => 'success', 'disabled' => false];
        }
        if ((bool) $invitation->awarded_at || (bool) $invitation->rejected_at) {
            return ['type' => 'disabled', 'label' => 'Completed', 'tone' => 'neutral', 'disabled' => true];
        }
        if (! $emailReady) {
            return ['type' => 'setup_email', 'label' => 'Set up email', 'tone' => 'warning', 'disabled' => false];
        }
        if (! $emailEnabled) {
            return ['type' => 'disabled', 'label' => 'Email disabled', 'tone' => 'neutral', 'disabled' => true];
        }
        if ($emailStatusKey === 'queued') {
            return ['type' => 'disabled', 'label' => 'Sending…', 'tone' => 'neutral', 'disabled' => true];
        }
        if ($emailStatusKey === 'failed') {
            return ['type' => 'send', 'label' => 'Retry', 'tone' => 'danger', 'disabled' => false];
        }
        if ($emailStatusKey === 'sent') {
            return ['type' => 'send', 'label' => 'Resend', 'tone' => 'success', 'disabled' => false];
        }

        return ['type' => 'send', 'label' => 'Send email', 'tone' => 'success', 'disabled' => false];
    }

    private static function initials(string $name): string
    {
        $parts = preg_split('/\s+/u', trim($name)) ?: [];
        $initials = collect($parts)
            ->filter()
            ->map(fn (string $part): string => mb_strtoupper(mb_substr($part, 0, 1)))
            ->take(2)
            ->implode('');

        return $initials !== '' ? $initials : '—';
    }
}
