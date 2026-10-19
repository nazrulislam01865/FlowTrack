<?php

namespace App\Http\Controllers\Rfq;

use App\Http\Controllers\Controller;
use App\Services\BrandingService;
use App\Services\Inquiries\InquiryRfqService;
use App\Services\Inquiries\PublicRfqPortalService;
use App\Support\AttachmentUpload;
use App\Support\StoredFileResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class PublicInquiryRfqController extends Controller
{
    public function show(
        Request $request,
        string $token,
        InquiryRfqService $rfq,
        PublicRfqPortalService $portal,
        BrandingService $branding,
    ): View {
        $invitation = $rfq->findPublicInvitation($token);
        $step = $portal->normalizeStep($request->query('step'), $invitation);

        return view('rfq.public-show', [
            'invitation' => $invitation,
            'brand' => $branding->current(),
            'token' => $token,
            ...$portal->viewData($invitation, $token, $step),
        ]);
    }

    public function respond(
        Request $request,
        string $token,
        InquiryRfqService $rfq,
        PublicRfqPortalService $portal,
    ): RedirectResponse {
        $invitation = $rfq->findPublicInvitation($token);
        $action = trim((string) $request->input('action'));

        if ($action === 'decline') {
            $rfq->markDeclined($invitation);
            return redirect()->route('rfq.public.show', ['token' => $token, 'step' => 'review'])
                ->with('success', 'Thank you. Your response has been recorded.');
        }

        if ($action === 'revise') {
            $rfq->beginQuoteRevision($invitation);

            return redirect()->route('rfq.public.show', ['token' => $token, 'step' => 'details'])
                ->with('success', 'Quotation reopened for revision. Your previous values and documents are preserved.');
        }

        if (in_array($action, ['save_details', 'continue_pricing'], true)) {
            $portal->saveDetails($invitation, $request->validate([
                'supplier_contact_name' => ['required', 'string', 'max:160'],
                'supplier_contact_email' => ['required', 'email', 'max:255'],
                'supplier_contact_phone' => ['nullable', 'string', 'max:80'],
            ]));

            return $this->redirectToStep($token, $action === 'continue_pricing' ? 'pricing' : 'details', 'Draft saved.');
        }

        if (in_array($action, ['save_pricing', 'continue_documents'], true)) {
            $scopedIds = $invitation->scopedItemIds();
            $itemIds = $scopedIds !== [] ? $scopedIds : $invitation->inquiry->items->pluck('id')->map(fn ($id) => (int) $id)->all();
            $data = $request->validate([
                'supplier_contact_name' => ['required', 'string', 'max:160'],
                'supplier_contact_email' => ['required', 'email', 'max:255'],
                'supplier_contact_phone' => ['nullable', 'string', 'max:80'],
                'currency' => ['required', 'in:USD,EUR,GBP,CNY'],
                'prices' => ['required', 'array'],
                'prices.*' => ['required', 'numeric', 'min:0', 'max:999999999.9999'],
                'moqs' => ['nullable', 'array'],
                'moqs.*' => ['nullable', 'numeric', 'min:0', 'max:999999999.99'],
                'tooling_cost' => ['nullable', 'numeric', 'min:0', 'max:999999999.99'],
                'sample_cost' => ['nullable', 'numeric', 'min:0', 'max:999999999.99'],
                'freight' => ['nullable', 'numeric', 'min:0', 'max:999999999.99'],
                'discount' => ['nullable', 'numeric', 'min:0', 'max:999999999.99'],
                'tax_status' => ['required', 'in:excluded,included'],
                'lead_time_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
                'sample_lead_time_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
                'incoterm' => ['nullable', 'string', 'max:24'],
                'shipping_port' => ['nullable', 'string', 'max:160'],
                'estimated_delivery_date' => ['nullable', 'date'],
                'validity_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
                'specification_compliance' => ['nullable', 'in:yes,partial,no'],
                'notes' => ['nullable', 'string', 'max:5000'],
                'document_notes' => ['nullable', 'string', 'max:5000'],
                'documents' => ['nullable', 'array', 'max:10'],
                'documents.*' => AttachmentUpload::itemRules(AttachmentUpload::BUSINESS_DOCUMENTS, 20480),
            ]);

            $prices = collect($data['prices'] ?? [])->mapWithKeys(fn ($price, $itemId) => [(int) $itemId => $price]);
            abort_unless(collect($itemIds)->every(fn (int $itemId): bool => $prices->has($itemId)), 422, 'Enter a unit price for every product.');
            $portal->saveDetails($invitation, $data);
            $portal->savePricing($invitation, $data);
            if (! empty($data['documents'])) {
                $portal->uploadDocuments($invitation, $data['documents']);
            }

            return $this->redirectToStep($token, $action === 'continue_documents' ? 'documents' : 'pricing', 'Draft saved.');
        }

        if (in_array($action, ['save_documents', 'continue_review'], true)) {
            $data = $request->validate([
                'document_types' => ['nullable', 'array'],
                'document_types.*' => ['nullable', 'in:'.implode(',', array_keys(PublicRfqPortalService::DOCUMENT_TYPES))],
                'supporting_information' => ['nullable', 'array'],
                'supporting_information.*' => ['in:'.implode(',', array_keys(PublicRfqPortalService::SUPPORTING_INFORMATION))],
                'document_notes' => ['nullable', 'string', 'max:5000'],
            ]);
            $quote = $portal->saveDocumentStep($invitation, $data);

            if ($action === 'continue_review') {
                $documentTypes = collect($quote->documents)->pluck('document_type');
                abort_unless(
                    collect(PublicRfqPortalService::REQUIRED_DOCUMENT_TYPES)->every(fn (string $type): bool => $documentTypes->contains($type)),
                    422,
                    'Upload the required quotation documents before continuing to review.',
                );
            }

            return $this->redirectToStep($token, $action === 'continue_review' ? 'review' : 'documents', 'Draft saved.');
        }

        if ($action === 'save_review') {
            $portal->touchDraft($invitation);
            return $this->redirectToStep($token, 'review', 'Draft saved.');
        }

        if ($action === 'submit') {
            $request->validate([
                'declaration_accuracy' => ['accepted'],
                'declaration_authority' => ['accepted'],
            ], [
                'declaration_accuracy.accepted' => 'Confirm that the quotation information is accurate.',
                'declaration_authority.accepted' => 'Confirm that you are authorized to submit this quotation.',
            ]);
            $rfq->submitSavedDraft($invitation);

            return redirect()->route('rfq.public.show', ['token' => $token, 'step' => 'review'])
                ->with('success', 'Your quotation has been submitted successfully.');
        }

        abort(422, 'Unknown quotation action.');
    }

    public function uploadDocuments(
        Request $request,
        string $token,
        InquiryRfqService $rfq,
        PublicRfqPortalService $portal,
    ): RedirectResponse {
        $invitation = $rfq->findPublicInvitation($token);
        $data = $request->validate([
            'documents' => ['required', 'array', 'min:1', 'max:10'],
            'documents.*' => AttachmentUpload::requiredRules(AttachmentUpload::BUSINESS_DOCUMENTS, 20480),
            'document_types' => ['nullable', 'array'],
            'document_types.*' => ['nullable', 'in:'.implode(',', array_keys(PublicRfqPortalService::DOCUMENT_TYPES))],
            'supporting_information' => ['nullable', 'array'],
            'supporting_information.*' => ['in:'.implode(',', array_keys(PublicRfqPortalService::SUPPORTING_INFORMATION))],
            'document_notes' => ['nullable', 'string', 'max:5000'],
            'return_step' => ['nullable', 'in:pricing,documents'],
        ]);
        if ($request->hasAny(['document_types', 'supporting_information', 'document_notes'])) {
            $portal->saveDocumentStep($invitation, $data);
        }
        $portal->uploadDocuments($invitation, $data['documents']);

        $returnStep = ($data['return_step'] ?? null) === 'pricing' ? 'pricing' : 'documents';
        return $this->redirectToStep($token, $returnStep, count($data['documents']).' document(s) uploaded.');
    }

    public function removeDocument(
        Request $request,
        string $token,
        int $document,
        InquiryRfqService $rfq,
        PublicRfqPortalService $portal,
    ): RedirectResponse {
        $invitation = $rfq->findPublicInvitation($token);
        $portal->removeDocument($invitation, $document);

        $returnStep = $request->input('return_step') === 'pricing' ? 'pricing' : 'documents';
        return $this->redirectToStep($token, $returnStep, 'Document removed.');
    }

    public function previewDocument(
        string $token,
        int $document,
        InquiryRfqService $rfq,
        PublicRfqPortalService $portal,
    ): StreamedResponse {
        $invitation = $rfq->findPublicInvitation($token);
        $file = $portal->documentForInvitation($invitation, $document);

        return StoredFileResponse::inline((string) $file->path, (string) $file->name, $file->mime_type);
    }

    public function downloadDocument(
        string $token,
        int $document,
        InquiryRfqService $rfq,
        PublicRfqPortalService $portal,
    ): StreamedResponse {
        $invitation = $rfq->findPublicInvitation($token);
        $file = $portal->documentForInvitation($invitation, $document);

        return StoredFileResponse::download((string) $file->path, (string) $file->name, $file->mime_type);
    }

    public function productImage(
        string $token,
        int $item,
        InquiryRfqService $rfq,
        PublicRfqPortalService $portal,
    ) {
        $invitation = $rfq->findPublicInvitation($token);
        $path = $portal->productImagePath($invitation, $item);
        abort_unless($path && Storage::disk('public')->exists($path), 404);

        return Storage::disk('public')->response($path, null, [
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function redirectToStep(string $token, string $step, string $message): RedirectResponse
    {
        return redirect()->route('rfq.public.show', ['token' => $token, 'step' => $step])->with('success', $message);
    }
}
