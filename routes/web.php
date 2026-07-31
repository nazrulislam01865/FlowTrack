<?php

use App\Http\Controllers\Auth\FlowTrackLoginController;
use App\Http\Controllers\FlowTrack\DocumentController;
use App\Http\Controllers\FlowTrack\NotificationController;
use App\Http\Controllers\FlowTrack\PusherAuthController;
use App\Http\Controllers\FlowTrack\SessionExpiredController;
use App\Http\Controllers\FlowTrack\SessionStatusController;
use App\Http\Controllers\FlowTrack\TemplateAccessController;
use App\Http\Controllers\FlowTrack\TemplateApiController;
use App\Http\Controllers\FlowTrack\TemplateConfigurationController;
use App\Http\Controllers\FlowTrack\TemplatePageController;
use App\Http\Controllers\FlowTrack\TemplateProfileController;
use App\Http\Controllers\FlowTrack\TemplateSessionController;
use App\Http\Controllers\FlowTrack\TemplateWorkflowController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| FlowTrack Blade interface
|--------------------------------------------------------------------------
| The application uses one canonical Blade/vanilla-JS interface that matches
| the supplied FlowTrac screens. Vue/Inertia screens are not mounted.
*/

Route::get('/', TemplatePageController::class)->name('home');
Route::get('/login', TemplatePageController::class)->name('login');
Route::post('/login', FlowTrackLoginController::class)->name('flowtrack.login.store');

Route::middleware(['auth', 'verified', 'flow.workspace'])->group(function (): void {
    Route::get('/dashboard', TemplatePageController::class)->name('dashboard');
    Route::get('/flowtrack', TemplatePageController::class)->name('flowtrack.prototype');
    Route::get('/flowtrack/dashboard', TemplatePageController::class)->name('flowtrack.dashboard');

    Route::controller(TemplatePageController::class)->prefix('flowtrack/view')->name('flowtrack.view.')->group(function (): void {
        Route::get('/dashboard', '__invoke')->name('dashboard');
        Route::get('/jobs', '__invoke')->name('jobs.index');
        Route::get('/jobs/new', '__invoke')->name('jobs.create');
        Route::get('/jobs/{record}/edit', '__invoke')->name('jobs.edit');
        Route::get('/jobs/{record}', '__invoke')->name('jobs.show');
        Route::get('/tasks/{record}', '__invoke')->name('tasks.show');
        Route::get('/clients', '__invoke')->name('clients.index');
        Route::get('/clients/new', '__invoke')->name('clients.create');
        Route::get('/clients/{record}/edit', '__invoke')->name('clients.edit');
        Route::get('/clients/{record}', '__invoke')->name('clients.show');
        Route::get('/board', '__invoke')->name('board');
        Route::get('/mywork', '__invoke')->name('mywork');
        Route::get('/documents', '__invoke')->name('documents');
        Route::get('/reports', '__invoke')->name('reports');
        Route::get('/notifications', '__invoke')->name('notifications');
        Route::get('/workflowsetup', '__invoke')->name('workflows.index');
        Route::get('/workflows/new', '__invoke')->name('workflows.create');
        Route::get('/workflows/{record}/edit', '__invoke')->name('workflows.edit');
        Route::get('/taskpacksetup', '__invoke')->name('task-packs.index');
        Route::get('/task-packs/new', '__invoke')->name('task-packs.create');
        Route::get('/task-packs/{record}/edit', '__invoke')->name('task-packs.edit');
        Route::get('/masterdata', '__invoke')->name('master-data');
        Route::get('/setup', '__invoke')->name('administration');
        Route::get('/{path?}', '__invoke')->where('path', '.*')->name('fallback');
    });
});

Route::post('/flowtrack/logout', TemplateSessionController::class)
    ->middleware('auth')
    ->name('flowtrack.logout');
Route::get('/flowtrack/session-expired', SessionExpiredController::class)
    ->name('flowtrack.session.expired');

