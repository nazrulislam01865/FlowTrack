<?php

use App\Http\Controllers\Auth\FlowTrackAuthPageController;
use App\Http\Controllers\Auth\FlowTrackLoginController;
use App\Http\Controllers\FlowTrack\DocumentController;
use App\Http\Controllers\FlowTrack\NotificationController;
use App\Http\Controllers\FlowTrack\PusherAuthController;
use App\Http\Controllers\FlowTrack\SessionExpiredController;
use App\Http\Controllers\FlowTrack\SessionStatusController;
use App\Http\Controllers\FlowTrack\Api\AccessController;
use App\Http\Controllers\FlowTrack\Api\ClientApiController;
use App\Http\Controllers\FlowTrack\Api\DocumentApiController;
use App\Http\Controllers\FlowTrack\Api\JobApiController;
use App\Http\Controllers\FlowTrack\Api\ReadApiController;
use App\Http\Controllers\FlowTrack\Api\TaskApiController;
use App\Http\Controllers\FlowTrack\Api\BrandingController;
use App\Http\Controllers\FlowTrack\Api\ConfigurationController;
use App\Http\Controllers\FlowTrack\Api\ProfileController;
use App\Http\Controllers\FlowTrack\Api\SessionController;
use App\Http\Controllers\FlowTrack\Api\WorkflowController;
use App\Http\Controllers\FlowTrack\Pages\AdministrationController as AdministrationPageController;
use App\Http\Controllers\FlowTrack\Pages\BoardController as BoardPageController;
use App\Http\Controllers\FlowTrack\Pages\ClientController as ClientPageController;
use App\Http\Controllers\FlowTrack\Pages\DashboardController as DashboardPageController;
use App\Http\Controllers\FlowTrack\Pages\DocumentController as DocumentPageController;
use App\Http\Controllers\FlowTrack\Pages\JobController as JobPageController;
use App\Http\Controllers\FlowTrack\Pages\MasterDataController as MasterDataPageController;
use App\Http\Controllers\FlowTrack\Pages\MyWorkController as MyWorkPageController;
use App\Http\Controllers\FlowTrack\Pages\NotificationController as NotificationPageController;
use App\Http\Controllers\FlowTrack\Pages\ProfileController as ProfilePageController;
use App\Http\Controllers\FlowTrack\Pages\ReportController as ReportPageController;
use App\Http\Controllers\FlowTrack\Pages\TaskController as TaskPageController;
use App\Http\Controllers\FlowTrack\Pages\TaskPackController as TaskPackPageController;
use App\Http\Controllers\FlowTrack\Pages\WorkflowController as WorkflowPageController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| FlowTrack Blade interface
|--------------------------------------------------------------------------
| The application uses one canonical Blade/vanilla-JS interface that matches
| the supplied FlowTrac screens. Vue/Inertia screens are not mounted.
*/

Route::get('/', FlowTrackAuthPageController::class)->name('home');
Route::get('/login', FlowTrackAuthPageController::class)->name('login');
Route::post('/login', FlowTrackLoginController::class)->name('flowtrack.login.store');

