<?php

namespace Tests\Unit;

use App\Services\SupplierBridge\ApprovedOrderArtwork;
use PHPUnit\Framework\TestCase;

final class SupplierApprovedArtworkRulesTest extends TestCase
{
    private function task(string $key, string $status, bool $completed = false): object
    {
        return (object) [
            'automation_key' => $key, 'status' => $status,
            'completed_at' => $completed ? '2026-10-12 10:00:00' : null,
        ];
    }

    public function test_internal_confirmation_and_handoff_releases_current_artwork_before_client_erp_decision(): void
    {
        $policy = new ApprovedOrderArtwork();
        $internal = $this->task('ART_INTERNAL_REVIEW', 'Completed', true);
        $sent = $this->task('ART_SEND_ORDER_TEAM', 'Completed', true);
        $client = $this->task('ART_CLIENT_ERP_DECISION', 'Ready');
        $sample = $this->task('ART_SAMPLE_APPROVAL', 'Not Started');

        $this->assertTrue($policy->confirmed(2, collect([$internal, $sent, $client, $sample])));
        $this->assertFalse($policy->confirmed(2, collect([
            $this->task('ART_INTERNAL_REVIEW', 'Ready'), $sent, $client, $sample,
        ])));
        $this->assertFalse($policy->confirmed(2, collect([$internal, $sent, $client,
            $this->task('ART_SAMPLE_APPROVAL', 'Ready'),
        ])));
        $this->assertFalse($policy->confirmed(2, collect([$internal, $sent,
            $this->task('ART_CLIENT_ERP_DECISION', 'Waiting for Sample Approval'), $sample,
        ])));
    }

    public function test_supplier_cannot_download_until_approval_is_complete(): void
    {
        $policy = new ApprovedOrderArtwork();
        $internal = $this->task('ART_INTERNAL_REVIEW', 'Completed', true);
        $client = $this->task('ART_CLIENT_ERP_DECISION', 'Completed', true);
        $samplePending = $this->task('ART_SAMPLE_APPROVAL', 'In Progress');
        $this->assertFalse($policy->confirmed(2, collect([$internal, $client, $samplePending])));
        $this->assertTrue($policy->confirmed(2, collect([
            $internal, $client, $this->task('ART_SAMPLE_APPROVAL', 'Skipped'),
        ])));
        $this->assertFalse($policy->confirmed(3, collect([
            $this->task('ART_INTERNAL_REVIEW', 'In Progress'), $client,
        ])));
        $this->assertTrue($policy->confirmed(3, collect([$internal])));
    }
}