Route::middleware(['auth', 'verified', 'flow.workspace'])
    ->prefix('flowtrack')
    ->name('flowtrack.')
    ->group(function (): void {
        Route::prefix('template-api')->name('template-api.')->middleware('flow.bootstrap.invalidate')->group(function (): void {
            Route::get('/bootstrap', [TemplateApiController::class, 'bootstrap'])->name('bootstrap');
            Route::get('/bootstrap/segments/{segment}', [TemplateApiController::class, 'bootstrapSegment'])
                ->whereIn('segment', ['clients', 'jobs', 'tasks', 'documents'])
                ->name('bootstrap.segment');
            Route::patch('/profile', [TemplateProfileController::class, 'update'])->name('profile.update');
            Route::put('/profile/password', [TemplateProfileController::class, 'updatePassword'])->name('profile.password.update');
            Route::post('/profile/avatar', [TemplateProfileController::class, 'updateAvatar'])->name('profile.avatar.update');
            Route::delete('/profile/avatar', [TemplateProfileController::class, 'destroyAvatar'])->name('profile.avatar.destroy');

            Route::post('/clients', [TemplateApiController::class, 'storeClient'])->name('clients.store');
            Route::put('/clients/{client}', [TemplateApiController::class, 'updateClient'])->name('clients.update');
            Route::delete('/clients/{client}', [TemplateApiController::class, 'destroyClient'])->name('clients.destroy');

            Route::post('/jobs', [TemplateApiController::class, 'storeJob'])->name('jobs.store');
            Route::put('/jobs/{job}', [TemplateApiController::class, 'updateJob'])->name('jobs.update');
            Route::delete('/jobs/{job}', [TemplateApiController::class, 'destroyJob'])->name('jobs.destroy');
            Route::patch('/jobs/{job}/phase', [TemplateApiController::class, 'moveJob'])->name('jobs.phase');
            Route::delete('/jobs/{job}/phases/{phase}', [TemplateApiController::class, 'destroyJobPhase'])->name('jobs.phases.destroy');
            Route::get('/jobs/{job}/tasks', [TemplateApiController::class, 'jobTasks'])->name('jobs.tasks.index');
            Route::post('/jobs/{job}/tasks', [TemplateApiController::class, 'storeTask'])->name('jobs.tasks.store');

            Route::put('/tasks/{task}', [TemplateApiController::class, 'updateTask'])->name('tasks.update');
            Route::patch('/tasks/{task}/due-date', [TemplateApiController::class, 'updateTaskDueDate'])->name('tasks.due-date.update');
            Route::delete('/tasks/{task}', [TemplateApiController::class, 'destroyTask'])->name('tasks.destroy');

            Route::post('/documents', [TemplateApiController::class, 'storeDocument'])->name('documents.store');
            Route::delete('/documents/{document}', [TemplateApiController::class, 'destroyDocument'])->name('documents.destroy');

            Route::get('/master-data/next-code', [TemplateConfigurationController::class, 'nextMasterCode'])->name('master-data.next-code');
            Route::post('/master-data', [TemplateConfigurationController::class, 'storeMaster'])->name('master-data.store');
            Route::put('/master-data/{record}', [TemplateConfigurationController::class, 'updateMaster'])->name('master-data.update');
            Route::patch('/master-data/{record}/toggle', [TemplateConfigurationController::class, 'toggleMaster'])->name('master-data.toggle');
            Route::delete('/master-data/{record}', [TemplateConfigurationController::class, 'destroyMaster'])->name('master-data.destroy');

            Route::get('/task-packs/next-code', [TemplateConfigurationController::class, 'nextTaskPackCode'])->name('task-packs.next-code');
            Route::post('/task-packs', [TemplateConfigurationController::class, 'storeTaskPack'])->name('task-packs.store');
            Route::put('/task-packs/{taskPack}', [TemplateConfigurationController::class, 'updateTaskPack'])->name('task-packs.update');

            Route::post('/workflows', [TemplateWorkflowController::class, 'store'])->name('workflows.store');
            Route::put('/workflows/{workflow}', [TemplateWorkflowController::class, 'update'])->name('workflows.update');
            Route::post('/workflows/{workflow}/phases', [TemplateWorkflowController::class, 'storePhase'])->name('workflows.phases.store');
            Route::put('/workflow-phases/{phase}', [TemplateWorkflowController::class, 'updatePhase'])->name('workflow-phases.update');
            Route::delete('/workflow-phases/{phase}', [TemplateWorkflowController::class, 'destroyPhase'])->name('workflow-phases.destroy');
            Route::patch('/workflows/{workflow}/reorder', [TemplateWorkflowController::class, 'reorder'])->name('workflows.reorder');
            Route::patch('/workflows/{workflow}/publish', [TemplateWorkflowController::class, 'publish'])->name('workflows.publish');

            Route::post('/access/roles', [TemplateAccessController::class, 'storeRole'])->name('access.roles.store');
            Route::put('/access/roles/{role}', [TemplateAccessController::class, 'updateRole'])->name('access.roles.update');
            Route::put('/access/roles/{role}/matrix', [TemplateAccessController::class, 'updateMatrix'])->name('access.roles.matrix.update');
            Route::delete('/access/roles/{role}', [TemplateAccessController::class, 'destroyRole'])->name('access.roles.destroy');
            Route::post('/access/members', [TemplateAccessController::class, 'storeMember'])->name('access.members.store');
            Route::put('/access/members/{membership}', [TemplateAccessController::class, 'updateMember'])->name('access.members.update');
            Route::delete('/access/members/{membership}', [TemplateAccessController::class, 'destroyMember'])->name('access.members.destroy');
        });

        Route::get('/session/status', SessionStatusController::class)->name('session.status');
        Route::post('/pusher/auth', PusherAuthController::class)->name('pusher.auth');

        Route::get('/notifications/status', [NotificationController::class, 'status'])->name('notifications.status');
        Route::patch('/notifications/read-all', [NotificationController::class, 'readAll'])->middleware('flow.bootstrap.invalidate')->name('notifications.read-all');
        Route::patch('/notifications/{notification}/read', [NotificationController::class, 'read'])->middleware('flow.bootstrap.invalidate')->name('notifications.read');

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
