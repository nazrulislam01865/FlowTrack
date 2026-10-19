<?php

namespace Tests\Unit;

use App\Services\SupplierBridge\ApprovedOrderArtwork;
use PHPUnit\Framework\TestCase;

final class ApprovedSupplierArtworkStateTest extends TestCase
{
    private function task(string $key, string $status): object
    {
        return (object) ['automation_key' => $key, 'status' => $status,
            'completed_at' => $status === 'Completed' ? '2026-10-13 12:00:00' : null];
    }

    public function test_untouched_optional_sample_does_not_hide_approved_artwork(): void
    {
        $tasks = collect([
            $this->task('ART_INTERNAL_REVIEW', 'Completed'),
            $this->task('ART_SEND_ORDER_TEAM', 'Completed'),
            $this->task('ART_CLIENT_ERP_DECISION', 'Not Started'),
            $this->task('ART_SAMPLE_APPROVAL', 'Ready'),
        ]);

        self::assertTrue((new ApprovedOrderArtwork())->confirmed(2, $tasks));
    }

    public function test_explicit_sample_branch_keeps_artwork_hidden_until_approved(): void
    {
        $tasks = collect([
            $this->task('ART_INTERNAL_REVIEW', 'Completed'),
            $this->task('ART_SEND_ORDER_TEAM', 'Completed'),
            $this->task('ART_CLIENT_ERP_DECISION', 'Waiting for Sample Approval'),
            $this->task('ART_SAMPLE_APPROVAL', 'Ready'),
        ]);

        self::assertFalse((new ApprovedOrderArtwork())->confirmed(2, $tasks));
    }

    public function test_unfinished_internal_review_never_exposes_uploaded_artwork(): void
    {
        $tasks = collect([
            $this->task('ART_INTERNAL_REVIEW', 'Ready'),
            $this->task('ART_SEND_ORDER_TEAM', 'Completed'),
        ]);

        self::assertFalse((new ApprovedOrderArtwork())->confirmed(2, $tasks));
    }
}
