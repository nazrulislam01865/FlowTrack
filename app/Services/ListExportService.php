<?php

namespace App\Services;

use App\Models\FlowJob;
use App\Models\Inquiry;
use App\Models\MasterRecord;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ListExportService
{
    public function exportOrders(User $user, array $filters): StreamedResponse
    {
        $access = app(AccessControlService::class);
        abort_unless($access->can($user, 'reports', 'export'), 403);
        abort_unless($access->can($user, 'jobs', 'view'), 403);

        $query = app(JobService::class)->ordersListQuery(
            $user,
            (string) ($filters['search'] ?? ''),
            $this->positiveInt($filters['client_id'] ?? null),
            $this->positiveInt($filters['phase_id'] ?? null),
            $this->positiveInt($filters['assignee_id'] ?? null),
            $this->positiveInt($filters['owner_id'] ?? null),
            (string) ($filters['metric_filter'] ?? ''),
            (string) ($filters['date_from'] ?? ''),
            (string) ($filters['date_to'] ?? ''),
            $this->positiveInt($filters['bulk_import_id'] ?? null),
        );

        $relations = [
            'client', 'supplier', 'sourceInquiry', 'workflow', 'phase', 'startedFromPhase',
            'owner', 'coordinator', 'creator', 'createdActivity.user', 'attentionRequester', 'orderFlag',
            'shippingSourceAddress', 'items.updatedBy', 'members.user',
            'phaseHistories.phase', 'phaseHistories.actor',
            'tasks' => fn ($tasks) => $access->applyTaskScope($tasks, $user)->with([
                'assignee.department', 'completionAssignee', 'phase', 'orderTaskStatus', 'orderTaskFlag',
                'documentCategory',
                'checklistItems', 'comments.user', 'documents.uploader', 'links.creator',
            ]),
            'documents.uploader', 'documents.task', 'activities.user',
        ];

        $canViewFinance = $access->can($user, 'finance', 'view');
        if ($canViewFinance) {
            $relations[] = 'invoices.billingContact';
            $relations[] = 'invoices.creator';
            $relations[] = 'invoices.items';
            $relations[] = 'invoices.payments.recorder';
            $relations[] = 'payments.invoice';
            $relations[] = 'payments.recorder';
            $relations[] = 'collection.owner';
            $relations[] = 'collection.updates.actor';
        }

        /** @var Collection<int, FlowJob> $orders */
        $orders = $query->with($relations)->get();
        $urgencyNames = $this->urgencyNameMap();
        $spreadsheet = $this->orderWorkbook($orders, $urgencyNames, $canViewFinance);

        return $this->download(
            $spreadsheet,
            'FlowTrack-orders-all-details-'.app(WorkspaceSettingsService::class)->localNow()->format('Ymd-His').'.xlsx'
        );
    }

    public function exportInquiries(User $user, array $filters): StreamedResponse
    {
        $access = app(AccessControlService::class);
        abort_unless($access->can($user, 'reports', 'export'), 403);
        abort_unless($access->can($user, 'inquiries', 'view'), 403);

        /** @var Builder $query */
        $query = app(InquiryService::class)->listQuery($user, $filters)
            ->reorder()
            ->orderByDesc('inquiries.created_at')
            ->orderByDesc('inquiries.id');

        /** @var Collection<int, Inquiry> $inquiries */
        $inquiries = $query->with([
            'client', 'owner', 'creator', 'attentionRequester', 'sourceTaskPack', 'sourceWorkflow', 'convertedJob',
            'items',
            'tasks' => fn ($tasks) => $access->applyInquiryTaskScope($tasks, $user)->with([
                'assignee.department', 'completionAssignee', 'setupAssignee', 'sourceTaskPackItem',
                'sourceWorkflowPhase', 'taskStatus', 'documents.uploader', 'comments.user', 'links.creator',
            ]),
            'documents.uploader', 'documents.task', 'activities.user',
        ])->get();

        $canViewFinance = $access->can($user, 'finance', 'view');
        $spreadsheet = $this->inquiryWorkbook($inquiries, $canViewFinance);

        return $this->download(
            $spreadsheet,
            'FlowTrack-inquiries-all-details-'.app(WorkspaceSettingsService::class)->localNow()->format('Ymd-His').'.xlsx'
        );
    }

    private function orderWorkbook(Collection $orders, Collection $urgencyNames, bool $canViewFinance): Spreadsheet
    {
        $book = new Spreadsheet();
        $book->getProperties()
            ->setCreator('FlowTrack')
            ->setTitle('Order list export')
            ->setSubject('Orders and related details');

        // Keep the downloaded workbook's FIRST tab aligned with the live Order list.
        // The remaining tabs preserve the existing full-detail/report data.
        $this->fillListViewSheet($book->getActiveSheet(), 'Order List', [
            'Created by / on', 'Order', 'Inquiry', 'Client / Products', 'Phase',
            'Flag', 'Owner / Delivery', 'Progress',
        ], $this->orderListRows($orders));

        $this->fillSheet($book->createSheet(), 'Order Details', [
            'Order Number', 'Reference Order No.', 'Order Title', 'Client', 'Order Status', 'Current Phase',
            'Order Priority', 'Order Progress %', 'Owner', 'Coordinator', 'Received Date',
            'Customer Requested Delivery Date', 'Estimated Delivery Date', 'Products', 'Product Count',
            'Total Quantity', 'Task Number', 'Task', 'Task Phase', 'Task Assignee', 'Assignee Email',
            'Assignee Department', 'Current Task Status', 'Task Flag', 'Task Priority', 'Task Progress %',
            'Task Start Date', 'Task Due Date', 'Required Document', 'Files', 'Links', 'Task Completed At',
            'Task Last Updated At', 'Order Last Updated At',
        ], $this->orderDetailRows($orders, $canViewFinance));

        $this->fillSheet($book->createSheet(), 'Orders', [
            'Order ID', 'Order Number', 'Reference Order No.', 'Repeat Order?', 'Repeat Order No.',
            'Order Title', 'Order Description', 'Notes', 'Client ID', 'Client Code', 'Client Name',
            'Source Inquiry', 'Workflow', 'Current Phase', 'Started From Phase', 'Status',
            'Order Flag', 'Priority', 'Progress %', 'Owner', 'Coordinator', 'Created By', 'Received Date',
            'Customer Requested Delivery Date', 'Estimated Delivery Date', 'Shipping Address',
            'Phone Country Code', 'Phone Number', 'Postal Code', 'Production Urgency', 'Shipment Urgency',
            'Primary Product', 'Category', 'Quantity', 'Commercial Value', 'Currency', 'Supplier', 'Warehouse',
            'Supplier Instruction', 'Source Row ID', 'Import Profile', 'Needs Attention?', 'Attention Requested?',
            'Attention Reason', 'Attention By', 'Product Count', 'Task Count', 'Open Task Count',
            'Completed Task Count', 'Latest Task Updated At', 'Completed At', 'Created At', 'Updated At',
        ], $orders->map(fn (FlowJob $order) => [
            $order->id,
            $order->displayOrderNumber(),
            $order->order_number,
            $this->yesNo($order->is_repeat_order),
            $order->repeat_order_number,
            $order->title,
            $this->plainText($order->description),
            $this->plainText($order->notes),
            $order->client_id,
            $order->client?->code,
            $order->client?->name,
            $order->sourceInquiry?->inquiry_number,
            $order->workflow?->name,
            $order->phase?->name,
            $order->startedFromPhase?->name,
            $order->status,
            $order->orderFlag?->name,
            $order->priority,
            $order->progress,
            $order->owner?->name,
            $order->coordinator?->name,
            $order->creator?->name,
            $this->date($order->received_date),
            $this->date($order->delivery_date),
            $this->date($order->estimated_delivery_date),
            $this->plainText($order->shipping_address),
            $order->shipping_phone_country_code,
            $order->shipping_phone,
            $order->shipping_postal_code,
            $this->masterNames($order->production_urgency_ids, $urgencyNames),
            $this->masterNames($order->shipment_urgency_ids, $urgencyNames),
            $order->product,
            $order->category,
            $order->quantity,
            $canViewFinance ? $order->commercial_value : '',
            $canViewFinance ? $order->currency : '',
            $order->supplier?->name,
            $order->warehouse,
            $this->plainText($order->supplier_instruction),
            $order->source_row_id,
            $order->import_profile,
            $this->yesNo($order->needs_attention),
            $this->yesNo($order->attention_requested),
            $this->plainText($order->attention_reason),
            $order->attentionRequester?->name,
            $this->orderProductCount($order),
            $order->tasks->count(),
            $order->tasks->filter(fn ($task) => !$task->completed_at)->count(),
            $order->tasks->filter(fn ($task) => (bool) $task->completed_at)->count(),
            $this->dateTime($this->latestUpdatedAt($order->tasks)),
            $this->dateTime($order->completed_at),
            $this->dateTime($order->created_at),
            $this->dateTime($order->updated_at),
        ]));

        $this->fillSheet($book->createSheet(), 'Products', [
            'Order Number', 'Item ID', 'Product', 'Category', 'Quantity', 'Unit Price', 'Notes', 'Updated By', 'Created At', 'Last Updated At',
        ], $orders->flatMap(fn (FlowJob $order) => $order->items->map(fn ($item) => [
            $order->displayOrderNumber(), $item->id, $item->product_name, $item->category_name, $item->quantity,
            $canViewFinance ? $item->unit_price : '', $this->plainText($item->notes), $item->updatedBy?->name,
            $this->dateTime($item->created_at), $this->dateTime($item->updated_at),
        ])));

        $this->fillSheet($book->createSheet(), 'Tasks', [
            'Order Number', 'Task ID', 'Task Number', 'Phase', 'Task', 'Description', 'Assignee', 'Assignee Email',
            'Assignee Department', 'Current Task Status', 'Status Master', 'Flag', 'Priority', 'Progress %',
            'Start Date', 'Due Date', 'Required Document Category', 'Document Requirement Source', 'File Count',
            'Link Count', 'Needs Attention?', 'Attention Reason', 'Completed At', 'Completed By', 'Created At',
            'Task Last Updated At',
        ], $orders->flatMap(fn (FlowJob $order) => $order->tasks->map(fn ($task) => [
            $order->displayOrderNumber(), $task->id, $task->task_number, $task->phase?->name, $task->title,
            $this->plainText($task->description), $task->assignee?->name, $task->assignee?->email,
            $task->assignee?->department?->name, $this->currentOrderTaskStatus($task), $task->orderTaskStatus?->name,
            $task->orderTaskFlag?->name, $task->priority, $task->progress, $this->date($task->start_date),
            $this->date($task->due_date), $task->documentCategory?->name, $task->document_requirement_source,
            $task->documents->count(), $task->links->count(), $this->yesNo($task->needs_attention), $this->plainText($task->attention_reason),
            $this->dateTime($task->completed_at), $task->completionAssignee?->name,
            $this->dateTime($task->created_at), $this->dateTime($task->updated_at),
        ])));

        $this->fillSheet($book->createSheet(), 'Task Checklist', [
            'Order Number', 'Task ID', 'Task Number', 'Task', 'Checklist Item', 'Completed?', 'Sort Order', 'Created At', 'Last Updated At',
        ], $orders->flatMap(fn (FlowJob $order) => $order->tasks->flatMap(fn ($task) => $task->checklistItems->map(fn ($item) => [
            $order->displayOrderNumber(), $task->id, $task->task_number, $task->title, $item->label,
            $this->yesNo($item->is_completed), $item->sort_order, $this->dateTime($item->created_at), $this->dateTime($item->updated_at),
        ]))));

        $this->fillSheet($book->createSheet(), 'Task Comments', [
            'Order Number', 'Task ID', 'Task Number', 'Task', 'Comment By', 'Comment', 'Created At', 'Last Updated At',
        ], $orders->flatMap(fn (FlowJob $order) => $order->tasks->flatMap(fn ($task) => $task->comments->map(fn ($comment) => [
            $order->displayOrderNumber(), $task->id, $task->task_number, $task->title, $comment->user?->name,
            $this->plainText($comment->body), $this->dateTime($comment->created_at), $this->dateTime($comment->updated_at),
        ]))));

        $this->fillSheet($book->createSheet(), 'Task Links', [
            'Order Number', 'Task ID', 'Task Number', 'Task', 'URL', 'Created By', 'Created At', 'Last Updated At',
        ], $orders->flatMap(fn (FlowJob $order) => $order->tasks->flatMap(fn ($task) => $task->links->map(fn ($link) => [
            $order->displayOrderNumber(), $task->id, $task->task_number, $task->title, $link->url,
            $link->creator?->name, $this->dateTime($link->created_at), $this->dateTime($link->updated_at),
        ]))));

        $this->fillSheet($book->createSheet(), 'Documents', [
            'Order Number', 'Document Number', 'Task ID', 'Task Number', 'Task', 'Category', 'Name', 'Note', 'MIME Type',
            'Size Bytes', 'Version', 'Final?', 'Uploaded By', 'Created At', 'Last Updated At',
        ], $orders->flatMap(fn (FlowJob $order) => $order->documents->map(fn ($document) => [
            $order->displayOrderNumber(), $document->document_number, $document->task_id, $document->task?->task_number,
            $document->task?->title, $document->category,
            $document->name, $this->plainText($document->note), $document->mime_type, $document->size,
            $document->version, $this->yesNo($document->is_final), $document->uploader?->name,
            $this->dateTime($document->created_at), $this->dateTime($document->updated_at),
        ])));

        $this->fillSheet($book->createSheet(), 'Members', [
            'Order Number', 'User', 'Access Level', 'Manage Tasks?', 'Upload Documents?', 'View Financials?', 'Added At',
        ], $orders->flatMap(fn (FlowJob $order) => $order->members->map(fn ($member) => [
            $order->displayOrderNumber(), $member->user?->name, $member->access_level,
            $this->yesNo($member->can_manage_tasks), $this->yesNo($member->can_upload_documents),
            $this->yesNo($member->can_view_financials), $this->dateTime($member->created_at),
        ])));

        $this->fillSheet($book->createSheet(), 'Phase History', [
            'Order Number', 'Phase', 'Status', 'Phase Owner ID', 'Target Date', 'Changed By',
            'Entered At', 'Completed At', 'Created At',
        ], $orders->flatMap(fn (FlowJob $order) => $order->phaseHistories->map(fn ($history) => [
            $order->displayOrderNumber(), $history->phase?->name, $history->status, $history->phase_owner_id,
            $this->date($history->target_date), $history->actor?->name,
            $this->dateTime($history->entered_at), $this->dateTime($history->completed_at), $this->dateTime($history->created_at),
        ])));

        $this->fillSheet($book->createSheet(), 'Activities', [
            'Order Number', 'Event', 'Description', 'User', 'Metadata', 'Created At',
        ], $orders->flatMap(fn (FlowJob $order) => $order->activities->where('event', '!=', 'job.health_updated')->map(fn ($activity) => [
            $order->displayOrderNumber(), $activity->event, $this->plainText($activity->description), $activity->user?->name,
            $this->json($activity->meta), $this->dateTime($activity->created_at),
        ])));

        if ($canViewFinance) {
            $this->fillSheet($book->createSheet(), 'Invoices', [
                'Order Number', 'Invoice Number', 'Type', 'Currency', 'Issue Date', 'Due Date', 'Billing Contact',
                'Billing Email', 'PO Reference', 'Notes', 'Subtotal', 'Tax Rate', 'Tax Amount', 'Previously Invoiced',
                'Total', 'Status', 'Created By', 'Created At', 'Updated At',
            ], $orders->flatMap(fn (FlowJob $order) => $order->invoices->map(fn ($invoice) => [
                $order->displayOrderNumber(), $invoice->invoice_number, $invoice->type, $invoice->currency,
                $this->date($invoice->issue_date), $this->date($invoice->due_date),
                $invoice->billing_contact_name ?: $invoice->billingContact?->name, $invoice->billing_contact_email,
                $invoice->purchase_order_reference, $this->plainText($invoice->notes), $invoice->subtotal, $invoice->tax_rate,
                $invoice->tax_amount, $invoice->previously_invoiced, $invoice->total, $invoice->status,
                $invoice->creator?->name, $this->dateTime($invoice->created_at), $this->dateTime($invoice->updated_at),
            ])));

            $this->fillSheet($book->createSheet(), 'Invoice Items', [
                'Order Number', 'Invoice Number', 'Description', 'Quantity', 'Unit Price', 'Amount', 'Sort Order',
            ], $orders->flatMap(fn (FlowJob $order) => $order->invoices->flatMap(fn ($invoice) => $invoice->items->map(fn ($item) => [
                $order->displayOrderNumber(), $invoice->invoice_number, $item->description, $item->quantity,
                $item->unit_price, $item->amount, $item->sort_order,
            ]))));

            $this->fillSheet($book->createSheet(), 'Payments', [
                'Order Number', 'Payment Number', 'Invoice Number', 'Payment Date', 'Method', 'Amount', 'Reference',
                'Notes', 'Recorded By', 'Created At',
            ], $orders->flatMap(fn (FlowJob $order) => $order->payments->map(fn ($payment) => [
                $order->displayOrderNumber(), $payment->payment_number, $payment->invoice?->invoice_number,
                $this->date($payment->payment_date), $payment->method, $payment->amount, $payment->reference,
                $this->plainText($payment->notes), $payment->recorder?->name, $this->dateTime($payment->created_at),
            ])));

            $this->fillSheet($book->createSheet(), 'Collections', [
                'Order Number', 'Collection Owner', 'Last Follow-up', 'Next Follow-up', 'Latest Note', 'Updated At',
            ], $orders->filter(fn (FlowJob $order) => $order->collection)->map(fn (FlowJob $order) => [
                $order->displayOrderNumber(), $order->collection?->owner?->name,
                $this->date($order->collection?->last_follow_up_at), $this->date($order->collection?->next_follow_up_at),
                $this->plainText($order->collection?->latest_note), $this->dateTime($order->collection?->updated_at),
            ]));
        }

        $book->setActiveSheetIndex(0);
        return $book;
    }

    // private function inquiryWorkbook(Collection $inquiries, bool $canViewFinance): Spreadsheet
    // {
    //     $book = new Spreadsheet();
    //     $book->getProperties()
    //         ->setCreator('FlowTrack')
    //         ->setTitle('Inquiry list export')
    //         ->setSubject('Inquiries and related details');

    //     // Keep the downloaded workbook's FIRST tab aligned with the live Inquiry list.
    //     // The remaining tabs preserve the existing full-detail/report data.
    //     $this->fillListViewSheet($book->getActiveSheet(), 'Inquiry List', [
    //         'Inquiry', 'Title', 'Client / Item', 'Priority', 'Due Date', 'Status', 'Flag',
    //         'Current Task', 'Assignee', 'Task Status', 'Started At', 'Progress', 'Updated At',
    //     ], $this->inquiryListRows($inquiries));

    //     $this->fillSheet($book->createSheet(), 'Inquiry Details', [
    //         'Inquiry Number', 'Reference Number', 'Subject', 'Client', 'Inquiry Status', 'Priority', 'Owner',
    //         'Received Date', 'Required Delivery Date', 'Products', 'Product Count', 'Total Quantity',
    //         'Task ID', 'Task Sequence', 'Workflow Phase', 'Task', 'Task Assignee', 'Assignee Email',
    //         'Assignee Department', 'Current Task Status', 'Due Date', 'Requires Submission?', 'Submission Label',
    //         'Files', 'Links', 'Task Started At', 'Task Completed At', 'Task Last Updated At', 'Inquiry Last Updated At',
    //     ], $this->inquiryDetailRows($inquiries, $canViewFinance));

    //     $this->fillSheet($book->createSheet(), 'Inquiries', [
    //         'Inquiry ID', 'Inquiry Number', 'Reference Number', 'Subject', 'Requirement Notes', 'Client ID', 'Client Code',
    //         'Client Name', 'Client Contact', 'Owner', 'Created By', 'Request Source', 'Received Date', 'Required Delivery Date',
    //         'Initial Follow-up Date', 'Priority', 'Status', 'Result', 'Dead Reason', 'Dead Note', 'Target Price', 'Currency',
    //         'Source Task Pack', 'Source Workflow', 'Converted Order', 'Needs Attention?', 'Attention Reason', 'Attention By',
    //         'Attention At', 'Product Count', 'Task Count', 'Open Task Count', 'Completed Task Count', 'Latest Task Updated At',
    //         'Started At', 'Completed At', 'Created At', 'Updated At',
    //     ], $inquiries->map(fn (Inquiry $inquiry) => [
    //         $inquiry->id, $inquiry->inquiry_number, $inquiry->reference_number, $inquiry->subject,
    //         $this->plainText($inquiry->requirement_notes), $inquiry->client_id, $inquiry->client?->code, $inquiry->client?->name,
    //         $inquiry->client_contact, $inquiry->owner?->name, $inquiry->creator?->name, $inquiry->request_source,
    //         $this->date($inquiry->received_date), $this->date($inquiry->required_delivery_date),
    //         $this->date($inquiry->initial_follow_up_date), $inquiry->priority, $inquiry->status, $inquiry->result,
    //         $inquiry->dead_reason, $this->plainText($inquiry->dead_note),
    //         $canViewFinance ? $inquiry->target_price : '', $canViewFinance ? $inquiry->currency : '',
    //         $inquiry->sourceTaskPack?->name, $inquiry->sourceWorkflow?->name,
    //         $inquiry->convertedJob?->displayOrderNumber(), $this->yesNo($inquiry->needs_attention),
    //         $this->plainText($inquiry->attention_reason), $inquiry->attentionRequester?->name,
    //         $this->dateTime($inquiry->attention_at), $inquiry->items->count(), $inquiry->tasks->count(),
    //         $inquiry->tasks->filter(fn ($task) => !$task->completed_at)->count(),
    //         $inquiry->tasks->filter(fn ($task) => (bool) $task->completed_at)->count(),
    //         $this->dateTime($this->latestUpdatedAt($inquiry->tasks)), $this->dateTime($inquiry->started_at),
    //         $this->dateTime($inquiry->completed_at), $this->dateTime($inquiry->created_at), $this->dateTime($inquiry->updated_at),
    //     ]));

    //     $this->fillSheet($book->createSheet(), 'Products', [
    //         'Inquiry Number', 'Item ID', 'Category', 'Product / Item', 'Quantity', 'Unit', 'Unit Price', 'Notes', 'Created At', 'Last Updated At',
    //     ], $inquiries->flatMap(fn (Inquiry $inquiry) => $inquiry->items->map(fn ($item) => [
    //         $inquiry->inquiry_number, $item->id, $item->category, $item->item_name, $item->quantity, $item->unit,
    //         $canViewFinance ? $item->unit_price : '', $this->plainText($item->notes), $this->dateTime($item->created_at), $this->dateTime($item->updated_at),
    //     ])));

    //     $this->fillSheet($book->createSheet(), 'Tasks', [
    //         'Inquiry Number', 'Task ID', 'Sequence', 'Workflow Phase', 'Task', 'Description', 'Assignee', 'Setup Assignee',
    //         'Assignee Email', 'Assignee Department', 'Current Task Status', 'Status Master', 'Due Date', 'Requires Submission?',
    //         'Submission Label', 'File Count', 'Link Count', 'Needs Attention?', 'Attention Reason', 'Started At',
    //         'Completed At', 'Completed By', 'Created At', 'Task Last Updated At',
    //     ], $inquiries->flatMap(fn (Inquiry $inquiry) => $inquiry->tasks->map(fn ($task) => [
    //         $inquiry->inquiry_number, $task->id, $task->sequence, $task->sourceWorkflowPhase?->name, $task->title,
    //         $this->plainText($task->description), $task->assignee?->name, $task->setupAssignee?->name,
    //         $task->assignee?->email, $task->assignee?->department?->name, $this->currentInquiryTaskStatus($task),
    //         $task->taskStatus?->name, $this->date($task->due_date), $this->yesNo($task->requires_submission),
    //         $task->submission_label, $task->documents->count(), $task->links->count(), $this->yesNo($task->needs_attention), $this->plainText($task->attention_reason),
    //         $this->dateTime($task->started_at), $this->dateTime($task->completed_at), $task->completionAssignee?->name,
    //         $this->dateTime($task->created_at), $this->dateTime($task->updated_at),
    //     ])));

    //     $this->fillSheet($book->createSheet(), 'Task Comments', [
    //         'Inquiry Number', 'Task ID', 'Task', 'Comment By', 'Comment', 'Created At', 'Last Updated At',
    //     ], $inquiries->flatMap(fn (Inquiry $inquiry) => $inquiry->tasks->flatMap(fn ($task) => $task->comments->map(fn ($comment) => [
    //         $inquiry->inquiry_number, $task->id, $task->title, $comment->user?->name,
    //         $this->plainText($comment->body), $this->dateTime($comment->created_at), $this->dateTime($comment->updated_at),
    //     ]))));

    //     $this->fillSheet($book->createSheet(), 'Task Links', [
    //         'Inquiry Number', 'Task ID', 'Task', 'URL', 'Created By', 'Created At', 'Last Updated At',
    //     ], $inquiries->flatMap(fn (Inquiry $inquiry) => $inquiry->tasks->flatMap(fn ($task) => $task->links->map(fn ($link) => [
    //         $inquiry->inquiry_number, $task->id, $task->title, $link->url, $link->creator?->name,
    //         $this->dateTime($link->created_at), $this->dateTime($link->updated_at),
    //     ]))));

    //     $this->fillSheet($book->createSheet(), 'Documents', [
    //         'Inquiry Number', 'Task ID', 'Task Sequence', 'Task', 'Name', 'MIME Type', 'Size Bytes', 'Uploaded By',
    //         'Created At', 'Last Updated At',
    //     ], $inquiries->flatMap(fn (Inquiry $inquiry) => $inquiry->documents->map(fn ($document) => [
    //         $inquiry->inquiry_number, $document->inquiry_task_id, $document->task?->sequence, $document->task?->title,
    //         $document->name, $document->mime_type, $document->size, $document->uploader?->name,
    //         $this->dateTime($document->created_at), $this->dateTime($document->updated_at),
    //     ])));

    //     $this->fillSheet($book->createSheet(), 'Activities', [
    //         'Inquiry Number', 'Event', 'Description', 'User', 'Metadata', 'Created At',
    //     ], $inquiries->flatMap(fn (Inquiry $inquiry) => $inquiry->activities->map(fn ($activity) => [
    //         $inquiry->inquiry_number, $activity->event, $this->plainText($activity->description), $activity->user?->name,
    //         $this->json($activity->meta), $this->dateTime($activity->created_at),
    //     ])));

    //     $book->setActiveSheetIndex(0);
    //     return $book;
    // }

    //New inquiry export function for testing start
    private function inquiryWorkbook(
        Collection $inquiries,
        bool $canViewFinance
    ): Spreadsheet {
        $book = new Spreadsheet();

        $book->getProperties()
            ->setCreator('FlowTrack')
            ->setTitle('Inquiry List')
            ->setSubject('Inquiry list report');

        $this->fillListViewSheet(
            $book->getActiveSheet(),
            'Inquiry List',
            [
                'Inquiry',
                'Title',
                'Client / Item',
                'Priority',
                'Due Date',
                'Status',
                'Flag',
                'Current Task',
                'Assignee',
                'Task Status',
                'Started At',
                'Progress',
                'Updated At',
            ],
            $this->inquiryListRows($inquiries)
        );

        return $book;
    }





    //New inquiry export function for testing end

    /**
     * Excel-facing Order list. Column order and visible values intentionally
     * mirror resources/views/components/jobs/table.blade.php. Each row also
     * carries its web-list client tone so the downloaded report is visually
     * recognisable without losing the detailed tabs that follow it.
     */
    private function orderListRows(Collection $orders): iterable
    {
        $flags = app(OrderTaskFlagService::class);
        $masterData = app(MasterDataService::class);

        foreach ($orders as $order) {
            $creator = $order->createdActivity?->user ?? $order->owner;
            $creatorName = trim((string) ($creator?->name ?: 'System'));
            $orderReference = trim((string) $order->order_number);
            if ($orderReference === '') {
                $orderReference = 'REF-'.str_pad((string) $order->id, 5, '0', STR_PAD_LEFT);
            }

            $inquiryCell = 'Not linked';
            if ($order->sourceInquiry) {
                $inquiryReference = trim((string) $order->sourceInquiry->reference_number);
                $inquiryCell = trim((string) $order->sourceInquiry->inquiry_number)
                    .($inquiryReference !== '' ? "\n".$inquiryReference : "\nSource inquiry");
            }

            $phaseName = trim((string) ($order->phase?->name ?: $order->status ?: '—'));
            $automaticFlag = trim((string) ($flags->labelForOrder($order) ?: ''));
            $manualAttention = (bool) ($order->attention_requested ?? false);
            $flag = $manualAttention ? 'Requires attention' : ($automaticFlag !== '' ? $automaticFlag : 'No flag');
            $ownerName = trim((string) ($order->owner?->name ?: 'Unassigned'));
            $delivery = $order->delivery_date
                ? 'Due '.$this->listDate($order->delivery_date, 'M j')
                : 'No delivery date';

            $flagColor = $manualAttention
                ? '#DC2626'
                : ($automaticFlag !== '' ? $masterData->displayColorFor('order_flag', $automaticFlag) : null);

            yield [
                'values' => [
                    $creatorName."\n".$this->listDateTime($order->created_at, 'M j, Y · g:i A'),
                    $order->displayOrderNumber()."\n".$orderReference,
                    $inquiryCell,
                    $this->orderListProductCell($order),
                    $phaseName,
                    $flag,
                    $ownerName."\n".$delivery,
                    max(0, min(100, (int) $order->progress)).'%',
                ],
                'row_color' => $this->clientListRowColor($order->client?->code, $order->client?->name),
                // 1-based column numbers, matching the sheet's visible columns.
                'cell_colors' => array_filter([
                    5 => \App\Support\MasterColor::normalize((string) ($order->phase?->color ?? '')),
                    6 => $flag === 'No flag' ? null : ($flagColor ?: $this->semanticListColor($flag)),
                ]),
            ];
        }
    }

    /**
     * Excel-facing Inquiry list. This mirrors the live Inquiry list's current
     * task, assignee, flag and task-progress calculations rather than exporting
     * one row per task on the first tab.
     */
    private function inquiryListRows(Collection $inquiries): iterable
    {
        $masterData = app(MasterDataService::class);
        $inquiryRead = app(\App\Services\Inquiries\InquiryReadService::class);
        $today = app(WorkspaceSettingsService::class)->localToday()->toDateString();

        foreach ($inquiries as $inquiry) {
            $tasks = $inquiry->tasks;
            $total = $tasks->count();
            $done = $tasks->filter(fn ($task) => (bool) $task->completed_at)->count();
            $progressed = $tasks->filter(fn ($task) => (bool) ($task->started_at || $task->completed_at))->count();
            $progress = $done === $total && $total > 0 ? $total : min($total, max($done, $progressed));
            $progressPercent = $total > 0 ? max(0, min(100, (int) round(($progress / $total) * 100))) : 0;
            $currentTask = $this->currentInquiryListTask($tasks);

            $status = match (true) {
                $inquiry->result === 'converted' => 'Converted',
                $inquiry->result === 'dead' => 'Closed',
                (string) $inquiry->status === 'Draft' => 'Draft',
                default => trim((string) ($inquiry->status ?: \App\Services\LegacyInquiryService::AUTO_READY_STATUS)),
            };
            $isCompleted = $status === \App\Services\LegacyInquiryService::AUTO_COMPLETED_STATUS;
            $taskStatus = $currentTask ? $this->currentInquiryTaskStatus($currentTask) : ($isCompleted ? 'Completed' : '—');
            $taskDue = $currentTask?->due_date ? $this->date($currentTask->due_date) : null;
            $inquiryNeedsAttention = (bool) ($inquiry->needs_attention ?? false);
            $taskNeedsAttention = (bool) ($currentTask?->needs_attention ?? false);
            $flag = match (true) {
                $inquiryNeedsAttention => 'Requires attention',
                $isCompleted || !$currentTask => 'No flag',
                $taskNeedsAttention => 'Requires attention',
                $taskDue !== null && $taskDue < $today => 'Overdue',
                $taskDue === $today => 'Due Today',
                default => 'No flag',
            };

            $displayAssignee = $isCompleted
                ? ($inquiry->owner ?: $inquiry->creator)
                : $currentTask?->assignee;
            $currentPosition = $currentTask
                ? max(1, min(max(1, $total), (int) ($currentTask->sequence ?: max(1, $progress))))
                : ($total > 0 ? $total : 0);
            $currentTaskLabel = $currentTask?->title
                ?: ($done === $total && $total > 0 ? 'Completed' : 'No active task');
            $taskCaption = $done === $total && $total > 0
                ? 'Workflow tasks finished'
                : 'Task '.$currentPosition.' of '.$total;

            $clientCell = trim((string) ($inquiry->client?->name ?: 'No client'));
            $contact = trim((string) ($inquiry->client_contact ?: ''));
            $firstItem = trim((string) ($inquiry->items->first()?->item_name ?: ''));
            if ($contact !== '') $clientCell .= "\nContact: ".$contact;
            if ($contact === '') $clientCell .= "\nContact: —";
            if ($firstItem !== '') $clientCell .= "\n".$firstItem;

            $hasCompletedTask = $done > 0;
            $activeTaskColor = $hasCompletedTask
                ? \App\Support\MasterColor::normalize((string) ($currentTask?->sourceTaskPackItem?->color ?? ''))
                : null;
            $rowColor = $activeTaskColor
                ? $this->blendWithWhite($activeTaskColor, 0.11)
                : $this->clientListRowColor($inquiry->client?->code, $inquiry->client?->name);
            $priority = trim((string) ($inquiry->priority ?: 'Medium'));
            $statusColor = $inquiryRead->inquiryStatusColor($status, $taskStatus);
            $taskStatusColor = $taskStatus !== '—'
                ? $masterData->displayColorFor('inquiry_task_status', $taskStatus)
                : null;

            yield [
                'values' => [
                    trim((string) $inquiry->inquiry_number)
                        ."\nCreated by ".trim((string) ($inquiry->creator?->name ?: 'System'))
                        ."\n".$this->listDateTime($inquiry->created_at, 'M j, Y · g:i A'),
                    trim((string) $inquiry->subject),
                    $clientCell,
                    $priority,
                    $currentTask?->due_date ? $this->listDate($currentTask->due_date, 'M j') : '—',
                    $status,
                    $flag,
                    $currentTaskLabel."\n".$taskCaption,
                    trim((string) ($displayAssignee?->name ?: ($isCompleted ? 'System' : 'Unassigned'))),
                    $taskStatus,
                    $inquiry->started_at ? $this->listDateTime($inquiry->started_at, 'M j, Y · g:i A') : 'Not Started',
                    $progress.'/'.$total.' ('.$progressPercent.'%)',
                    $this->listDateTime($inquiry->updated_at, 'M j, Y · g:i A'),
                ],
                'row_color' => $rowColor,
                'cell_colors' => array_filter([
                    4 => $masterData->displayColorFor('priority', $priority),
                    6 => $statusColor,
                    7 => $flag === 'No flag' ? null : $this->semanticListColor($flag),
                    10 => $taskStatusColor,
                ]),
            ];
        }
    }

    private function currentInquiryListTask(Collection $tasks): mixed
    {
        return $tasks
            ->filter(fn ($task) => !$task->completed_at)
            ->sort(function ($left, $right): int {
                $leftStarted = (bool) $left->started_at;
                $rightStarted = (bool) $right->started_at;
                if ($leftStarted !== $rightStarted) return $leftStarted ? -1 : 1;

                $leftSequence = (int) ($left->sequence ?? 0);
                $rightSequence = (int) ($right->sequence ?? 0);
                if ($leftStarted && $rightStarted) return $rightSequence <=> $leftSequence;

                return $leftSequence <=> $rightSequence;
            })
            ->first();
    }

    private function orderListProductCell(FlowJob $order): string
    {
        $clientName = trim((string) ($order->client?->name ?: '—'));
        $items = $order->items;

        if ($items->isEmpty()) {
            $product = trim((string) ($order->product ?: 'Product'));
            $quantity = (int) ($order->quantity ?? 0);
            return $clientName."\n".$product."\n".number_format($quantity).' '.($quantity === 1 ? 'pc' : 'pcs');
        }

        $totalUnits = (int) $items->sum(fn ($item) => (int) ($item->quantity ?? 0));
        $productNames = $items->pluck('product_name')->map(fn ($name) => trim((string) $name))->filter()->values();

        if ($items->count() === 1) {
            return $clientName."\n".($productNames->first() ?: 'Product')."\n"
                .number_format($totalUnits).' '.($totalUnits === 1 ? 'pc' : 'pcs');
        }

        return $clientName."\n".$items->count().' ordered products · '.number_format($totalUnits).' pcs'
            .($productNames->isNotEmpty() ? "\n".$productNames->implode(' · ') : '');
    }

    /**
     * Styled spreadsheet list view inspired by the supplied Excel example while
     * preserving FlowTrack's actual Order/Inquiry list row colors.
     *
     * @param iterable<int,array{values:array,row_color?:?string,cell_colors?:array<int,?string>}> $rows
     */
    private function fillListViewSheet(Worksheet $sheet, string $title, array $headers, iterable $rows): void
    {
        $sheet->setTitle(mb_substr($title, 0, 31));
        if (method_exists($sheet, 'setShowGridlines')) {
            $sheet->setShowGridlines(false);
        }

        $this->writeRow($sheet, 1, $headers, true);
        $rowNumber = 2;
        foreach ($rows as $row) {
            $values = array_values((array) ($row['values'] ?? []));
            $this->writeRow($sheet, $rowNumber, $values);

            $lastColumn = Coordinate::stringFromColumnIndex(max(1, count($headers)));
            $rowRange = 'A'.$rowNumber.':'.$lastColumn.$rowNumber;
            $rowColor = $this->excelArgb($row['row_color'] ?? null);
            if ($rowColor) {
                $sheet->getStyle($rowRange)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB($rowColor);
            }

            foreach ((array) ($row['cell_colors'] ?? []) as $columnIndex => $cellColor) {
                $normalized = \App\Support\MasterColor::normalize((string) $cellColor);
                if (!$normalized) continue;
                $cell = Coordinate::stringFromColumnIndex((int) $columnIndex).$rowNumber;
                $sheet->getStyle($cell)->getFill()->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()->setARGB($this->excelArgb($this->blendWithWhite($normalized, 0.18)));
                $sheet->getStyle($cell)->getFont()->getColor()->setARGB($this->excelArgb($normalized));
                $sheet->getStyle($cell)->getFont()->setBold(true);
            }

            $sheet->getRowDimension($rowNumber)->setRowHeight(42);
            $rowNumber++;
        }

        $lastColumn = Coordinate::stringFromColumnIndex(max(1, count($headers)));
        $lastRow = max(1, $rowNumber - 1);
        $range = 'A1:'.$lastColumn.$lastRow;
        $headerRange = 'A1:'.$lastColumn.'1';

        $sheet->freezePane('A2');
        $sheet->setAutoFilter($range);
        $sheet->getStyle($range)->getFont()->setName('Arial')->setSize(10);
        $sheet->getStyle($range)->getAlignment()
            ->setVertical(Alignment::VERTICAL_CENTER)
            ->setHorizontal(Alignment::HORIZONTAL_CENTER)
            ->setWrapText(true);
        $sheet->getStyle($range)->getBorders()->getAllBorders()
            ->setBorderStyle(Border::BORDER_THIN)
            ->getColor()->setARGB('FF1F1F1F');

        // The supplied report uses a lime-green header and compact black grid.
        $sheet->getStyle($headerRange)->getFont()->setBold(true)->setSize(10)->getColor()->setARGB('FF111111');
        $sheet->getStyle($headerRange)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF92D050');
        $sheet->getStyle($headerRange)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getRowDimension(1)->setRowHeight(38);

        foreach ($headers as $index => $header) {
            $column = Coordinate::stringFromColumnIndex($index + 1);
            $label = mb_strtolower((string) $header);
            $width = 16;
            if (preg_match('/client \/ products|client \/ item/', $label)) {
                $width = 34;
            } elseif (preg_match('/created by|owner \/ delivery|current task/', $label)) {
                $width = 25;
            } elseif (preg_match('/title/', $label)) {
                $width = 30;
            } elseif (preg_match('/order|inquiry/', $label)) {
                $width = 22;
            } elseif (preg_match('/phase|health|status|assignee|updated|started|due/', $label)) {
                $width = 18;
            } elseif (preg_match('/priority|flag|progress/', $label)) {
                $width = 15;
            }
            $sheet->getColumnDimension($column)->setWidth($width);

            if ($lastRow >= 2 && preg_match('/created by|order$|inquiry$|title|client|owner|current task|assignee/', $label)) {
                $sheet->getStyle($column.'2:'.$column.$lastRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_LEFT);
            }
        }

        $sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE);
        $sheet->getPageSetup()->setFitToWidth(1);
        $sheet->getPageSetup()->setFitToHeight(0);
        $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd(1, 1);
        $sheet->getPageSetup()->setPrintArea($range);
        $sheet->getPageMargins()->setTop(0.35)->setRight(0.25)->setBottom(0.35)->setLeft(0.25);
    }

    private function clientListRowColor(mixed $code, mixed $name): ?string
    {
        $code = strtoupper(trim((string) $code));
        $name = strtoupper(trim((string) $name));
        if ($code === 'IID' || preg_match('/\\bIID\\b/i', $name)) return '#EEF9F1';
        if ($code === 'NEP' || preg_match('/\\bNEP\\b/i', $name)) return '#EEF6FF';
        return null;
    }

    private function semanticListColor(?string $value): string
    {
        $value = mb_strtolower(trim((string) $value));
        return match (true) {
            str_contains($value, 'delayed'), str_contains($value, 'issue'), str_contains($value, 'overdue'),
            str_contains($value, 'blocked'), str_contains($value, 'attention'), str_contains($value, 'critical') => '#DC2626',
            str_contains($value, 'risk'), str_contains($value, 'wait'), str_contains($value, 'hold'),
            str_contains($value, 'revision'), str_contains($value, 'due'), str_contains($value, 'urgent'),
            str_contains($value, 'high') => '#D97706',
            str_contains($value, 'track'), str_contains($value, 'ready'), str_contains($value, 'invoice'),
            str_contains($value, 'warehouse'), str_contains($value, 'shipping'), str_contains($value, 'complete'),
            str_contains($value, 'no flag') => '#16A34A',
            str_contains($value, 'artwork'), str_contains($value, 'sample'), str_contains($value, 'client') => '#7C3AED',
            default => '#2563EB',
        };
    }

    /** Blend a configured task/master color over white, matching the web list's translucent fills. */
    private function blendWithWhite(string $hex, float $strength): string
    {
        $hex = ltrim((string) \App\Support\MasterColor::normalize($hex), '#');
        if (strlen($hex) !== 6) return '#FFFFFF';
        $strength = max(0, min(1, $strength));
        $rgb = [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
        $mixed = array_map(fn (int $channel): int => (int) round(255 - ((255 - $channel) * $strength)), $rgb);
        return sprintf('#%02X%02X%02X', $mixed[0], $mixed[1], $mixed[2]);
    }

    private function excelArgb(?string $hex): ?string
    {
        $hex = \App\Support\MasterColor::normalize((string) $hex);
        return $hex ? 'FF'.ltrim($hex, '#') : null;
    }

    private function listDate(mixed $value, string $format): string
    {
        if (!$value) return '—';
        if (is_object($value) && method_exists($value, 'format')) return $value->format($format);
        return (string) $value;
    }

    private function listDateTime(mixed $value, string $format): string
    {
        if (!$value) return '—';
        if (is_object($value) && method_exists($value, 'timezone') && method_exists($value, 'format')) {
            return $value->copy()->timezone(app(WorkspaceSettingsService::class)->displayTimezone())->format($format);
        }
        if (is_object($value) && method_exists($value, 'format')) return $value->format($format);
        return (string) $value;
    }

    /**
     * Operational Order export: one row per visible task, with the parent Order
     * and its product context repeated so Excel users can filter/sort without
     * joining sheets manually. Orders without tasks still receive one row.
     */
    private function orderDetailRows(Collection $orders, bool $canViewFinance): iterable
    {
        foreach ($orders as $order) {
            $tasks = $order->tasks->isNotEmpty() ? $order->tasks : collect([null]);
            $productSummary = $this->orderProductSummary($order, $canViewFinance);
            $productCount = $this->orderProductCount($order);
            $totalQuantity = $this->orderTotalQuantity($order);

            foreach ($tasks as $task) {
                yield [
                    $order->displayOrderNumber(),
                    $order->order_number,
                    $order->title,
                    $order->client?->name,
                    $order->status,
                    $order->phase?->name,
                    $order->priority,
                    $order->progress,
                    $order->owner?->name,
                    $order->coordinator?->name,
                    $this->date($order->received_date),
                    $this->date($order->delivery_date),
                    $this->date($order->estimated_delivery_date),
                    $productSummary,
                    $productCount,
                    $totalQuantity,
                    $task?->task_number,
                    $task?->title,
                    $task?->phase?->name,
                    $task?->assignee?->name,
                    $task?->assignee?->email,
                    $task?->assignee?->department?->name,
                    $task ? $this->currentOrderTaskStatus($task) : '',
                    $task?->orderTaskFlag?->name,
                    $task?->priority,
                    $task?->progress,
                    $this->date($task?->start_date),
                    $this->date($task?->due_date),
                    $task?->documentCategory?->name ?: ($task?->document_requirement_source ?: ''),
                    $task?->documents?->count() ?? 0,
                    $task?->links?->count() ?? 0,
                    $this->dateTime($task?->completed_at),
                    $this->dateTime($task?->updated_at),
                    $this->dateTime($order->updated_at),
                ];
            }
        }
    }

    /**
     * Operational Inquiry export: one row per visible task with Inquiry and
     * product context. Inquiries without tasks still receive one row.
     */
    private function inquiryDetailRows(Collection $inquiries, bool $canViewFinance): iterable
    {
        foreach ($inquiries as $inquiry) {
            $tasks = $inquiry->tasks->isNotEmpty() ? $inquiry->tasks : collect([null]);
            $productSummary = $this->inquiryProductSummary($inquiry, $canViewFinance);
            $totalQuantity = (float) $inquiry->items->sum(fn ($item) => (float) ($item->quantity ?? 0));

            foreach ($tasks as $task) {
                yield [
                    $inquiry->inquiry_number,
                    $inquiry->reference_number,
                    $inquiry->subject,
                    $inquiry->client?->name,
                    $inquiry->status,
                    $inquiry->priority,
                    $inquiry->owner?->name,
                    $this->date($inquiry->received_date),
                    $this->date($inquiry->required_delivery_date),
                    $productSummary,
                    $inquiry->items->count(),
                    $totalQuantity,
                    $task?->id,
                    $task?->sequence,
                    $task?->sourceWorkflowPhase?->name,
                    $task?->title,
                    $task?->assignee?->name,
                    $task?->assignee?->email,
                    $task?->assignee?->department?->name,
                    $task ? $this->currentInquiryTaskStatus($task) : '',
                    $this->date($task?->due_date),
                    $task ? $this->yesNo($task->requires_submission) : '',
                    $task?->submission_label,
                    $task?->documents?->count() ?? 0,
                    $task?->links?->count() ?? 0,
                    $this->dateTime($task?->started_at),
                    $this->dateTime($task?->completed_at),
                    $this->dateTime($task?->updated_at),
                    $this->dateTime($inquiry->updated_at),
                ];
            }
        }
    }

    private function orderProductSummary(FlowJob $order, bool $canViewFinance): string
    {
        if ($order->items->isNotEmpty()) {
            return $order->items->map(function ($item) use ($order, $canViewFinance): string {
                $parts = [trim((string) $item->product_name)];
                if ($item->category_name) $parts[] = '['.trim((string) $item->category_name).']';
                $parts[] = 'Qty: '.(string) ($item->quantity ?? 0);
                if ($canViewFinance && $item->unit_price !== null) {
                    $parts[] = 'Unit: '.$this->moneyText($item->unit_price, (string) $order->currency);
                }
                return implode(' · ', array_filter($parts, fn ($part) => $part !== ''));
            })->implode("\n");
        }

        if (!$order->product && !$order->category && !$order->quantity) return '';

        return implode(' · ', array_filter([
            trim((string) $order->product),
            $order->category ? '['.trim((string) $order->category).']' : '',
            'Qty: '.(string) ($order->quantity ?? 0),
        ], fn ($part) => $part !== ''));
    }

    private function inquiryProductSummary(Inquiry $inquiry, bool $canViewFinance): string
    {
        return $inquiry->items->map(function ($item) use ($inquiry, $canViewFinance): string {
            $parts = [trim((string) $item->item_name)];
            if ($item->category) $parts[] = '['.trim((string) $item->category).']';
            $quantity = (string) ($item->quantity ?? 0);
            if ($item->unit) $quantity .= ' '.trim((string) $item->unit);
            $parts[] = 'Qty: '.$quantity;
            if ($canViewFinance && $item->unit_price !== null) {
                $parts[] = 'Unit: '.$this->moneyText($item->unit_price, (string) $inquiry->currency);
            }
            return implode(' · ', array_filter($parts, fn ($part) => $part !== ''));
        })->implode("\n");
    }

    private function orderProductCount(FlowJob $order): int
    {
        if ($order->items->isNotEmpty()) return $order->items->count();
        return ($order->product || $order->category || $order->quantity) ? 1 : 0;
    }

    private function orderTotalQuantity(FlowJob $order): float|int
    {
        if ($order->items->isNotEmpty()) {
            return $order->items->sum(fn ($item) => (float) ($item->quantity ?? 0));
        }
        return (int) ($order->quantity ?? 0);
    }

    private function currentOrderTaskStatus(mixed $task): string
    {
        return trim((string) ($task->orderTaskStatus?->name ?: $task->status));
    }

    private function currentInquiryTaskStatus(mixed $task): string
    {
        return trim((string) ($task->taskStatus?->name ?: $task->status));
    }

    private function moneyText(mixed $amount, string $currency): string
    {
        $currency = trim($currency) ?: 'USD';
        return $currency.' '.number_format((float) $amount, 2, '.', ',');
    }

    private function latestUpdatedAt(Collection $models): mixed
    {
        return $models->reduce(function (mixed $latest, mixed $model): mixed {
            $updatedAt = $model?->updated_at;
            if (!$updatedAt) return $latest;
            if (!$latest) return $updatedAt;

            return $updatedAt->greaterThan($latest) ? $updatedAt : $latest;
        });
    }

    private function fillSheet(Worksheet $sheet, string $title, array $headers, iterable $rows): void
    {
        $sheet->setTitle(mb_substr($title, 0, 31));
        if (method_exists($sheet, 'setShowGridlines')) {
            $sheet->setShowGridlines(false);
        }
        $this->writeRow($sheet, 1, $headers, true);

        $rowNumber = 2;
        foreach ($rows as $row) {
            $this->writeRow($sheet, $rowNumber++, array_values((array) $row));
        }

        $lastColumn = Coordinate::stringFromColumnIndex(max(1, count($headers)));
        $lastRow = max(1, $rowNumber - 1);
        $range = 'A1:'.$lastColumn.$lastRow;

        $sheet->freezePane('A2');
        $sheet->setAutoFilter('A1:'.$lastColumn.$lastRow);
        $sheet->getStyle($range)->getAlignment()->setVertical(Alignment::VERTICAL_TOP);
        $sheet->getStyle($range)->getAlignment()->setWrapText(true);
        $sheet->getStyle('A1:'.$lastColumn.'1')->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
        $sheet->getStyle('A1:'.$lastColumn.'1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF1F4E78');
        $sheet->getStyle('A1:'.$lastColumn.'1')->getBorders()->getBottom()->setBorderStyle(Border::BORDER_THIN)->getColor()->setARGB('FFD9E2F3');
        $sheet->getRowDimension(1)->setRowHeight(24);

        foreach ($headers as $index => $header) {
            $column = Coordinate::stringFromColumnIndex($index + 1);
            $label = mb_strtolower((string) $header);
            $width = 16;
            if (preg_match('/description|notes|address|comment|metadata|instruction|reason|url/', $label)) {
                $width = 38;
            } elseif (preg_match('/title|product|client|workflow|phase|assignee|owner|status|number|reference/', $label)) {
                $width = 22;
            } elseif (preg_match('/date| at$|created|updated|completed|started|entered|follow-up/', $label)) {
                $width = 20;
            }
            $sheet->getColumnDimension($column)->setWidth($width);
        }
    }

    private function writeRow(Worksheet $sheet, int $rowNumber, array $values, bool $header = false): void
    {
        foreach ($values as $index => $value) {
            $cell = Coordinate::stringFromColumnIndex($index + 1).$rowNumber;

            if ($header || is_string($value) || $value === null || is_bool($value)) {
                $sheet->setCellValueExplicit($cell, $value === null ? '' : (string) $value, DataType::TYPE_STRING);
                continue;
            }

            $sheet->setCellValue($cell, $value);
        }
    }

    private function download(Spreadsheet $spreadsheet, string $filename): StreamedResponse
    {
        return response()->streamDownload(function () use ($spreadsheet): void {
            $writer = new Xlsx($spreadsheet);
            $writer->setPreCalculateFormulas(false);
            $writer->save('php://output');
            $spreadsheet->disconnectWorksheets();
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'private, no-store, no-cache, must-revalidate, max-age=0',
        ]);
    }

    private function urgencyNameMap(): Collection
    {
        return MasterRecord::query()
            ->forWorkspace(app(MasterDataService::class)->workspaceId())
            ->whereIn('type', ['production_urgency', 'shipment_urgency'])
            ->pluck('name', 'id');
    }

    private function masterNames(mixed $ids, Collection $map): string
    {
        return collect((array) $ids)
            ->map(fn ($id) => $map->get((int) $id))
            ->filter()
            ->implode(', ');
    }

    private function positiveInt(mixed $value): ?int
    {
        $value = is_numeric($value) ? (int) $value : 0;
        return $value > 0 ? $value : null;
    }

    private function yesNo(mixed $value): string
    {
        return $value ? 'Yes' : 'No';
    }

    private function plainText(mixed $value): string
    {
        if ($value === null) return '';
        $text = html_entity_decode(strip_tags((string) $value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[\t ]+/', ' ', $text) ?? $text;
        $text = preg_replace('/\R{3,}/u', "\n\n", $text) ?? $text;
        return trim($text);
    }

    private function date(mixed $value): string
    {
        if (!$value) return '';
        if (is_object($value) && method_exists($value, 'format')) return $value->format('Y-m-d');
        return (string) $value;
    }

    private function dateTime(mixed $value): string
    {
        if (!$value) return '';
        if (is_object($value) && method_exists($value, 'timezone') && method_exists($value, 'format')) {
            return $value->copy()->timezone(app(WorkspaceSettingsService::class)->displayTimezone())->format('Y-m-d H:i:s');
        }
        if (is_object($value) && method_exists($value, 'format')) return $value->format('Y-m-d H:i:s');
        return (string) $value;
    }

    private function json(mixed $value): string
    {
        if ($value === null || $value === [] || $value === '') return '';
        if (is_string($value)) return $value;
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
