<?php

namespace Tests\Feature;

use Tests\TestCase;

class OrderWorkflowEmailHandoffImplementationTest extends TestCase
{
    public function test_order_workflow_handoffs_use_team_members_central_email_and_exact_preview(): void
    {
        $service = file_get_contents(app_path('Services/Orders/OrderWorkflowEmailService.php'));
        $actions = file_get_contents(app_path('Services/OrderWorkflowActionService.php'));
        $modal = file_get_contents(resource_path('views/components/jobs/order-detail/workflow-action-modal.blade.php'));
        $preview = file_get_contents(resource_path('views/components/email/handoff-preview.blade.php'));
        $email = file_get_contents(resource_path('views/emails/orders/workflow-handoff.blade.php'));
        $detailCss = file_get_contents(resource_path('css/modules/orders/detail/detail-02.css'));

        $this->assertStringContainsString('EmailService $email', $service);
        $this->assertStringContainsString('EmailMessage::storageAttachment', $service);
        $this->assertStringContainsString('NEW_SEND_PO_ARTWORK', $service);
        $this->assertStringContainsString('ART_SEND_ORDER_TEAM', $service);
        $this->assertStringContainsString("'job.purchase_order_emailed_to_artwork_team'", $service);
        $this->assertStringContainsString("'job.artwork_emailed_to_order_team'", $service);

        // Recipient rules are explicit and can return multiple people.
        // Artwork -> Order Team uses the active current-workspace user directory
        // so autocomplete never disappears because of a legacy role/team name.
        $this->assertStringContainsString('orderTeamRecipientCandidates()', $service);
        $this->assertStringContainsString("whereHas('workspaceMemberships'", $service);
        $this->assertStringContainsString("where('status', 'active')", $service);
        $this->assertStringContainsString('matching active system users are suggested as you type', $service);
        $this->assertStringContainsString('Active FlowTrack users in the current workspace', $service);

        // Purchase Order -> Artwork Team uses Administration > Users & role
        // assignments. It no longer depends on current Order task assignees.
        $this->assertStringContainsString('artworkTeamMembers()', $service);
        $this->assertStringContainsString('Department::query()', $service);
        $this->assertStringContainsString("'artworkteam'", $service);
        $this->assertStringContainsString("'design'", $service);
        $this->assertStringContainsString("whereIn('department_id', \$departmentIds->all())", $service);
        $this->assertStringContainsString("\$method = \$departmentIds->isNotEmpty() ? 'orWhereHas' : 'whereHas';", $service);
        $this->assertStringContainsString("\$query->{\$method}('roles'", $service);
        $this->assertStringContainsString("['artworkteam', 'artwork']", $service);
        $this->assertStringContainsString('Users & role assignments — Artwork Team users', $service);
        $this->assertStringNotContainsString('artworkPhaseAssignees($job)', $service);
        $this->assertStringContainsString('purchaseOrderRecipientSelection(', $service);
        $this->assertStringContainsString("'orderWorkflowActionPayload.to_email'", $service);
        $this->assertStringContainsString("'orderWorkflowActionPayload.cc_emails'", $service);
        $this->assertStringContainsString('filter_var($toEmail, FILTER_VALIDATE_EMAIL)', $service);
        $this->assertStringContainsString('$candidateByEmail', $service);
        $this->assertStringContainsString('\'assignee\' => $primary ?: $legacyAssignee', $service);
        $this->assertStringContainsString('cc: $ccEmails->all()', $service);
        $this->assertStringContainsString('$artworkPreparationTask = $assignmentRecipient', $service);
        $this->assertStringContainsString('private function artworkPreparationTask(', $service);
        $this->assertStringContainsString('assignArtworkPreparationTask(', $service);

        // Preview and send share the exact same email Blade/view data.
        $this->assertStringContainsString("view('emails.orders.workflow-handoff', \$viewData)->render()", $service);
        $this->assertStringContainsString("'html' => \$previewHtml", $service);
        $this->assertStringContainsString("'delivery' => \$emailServiceEnabled ? \$this->deliveryLabel()", $service);
        $this->assertStringContainsString('private function sourceDocuments(', $service);
        $this->assertStringContainsString('currentArtworkDocuments(', $service);
        $this->assertStringContainsString('attachments: $attachments', $service);
        $this->assertStringContainsString("'from_address' => \$this->senderAddress()", $service);

        $this->assertStringContainsString("'variant' => 'purchase_order_email'", $actions);
        $this->assertStringContainsString('OrderWorkflowEmailService::class)->send', $actions);
        $this->assertStringNotContainsString("['NEW_SEND_PO_ARTWORK', 'PROD_START'", $actions);

        $this->assertStringNotContainsString('OrderWorkflowEmailService::class', $modal);
        $this->assertStringContainsString('$emailHandoffPreview = (array) ($modalPreview[\'email_handoff_preview\'] ?? []);', $modal);
        $this->assertStringContainsString('wire:init="loadOrderWorkflowActionEmailPreview"', $modal);
        $this->assertStringContainsString('<x-email.handoff-preview', $modal);
        $this->assertStringContainsString('emptyRecipientText=', $modal);
        $this->assertStringContainsString('orderWorkflowActionEmail', $modal);
        $this->assertStringContainsString('wire:model.live.debounce.300ms="orderWorkflowActionPayload.to_email"', $modal);
        $this->assertStringContainsString('wire:model.live.debounce.300ms="orderWorkflowActionPayload.cc_emails"', $modal);
        $this->assertStringContainsString('Enter email or search Artwork Team', $modal);
        $this->assertStringContainsString('Add email addresses, separated by commas', $modal);
        $this->assertStringContainsString('will receive the Purchase Order by email and will be automatically assigned to', $modal);
        $this->assertStringContainsString('Prepare &amp; Upload Artwork', $modal);

        // Artwork -> Order Team now uses one Gmail-style To field. It accepts
        // multiple addresses and suggests matching Order Team users as typed.
        $this->assertStringContainsString('artworkRecipientSelection(', $service);
        $this->assertStringContainsString("'orderWorkflowActionPayload.to_emails'", $service);
        $this->assertStringContainsString("preg_split('/[\\s,;]+/', \$toInput)", $service);
        $this->assertStringContainsString('Add no more than 10 To email addresses.', $service);
        $this->assertStringContainsString('wire:model.live.debounce.300ms="orderWorkflowActionPayload.to_emails"', $modal);
        $this->assertStringContainsString('Enter email or search users', $modal);
        $this->assertStringContainsString('aria-label="System user suggestions"', $modal);
        $this->assertStringContainsString('ft-po-mail-row--single', $modal);
        $this->assertStringContainsString('.ft-artwork-mail-recipients', $detailCss);
        $this->assertStringNotContainsString('artwork-cc-emails', $modal);

        $this->assertStringContainsString('ft-po-mail-recipients', $modal);
        $this->assertStringContainsString('ft-po-mail-cc-toggle', $modal);
        $this->assertStringContainsString('ft-po-mail-suggestions', $modal);
        $this->assertStringNotContainsString('ft-po-preview-details', $modal);
        $this->assertStringNotContainsString('property="orderWorkflowActionPayload.cc_user_ids"', $modal);
        $this->assertStringNotContainsString('wire:model.live.debounce.350ms="orderWorkflowActionPayload.external_cc_emails"', $modal);
        $this->assertStringContainsString('.ft-po-mail-recipients', $detailCss);
        $this->assertStringContainsString('.ft-po-mail-cc-toggle', $detailCss);
        $this->assertStringContainsString('.ft-po-mail-suggestions', $detailCss);

        $this->assertStringContainsString('Exact email that will be sent', $preview);
        $this->assertStringContainsString('ft-order-email-recipient-chip', $preview);
        $this->assertStringContainsString('Recipient rule', $preview);
        $this->assertStringContainsString('empty_recipient_message', $preview);
        $this->assertStringContainsString('srcdoc="{{ $html }}"', $preview);
        $this->assertStringContainsString('Message body preview', $preview);
        $this->assertStringContainsString('<span>CC</span>', $preview);

        $this->assertStringContainsString('Purchase Order ready for Artwork', $email);
        $this->assertStringContainsString('Artwork ready for Order Team', $email);
        $this->assertStringContainsString('$brand', $email);
    }
}