Route::middleware(['auth', 'verified', 'flow.workspace'])->group(function (): void {
    Route::get('/dashboard', [DashboardPageController::class, 'index'])->name('dashboard');
    Route::get('/flowtrack', [DashboardPageController::class, 'index'])->name('flowtrack.prototype');
    Route::get('/flowtrack/dashboard', [DashboardPageController::class, 'index'])->name('flowtrack.dashboard');

    Route::prefix('flowtrack/view')->name('flowtrack.view.')->group(function (): void {
        Route::get('/dashboard', [DashboardPageController::class, 'index'])->name('dashboard');

        Route::get('/jobs', [JobPageController::class, 'index'])->name('jobs.index');
        Route::get('/jobs/new', [JobPageController::class, 'create'])->name('jobs.create');
        Route::get('/jobs/{record}/edit', [JobPageController::class, 'edit'])->name('jobs.edit');
        Route::get('/jobs/{record}', [JobPageController::class, 'show'])->name('jobs.show');

        Route::get('/tasks/{record}', [TaskPageController::class, 'show'])->name('tasks.show');

        Route::get('/clients', [ClientPageController::class, 'index'])->name('clients.index');
        Route::get('/clients/new', [ClientPageController::class, 'create'])->name('clients.create');
        Route::get('/clients/{record}/edit', [ClientPageController::class, 'edit'])->name('clients.edit');
        Route::get('/clients/{record}', [ClientPageController::class, 'show'])->name('clients.show');

        Route::get('/board', [BoardPageController::class, 'index'])->name('board');
        Route::get('/mywork', [MyWorkPageController::class, 'index'])->name('mywork');
        Route::get('/documents', [DocumentPageController::class, 'index'])->name('documents');
        Route::get('/reports', [ReportPageController::class, 'index'])->name('reports');
        Route::get('/notifications', [NotificationPageController::class, 'index'])->name('notifications');
        Route::get('/profile', [ProfilePageController::class, 'index'])->name('profile');

        Route::get('/workflowsetup', [WorkflowPageController::class, 'index'])->name('workflows.index');
        Route::get('/workflows/new', [WorkflowPageController::class, 'create'])->name('workflows.create');
        Route::get('/workflows/{record}/edit', [WorkflowPageController::class, 'edit'])->name('workflows.edit');

        Route::get('/taskpacksetup', [TaskPackPageController::class, 'index'])->name('task-packs.index');
        Route::get('/task-packs/new', [TaskPackPageController::class, 'create'])->name('task-packs.create');
        Route::get('/task-packs/{record}/edit', [TaskPackPageController::class, 'edit'])->name('task-packs.edit');

        Route::get('/masterdata', [MasterDataPageController::class, 'index'])->name('master-data');
        Route::get('/setup', [AdministrationPageController::class, 'index'])->name('administration');

        Route::get('/{path?}', [DashboardPageController::class, 'index'])
            ->where('path', '.*')
            ->name('fallback');
    });
});

Route::post('/flowtrack/logout', SessionController::class)
    ->middleware('auth')
    ->name('flowtrack.logout');
Route::get('/flowtrack/session-expired', SessionExpiredController::class)
    ->name('flowtrack.session.expired');

