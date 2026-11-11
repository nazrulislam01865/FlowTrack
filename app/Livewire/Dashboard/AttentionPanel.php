<?php

namespace App\Livewire\Dashboard;

use App\Queries\Dashboard\DashboardAttentionQuery;
use App\Services\WorkspaceSettingsService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\QueryException;

final class AttentionPanel extends Panel
{
    public string $attentionTab = 'all';
    protected function heading(): string { return 'Needs attention'; }
    protected function placeholderHeight(): int { return 300; }

    public function setAttentionTab(string $tab): void
    {
        abort_unless(in_array($tab, ['all', 'orders', 'inquiries'], true), 422);
        $this->attentionTab = $tab;
    }

    public function render(): View
    {
        if (! $this->dashboardReady) return $this->placeholder();
        [$actor, $filters] = $this->authorizedFilters();
        abort_unless(in_array($this->attentionTab, ['all', 'orders', 'inquiries'], true), 422);
        $clientId = $filters->clientId;
        $departmentId = $filters->departmentId;
        $query = $filters->search;
        $today = app(WorkspaceSettingsService::class)->localToday();
        try {
            $source = app(DashboardAttentionQuery::class)->handleVisible($actor, $filters);
            // Preserve both count totals and the legacy 3+3 combined ordering.
            $attentionOrders = $this->filterCollection(
                $source['attentionOrders'], $clientId, $departmentId, $query,
                fn ($row): array => [
                    $row->job_number, $row->title, $row->attention_reason,
                    $row->client?->name, $row->owner?->name,
                    $row->flaggedTasks?->first()?->attention_reason,
                    $row->tasks?->pluck('title')->filter()->implode(' '),
                    $row->tasks?->pluck('status')->filter()->implode(' '),
                    $row->tasks?->pluck('attention_reason')->filter()->implode(' '),
                ],
                fn ($row): ?int => $row->client_id ? (int) $row->client_id : null,
                fn ($row): ?int => $row->owner?->department_id ? (int) $row->owner->department_id : null,
            );
            $attentionInquiries = $this->filterCollection(
                $source['attentionInquiries'], $clientId, $departmentId, $query,
                fn ($row): array => [
                    $row->inquiry_number, $row->subject, $row->status,
                    $row->client?->name, $row->owner?->name,
                    $row->currentTask?->title, $row->currentTask?->status,
                    $row->currentTask?->attention_reason, $row->currentTask?->assignee?->name,
                ],
                fn ($row): ?int => $row->client_id ? (int) $row->client_id : null,
                fn ($row): ?int => $row->currentTask?->assignee?->department_id
                    ? (int) $row->currentTask->assignee->department_id
                    : ($row->owner?->department_id ? (int) $row->owner->department_id : null),
            );
        } catch (QueryException $exception) {
            return $this->failed($exception);
        }
        $attentionSort = static function (array $left, array $right) use ($today): int {
            $score = static function (array $item) use ($today): array {
                $row = $item['record'];
                $due = $item['kind'] === 'orders'
                    ? $row->delivery_date
                    : ($row->currentTask?->due_date ?: $row->required_delivery_date);
                return [
                    $due && $due->lt($today) ? 0 : 1,
                    ((bool) ($row->needs_attention ?? false) || (bool) ($row->attention_requested ?? false)) ? 0 : 1,
                    -($row->updated_at?->timestamp ?? 0),
                ];
            };
            return $score($left) <=> $score($right);
        };
        $items = match ($this->attentionTab) {
            'orders' => $attentionOrders->map(fn ($row) => ['kind' => 'orders', 'record' => $row])->take(6)->values(),
            'inquiries' => $attentionInquiries->map(fn ($row) => ['kind' => 'inquiries', 'record' => $row])->take(6)->values(),
            default => $attentionOrders->take(3)->map(fn ($row) => ['kind' => 'orders', 'record' => $row])
                ->concat($attentionInquiries->take(3)->map(fn ($row) => ['kind' => 'inquiries', 'record' => $row]))
                ->sort($attentionSort)->values(),
        };
        return view('livewire.dashboard.attention-panel', [
            'attentionOrderCount' => $attentionOrders->count(),
            'attentionInquiryCount' => $attentionInquiries->count(),
            'attentionTotalCount' => $attentionOrders->count() + $attentionInquiries->count(),
            'attentionItems' => $items,
        ]);
    }
}
