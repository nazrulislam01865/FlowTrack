<?php

namespace App\Providers;

use App\Models\Client;
use App\Models\Document;
use App\Models\FlowJob;
use App\Models\Inquiry;
use App\Models\InquiryTask;
use App\Models\MasterRecord;
use App\Models\Task;
use App\Policies\ClientPolicy;
use App\Policies\DocumentPolicy;
use App\Policies\FlowJobPolicy;
use App\Policies\InquiryPolicy;
use App\Policies\InquiryTaskPolicy;
use App\Policies\TaskPolicy;
use App\Observers\WorkspaceDataObserver;
use App\Services\AccessControlService;
use App\Services\BrandingService;
use App\Services\MentionService;
use App\Services\MasterDataService;
use App\Services\OrderWorkflowBindingService;
use App\Services\OrderWorkflowSetupService;
use App\Services\ShellDataService;
use App\Services\StoredAssetUrlService;
use App\Services\SetupContext;
use App\Services\WorkspaceContext;
use App\Support\Performance\RequestPerformanceMonitor;
use App\Services\Observability\OperationsMetrics;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Cache\Events\CacheFailedOver;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\KeyForgotten;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\QueueBusy;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use RuntimeException;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(AccessControlService::class);
        $this->app->scoped(MentionService::class);
        $this->app->scoped(MasterDataService::class);
        $this->app->scoped(OrderWorkflowSetupService::class);
        $this->app->scoped(OrderWorkflowBindingService::class);
        $this->app->scoped(BrandingService::class);
        $this->app->scoped(RequestPerformanceMonitor::class);
        $this->app->scoped(ShellDataService::class);
        $this->app->scoped(StoredAssetUrlService::class);
        $this->app->scoped(WorkspaceContext::class);
        $this->app->scoped(SetupContext::class);
        $this->app->scoped(\App\Services\WorkspaceRefreshService::class);
    }

    public function boot(): void
    {
        // A signed supplier inquiry file must not be rejected because other
        // supplier/order bridge actions from the same server exhausted the
        // generic IP throttle counter. The HMAC and task scope remain enforced
        // by the receiving controller.
        RateLimiter::for('supplier-inquiry-task-bridge', static fn (Request $request): Limit =>
            Limit::perMinute(60)->by('signed-inquiry-task:' . $request->ip()));
        RateLimiter::for('supplier-inquiry-task-document-bridge', static fn (Request $request): Limit =>
            Limit::perMinute(120)->by('signed-inquiry-document:' . $request->ip()));

        // FlowTrack's Alpine component factories are installed by the Vite module in
        // <head>. Livewire ships its own Alpine runtime and otherwise starts it from a
        // classic script at the end of <body>, which can run before that module has
        // executed. Defer Livewire so window.FlowTrack.ui.inlineEdit is registered
        // before Alpine evaluates x-data on Order/Inquiry detail pages.
        app('livewire')->useScriptTagAttributes([
            'defer' => true,
        ]);

        if ((bool) config('performance.detect_lazy_loading', true) && app()->environment('local', 'testing')) {
            Model::preventLazyLoading();
            Model::handleLazyLoadingViolationUsing(function (Model $model, string $relation): void {
                Log::warning('flowtrack.performance.lazy_loading', [
                    'model' => $model::class,
                    'id' => $model->getKey(),
                    'relation' => $relation,
                ]);
            });
        }

        Gate::policy(FlowJob::class, FlowJobPolicy::class);
        Gate::policy(Inquiry::class, InquiryPolicy::class);
        Gate::policy(Task::class, TaskPolicy::class);
        Gate::policy(InquiryTask::class, InquiryTaskPolicy::class);
        Gate::policy(Document::class, DocumentPolicy::class);
        Gate::policy(Client::class, ClientPolicy::class);

        foreach (WorkspaceDataObserver::observedModels() as $modelClass) {
            $modelClass::observe(WorkspaceDataObserver::class);
        }

        \App\Models\Workspace::observe(\App\Observers\SupplierWorkspaceBrandObserver::class);
        InquiryTask::observe(\App\Observers\SupplierInquiryTaskObserver::class);

        // Supplier sync only reacts to writes, after commit. Existing page reads
        // and master-data screens do not perform any extra catalogue queries.
        foreach ([MasterRecord::class, FlowJob::class, \App\Models\FlowJobItem::class,
            Task::class, Document::class] as $modelClass) {
            $modelClass::observe(\App\Observers\SupplierBridgeSyncObserver::class);
        }

        Event::listen(QueryExecuted::class, function (QueryExecuted $event): void {
            app(RequestPerformanceMonitor::class)->recordQuery($event);
        });

        Event::listen(CacheHit::class, fn () => app(RequestPerformanceMonitor::class)->recordCacheHit());
        Event::listen(CacheMissed::class, fn () => app(RequestPerformanceMonitor::class)->recordCacheMiss());
        Event::listen(KeyWritten::class, fn () => app(RequestPerformanceMonitor::class)->recordCacheWrite());
        Event::listen(KeyForgotten::class, fn () => app(RequestPerformanceMonitor::class)->recordCacheForget());
        Event::listen(CacheFailedOver::class, fn () => app(RequestPerformanceMonitor::class)->recordCacheFailover());

        Event::listen(RequestSending::class, function (RequestSending $event): void {
            app(RequestPerformanceMonitor::class)->startOutgoing($event->request);
        });

        Event::listen(ResponseReceived::class, function (ResponseReceived $event): void {
            app(RequestPerformanceMonitor::class)->finishOutgoing($event->request, $event->response);
        });

        Event::listen(ConnectionFailed::class, function (ConnectionFailed $event): void {
            $exception = property_exists($event, 'exception') && $event->exception instanceof Throwable
                ? $event->exception
                : new RuntimeException('HTTP connection failed.');
            app(RequestPerformanceMonitor::class)->finishOutgoing($event->request, null, $exception);
        });

        // Queue failures/depth are infrastructure signals, not page concerns.
        // Keep them centrally logged so Supervisor/Redis failures can be
        // alerted from the normal application log pipeline.
        Event::listen(JobFailed::class, function (JobFailed $event): void {
            app(OperationsMetrics::class)->recordQueueFailure($event->job->resolveName(), $event->job->getQueue());
            Log::error('flowtrack.queue.failed', [
                'connection' => $event->connectionName,
                'queue' => $event->job->getQueue(),
                'job' => $event->job->resolveName(),
                'exception' => $event->exception->getMessage(),
            ]);
        });

        Event::listen(QueueBusy::class, function (QueueBusy $event): void {
            Log::warning('flowtrack.queue.busy', [
                'connection' => $event->connectionName,
                'queue' => $event->queue,
                'size' => $event->size,
                'max_depth' => (int) config('scalability.queues.max_depth', 100),
            ]);
        });

        View::composer(['layouts.app', 'auth.login', 'tracking.layout'], function ($view): void {
            $view->with('branding', app(BrandingService::class)->current());
        });

        View::composer('layouts.app', function ($view): void {
            $user = auth()->user();
            $view->with('shellData', $user ? app(ShellDataService::class)->for($user) : []);
        });
    }
}
