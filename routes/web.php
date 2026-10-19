<?php

use App\Http\Controllers\AdministrationController;
use App\Http\Controllers\SupplierArtworkReminderController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\ArtworkChunkUploadController;
use App\Http\Controllers\BoardController;
use App\Http\Controllers\BulkOrderImportController;
use App\Http\Controllers\CancelledOrdersController;
use App\Http\Controllers\CancelledOrdersExportController;
use App\Http\Controllers\BrandingAssetController;
use App\Http\Controllers\ClientsController;
use App\Http\Controllers\ClientLogoController;
use App\Http\Controllers\CompanySetupController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocumentsController;
use App\Http\Controllers\FilterOptionController;
use App\Http\Controllers\JobsController;
use App\Http\Controllers\InquiriesController;
use App\Http\Controllers\InvoicePdfController;
use App\Http\Controllers\ListExportController;
use App\Http\Controllers\FinanceAttachmentController;
use App\Http\Controllers\MasterDataController;
use App\Http\Controllers\MyWorkController;
use App\Http\Controllers\NotificationsController;
use App\Http\Controllers\OrderWorkflowSetupController;
use App\Http\Controllers\OrderSummaryReportController;
use App\Http\Controllers\OrderSummaryExportController;
use App\Http\Controllers\NotificationOpenController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProfileImageController;
use App\Http\Controllers\ProductDocumentController;
use App\Http\Controllers\ProductImageController;
use App\Http\Controllers\ProductOptionImageController;
use App\Http\Controllers\RichTextImageController;
use App\Http\Controllers\ReportsController;
use App\Http\Controllers\Rfq\PublicInquiryRfqController;
use App\Http\Controllers\WorkflowSetupController;
use App\Http\Controllers\TaskPackSetupController;
use App\Http\Controllers\TeamPerformanceReportController;
use App\Http\Controllers\OrderTrackingController;
use App\Http\Controllers\OrderQrCodeController;
use App\Http\Controllers\UserCreateController;
use App\Http\Controllers\UserEditController;
use App\Models\Document;
use App\Support\StoredFileResponse;
use App\Services\JobService;
use Illuminate\Support\Facades\Route;


// Binary product assets are restricted to the signed, supplier-scoped bridge.
Route::get('/api/supplier-bridge/product-image/{supplier}/{product}', \App\Http\Controllers\SupplierBridge\SupplierProductImage::class)
    ->whereNumber('supplier')->whereNumber('product')->middleware('throttle:120,1');

Route::get('/branding-assets/{type}/{filename}/workspace/{workspace}', BrandingAssetController::class)
    ->where('type', 'logo|favicon')
    ->where('filename', '[A-Za-z0-9_-]+\.(?:jpg|jpeg|png|webp|ico)')
    ->whereNumber('workspace')
    ->name('branding-assets.workspace');
Route::get('/branding-assets/{type}/{filename}', BrandingAssetController::class)
    ->where('type', 'logo|favicon')
    ->where('filename', '[A-Za-z0-9_-]+\.(?:jpg|jpeg|png|webp|ico)')
    ->name('branding-assets.show');


Route::get('/rfq/{token}', [PublicInquiryRfqController::class, 'show'])
    ->middleware('throttle:60,1')
    ->where('token', '[A-Za-z0-9]{32,100}')
    ->name('rfq.public.show');
Route::post('/rfq/{token}', [PublicInquiryRfqController::class, 'respond'])
    ->middleware('throttle:30,1')
    ->where('token', '[A-Za-z0-9]{32,100}')
    ->name('rfq.public.respond');
Route::post('/rfq/{token}/documents', [PublicInquiryRfqController::class, 'uploadDocuments'])
    ->middleware('throttle:20,1')
    ->where('token', '[A-Za-z0-9]{32,100}')
    ->name('rfq.public.documents.upload');
Route::post('/rfq/{token}/documents/{document}/remove', [PublicInquiryRfqController::class, 'removeDocument'])
    ->middleware('throttle:30,1')
    ->where(['token' => '[A-Za-z0-9]{32,100}', 'document' => '[0-9]+'])
    ->name('rfq.public.documents.remove');
