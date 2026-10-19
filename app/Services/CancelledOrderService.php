<?php

namespace App\Services;

use App\Models\Client;
use App\Models\FlowJob;
use App\Models\User;
use App\Models\WorkflowPhase;
use App\Support\UserLocalTime;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CancelledOrderService
{
    public const PER_PAGE = 6;

    /** @var array<string,string> */
    public const REASON_LABELS = [
        'client_request' => 'Client request',
        'duplicate_order' => 'Duplicate order',
        'supplier_unavailable' => 'Supplier unavailable',
        'pricing_not_approved' => 'Pricing not approved',
        'payment_issue' => 'Payment issue',
        'other' => 'Other',
    ];

    public function sidebarCount(User $user): int
    {
        return $this->cancelledBaseQuery($user)->count('flow_jobs.id');
    }

    public function paginate(User $user, array $filters, int $perPage = self::PER_PAGE): LengthAwarePaginator
    {
        return $this->filteredQuery($user, $filters)
            ->with($this->relations())
            ->paginate(max(1, min(50, $perPage)));
    }

    /** @return array{total:int,this_month:int,month_label:string,common_reason_label:string,common_reason_count:int,restorable:int} */
    public function metrics(User $user, array $filters = []): array
    {
        /*
         * Summary cards intentionally use the same filtered dataset as the table.
         * With no filters this is the complete visible cancellation history; when
         * filters change the cards update with the table instead of showing stale
         * global totals.
         */
        $base = $this->filteredQuery($user, $filters)->reorder();
        $workspace = app(WorkspaceSettingsService::class);
        $monthStart = $workspace->localNow()->startOfMonth()->utc();
        $monthEnd = $workspace->localNow()->endOfMonth()->utc();

        /*
         * Do not GROUP BY the CASE expression here.
         *
         * MySQL with ONLY_FULL_GROUP_BY can reject the repeated CASE expression
         * because it references flow_jobs.cancellation_reason, even when the
         * SELECT and GROUP BY expressions are textually identical. We only need
         * one column for this metric, so classify the visible cancellation reason
         * in PHP instead. This keeps the query permission-scoped and is portable
         * across strict MySQL/MariaDB configurations.
         */
        $reasonCounts = (clone $base)
            ->reorder()
            ->select('flow_jobs.cancellation_reason')
            ->pluck('flow_jobs.cancellation_reason')
            ->map(function ($reason): string {
                $plainReason = app(RichTextService::class)
                    ->plainText((string) $reason);

                return $this->classifyReason($plainReason);
            })
            ->countBy();

        $commonKey = (string) ($reasonCounts->sortDesc()->keys()->first() ?: 'other');
        $commonCount = (int) ($reasonCounts[$commonKey] ?? 0);

        return [
            'total' => (clone $base)->count('flow_jobs.id'),
            'this_month' => (clone $base)
                ->whereRaw('COALESCE(flow_jobs.cancelled_at, flow_jobs.updated_at) BETWEEN ? AND ?', [$monthStart, $monthEnd])
                ->count('flow_jobs.id'),
            'month_label' => $workspace->localNow()->format('F Y'),
            'common_reason_label' => self::REASON_LABELS[$commonKey] ?? 'Other',
            'common_reason_count' => $commonCount,
            'restorable' => (clone $base)
                ->whereNull('flow_jobs.completed_at')
                ->whereHas('phase', fn (Builder $phase) => $phase->where('sequence', '<=', 4))
                ->whereDoesntHave('invoices', fn (Builder $invoice) => $invoice->whereRaw("LOWER(TRIM(COALESCE(status, ''))) NOT IN ('draft','cancelled')"))
                ->count('flow_jobs.id'),
        ];
    }

    public function clientOptions(User $user): Collection
    {
        $ids = $this->cancelledBaseQuery($user)
            ->reorder()
            ->whereNotNull('flow_jobs.client_id')
            ->select('flow_jobs.client_id')
            ->distinct();

        return Client::query()
            ->whereIn('id', $ids)
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    public function phaseOptions(User $user): Collection
    {
        $ids = $this->cancelledBaseQuery($user)
            ->reorder()
            ->whereNotNull('flow_jobs.workflow_phase_id')
            ->select('flow_jobs.workflow_phase_id')
            ->distinct();

        return WorkflowPhase::query()
            ->whereIn('id', $ids)
            ->orderBy('sequence')
            ->orderBy('name')
            ->get(['id', 'name', 'short_name', 'sequence', 'color']);
    }

    public function cancellerOptions(User $user): Collection
    {
        $ids = $this->cancelledBaseQuery($user)
            ->reorder()
            ->whereNotNull('flow_jobs.cancelled_by')
            ->select('flow_jobs.cancelled_by')
            ->distinct();

        return User::query()
            ->whereIn('id', $ids)
            ->orderBy('name')
            ->get(['id', 'name', 'profile_image_path']);
    }

    /** @return array<int,array<string,mixed>> */
    public function rows(LengthAwarePaginator|Collection $orders): array
    {
        $items = $orders instanceof LengthAwarePaginator
            ? collect($orders->items())
            : $orders;

        return $items->map(fn (FlowJob $order) => $this->present($order))->all();
    }

    public function filteredQuery(User $user, array $filters): Builder
    {
        $search = trim((string) ($filters['search'] ?? ''));
        $clientId = max(0, (int) ($filters['client_id'] ?? 0));
        $phaseId = max(0, (int) ($filters['phase_id'] ?? 0));
        $reason = trim((string) ($filters['reason'] ?? ''));
        $cancelledBy = max(0, (int) ($filters['cancelled_by'] ?? 0));
        [$fromUtc, $toUtc] = app(WorkspaceSettingsService::class)->localDateRangeUtcBounds(
            $filters['from_date'] ?? null,
            $filters['to_date'] ?? null,
        );

        $query = $this->cancelledBaseQuery($user);

        if ($search !== '') {
            $like = '%'.$search.'%';
            $legacySearch = preg_replace('/^ORDER-/i', 'JOB-', $search) ?: $search;
            $legacyLike = '%'.$legacySearch.'%';

            $query->where(function (Builder $match) use ($like, $legacyLike): void {
                $match
                    ->whereLike('flow_jobs.job_number', $like)
                    ->orWhereLike('flow_jobs.job_number', $legacyLike)
                    ->orWhereLike('flow_jobs.order_number', $like)
                    ->orWhereLike('flow_jobs.title', $like)
                    ->orWhereLike('flow_jobs.product', $like)
                    ->orWhereLike('flow_jobs.cancellation_reason', $like)
                    ->orWhereHas('client', fn (Builder $client) => $client->whereLike('name', $like))
                    ->orWhereHas('items', fn (Builder $item) => $item
                        ->where('is_removed', false)
                        ->where(function (Builder $product) use ($like): void {
                            $product->whereLike('product_name', $like)
                                ->orWhereLike('category_name', $like);
                        }))
                    ->orWhereHas('sourceInquiry', fn (Builder $inquiry) => $inquiry
                        ->whereLike('reference_number', $like)
                        ->orWhereLike('inquiry_number', $like));
            });
        }

        if ($clientId > 0) {
            $query->where('flow_jobs.client_id', $clientId);
        }

        if ($phaseId > 0) {
            $query->where('flow_jobs.workflow_phase_id', $phaseId);
        }

        if (array_key_exists($reason, self::REASON_LABELS)) {
            $query->whereRaw($this->reasonCaseSql().' = ?', [$reason]);
        }

        if ($cancelledBy > 0) {
            $query->where('flow_jobs.cancelled_by', $cancelledBy);
        }

        if ($fromUtc) {
            $query->whereRaw('COALESCE(flow_jobs.cancelled_at, flow_jobs.updated_at) >= ?', [$fromUtc]);
        }

        if ($toUtc) {
            $query->whereRaw('COALESCE(flow_jobs.cancelled_at, flow_jobs.updated_at) <= ?', [$toUtc]);
        }

        return $query
            ->reorder()
            ->orderByRaw('COALESCE(flow_jobs.cancelled_at, flow_jobs.updated_at) DESC')
            ->orderByDesc('flow_jobs.id');
    }

    public function export(User $user, array $filters): StreamedResponse
    {
        $access = app(AccessControlService::class);
        abort_unless($access->can($user, 'jobs', 'view'), 403);
        abort_unless($access->can($user, 'reports', 'export'), 403);

        $book = new Spreadsheet();
        $book->getProperties()
            ->setCreator('FlowTrack')
            ->setTitle('Cancelled Orders')
            ->setSubject('Cancelled order history');

        $sheet = $book->getActiveSheet();
        $sheet->setTitle('Cancelled Orders');

        $headers = [
            'Order',
            'Reference',
            'Created Date',
            'Client',
            'Product',
            'Quantity',
            'Last Stage',
            'Status',
            'Cancellation Reason',
            'Cancelled By',
            'Cancelled Date',
            'Order Owner',
        ];
        $sheet->fromArray($headers, null, 'A1');

        $rowNumber = 2;
        $query = $this->filteredQuery($user, $filters)
            ->reorder()
            ->orderBy('flow_jobs.id')
            ->with($this->relations());

        $query->chunkById(250, function (Collection $orders) use ($sheet, &$rowNumber): void {
            foreach ($orders as $order) {
                $row = $this->present($order);
                $sheet->fromArray([
                    $row['order_number'],
                    $row['reference'],
                    $row['created_date'],
                    $row['client_name'],
                    $row['product_name'],
                    $row['quantity'],
                    $row['stage_name'],
                    'Cancelled',
                    $row['reason_label'].($row['reason_detail'] !== '' ? ': '.$row['reason_detail'] : ''),
                    $row['cancelled_by_name'],
                    $row['cancelled_at_export'],
                    $row['owner_name'],
                ], null, 'A'.$rowNumber);
                $rowNumber++;
            }
        }, 'flow_jobs.id', 'id');

        $lastRow = max(1, $rowNumber - 1);
        $lastColumn = Coordinate::stringFromColumnIndex(count($headers));
        $range = 'A1:'.$lastColumn.$lastRow;

        $sheet->freezePane('A2');
        $sheet->setAutoFilter('A1:'.$lastColumn.'1');
        $sheet->getStyle($range)->getAlignment()
            ->setVertical(Alignment::VERTICAL_CENTER)
            ->setWrapText(true);
        $sheet->getStyle($range)->getBorders()->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN)
            ->getColor()->setRGB('DCE6EF');
        $sheet->getStyle('A1:'.$lastColumn.'1')->getFont()->setBold(true)->getColor()->setRGB('31445F');
        $sheet->getStyle('A1:'.$lastColumn.'1')->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB('F5F8FB');
        $sheet->getRowDimension(1)->setRowHeight(28);

        $widths = [20, 18, 14, 18, 34, 11, 18, 13, 42, 20, 22, 20];
        foreach ($widths as $index => $width) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($index + 1))->setWidth($width);
        }

        $sheet->getPageSetup()
            ->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)
            ->setPaperSize(PageSetup::PAPERSIZE_A4)
            ->setFitToWidth(1)
            ->setFitToHeight(0);

        $filename = 'FlowTrack-Cancelled-Orders-'.app(WorkspaceSettingsService::class)->localNow()->format('Ymd-His').'.xlsx';

        return response()->streamDownload(function () use ($book): void {
            $writer = new Xlsx($book);
            $writer->setPreCalculateFormulas(false);
            $writer->save('php://output');
            $book->disconnectWorksheets();
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'private, no-store, no-cache, must-revalidate',
        ]);
    }

    private function cancelledBaseQuery(User $user): Builder
    {
        return app(JobService::class)
            ->visibleQuery($user)
            ->whereRaw("LOWER(TRIM(COALESCE(flow_jobs.status, ''))) = 'cancelled'");
    }

    /** @return array<int,string> */
    private function relations(): array
    {
        return [
            'client:id,name,logo_path',
            'phase:id,name,short_name,sequence,color',
            'cancelledBy:id,name,profile_image_path',
            'owner:id,name,profile_image_path',
            'sourceInquiry:id,inquiry_number,reference_number',
            'items' => fn ($items) => $items
                ->select(['id', 'flow_job_id', 'product_name', 'category_name', 'quantity', 'is_removed', 'sort_order'])
                ->where('is_removed', false)
                ->orderBy('sort_order')
                ->orderBy('id'),
        ];
    }

    /** @return array<string,mixed> */
    private function present(FlowJob $order): array
    {
        $items = $order->items->where('is_removed', false)->values();
        $firstItem = $items->first();
        $quantity = (int) $items->sum(fn ($item): int => max(0, (int) $item->quantity));
        if ($quantity < 1) {
            $quantity = max(0, (int) $order->quantity);
        }

        $productName = trim((string) ($firstItem?->product_name ?: $order->product ?: $order->title));
        $reasonText = $this->cleanReasonText((string) $order->cancellation_reason);
        $reasonKey = $this->classifyReason($reasonText);
        $cancelledAt = $order->cancelled_at ?: $order->updated_at;
        $phaseSequence = max(0, (int) ($order->phase?->sequence ?? 0));
        $reference = trim((string) ($order->order_number
            ?: $order->sourceInquiry?->reference_number
            ?: $order->sourceInquiry?->inquiry_number));

        return [
            'id' => (int) $order->id,
            'order_number' => $order->displayOrderNumber(),
            'reference' => $reference ?: '—',
            'created_date' => UserLocalTime::format($order->created_at, 'Y-m-d'),
            'client_name' => trim((string) $order->client?->name) ?: '—',
            'client_logo_url' => $order->client?->logoUrl(),
            'client_initial' => $this->initial((string) $order->client?->name),
            'product_name' => $productName ?: '—',
            'quantity' => $quantity,
            'stage_name' => trim((string) ($order->phase?->short_name ?: $order->phase?->name)) ?: '—',
            'stage_sequence' => $phaseSequence,
            'stage_color' => trim((string) ($order->phase?->color ?? '')),
            'reason_key' => $reasonKey,
            'reason_label' => self::REASON_LABELS[$reasonKey] ?? 'Other',
            'reason_detail' => $reasonText,
            'cancelled_by_name' => trim((string) $order->cancelledBy?->name) ?: 'System',
            'cancelled_by_initial' => $this->initial((string) $order->cancelledBy?->name ?: 'System'),
            'cancelled_by_image_url' => $this->profileImageUrl($order->cancelledBy),
            'cancelled_at_date' => UserLocalTime::format($cancelledAt, 'M j, Y'),
            'cancelled_at_time' => UserLocalTime::format($cancelledAt, 'g:i A'),
            'cancelled_at_export' => UserLocalTime::format($cancelledAt, 'Y-m-d H:i'),
            'owner_name' => trim((string) $order->owner?->name) ?: '—',
            'owner_initial' => $this->initial((string) $order->owner?->name),
            'owner_image_url' => $this->profileImageUrl($order->owner),
            'open_url' => route('jobs.index', ['open' => $order->id]),
        ];
    }


    private function cleanReasonText(string $value): string
    {
        $text = app(RichTextService::class)->plainText($value);

        /*
         * Rich cancellation notes can contain pasted images. The list page should
         * not render the editor's [Image] placeholder as if it were the reason.
         * Attachments remain available from the Order itself.
         */
        $text = preg_replace('/\[Image\]/iu', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    private function classifyReason(string $text): string
    {
        $text = Str::lower(trim($text));
        if ($text === '') return 'other';

        if (Str::contains($text, ['duplicate', 'duplicated'])) return 'duplicate_order';
        if (Str::contains($text, ['supplier unavailable', 'supplier not available', 'cannot source', 'could not be sourced', 'unable to source', 'supplier cannot'])) return 'supplier_unavailable';
        if (Str::contains($text, ['pricing', 'price not approved', 'price rejected', 'quotation', 'quote not approved', 'quote rejected'])) return 'pricing_not_approved';
        if (Str::contains($text, ['payment', 'invoice issue', 'invoice problem'])) return 'payment_issue';
        if (Str::contains($text, ['client request', 'client requested', 'customer request', 'customer requested', 'client cancelled', 'customer cancelled', 'client changed', 'customer changed'])) return 'client_request';

        return 'other';
    }

    private function reasonCaseSql(): string
    {
        $value = "LOWER(COALESCE(flow_jobs.cancellation_reason, ''))";

        return "CASE
            WHEN {$value} LIKE '%duplicate%' THEN 'duplicate_order'
            WHEN ({$value} LIKE '%supplier unavailable%' OR {$value} LIKE '%supplier not available%' OR {$value} LIKE '%cannot source%' OR {$value} LIKE '%could not be sourced%' OR {$value} LIKE '%unable to source%' OR {$value} LIKE '%supplier cannot%') THEN 'supplier_unavailable'
            WHEN ({$value} LIKE '%pricing%' OR {$value} LIKE '%price not approved%' OR {$value} LIKE '%price rejected%' OR {$value} LIKE '%quotation%' OR {$value} LIKE '%quote not approved%' OR {$value} LIKE '%quote rejected%') THEN 'pricing_not_approved'
            WHEN ({$value} LIKE '%payment%' OR {$value} LIKE '%invoice issue%' OR {$value} LIKE '%invoice problem%') THEN 'payment_issue'
            WHEN ({$value} LIKE '%client request%' OR {$value} LIKE '%client requested%' OR {$value} LIKE '%customer request%' OR {$value} LIKE '%customer requested%' OR {$value} LIKE '%client cancelled%' OR {$value} LIKE '%customer cancelled%' OR {$value} LIKE '%client changed%' OR {$value} LIKE '%customer changed%') THEN 'client_request'
            ELSE 'other'
        END";
    }

    private function profileImageUrl(?User $user): ?string
    {
        return $user?->profileImageUrl();
    }

    private function initial(string $name): string
    {
        $name = trim($name);
        return $name === '' ? '—' : mb_strtoupper(mb_substr($name, 0, 1));
    }
}
