<?php

namespace Tests\Feature;

use App\Jobs\DeliverSupplierRfq;
use App\Models\InquiryRfqInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class InquirySupplierRfqFastBridgeTest extends TestCase
{
    use RefreshDatabase;

    public function test_supplier_scoped_invitations_have_a_persisted_item_mapping(): void
    {
        $this->assertTrue(Schema::hasTable('inquiry_rfq_invitation_items'));
        $this->assertTrue(class_exists(DeliverSupplierRfq::class));
        $this->assertTrue(method_exists(InquiryRfqInvitation::class, 'scopedItemIds'));
    }

    public function test_supplier_rfq_delivery_is_decoupled_from_email_and_uses_the_critical_worker(): void
    {
        $service = (string) file_get_contents(app_path('Services/SupplierBridge/DeliverRfq.php'));
        $creation = (string) file_get_contents(app_path('Services/Inquiries/InquiryRfqService.php'));
        $planner = (string) file_get_contents(app_path('Livewire/Inquiries/Concerns/ManagesInquiryCreateRfq.php'));
        $quote = (string) file_get_contents(app_path('Http/Controllers/SupplierBridge/ReceiveSupplierQuotation.php'));
        $this->assertStringContainsString("->onQueue('supplier-critical')", $service);
        $this->assertStringContainsString('->whereNull(\'next_attempt_at\')', $service);
        $this->assertStringContainsString('->scopedItemIds()', $service);
        $this->assertStringContainsString("if ($".'sendEmail) {', $creation);
        $this->assertStringContainsString("'row_indexes' => [", $planner);
        $this->assertStringContainsString('->scopedItemIds()', $quote);
    }

    public function test_reference_file_signature_is_version_scoped(): void
    {
        $source = file_get_contents(app_path('Http/Controllers/SupplierBridge/DownloadRfqDocument.php'));
        $this->assertStringContainsString('X-FlowTrack-Document-Version', $source);
        $this->assertStringContainsString('hash_hmac', $source);
    }
}