Route::get('/rfq/{token}/documents/{document}/preview', [PublicInquiryRfqController::class, 'previewDocument'])
    ->middleware('throttle:60,1')
    ->where(['token' => '[A-Za-z0-9]{32,100}', 'document' => '[0-9]+'])
    ->name('rfq.public.documents.preview');
Route::get('/rfq/{token}/documents/{document}/download', [PublicInquiryRfqController::class, 'downloadDocument'])
    ->middleware('throttle:60,1')
    ->where(['token' => '[A-Za-z0-9]{32,100}', 'document' => '[0-9]+'])
    ->name('rfq.public.documents.download');
Route::get('/rfq/{token}/products/{item}/image', [PublicInquiryRfqController::class, 'productImage'])
    ->middleware('throttle:120,1')
    ->where(['token' => '[A-Za-z0-9]{32,100}', 'item' => '[0-9]+'])
    ->name('rfq.public.product-image');

Route::get('/session/recover', function (\Illuminate\Http\Request $request) {
    // Recovery is intentionally a GET: it is the safe landing point after a
    // CSRF/session mismatch, where the old server-side session is discarded
    // and the browser receives a fresh session/CSRF cookie pair.
    $request->session()->invalidate();
    $request->session()->regenerateToken();

    return redirect()->route('login', ['reason' => 'session-refresh'])
        ->withHeaders([
            'Cache-Control' => 'private, no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
        ]);
})->name('session.recover');

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'create'])->name('login');
    Route::post('/login', [AuthController::class, 'store'])->name('login.store');
});

if ((bool) config('flowtrack.order_tracking_enabled', false)) {
    Route::prefix('track')->middleware('web')->group(function () {
        Route::get('/', [OrderTrackingController::class, 'index'])
            ->middleware('throttle:60,1')
            ->name('order.track');
        Route::post('/lookup', [OrderTrackingController::class, 'lookup'])
            ->middleware('throttle:12,1')
            ->name('order.track.lookup');
        Route::get('/{token}', [OrderTrackingController::class, 'show'])
            ->middleware('throttle:60,1')
            ->where('token', '[A-Za-z0-9]{32,64}')
            ->name('order.track.show');
    });
}

Route::get('/session/status', function () {
    return response()->json(['ok' => true, 'user_id' => auth()->id()]);
})->middleware('auth')->name('session.status');

Route::middleware(['auth', 'super.admin'])->prefix('administration/supplier-prices')->name('supplier-prices.')->group(function () {
    Route::get('/', [\App\Http\Controllers\SupplierBridge\SupplierPriceReviewController::class, 'index'])->name('index');
    Route::post('/update/{supplier}/{product}', [\App\Http\Controllers\SupplierBridge\SupplierPriceReviewController::class, 'updatePrice'])
        ->whereNumber('supplier')->whereNumber('product')->middleware('throttle:30,1')->name('update');
    Route::post('/{proposal}/review', [\App\Http\Controllers\SupplierBridge\SupplierPriceReviewController::class, 'review'])
        ->whereNumber('proposal')->middleware('throttle:30,1')->name('review');
});

Route::middleware(['auth', 'super.admin'])->prefix('administration/reminders/supplier-artwork')->name('supplier-reminder.')->group(function () {
    Route::get('/', [SupplierArtworkReminderController::class, 'index'])->name('index');
    Route::post('/create', [SupplierArtworkReminderController::class, 'create'])->name('create');
    Route::post('/save', [SupplierArtworkReminderController::class, 'save'])->name('save');
    Route::post('/pause', [SupplierArtworkReminderController::class, 'pause'])->name('pause');
    Route::post('/preview', [SupplierArtworkReminderController::class, 'preview'])->name('preview');
    Route::post('/test', [SupplierArtworkReminderController::class, 'test'])->middleware('throttle:3,1')->name('test');
    Route::get('/history', [SupplierArtworkReminderController::class, 'history'])->name('history');
});

