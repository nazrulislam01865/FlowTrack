<?php

namespace Tests\Feature;

use App\Jobs\DeliverRealtimeNotification;
use App\Models\Activity;
use App\Models\Client;
use App\Models\FlowNotification;
use App\Models\Inquiry;
use App\Models\InquiryTask;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\WorkspaceRefreshService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ReverbSystemwideCoverageTest extends TestCase
{
    use RefreshDatabase;

    public function test_record_backed_livewire_pages_use_the_shared_workspace_refresh_concern(): void
    {
        $components = [
            'Administration/Index.php',
            'Board/Index.php',
            'Clients/Index.php',
            'CompanySetup/Index.php',
            'Dashboard/Index.php',
            'Dashboard/Secondary.php',
            'Dashboard/TaggedComments.php',
            'Documents/Index.php',
            'Inquiries/Index.php',
            'Jobs/Index.php',
            'MasterData/Index.php',
            'MyWork/Index.php',
            'Notifications/Index.php',
            'Orders/Index.php',
            'Profile/Index.php',
            'Reports/Index.php',
            'TaskPackSetup/Form.php',
            'TaskPackSetup/Index.php',
            'UserEditor/Index.php',
            'WorkflowSetup/Form.php',
            'WorkflowSetup/Index.php',
        ];

        foreach ($components as $component) {
            $source = file_get_contents(app_path('Livewire/'.$component));
            $this->assertStringContainsString('use App\\Livewire\\Concerns\\RefreshesFromWorkspace;', $source, $component);
            $this->assertStringContainsString('use RefreshesFromWorkspace;', $source, $component);
        }

        $concern = file_get_contents(app_path('Livewire/Concerns/RefreshesFromWorkspace.php'));
        $this->assertStringContainsString("#[On('flowtrack-refresh')]", $concern);
        $this->assertStringContainsString('public function refreshFromWorkspace(): void', $concern);
    }

    public function test_workspace_observer_covers_operational_parent_and_child_records(): void
    {
        $observer = file_get_contents(app_path('Observers/WorkspaceDataObserver.php'));
        $provider = file_get_contents(app_path('Providers/AppServiceProvider.php'));

        foreach ([
            'Activity::class',
            'Client::class',
            'Document::class',
            'FlowJob::class',
            'FlowJobItem::class',
            'FlowJobMember::class',
            'FlowTaskChecklistItem::class',
            'FlowTaskComment::class',
            'Inquiry::class',
            'InquiryDocument::class',
            'InquiryItem::class',
            'InquiryTask::class',
            'InquiryTaskComment::class',
            'InquiryTaskLink::class',
            'Invoice::class',
            'InvoiceItem::class',
            'OrderCollection::class',
            'Payment::class',
            'Task::class',
            'TaskLink::class',
            'TaskPack::class',
            'TaskPackItem::class',
            'TaskPackTask::class',
            'Workflow::class',
            'WorkflowPhase::class',
            'WorkflowTemplate::class',
        ] as $model) {
            $this->assertStringContainsString($model, $observer, $model);
        }

        $this->assertStringNotContainsString('FlowNotification::class', $observer);
        $this->assertStringContainsString('WorkspaceDataObserver::observedModels()', $provider);
    }

    public function test_child_record_change_advances_the_workspace_data_version(): void
    {
        Cache::flush();
        $before = app(WorkspaceRefreshService::class)->version();

        Activity::create([
            'subject_type' => Client::class,
            'subject_id' => 999999,
            'user_id' => null,
            'event' => 'realtime.coverage_test',
            'description' => 'Realtime observer coverage test',
        ]);

        $after = app(WorkspaceRefreshService::class)->version();
        $this->assertNotSame($before, $after);
    }

    public function test_inquiry_assignment_notifications_use_the_queued_reverb_delivery_path(): void
    {
        Queue::fake();
        Cache::flush();
        config([
            'services.realtime.enabled' => true,
            'services.realtime.api_host' => '127.0.0.1',
            'reverb.apps.apps.0.app_id' => 'flowtrack-test',
            'reverb.apps.apps.0.key' => 'flowtrack-test-key',
            'reverb.apps.apps.0.secret' => 'flowtrack-test-secret',
            'reverb.apps.apps.0.options.host' => '127.0.0.1',
            'reverb.apps.apps.0.options.port' => 8080,
            'reverb.apps.apps.0.options.scheme' => 'http',
        ]);

        $recipient = User::factory()->create(['is_super_admin' => true, 'is_active' => true]);
        $actor = User::factory()->create(['is_super_admin' => true, 'is_active' => true]);
        $client = Client::create(['name' => 'Realtime Inquiry Client', 'code' => 'RT-INQ', 'is_active' => true]);
        $inquiry = Inquiry::create([
            'workspace_id' => 1,
            'inquiry_number' => 'INQ-REALTIME-ASSIGN',
            'client_id' => $client->id,
            'owner_id' => $actor->id,
            'created_by' => $actor->id,
            'received_date' => today(),
            'subject' => 'Realtime assignment',
            'status' => 'In Progress',
        ]);
        $task = InquiryTask::create([
            'inquiry_id' => $inquiry->id,
            'assignee_id' => $recipient->id,
            'title' => 'Realtime assigned task',
            'sequence' => 1,
            'status' => 'Not Started',
        ]);

        app(NotificationService::class)->notifyInquiryUser(
            $recipient,
            $inquiry,
            $task,
            'Task assigned: '.$task->title,
            $inquiry->inquiry_number.' · No due date',
            'assignment',
            $actor,
        );

        $notification = FlowNotification::query()
            ->where('user_id', $recipient->id)
            ->where('inquiry_id', $inquiry->id)
            ->where('inquiry_task_id', $task->id)
            ->where('type', 'assignment')
            ->first();

        $this->assertNotNull($notification);
        Queue::assertPushed(DeliverRealtimeNotification::class, fn (DeliverRealtimeNotification $job) =>
            $job->userId === (int) $recipient->id
            && $job->event === 'flowtrack.notification'
            && (int) ($job->payload['inquiry_id'] ?? 0) === (int) $inquiry->id
            && (int) ($job->payload['inquiry_task_id'] ?? 0) === (int) $task->id
        );

        app(NotificationService::class)->markRead($recipient, $notification);
        Queue::assertPushed(DeliverRealtimeNotification::class, fn (DeliverRealtimeNotification $job) =>
            $job->userId === (int) $recipient->id
            && $job->event === 'flowtrack.notification-state'
            && (int) ($job->payload['unread_count'] ?? -1) === 0
        );
    }

    public function test_known_mass_mutations_publish_workspace_invalidation_explicitly(): void
    {
        $documents = file_get_contents(app_path('Services/DocumentService.php'));
        $masterData = \Tests\Support\AdministrationPhase7Source::masterData();
        $permanentDelete = file_get_contents(app_path('Services/PermanentJobDeleteService.php'));
        $inquiryService = $this->inquiryServiceSource();
        $notificationService = file_get_contents(app_path('Services/NotificationService.php'));
        $board = file_get_contents(app_path('Services/BoardService.php'));
        $reports = file_get_contents(app_path('Services/ReportService.php'));
        $workflow = file_get_contents(app_path('Services/WorkflowService.php'));
        $masterDataService = file_get_contents(app_path('Services/MasterDataService.php'));
        $reverbClient = file_get_contents(resource_path('js/core/realtime.js'));
        $notificationRuntime = file_get_contents(resource_path('js/features/notifications.js'));

        $this->assertStringContainsString("touch('Document:renamed')", $documents);
        $this->assertStringContainsString("touch('MasterRecord:bulk-product-status')", $masterData);
        $this->assertStringContainsString("touch('FlowJob:force-deleted')", $permanentDelete);
        $this->assertStringContainsString("touch('WorkflowTemplate:saved')", $workflow);
        $this->assertStringContainsString("touch('MasterRecord:propagated')", $masterDataService);
        $this->assertStringContainsString('NotificationService::class)->notifyInquiryUser(', $inquiryService);
        $this->assertStringNotContainsString('FlowNotification::create([', $inquiryService);
        $this->assertStringContainsString('public function notifyInquiryUser(', $notificationService);
        $this->assertStringContainsString('public function broadcastRealtimeState(', $notificationService);
        $this->assertStringContainsString('REALTIME_EVENTS.NOTIFICATION_STATE', $notificationRuntime);
        $this->assertStringContainsString('state.notificationChannel.bind(REALTIME_EVENTS.NOTIFICATION_STATE', $notificationRuntime);
        $this->assertStringContainsString("':data-'.app(WorkspaceRefreshService::class)->version()", $board);
        $this->assertStringContainsString("':data-'.app(WorkspaceRefreshService::class)->version()", $reports);
    }
}
