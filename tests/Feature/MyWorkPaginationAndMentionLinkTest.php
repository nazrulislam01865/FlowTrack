<?php

namespace Tests\Feature;

use Tests\TestCase;
use Tests\Support\OrderPhase5Source;

class MyWorkPaginationAndMentionLinkTest extends TestCase
{
    public function test_my_work_is_hard_limited_to_three_order_groups_per_page(): void
    {
        $service = file_get_contents(app_path('Services/MyWorkService.php'));
        $component = file_get_contents(app_path('Livewire/MyWork/Index.php'));
        $view = file_get_contents(resource_path('views/livewire/my-work/index.blade.php'));

        $this->assertStringContainsString('public const JOBS_PER_PAGE = 3;', $service);
        $this->assertStringContainsString('min(self::JOBS_PER_PAGE, $perPage)', $service);
        $this->assertStringContainsString('public int $perPage = MyWorkService::JOBS_PER_PAGE;', $component);
        $this->assertStringContainsString("previousPage('workPage')", $view);
        $this->assertStringContainsString("gotoPage({{ \$pageNumber }}, 'workPage')", $view);
        $this->assertStringContainsString("nextPage('workPage')", $view);
    }

    public function test_tagged_comments_open_through_safe_notification_resolver_and_focus_exact_comment(): void
    {
        $notificationService = file_get_contents(app_path('Services/NotificationService.php'));
        $controller = file_get_contents(app_path('Http/Controllers/NotificationOpenController.php'));
        $routes = file_get_contents(base_path('routes/web.php'));
        $tagged = file_get_contents(resource_path('views/livewire/dashboard/tagged-comments.blade.php'));
        $jobs = OrderPhase5Source::livewire();
        $taskDetail = OrderPhase5Source::taskDetailView();
        $jobActivity = file_get_contents(resource_path('views/components/jobs/detail-activity.blade.php'));

        $this->assertStringContainsString("route('notifications.open'", $notificationService);
        $this->assertStringContainsString("name('notifications.open')", $routes);
        $this->assertStringContainsString('NotificationService::class)->urlFor($mention)', $tagged);
        $this->assertStringContainsString('$params[\'comment\'] = \'task-\'', $controller);
        $this->assertStringContainsString('$params[\'comment\'] = \'job-\'', $controller);
        $this->assertStringContainsString("#task-comment-", $controller);
        $this->assertStringContainsString("#job-comment-", $controller);
        $this->assertStringContainsString("#[Url(as: 'comment', history: true)]", $jobs);
        $this->assertStringContainsString('$this->taskActivityTab = \'comments\';', $jobs);
        $this->assertStringContainsString('$this->jobActivityTab = \'comments\';', $jobs);
        $this->assertStringContainsString('id="{{ $entryAnchor }}"', $taskDetail);
        $this->assertStringContainsString('id="{{ $activityAnchor }}"', $jobActivity);
        $this->assertStringContainsString('is-focused-comment', $taskDetail);
        $this->assertStringContainsString('is-focused-comment', $jobActivity);
    }

    public function test_my_work_mentions_show_only_exact_mentioned_tasks_and_dashboard_realtime_refresh(): void
    {
        $notificationService = file_get_contents(app_path('Services/NotificationService.php'));
        $myWork = file_get_contents(app_path('Services/MyWorkService.php'));
        $dashboard = file_get_contents(app_path('Livewire/Dashboard/Index.php'));
        $tagged = file_get_contents(app_path('Livewire/Dashboard/TaggedComments.php'));

        $this->assertStringNotContainsString('->reject(fn ($id) => $actor && $id === (int) $actor->id)', $notificationService);
        $this->assertStringContainsString("->whereColumn('my_work_mention_activity.subject_id', 'tasks.id')", $myWork);
        $this->assertStringContainsString("->where('my_work_mention_activity.event', 'task.comment')", $myWork);
        $this->assertStringContainsString("mention_user_ids", $myWork);
        $this->assertStringNotContainsString("->whereColumn('flow_notifications.flow_job_id', 'tasks.flow_job_id')", $myWork);
        $this->assertStringNotContainsString("my_work_metric_job_mentions", $myWork);
        $this->assertStringContainsString("sibling tasks from the same Order", $myWork);
        $this->assertStringContainsString("#[On('flowtrack-notification')]", $dashboard);
        $this->assertStringContainsString("#[On('flowtrack-notification')]", $tagged);
    }
    public function test_dashboard_mentions_include_descriptions_and_inquiries_with_realtime_delivery(): void
    {
        $dashboardService = file_get_contents(app_path('Services/LegacyDashboardService.php'));
        $notificationService = file_get_contents(app_path('Services/NotificationService.php'));
        $inquiryService = $this->inquiryServiceSource();
        $tagged = file_get_contents(app_path('Livewire/Dashboard/TaggedComments.php'));
        $taggedView = file_get_contents(resource_path('views/livewire/dashboard/tagged-comments.blade.php'));

        $this->assertStringContainsString('dashboardMentionQuery', $dashboardService);
        $this->assertStringContainsString("flow_notifications.inquiry_id", $dashboardService);
        $this->assertStringContainsString("flow_notifications.inquiry_task_id", $dashboardService);
        $this->assertStringNotContainsString("flow_task_comments.body', 'flow_notifications.message'", $dashboardService);
        $this->assertStringContainsString('notifyInquiryMentionedUsers', $notificationService);
        $this->assertStringContainsString("'inquiry_id' => \$inquiry?->id", $notificationService);
        $this->assertStringContainsString('$this->notifyMentions($inquiry->refresh(), null, $newDisplay, $actor);', $inquiryService);
        $this->assertStringNotContainsString('if ((int) $recipient->id === (int) $actor->id) return;', $inquiryService);
        $this->assertStringContainsString("'inquiries'", $tagged);
        $this->assertStringContainsString(">Inquiries</button>", $taggedView);
    }

}
