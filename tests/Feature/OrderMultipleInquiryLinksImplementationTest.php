<?php

namespace Tests\Feature;

use Tests\TestCase;

class OrderMultipleInquiryLinksImplementationTest extends TestCase
{
    public function test_order_supports_multiple_inquiry_links_with_legacy_primary_compatibility(): void
    {
        $migration = file_get_contents(database_path('migrations/2026_09_03_170000_create_flow_job_inquiries_table.php'));
        $jobModel = file_get_contents(app_path('Models/FlowJob.php'));
        $inquiryModel = file_get_contents(app_path('Models/Inquiry.php'));
        $service = file_get_contents(app_path('Services/LegacyJobService.php'));
        $livewire = file_get_contents(app_path('Livewire/Jobs/Concerns/ManagesOrderInquiryLink.php'));
        $builder = file_get_contents(app_path('Livewire/Jobs/Concerns/BuildsOrderPageData.php'));
        $view = file_get_contents(resource_path('views/components/jobs/detail-inquiry.blade.php'));
        $tabs = file_get_contents(resource_path('views/components/jobs/order-detail/tabs.blade.php'));

        $this->assertStringContainsString("Schema::create('flow_job_inquiries'", $migration);
        $this->assertStringContainsString("unique('inquiry_id'", $migration);
        $this->assertStringContainsString('source_inquiry_id', $migration);
        $this->assertStringContainsString('function linkedInquiries(): BelongsToMany', $jobModel);
        $this->assertStringContainsString('function linkedOrders(): BelongsToMany', $inquiryModel);

        $this->assertStringContainsString('$lockedJob->linkedInquiries()->attach', $service);
        $this->assertStringNotContainsString('abort_if($lockedJob->source_inquiry_id, 409', $service);
        $this->assertStringContainsString('The first linked Inquiry remains the legacy primary/source Inquiry.', $service);
        $this->assertStringContainsString('unlinkSourceInquiry(FlowJob $job, User $actor, ?int $inquiryId = null)', $service);
        $this->assertStringContainsString('replacementInquiryId', $service);

        $this->assertStringContainsString('openInquiryUnlinkConfirm(int $inquiryId)', $livewire);
        $this->assertStringContainsString('$this->unlinkInquiryId', $livewire);
        $this->assertStringContainsString('Keep the search available after the first link', $builder);
        $this->assertStringContainsString('Multiple Inquiries can be linked to one Order', $view);
        $this->assertStringContainsString('Link another inquiry', $view);
        $this->assertStringContainsString('openInquiryUnlinkConfirm({{ $linked->id }})', $view);
        $this->assertStringContainsString('linked_inquiries_count', $tabs);
    }
}
