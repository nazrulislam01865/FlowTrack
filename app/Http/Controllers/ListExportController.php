<?php

namespace App\Http\Controllers;

use App\Services\ListExportService;
use App\Services\WorkspaceSettingsService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ListExportController extends Controller
{
    public function orders(Request $request, ListExportService $exports): StreamedResponse
    {
        [$dateFrom, $dateTo] = $this->exportDateRange($request);

        return $exports->exportOrders($request->user(), [
            'search' => trim((string) $request->query('search', '')),
            'client_id' => $request->integer('client') ?: null,
            'phase_id' => $request->integer('phase') ?: null,
            'assignee_id' => $request->integer('assignee') ?: null,
            'owner_id' => $request->integer('owner') ?: null,
            'metric_filter' => trim((string) $request->query('metric', '')),
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'bulk_import_id' => $request->integer('import') ?: null,
        ]);
    }

    public function inquiries(Request $request, ListExportService $exports): StreamedResponse
    {
        $quick = trim((string) $request->query('quick', 'all'));
        if (! in_array($quick, ['all', 'attention'], true)) {
            $quick = 'all';
        }
        [$dateFrom, $dateTo] = $this->exportDateRange($request);

        return $exports->exportInquiries($request->user(), [
            'search' => trim((string) $request->query('search', '')),
            'quick' => $quick,
            'metric_filter' => trim((string) $request->query('metric', '')),
            'client_id' => $request->integer('client') ?: null,
            'status' => trim((string) $request->query('status', '')),
            'hide_completed' => $request->boolean('hide_completed'),
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
        ]);
    }

    /**
     * Resolve the export period in the user's/workspace display timezone.
     * Existing direct links without export_period keep their explicit date
     * range for backward compatibility; modal exports always use a preset.
     *
     * @return array{0:string,1:string}
     */
    private function exportDateRange(Request $request): array
    {
        $period = trim((string) $request->query('export_period', ''));
        if ($period === '') {
            return [
                trim((string) $request->query('date_from', '')),
                trim((string) $request->query('date_to', '')),
            ];
        }

        $settings = app(WorkspaceSettingsService::class);
        $now = $settings->localNow();

        return match ($period) {
            'today' => [$now->format('Y-m-d'), $now->format('Y-m-d')],
            'last_7_days' => [$now->subDays(6)->format('Y-m-d'), $now->format('Y-m-d')],
            'last_30_days' => [$now->subDays(29)->format('Y-m-d'), $now->format('Y-m-d')],
            'this_month' => [$now->startOfMonth()->format('Y-m-d'), $now->endOfMonth()->format('Y-m-d')],
            'selected_month' => $this->selectedMonthRange($request, $settings),
            'all_time' => ['', ''],
            default => [$now->startOfMonth()->format('Y-m-d'), $now->endOfMonth()->format('Y-m-d')],
        };
    }

    /** @return array{0:string,1:string} */
    private function selectedMonthRange(Request $request, WorkspaceSettingsService $settings): array
    {
        $month = trim((string) $request->query('export_month', ''));
        $now = $settings->localNow();

        if (! preg_match('/^\d{4}-\d{2}$/', $month)) {
            return [$now->startOfMonth()->format('Y-m-d'), $now->endOfMonth()->format('Y-m-d')];
        }

        try {
            $selected = \Carbon\CarbonImmutable::createFromFormat('!Y-m', $month, $settings->displayTimezone());
        } catch (\Throwable) {
            $selected = null;
        }

        if (! $selected || $selected->format('Y-m') !== $month) {
            return [$now->startOfMonth()->format('Y-m-d'), $now->endOfMonth()->format('Y-m-d')];
        }

        return [$selected->startOfMonth()->format('Y-m-d'), $selected->endOfMonth()->format('Y-m-d')];
    }
}
