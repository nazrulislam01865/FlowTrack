<?php

namespace App\Services\Inquiries;

use App\DTOs\Email\EmailMessage;
use App\Models\Inquiry;
use App\Models\InquiryRfqInvitation;
use App\Models\InquiryRfqQuote;
use App\Models\MasterRecord;
use App\Models\User;
use App\Services\BrandingService;
use App\Services\CompanyProfileService;
use App\Services\Email\EmailService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;

/**
 * RFQ-specific email composition.
 *
 * Delivery is intentionally delegated to FlowTrack's central EmailService so
 * SMTP/SES/Postmark/Resend/provider changes remain outside the Inquiry module.
 */
final class InquiryRfqEmailService
{
    public function __construct(
        private readonly EmailService $email,
        private readonly BrandingService $branding,
        private readonly CompanyProfileService $companyProfile,
    ) {}

    private function supplierItems(InquiryRfqInvitation $invitation): \Illuminate\Support\Collection
    {
        $items = $invitation->inquiry->items;
        $ids = $invitation->scopedItemIds();
        return $ids === [] ? $items : $items->whereIn('id', $ids)->values();
    }

    public function sendInvitation(InquiryRfqInvitation $invitation, string $token): string
    {
        $inquiry = $invitation->relationLoaded('inquiry')
            && $invitation->inquiry
            && $invitation->inquiry->relationLoaded('items')
                ? $invitation->inquiry
                : $invitation->inquiry()->with('items')->firstOrFail();

        return $this->email->sendNow(EmailMessage::view(
            $invitation->supplierEmail(),
            'Quotation requested — '.$inquiry->inquiry_number,
            'emails.rfq.invitation',
            [
                'brand' => $this->rfqBrand(),
                'inquiry' => $inquiry,
                'supplier' => $invitation->supplier,
                'contact' => $invitation->supplierContactName(),
                'items' => $this->supplierItems($invitation),
                'due' => $invitation->due_at,
                'requestMessage' => trim((string) ($invitation->request_message ?? '')),
                'supplierDetails' => trim((string) ($invitation->supplier_details ?? '')),
                'linkExpiresAt' => $invitation->link_expires_at,
                'publicUrl' => route('rfq.public.show', ['token' => $token]),
            ],
            ['type' => 'rfq_invitation', 'reference' => $inquiry->inquiry_number, 'inquiry_id' => $inquiry->id, 'supplier_id' => $invitation->supplier_id],
        ));
    }

    public function sendReminder(InquiryRfqInvitation $invitation, string $token): string
    {
        return $this->email->send(EmailMessage::view(
            $invitation->supplierEmail(),
            'Reminder — quotation due '.$invitation->due_at?->format('M j, Y'),
            'emails.rfq.reminder',
            [
                'brand' => $this->rfqBrand(),
                'inquiry' => $invitation->inquiry,
                'supplier' => $invitation->supplier,
                'contact' => $invitation->supplierContactName(),
                'items' => $this->supplierItems($invitation),
                'due' => $invitation->due_at,
                'linkExpiresAt' => $invitation->link_expires_at,
                'publicUrl' => route('rfq.public.show', ['token' => $token]),
            ],
            ['type' => 'rfq_due_reminder', 'reference' => $invitation->inquiry->inquiry_number, 'inquiry_id' => $invitation->inquiry_id, 'supplier_id' => $invitation->supplier_id],
        ));
    }

    public function sendQuoteReceived(InquiryRfqInvitation $invitation, InquiryRfqQuote $quote): string
    {
        return $this->email->send(EmailMessage::view(
            $invitation->supplierEmail(),
            'Quotation received — thank you',
            'emails.rfq.quote-received',
            [
                'brand' => $this->rfqBrand(),
                'inquiry' => $invitation->inquiry,
                'supplier' => $invitation->supplier,
                'contact' => $invitation->supplierContactName(),
                'items' => $this->supplierItems($invitation),
                'due' => $invitation->due_at,
                'quote' => $quote,
            ],
            ['type' => 'rfq_quote_received', 'reference' => $invitation->inquiry->inquiry_number, 'inquiry_id' => $invitation->inquiry_id, 'supplier_id' => $invitation->supplier_id, 'quote_id' => $quote->id],
        ));
    }

    public function sendAward(InquiryRfqInvitation $invitation, User $actor): string
    {
        $token = Crypt::decryptString((string) $invitation->token_cipher);
        return $this->email->send(EmailMessage::view(
            $invitation->supplierEmail(),
            'Congratulations — quotation awarded',
            'emails.rfq.award',
            [
                'brand' => $this->rfqBrand(),
                'inquiry' => $invitation->inquiry,
                'supplier' => $invitation->supplier,
                'contact' => $invitation->supplierContactName(),
                'items' => $this->supplierItems($invitation),
                'due' => $invitation->due_at,
                'quote' => $invitation->quote,
                'awardedBy' => $actor->name,
                'publicUrl' => route('rfq.public.show', ['token' => $token]),
            ],
            ['type' => 'rfq_supplier_award', 'reference' => $invitation->inquiry->inquiry_number, 'inquiry_id' => $invitation->inquiry_id, 'supplier_id' => $invitation->supplier_id, 'quote_id' => $invitation->quote?->id],
        ));
    }