Route::middleware('auth')->group(function () {
    if ((bool) config('flowtrack.order_tracking_enabled', false)) {
        Route::get('/orders/{job}/qr-code/download', [OrderQrCodeController::class, 'download'])->name('orders.qr.download');
    }
    Route::post('/session/timezone', function (\Illuminate\Http\Request $request) {
        $data = $request->validate([
            'timezone' => ['required', 'string', 'max:120'],
        ]);

        abort_unless(in_array($data['timezone'], \DateTimeZone::listIdentifiers(), true), 422, 'Invalid time zone.');
        $request->session()->put('flowtrack_timezone', $data['timezone']);

        return response()->noContent();
    })->name('session.timezone');
    Route::post('/realtime/auth', function (\Illuminate\Http\Request $request) {
        $data = $request->validate([
            'socket_id' => ['required','string','max:80'],
            'channel_name' => ['required','string','max:160'],
        ]);

        return response()->json(app(\App\Services\ReverbChannelService::class)->authenticate(
            $data['socket_id'], $data['channel_name'], (int) auth()->id()
        ));
    })->name('realtime.auth');
    Route::redirect('/', '/dashboard');

    if (app()->environment('local', 'testing')) {
        Route::view('/_dev/ui-kit', 'dev.ui-kit')->name('dev.ui-kit');
    }

    Route::get('/dashboard', DashboardController::class)->middleware('permission:dashboard.view')->name('dashboard');
    Route::get('/team-performance-report', TeamPerformanceReportController::class)->middleware('permission:reports.view')->name('team-performance.report');
    Route::get('/order-summary-report', OrderSummaryReportController::class)->middleware(['permission:reports.view', 'permission:jobs.view'])->name('order-summary.report');
    Route::get('/order-summary-report/export', OrderSummaryExportController::class)->middleware(['permission:reports.view', 'permission:reports.export', 'permission:jobs.view'])->name('order-summary.export');
    Route::get('/filter-options/{type}', FilterOptionController::class)->where('type', '[a-z-]+')->name('filter-options.index');
    Route::get('/my-work', MyWorkController::class)->middleware('permission:tasks.view')->name('my-work');
    Route::get('/inquiries', InquiriesController::class)->middleware('permission:inquiries.view')->name('inquiries.index');
    Route::get('/inquiries/export', [ListExportController::class, 'inquiries'])->middleware(['permission:inquiries.view', 'permission:reports.export'])->name('inquiries.export');
    Route::get('/orders/bulk-import', [BulkOrderImportController::class, 'index'])->middleware('permission:jobs.create')->name('orders.bulk-import');
    Route::post('/orders/bulk-import/validate', [BulkOrderImportController::class, 'validateUpload'])->middleware('permission:jobs.create')->name('orders.bulk-import.validate');
    Route::post('/orders/bulk-import/revalidate', [BulkOrderImportController::class, 'revalidate'])->middleware('permission:jobs.create')->name('orders.bulk-import.revalidate');
    Route::post('/orders/bulk-import/import', [BulkOrderImportController::class, 'import'])->middleware('permission:jobs.create')->name('orders.bulk-import.import');
    Route::get('/orders/bulk-import/template', [BulkOrderImportController::class, 'template'])->middleware('permission:jobs.create')->name('orders.bulk-import.template');
    Route::get('/orders/export', [ListExportController::class, 'orders'])->middleware(['permission:jobs.view', 'permission:reports.export'])->name('orders.export');
    Route::get('/orders/cancelled', CancelledOrdersController::class)->middleware('permission:jobs.view')->name('orders.cancelled');
    Route::get('/orders/cancelled/export', CancelledOrdersExportController::class)->middleware(['permission:jobs.view', 'permission:reports.export'])->name('orders.cancelled.export');
    // Large Artwork uses small resumable-ish chunks instead of one long
    // Livewire temporary-upload request. Standard document uploads are unchanged.
    Route::post('/orders/artwork-uploads', [ArtworkChunkUploadController::class, 'start'])
        ->middleware(['permission:documents.create', 'throttle:240,1'])
        ->name('orders.artwork-uploads.start');
    Route::post('/orders/artwork-uploads/{token}/chunks', [ArtworkChunkUploadController::class, 'chunk'])
        ->middleware(['permission:documents.create', 'throttle:600,1'])
        ->whereUuid('token')
        ->name('orders.artwork-uploads.chunk');
    Route::post('/orders/artwork-uploads/{token}/complete', [ArtworkChunkUploadController::class, 'complete'])
        ->middleware(['permission:documents.create', 'throttle:240,1'])
        ->whereUuid('token')
        ->name('orders.artwork-uploads.complete');
    Route::delete('/orders/artwork-uploads/{token}', [ArtworkChunkUploadController::class, 'destroy'])
        ->middleware(['permission:documents.create', 'throttle:240,1'])
        ->whereUuid('token')
        ->name('orders.artwork-uploads.destroy');

    Route::get('/orders', JobsController::class)->middleware('permission:jobs.view')->name('jobs.index');
    Route::get('/jobs', function (\Illuminate\Http\Request $request) {
        return redirect()->route('jobs.index', $request->query());
    })->middleware('permission:jobs.view')->name('jobs.legacy');
    Route::get('/clients', ClientsController::class)->middleware('permission:clients.view')->name('clients.index');
    Route::get('/all-tasks', BoardController::class)->middleware('super.admin')->name('all-tasks');
    Route::get('/documents', DocumentsController::class)->middleware('permission:document_archive.view')->name('documents.index');
    Route::get('/document-archive/inquiries/{document}/open', function (\App\Models\InquiryDocument $document) {
        app(\App\Services\AccessControlService::class)
            ->applyInquiryDocumentArchiveScope(\App\Models\InquiryDocument::query()->whereKey($document->id), auth()->user())
            ->firstOrFail();

        return StoredFileResponse::inline((string) $document->path, (string) $document->name, $document->mime_type);
    })->middleware('permission:document_archive.view')->name('document-archive.inquiries.open');
    Route::get('/document-archive/inquiries/{document}/download', function (\App\Models\InquiryDocument $document) {
        abort_unless(auth()->user()->canModule('document_archive', 'export'), 403);
        app(\App\Services\AccessControlService::class)
            ->applyInquiryDocumentArchiveScope(\App\Models\InquiryDocument::query()->whereKey($document->id), auth()->user())
            ->firstOrFail();

        return StoredFileResponse::download((string) $document->path, (string) $document->name, $document->mime_type);
    })->name('document-archive.inquiries.download');
    Route::get('/document-archive/orders/{document}/open', function (Document $document) {
        app(\App\Services\AccessControlService::class)
            ->applyDocumentScope(Document::query()->whereKey($document->id), auth()->user(), 'document_archive')
            ->firstOrFail();

        return StoredFileResponse::inline((string) $document->path, (string) $document->name, $document->mime_type);
    })->middleware('permission:document_archive.view')->name('document-archive.orders.open');
    Route::get('/document-archive/orders/{document}/download', function (Document $document) {
        abort_unless(auth()->user()->canModule('document_archive', 'export'), 403);
        app(\App\Services\AccessControlService::class)
            ->applyDocumentScope(Document::query()->whereKey($document->id), auth()->user(), 'document_archive')
            ->firstOrFail();

        return StoredFileResponse::download((string) $document->path, (string) $document->name, $document->mime_type);
    })->name('document-archive.orders.download');

    Route::get('/inquiries/rfq-quote-documents/{document}/download', function (\App\Models\InquiryRfqQuoteDocument $document) {
        abort_unless(auth()->user()->canModule('documents', 'view'), 403);
        $inquiryId = $document->quote?->invitation?->inquiry_id;
        abort_unless($inquiryId && app(\App\Services\InquiryService::class)
            ->visibleQuery(auth()->user())->whereKey($inquiryId)->exists(), 404);
        return StoredFileResponse::download((string)$document->path, (string)$document->name, $document->mime_type);
    })->name('inquiries.rfq-quote-documents.download');

    Route::get('/inquiries/documents/{document}/open', function (\App\Models\InquiryDocument $document) {
        abort_unless(auth()->user()->canModule('documents', 'view'), 403);
        app(\App\Services\InquiryService::class)->visibleQuery(auth()->user())->whereKey($document->inquiry_id)->firstOrFail();

        return StoredFileResponse::inline((string) $document->path, (string) $document->name, $document->mime_type);
    })->name('inquiries.documents.open');
    Route::get('/inquiries/documents/{document}/download', function (\App\Models\InquiryDocument $document) {
        abort_unless(auth()->user()->canModule('documents', 'export'), 403);
        app(\App\Services\InquiryService::class)->visibleQuery(auth()->user())->whereKey($document->inquiry_id)->firstOrFail();

        return StoredFileResponse::download((string) $document->path, (string) $document->name, $document->mime_type);
    })->name('inquiries.documents.download');
    Route::get('/documents/{document}/open', function (Document $document) {
        if (! (bool) config('flowtrack.order_tracking_enabled', false) && $document->category === Document::CATEGORY_ARTWORK_TRACKING_PDF) {
            abort(404);
        }
        app(\App\Services\AccessControlService::class)->applyDocumentScope(Document::query()->whereKey($document->id), auth()->user())->firstOrFail();

        return StoredFileResponse::inline((string) $document->path, (string) $document->name, $document->mime_type);
    })->name('documents.open');
    Route::get('/invoices/{invoice}/pdf/open', [InvoicePdfController::class, 'open'])->whereNumber('invoice')->name('invoices.pdf.open');
    Route::get('/invoices/{invoice}/pdf/download', [InvoicePdfController::class, 'download'])->whereNumber('invoice')->name('invoices.pdf.download');
    Route::get('/invoices/{invoice}/attachment/open', [FinanceAttachmentController::class, 'invoiceOpen'])->whereNumber('invoice')->name('invoices.attachment.open');
    Route::get('/invoices/{invoice}/attachment/download', [FinanceAttachmentController::class, 'invoiceDownload'])->whereNumber('invoice')->name('invoices.attachment.download');
    Route::get('/payments/{payment}/receipt/open', [FinanceAttachmentController::class, 'paymentOpen'])->whereNumber('payment')->name('payments.receipt.open');
    Route::get('/payments/{payment}/receipt/download', [FinanceAttachmentController::class, 'paymentDownload'])->whereNumber('payment')->name('payments.receipt.download');
    Route::get('/documents/{document}/download', function (Document $document) {
        abort_unless(auth()->user()->canModule('documents', 'export'), 403);
        if (! (bool) config('flowtrack.order_tracking_enabled', false) && $document->category === Document::CATEGORY_ARTWORK_TRACKING_PDF) {
            abort(404);
        }
        app(\App\Services\AccessControlService::class)->applyDocumentScope(Document::query()->whereKey($document->id), auth()->user())->firstOrFail();

        return StoredFileResponse::download((string) $document->path, (string) $document->name, $document->mime_type);
    })->name('documents.download');
    Route::get('/inquiry-intelligence', ReportsController::class)->middleware('permission:reports.view')->name('reports');
    Route::get('/notifications', NotificationsController::class)->middleware('permission:notifications.view')->name('notifications');
    Route::get('/notifications/{notification}/open', NotificationOpenController::class)->middleware('permission:notifications.view')->whereNumber('notification')->name('notifications.open');
    Route::get('/notifications/unread-count', function () {
        $user = auth()->user();
        $service = app(\App\Services\NotificationService::class);
        $shell = app(\App\Services\ShellDataService::class)->for($user);
        $latest = $service->latest($user);

        return response()->json([
            'count' => (int) ($shell['unread_notifications'] ?? 0),
            'my_work_count' => (int) ($shell['open_my_work'] ?? 0),
            'cancelled_order_count' => (int) ($shell['cancelled_orders'] ?? 0),
            'data_version' => app(\App\Services\WorkspaceRefreshService::class)->version(),
            'latest' => $latest ? [
                'id' => $latest->id,
                'type' => $latest->type,
                'title' => $latest->title,
                'message' => app(\App\Services\RichTextService::class)->plainText($latest->message),
                'url' => $service->urlFor($latest),
                'created_at' => $latest->created_at?->toIso8601String(),
            ] : null,
        ]);
    })->name('notifications.unread-count');
    Route::get('/profile-images/{user}/{filename}', ProfileImageController::class)
        ->whereNumber('user')
        ->where('filename', '[A-Za-z0-9_-]+\.(?:jpg|jpeg|png|webp)')
        ->name('profile-images.show');
    Route::get('/client-logos/{client}/{filename}', ClientLogoController::class)
        ->whereNumber('client')
        ->where('filename', '[A-Za-z0-9_-]+\.(?:jpg|jpeg|png|webp)')
        ->name('client-logos.show');
    Route::get('/master-data/products/{product}/image/{filename}', ProductImageController::class)
        ->whereNumber('product')
        ->where('filename', '[A-Za-z0-9_-]+\.(?:jpg|jpeg|png|webp)')
        ->name('master-data.product-image');
    Route::get('/master-data/products/{product}/options/{optionKey}/image/{filename}', ProductOptionImageController::class)
        ->whereNumber('product')
        ->where('optionKey', '[A-Za-z0-9-]{8,80}')
        ->where('filename', '[A-Za-z0-9_-]+\.(?:jpg|jpeg|png|webp)')
        ->name('master-data.product-option-image');
    Route::get('/master-data/products/{product}/documents/{kind}/{filename}', ProductDocumentController::class)
        ->whereNumber('product')
        ->whereIn('kind', ['certificate', 'template'])
        ->where('filename', '[A-Za-z0-9._-]+')
        ->name('master-data.product-document');
    Route::post('/rich-text-images', [RichTextImageController::class, 'store'])
        ->name('rich-text-images.store');
    Route::get('/rich-text-images/{filename}/download', [RichTextImageController::class, 'download'])
        ->where('filename', '[A-Za-z0-9-]+\.(?:jpg|jpeg|png|webp|gif)')
        ->name('rich-text-images.download');
    Route::get('/rich-text-images/{filename}', [RichTextImageController::class, 'show'])
        ->where('filename', '[A-Za-z0-9-]+\.(?:jpg|jpeg|png|webp|gif)')
        ->name('rich-text-images.show');
    Route::get('/profile', ProfileController::class)->name('profile');
    Route::get('/users/create', UserCreateController::class)->middleware('super.admin')->name('users.create');
    Route::get('/users/{user}/edit', UserEditController::class)->whereNumber('user')->name('users.edit');
    Route::post('/logout', [AuthController::class, 'destroy'])->name('logout');

    Route::get('/order-workflow-setup', OrderWorkflowSetupController::class)->middleware('permission:workflow.view')->name('order-workflow.setup');

    Route::get('/workflow-setup', [WorkflowSetupController::class, 'index'])->middleware('permission:workflow.view')->name('workflow.setup');
    Route::get('/workflow-setup/create', [WorkflowSetupController::class, 'create'])->middleware('permission:workflow.create')->name('workflow.create');
    Route::get('/workflow-setup/{workflow}/edit', [WorkflowSetupController::class, 'edit'])->middleware('permission:workflow.update')->whereNumber('workflow')->name('workflow.edit');

    Route::get('/task-pack-setup', [TaskPackSetupController::class, 'index'])->middleware('permission:taskpacks.view')->name('task-pack.setup');
    Route::get('/task-pack-setup/create', [TaskPackSetupController::class, 'create'])->middleware('permission:taskpacks.create')->name('task-pack.create');
    Route::get('/task-pack-setup/{taskPack}/edit', [TaskPackSetupController::class, 'edit'])->middleware('permission:taskpacks.update')->whereNumber('taskPack')->name('task-pack.edit');
    Route::get('/financial-master-data', MasterDataController::class)
        ->middleware('permission:finance.view')
        ->name('financial-master-data');
    Route::get('/master-data', MasterDataController::class)->name('master-data');
    Route::get('/company-setup', CompanySetupController::class)->middleware('super.admin')->name('company.setup');
    Route::get('/administration', AdministrationController::class)->middleware('super.admin')->name('administration');
});
