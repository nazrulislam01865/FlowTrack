<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\FlowJob;
use App\Models\FlowJobPhaseHistory;
use App\Models\Task;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowPhase;
use App\Services\DashboardService;
use App\Services\ReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class DashboardReportsLazyLoadingTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_attention_query_returns_a_bounded_collection(): void
    {
        $user = User::factory()->create(['is_super_admin' => true, 'is_active' => true]);

        $attentionJobs = app(DashboardService::class)->attentionJobs($user);

        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Collection::class, $attentionJobs);
        $this->assertLessThanOrEqual(6, $attentionJobs->count());
    }

    public function test_dashboard_and_report_metrics_are_calculated_from_records(): void
    {
        Cache::flush();
        $user = User::factory()->create(['is_super_admin' => true, 'is_active' => true]);
        $client = Client::create(['name' => 'Metrics Client', 'code' => 'METRICS', 'is_active' => true]);
        $workflow = Workflow::create(['name' => 'Metrics Workflow', 'slug' => 'metrics-workflow', 'is_active' => true]);
        $artwork = WorkflowPhase::create([
            'workflow_id' => $workflow->id,
            'sequence' => 1,
            'name' => 'Artwork Approval',
            'short_name' => 'Artwork',
            'allow_job_start' => true,
            'is_active' => true,
        ]);
        $shipment = WorkflowPhase::create([
            'workflow_id' => $workflow->id,
            'sequence' => 2,
            'name' => 'Shipment',
            'short_name' => 'Ship',
            'allow_job_start' => true,
            'is_active' => true,
        ]);

        $activeJob = FlowJob::create([
            'job_number' => 'JOB-METRICS-ACTIVE',
            'client_id' => $client->id,
            'workflow_id' => $workflow->id,
            'workflow_phase_id' => $shipment->id,
            'title' => 'Active shipping job',
            'status' => 'Active',
            'health' => 'On Track',
            'priority' => 'Medium',
            'delivery_date' => today()->addDay(),
        ]);
        $completedJob = FlowJob::create([
            'job_number' => 'JOB-METRICS-COMPLETE',
            'client_id' => $client->id,
            'workflow_id' => $workflow->id,
            'workflow_phase_id' => $shipment->id,
            'title' => 'Completed shipping job',
            'status' => 'Completed',
            'health' => 'Completed',
            'priority' => 'Medium',
            'delivery_date' => today(),
            'completed_at' => today()->setTime(12, 0),
        ]);

        Task::create([
            'task_number' => 'TASK-METRICS-DONE',
            'flow_job_id' => $completedJob->id,
            'workflow_phase_id' => $artwork->id,
            'title' => 'Completed artwork',
            'status' => 'Completed',
            'priority' => 'Medium',
            'completed_at' => now(),
        ]);
        Task::create([
            'task_number' => 'TASK-METRICS-OVERDUE',
            'flow_job_id' => $activeJob->id,
            'workflow_phase_id' => $shipment->id,
            'title' => 'Overdue shipment task',
            'status' => 'In Progress',
            'priority' => 'High',
            'due_date' => today()->subDay(),
        ]);

        FlowJobPhaseHistory::create([
            'flow_job_id' => $completedJob->id,
            'workflow_phase_id' => $artwork->id,
            'status' => 'completed',
            'entered_at' => now()->subDays(4),
            'completed_at' => now()->subDays(2),
        ]);
        FlowJobPhaseHistory::create([
            'flow_job_id' => $completedJob->id,
            'workflow_phase_id' => $shipment->id,
            'status' => 'completed',
            'target_date' => today(),
            'entered_at' => now()->subDay(),
            'completed_at' => today()->setTime(10, 0),
        ]);

        $dashboard = app(DashboardService::class)->data($user);
        $reports = app(ReportService::class)->data($user);

        $this->assertSame(1, $dashboard['metrics']['activeJobs']);
        $this->assertSame(1, $dashboard['metrics']['shipping']);
        $this->assertSame(1, $dashboard['metrics']['overdueTasks']);
        $this->assertSame(1, $reports['kpis']['active_jobs']);
        $this->assertSame(1, $reports['kpis']['completed_jobs']);
        $this->assertSame(100, $reports['kpis']['on_time']);
        $this->assertSame(50, $reports['kpis']['task_completion']);
        $this->assertSame(1, $reports['kpis']['overdue_tasks']);
        $this->assertSame(2.0, $reports['kpis']['avg_artwork_cycle']);
        $this->assertSame(100, $reports['kpis']['shipment_on_time']);
    }

    public function test_dashboard_uses_stable_render_components_and_omits_guide_ui(): void
    {
        $page = file_get_contents(resource_path('views/pages/dashboard.blade.php'));
        $dashboard = file_get_contents(resource_path('views/livewire/dashboard/index.blade.php'));
        $dashboardComponent = file_get_contents(app_path('Livewire/Dashboard/Index.php'));
        $taggedComponent = file_get_contents(app_path('Livewire/Dashboard/TaggedComments.php'));
        $secondaryComponent = file_get_contents(app_path('Livewire/Dashboard/Secondary.php'));
        $dashboardService = file_get_contents(app_path('Services/LegacyDashboardService.php'));
        $readModelCache = file_get_contents(app_path('Services/Dashboard/DashboardReadModelCache.php'));
        $secondaryQuery = file_get_contents(app_path('Queries/Dashboard/DashboardSecondaryQuery.php'));
        $appJs = file_get_contents(resource_path('js/app.js'));
        $timezoneJs = file_get_contents(resource_path('js/core/timezone.js'));
        $dashboardCss = $this->compatibilityCss('flowtrack-dashboard-prototype.css');
        $secondaryView = file_get_contents(resource_path('views/livewire/dashboard/secondary.blade.php'));

        $this->assertStringContainsString('<livewire:dashboard.index />', $page);
        $this->assertStringContainsString('<livewire:dashboard.tagged-comments', $dashboard);
        $this->assertStringNotContainsString('<livewire:dashboard.secondary', $dashboard);
        $this->assertStringContainsString('public function placeholder(): string', $taggedComponent);
        $this->assertStringContainsString('Loading comments...', $taggedComponent);
        $this->assertStringNotContainsString('wire:init=', $dashboard);
        $this->assertStringNotContainsString('window.location.reload()', $appJs);
        $this->assertStringContainsString('attemptedTimezone', $timezoneJs);
        $this->assertStringContainsString('animation:none', $dashboardCss);
        $this->assertStringContainsString('content-visibility:visible', $dashboardCss);
        $this->assertStringContainsString('flowtrack-notification', $dashboardComponent);
        $this->assertStringContainsString('markAllRead', $taggedComponent);
        $this->assertStringContainsString('MarkDashboardMentionsRead::class', $taggedComponent);
        $this->assertStringContainsString('dashboardMentionQuery', $dashboardService);
        $this->assertStringContainsString("flow_notifications.inquiry_task_id", $dashboardService);
        $this->assertStringContainsString("flow_notifications.inquiry_id", $dashboardService);
        $this->assertStringNotContainsString("whereColumn('flow_task_comments.body', 'flow_notifications.message')", $dashboardService);
        $this->assertStringNotContainsString("whereColumn('activities.description', 'flow_notifications.message')", $dashboardService);
        $this->assertStringContainsString('DashboardSecondaryQuery::class', $secondaryComponent);
        $this->assertStringContainsString('DashboardTeamPerformanceQuery', $secondaryQuery);
        $this->assertStringContainsString('DashboardClientPortfolioQuery', $secondaryQuery);
        $this->assertStringNotContainsString('secondaryData(', $secondaryQuery);
        $this->assertStringContainsString('@forelse($clientPortfolio as $portfolioClient)', $secondaryView);
        $this->assertStringContainsString(':client="$portfolioClient"', $secondaryView);
        $this->assertStringContainsString('@endforelse', $secondaryView);
        $this->assertStringNotContainsString('@foreach($clientPortfolio as $portfolioClient)', $secondaryView);
        $this->assertStringContainsString("CACHE_VERSION = 'v19-shipping-phase-compat'", $dashboardService);
        $this->assertStringContainsString('dashboard_cache_seconds', $readModelCache);
        $this->assertStringContainsString('isSafeCacheValue', $dashboardService);
        $this->assertStringContainsString('private ?int $clientLifecycleVersion = null;', $dashboardService);
        $this->assertStringContainsString('Cache::get($key, $missing)', $dashboardService);
        $this->assertStringContainsString('Cache::put(', $dashboardService);
        $this->assertStringNotContainsString('return Cache::remember(', $dashboardService);
        $this->assertStringNotContainsString("return \$this->remember(\$user, 'mentions'", $dashboardService);
        $this->assertStringNotContainsString("return \$this->remember(\$user, 'assignees'", $dashboardService);
        $this->assertStringNotContainsString("return \$this->remember(\$user, 'ongoing-jobs'", $dashboardService);
        $this->assertStringNotContainsString("return \$this->remember(\$user, 'ongoing-tasks'", $dashboardService);
    }

}