    public function sendNotSelected(InquiryRfqInvitation $invitation): string
    {
        return $this->email->send(EmailMessage::view(
            $invitation->supplierEmail(),
            'Update on quotation '.$invitation->inquiry->inquiry_number,
            'emails.rfq.not-selected',
            [
                'brand' => $this->rfqBrand(),
                'inquiry' => $invitation->inquiry,
                'supplier' => $invitation->supplier,
                'contact' => $invitation->supplierContactName(),
                'items' => $this->supplierItems($invitation),
                'due' => $invitation->due_at,
            ],
            ['type' => 'rfq_not_selected', 'reference' => $invitation->inquiry->inquiry_number, 'inquiry_id' => $invitation->inquiry_id, 'supplier_id' => $invitation->supplier_id],
        ));
    }

    /** @return array<string,string> */
    public function previewHtml(Inquiry $inquiry, Collection $invitations): array
    {
        $primary = $invitations->first();
        $submitted = $invitations->first(fn (InquiryRfqInvitation $row) => $row->quote_status === 'submitted' && $row->quote);
        $winner = $invitations->first(fn (InquiryRfqInvitation $row) => (bool) $row->awarded_at) ?: $submitted;
        $loser = $invitations->first(fn (InquiryRfqInvitation $row) => !$winner || $row->id !== $winner->id) ?: $primary;
        $brand = $this->rfqBrand();
        $fallbackSupplier = new MasterRecord(['name' => 'Supplier']);
        $fallbackSupplier->metadata = ['contact_person' => 'Supplier'];

        $supplier = $primary?->supplier ?: $fallbackSupplier;
        $contact = trim((string) data_get($supplier->metadata, 'contact_person')) ?: 'Supplier';
        $due = $primary?->due_at ?: now()->addDays(7)->endOfDay();
        $items = $inquiry->relationLoaded('items') ? $inquiry->items : $inquiry->items()->get();
        $requestMessage = trim((string) ($primary?->request_message ?? ''));
        $supplierDetails = trim((string) ($primary?->supplier_details ?? ''));
        $linkExpiresAt = $primary?->link_expires_at;
        $base = compact('brand','supplier','contact','due','items','inquiry','requestMessage','supplierDetails','linkExpiresAt');

        $quote = $submitted?->quote;
        $winnerQuote = $winner?->quote;
        $winnerSupplier = $winner?->supplier ?: $supplier;
        $loserSupplier = $loser?->supplier ?: $supplier;

        return [
            'invitation' => view('emails.rfq.invitation', $base + ['publicUrl' => '#'])->render(),
            'reminder' => view('emails.rfq.reminder', $base + ['publicUrl' => '#'])->render(),
            'received' => view('emails.rfq.quote-received', $base + ['quote' => $quote])->render(),
            'award' => view('emails.rfq.award', $base + [
                'supplier' => $winnerSupplier,
                'contact' => trim((string) data_get($winnerSupplier->metadata, 'contact_person')) ?: 'Supplier',
                'quote' => $winnerQuote,
                'awardedBy' => ($brand['name'] ?? 'Company').' team',
                'publicUrl' => '#',
            ])->render(),
            'not_selected' => view('emails.rfq.not-selected', $base + [
                'supplier' => $loserSupplier,
                'contact' => trim((string) data_get($loserSupplier->metadata, 'contact_person')) ?: 'Supplier',
            ])->render(),
        ];
    }

    /**
     * RFQ emails are external company communications, so their visible identity
     * comes from Company Setup rather than the internal FlowTrack workspace name.
     * Branding assets are kept from BrandingService while legal/contact details
     * are read from CompanyProfileService.
     *
     * @return array<string,mixed>
     */
    private function rfqBrand(): array
    {
        $branding = $this->branding->current();
        $profile = $this->companyProfile->current();

        $tradingName = trim((string) ($profile['trading_name'] ?? ''));
        $legalName = trim((string) ($profile['legal_name'] ?? ''));
        $displayName = $tradingName !== ''
            ? $tradingName
            : ($legalName !== '' ? $legalName : trim((string) ($branding['name'] ?? '')));

        return array_merge($branding, [
            'name' => $displayName !== '' ? $displayName : 'Company',
            'legal_name' => $legalName,
            'trading_name' => $tradingName,
            'registration_number' => trim((string) ($profile['registration_number'] ?? '')),
            'tax_number' => trim((string) ($profile['tax_number'] ?? '')),
            'billing_email' => trim((string) ($profile['billing_email'] ?? '')),
            'phone' => trim((string) ($profile['phone'] ?? '')),
            'website' => trim((string) ($profile['website'] ?? '')),
            'address_lines' => $this->companyProfile->addressLines($profile),
        ]);
    }

}