Route::middleware(['auth', 'verified', 'flow.workspace'])
    ->prefix('flowtrack')
    ->name('flowtrack.')
    ->group(function (): void {
        Route::prefix('template-api')->name('template-api.')->middleware(['flow.bootstrap.invalidate', 'flow.route.access'])->group(function (): void {
            Route::get('/bootstrap', [ReadApiController::class, 'bootstrap'])->name('bootstrap');
            Route::get('/context/{page}', [ReadApiController::class, 'pageContext'])
                ->whereIn('page', ['dashboard', 'mywork', 'jobs', 'clients', 'board', 'documents', 'reports', 'workflowsetup', 'taskpacksetup', 'masterdata', 'setup', 'notifications', 'profile'])
                ->name('context.show');
            Route::get('/bootstrap/segments/{segment}', [ReadApiController::class, 'bootstrapSegment'])
                ->whereIn('segment', ['clients', 'jobs', 'tasks', 'documents'])
                ->name('bootstrap.segment');
            Route::get('/lists/{segment}', [ReadApiController::class, 'listSegment'])
                ->whereIn('segment', ['clients', 'jobs', 'tasks', 'documents'])
                ->name('lists.index');
            Route::get('/clients/{record}', [ReadApiController::class, 'showClient'])->name('clients.show');
            Route::get('/jobs/{record}', [ReadApiController::class, 'showJob'])->name('jobs.show');
            Route::get('/tasks/{record}', [ReadApiController::class, 'showTask'])->name('tasks.show');
            Route::get('/documents/{record}', [ReadApiController::class, 'showDocument'])->name('documents.show');
            Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
            Route::put('/profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password.update');
            Route::post('/profile/avatar', [ProfileController::class, 'updateAvatar'])->name('profile.avatar.update');
            Route::delete('/profile/avatar', [ProfileController::class, 'destroyAvatar'])->name('profile.avatar.destroy');

            Route::post('/branding/logo', [BrandingController::class, 'updateLogo'])->name('branding.logo.update');
            Route::delete('/branding/logo', [BrandingController::class, 'destroyLogo'])->name('branding.logo.destroy');
            Route::post('/branding/favicon', [BrandingController::class, 'updateFavicon'])->name('branding.favicon.update');
            Route::delete('/branding/favicon', [BrandingController::class, 'destroyFavicon'])->name('branding.favicon.destroy');

            Route::post('/clients', [ClientApiController::class, 'storeClient'])->name('clients.store');
            Route::put('/clients/{client}', [ClientApiController::class, 'updateClient'])->name('clients.update');
            Route::delete('/clients/{client}', [ClientApiController::class, 'destroyClient'])->name('clients.destroy');

            Route::post('/jobs', [JobApiController::class, 'storeJob'])->name('jobs.store');
            Route::patch('/jobs/bulk', [JobApiController::class, 'bulkJobs'])->name('jobs.bulk');
            Route::put('/jobs/{job}', [JobApiController::class, 'updateJob'])->name('jobs.update');
            Route::delete('/jobs/{job}', [JobApiController::class, 'destroyJob'])->name('jobs.destroy');
            Route::patch('/jobs/{job}/phase', [JobApiController::class, 'moveJob'])->name('jobs.phase');
            Route::delete('/jobs/{job}/phases/{phase}', [JobApiController::class, 'destroyJobPhase'])->name('jobs.phases.destroy');
            Route::post('/jobs/{job}/comments', [JobApiController::class, 'storeJobComment'])->name('jobs.comments.store');
            Route::patch('/jobs/{job}/phase-controls', [JobApiController::class, 'updateJobPhaseControls'])->name('jobs.phase-controls.update');
            Route::post('/jobs/{job}/phases/{phase}/tasks/sync', [JobApiController::class, 'syncJobPhaseTasks'])->name('jobs.phases.tasks.sync');
            Route::get('/jobs/{job}/tasks', [JobApiController::class, 'jobTasks'])->name('jobs.tasks.index');
            Route::post('/jobs/{job}/tasks', [TaskApiController::class, 'storeTask'])->name('jobs.tasks.store');

            Route::put('/tasks/{task}', [TaskApiController::class, 'updateTask'])->name('tasks.update');
            Route::patch('/tasks/{task}/due-date', [TaskApiController::class, 'updateTaskDueDate'])->name('tasks.due-date.update');
            Route::delete('/tasks/{task}', [TaskApiController::class, 'destroyTask'])->name('tasks.destroy');

            Route::post('/documents', [DocumentApiController::class, 'storeDocument'])->name('documents.store');
            Route::post('/jobs/{job}/documents/link-existing', [DocumentApiController::class, 'linkExistingDocument'])->name('jobs.documents.link-existing');
            Route::post('/jobs/{job}/attachments', [DocumentApiController::class, 'storeJobAttachment'])->name('jobs.attachments.store');
            Route::post('/jobs/{job}/attachments/link-existing', [DocumentApiController::class, 'linkExistingJobAttachment'])->name('jobs.attachments.link-existing');
            Route::delete('/documents/{document}', [DocumentApiController::class, 'destroyDocument'])->name('documents.destroy');

            Route::get('/master-data/next-code', [ConfigurationController::class, 'nextMasterCode'])->name('master-data.next-code');
            Route::post('/master-data', [ConfigurationController::class, 'storeMaster'])->name('master-data.store');
            Route::put('/master-data/{record}', [ConfigurationController::class, 'updateMaster'])->name('master-data.update');
            Route::patch('/master-data/{record}/toggle', [ConfigurationController::class, 'toggleMaster'])->name('master-data.toggle');
            Route::delete('/master-data/{record}', [ConfigurationController::class, 'destroyMaster'])->name('master-data.destroy');

            Route::get('/task-packs/next-code', [ConfigurationController::class, 'nextTaskPackCode'])->name('task-packs.next-code');
            Route::post('/task-packs', [ConfigurationController::class, 'storeTaskPack'])->name('task-packs.store');
            Route::put('/task-packs/{taskPack}', [ConfigurationController::class, 'updateTaskPack'])->name('task-packs.update');

            Route::post('/workflows', [WorkflowController::class, 'store'])->name('workflows.store');
            Route::put('/workflows/{workflow}', [WorkflowController::class, 'update'])->name('workflows.update');
            Route::post('/workflows/{workflow}/phases', [WorkflowController::class, 'storePhase'])->name('workflows.phases.store');
            Route::put('/workflow-phases/{phase}', [WorkflowController::class, 'updatePhase'])->name('workflow-phases.update');
            Route::delete('/workflow-phases/{phase}', [WorkflowController::class, 'destroyPhase'])->name('workflow-phases.destroy');
            Route::patch('/workflows/{workflow}/reorder', [WorkflowController::class, 'reorder'])->name('workflows.reorder');
            Route::patch('/workflows/{workflow}/publish', [WorkflowController::class, 'publish'])->name('workflows.publish');

            Route::post('/access/roles', [AccessController::class, 'storeRole'])->name('access.roles.store');
            Route::put('/access/roles/{role}', [AccessController::class, 'updateRole'])->name('access.roles.update');
            Route::put('/access/roles/{role}/matrix', [AccessController::class, 'updateMatrix'])->name('access.roles.matrix.update');
            Route::delete('/access/roles/{role}', [AccessController::class, 'destroyRole'])->name('access.roles.destroy');
            Route::post('/access/members', [AccessController::class, 'storeMember'])->name('access.members.store');
            Route::put('/access/members/{membership}', [AccessController::class, 'updateMember'])->name('access.members.update');
            Route::delete('/access/members/{membership}', [AccessController::class, 'destroyMember'])->name('access.members.destroy');
        });

        Route::get('/session/status', SessionStatusController::class)->name('session.status');
        Route::post('/pusher/auth', PusherAuthController::class)->name('pusher.auth');

        Route::get('/notifications/status', [NotificationController::class, 'status'])->name('notifications.status');
        Route::get('/notifications/feed', [NotificationController::class, 'feed'])->name('notifications.feed');
        Route::patch('/notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
        Route::patch('/notifications/{notification}/read', [NotificationController::class, 'read'])->name('notifications.read');

        Route::get('/documents/versions/{version}/preview', [DocumentController::class, 'preview'])->name('documents.versions.preview');
        Route::get('/documents/versions/{version}/download', [DocumentController::class, 'download'])->name('documents.versions.download');

        Route::get('/jobs', fn () => redirect('/flowtrack/view/jobs'))->name('jobs.index');
        Route::get('/jobs/{job}', function (Request $request, string $job) {
            $target = '/flowtrack/view/jobs/'.rawurlencode($job);
            if ($request->filled('task')) {
                $target = '/flowtrack/view/tasks/'.rawurlencode((string) $request->query('task'));
            }
            return redirect($target);
        })->name('jobs.show');
        Route::get('/tasks/{task}', fn (string $task) => redirect('/flowtrack/view/tasks/'.rawurlencode($task)))->name('tasks.show');
        Route::get('/clients', fn () => redirect('/flowtrack/view/clients'))->name('clients.index');
        Route::get('/clients/{client}', fn (string $client) => redirect('/flowtrack/view/clients/'.rawurlencode($client)))->name('clients.show');
        Route::get('/board', fn () => redirect('/flowtrack/view/board'))->name('board.index');
        Route::get('/documents', fn () => redirect('/flowtrack/view/documents'))->name('documents.index');
        Route::get('/reports', fn () => redirect('/flowtrack/view/reports'))->name('reports');
        Route::get('/my-work', fn () => redirect('/flowtrack/view/mywork'))->name('my-work');
        Route::get('/notifications', fn () => redirect('/flowtrack/view/notifications'))->name('notifications.index');
        Route::get('/workflow-setup', fn () => redirect('/flowtrack/view/workflowsetup'))->name('workflows.index');
        Route::get('/task-pack-setup', fn () => redirect('/flowtrack/view/taskpacksetup'))->name('task-packs.index');
        Route::get('/master-data', fn () => redirect('/flowtrack/view/masterdata'))->name('master-data.index');
        Route::get('/administration', fn () => redirect('/flowtrack/view/setup'))->name('administration.index');
    });

// FLOWTRACK_CSRF_REFRESH_ROUTE
\Illuminate\Support\Facades\Route::middleware('auth')->get(
    '/flowtrack/csrf-token',
    \App\Http\Controllers\FlowTrack\CsrfTokenController::class
)->name('flowtrack.csrf-token');

