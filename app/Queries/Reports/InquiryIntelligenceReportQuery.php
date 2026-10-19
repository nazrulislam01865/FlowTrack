<?php
namespace App\Queries\Reports;
use App\Models\User;
use App\Services\AccessControlService;
use App\Services\InquiryIntelligenceService;
final class InquiryIntelligenceReportQuery {
    public function __construct(private readonly InquiryIntelligenceService $reports, private readonly AccessControlService $access) {}
    public function data(User $actor,array $filters): array { abort_unless($this->access->can($actor,'reports','view'),403); return $this->reports->data($actor,$filters); }
    public function exportRows(User $actor,array $filters): array { abort_unless($this->access->can($actor,'reports','view'),403); return $this->reports->exportRows($actor,$filters); }
}
