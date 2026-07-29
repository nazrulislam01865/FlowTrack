<?php

use App\Http\Controllers\FlowTrack\AdministrationController;
use App\Http\Controllers\FlowTrack\BoardController;
use App\Http\Controllers\FlowTrack\ClientController;
use App\Http\Controllers\FlowTrack\DashboardController;
use App\Http\Controllers\FlowTrack\GlobalSearchController;
use App\Http\Controllers\FlowTrack\DocumentController;
use App\Http\Controllers\FlowTrack\JobController;
use App\Http\Controllers\FlowTrack\MasterDataController;
use App\Http\Controllers\FlowTrack\MyWorkController;
use App\Http\Controllers\FlowTrack\NotificationController;
use App\Http\Controllers\FlowTrack\PusherAuthController;
use App\Http\Controllers\FlowTrack\ReportController;
use App\Http\Controllers\FlowTrack\TaskController;
use App\Http\Controllers\FlowTrack\TaskPackController;
use App\Http\Controllers\FlowTrack\TemplateApiController;
use App\Http\Controllers\FlowTrack\TemplatePageController;
use App\Http\Controllers\FlowTrack\TemplateProfileController;
use App\Http\Controllers\FlowTrack\TemplateSessionController;
use App\Http\Controllers\FlowTrack\SessionExpiredController;
use App\Http\Controllers\FlowTrack\SessionStatusController;
use App\Http\Controllers\FlowTrack\TemplateAccessController;
use App\Http\Controllers\FlowTrack\TemplateConfigurationController;
use App\Http\Controllers\FlowTrack\TemplateWorkflowController;
use App\Http\Controllers\FlowTrack\WorkflowController;
use Illuminate\Support\Facades\Route;

Route::get('/', TemplatePageController::class)->name('home');
Route::get('/login', TemplatePageController::class)->name('login');
Route::get('/dashboard', TemplatePageController::class)->name('dashboard');
Route::get('/flowtrack', TemplatePageController::class)->name('flowtrack.prototype');
Route::get('/flowtrack/dashboard', TemplatePageController::class)->name('flowtrack.dashboard');
Route::post('/flowtrack/logout', TemplateSessionController::class)->middleware('auth')->name('flowtrack.logout');
Route::get('/flowtrack/session-expired', SessionExpiredController::class)->name('flowtrack.session.expired');

Route::middleware(['auth', 'verified', 'flow.workspace'])->prefix('flowtrack')->name('flowtrack.')->group(function () {
    Route::prefix('template-api')->name('template-api.')->group(function () {
        Route::get('/bootstrap', [TemplateApiController::class, 'bootstrap'])->name('bootstrap');
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
        Route::put('/tasks/{task}', [TemplateApiController::class, 'updateTask'])->name('tasks.update');
        Route::patch('/tasks/{task}/due-date', [TemplateApiController::class, 'updateTaskDueDate'])->name('tasks.due-date.update');
        Route::delete('/tasks/{task}', [TemplateApiController::class, 'destroyTask'])->name('tasks.destroy');
        Route::post('/documents', [TemplateApiController::class, 'storeDocument'])->name('documents.store');
        Route::delete('/documents/{document}', [TemplateApiController::class, 'destroyDocument'])->name('documents.destroy');
        Route::patch('/jobs/{job}/phase', [TemplateApiController::class, 'moveJob'])->name('jobs.phase');
        Route::delete('/jobs/{job}/phases/{phase}', [TemplateApiController::class, 'destroyJobPhase'])->name('jobs.phases.destroy');
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
    Route::get('/search', GlobalSearchController::class)->name('search');
    Route::get('/my-work', MyWorkController::class)->name('my-work');

    Route::get('/jobs', [JobController::class, 'index'])->name('jobs.index');
    Route::post('/jobs', [JobController::class, 'store'])->name('jobs.store');
    Route::put('/jobs/{job}', [JobController::class, 'update'])->name('jobs.update');
    Route::get('/jobs/{job}', [JobController::class, 'show'])->name('jobs.show');
    Route::delete('/jobs/{job}', [JobController::class, 'destroy'])->name('jobs.destroy');
    Route::put('/jobs/{job}/members', [JobController::class, 'updateMembers'])->name('jobs.members.update');

    Route::get('/clients', [ClientController::class, 'index'])->name('clients.index');
    Route::post('/clients', [ClientController::class, 'store'])->name('clients.store');
    Route::put('/clients/{client}', [ClientController::class, 'update'])->name('clients.update');
    Route::delete('/clients/{client}', [ClientController::class, 'destroy'])->name('clients.destroy');

    Route::get('/board', [BoardController::class, 'index'])->name('board.index');
    Route::patch('/board/jobs/{job}/phase', [BoardController::class, 'moveJob'])->name('board.jobs.move');
    Route::patch('/board/tasks/{task}/status', [BoardController::class, 'moveTask'])->name('board.tasks.move');

    Route::post('/jobs/{job}/tasks', [TaskController::class, 'store'])->name('tasks.store');
    Route::get('/tasks/{task}', [TaskController::class, 'show'])->name('tasks.show');
    Route::put('/tasks/{task}', [TaskController::class, 'update'])->name('tasks.update');
    Route::patch('/tasks/{task}/due-date', [TaskController::class, 'updateDueDate'])->name('tasks.due-date.update');
    Route::delete('/tasks/{task}', [TaskController::class, 'destroy'])->name('tasks.destroy');
    Route::delete('/jobs/{job}/phases/{phase}', [JobController::class, 'destroyPhase'])->name('jobs.phases.destroy');

    Route::get('/documents', [DocumentController::class, 'index'])->name('documents.index');
    Route::post('/documents', [DocumentController::class, 'store'])->name('documents.store');
    Route::delete('/documents/{document}', [DocumentController::class, 'destroy'])->name('documents.destroy');
    Route::get('/documents/versions/{version}/download', [DocumentController::class, 'download'])->name('documents.versions.download');

    Route::get('/reports', ReportController::class)->middleware('flow.action:reports,view')->name('reports');

    Route::post('/pusher/auth', PusherAuthController::class)->name('pusher.auth');
    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::get('/notifications/status', [NotificationController::class, 'status'])->name('notifications.status');
    Route::patch('/notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
    Route::patch('/notifications/{notification}/read', [NotificationController::class, 'read'])->name('notifications.read');

    Route::get('/workflow-setup', [WorkflowController::class, 'index'])->middleware('flow.action:workflow,view')->name('workflows.index');
    Route::post('/workflow-setup', [WorkflowController::class, 'store'])->middleware('flow.action:workflow,create')->name('workflows.store');
    Route::post('/workflow-setup/{workflow}/phases', [WorkflowController::class, 'storePhase'])->middleware('flow.action:workflow,create')->name('workflows.phases.store');
    Route::put('/workflow-phases/{phase}', [WorkflowController::class, 'updatePhase'])->middleware('flow.action:workflow,edit_all')->name('workflows.phases.update');
    Route::delete('/workflow-phases/{phase}', [WorkflowController::class, 'destroyPhase'])->middleware('flow.action:workflow,delete')->name('workflows.phases.destroy');
    Route::get('/task-pack-setup', [TaskPackController::class, 'index'])->middleware('flow.action:workflow,view')->name('task-packs.index');
    Route::post('/task-packs', [TaskPackController::class, 'store'])->middleware('flow.action:workflow,create')->name('task-packs.store');
    Route::put('/task-packs/{taskPack}', [TaskPackController::class, 'update'])->middleware('flow.action:workflow,edit_all')->name('task-packs.update');
    Route::post('/task-packs/{taskPack}/items', [TaskPackController::class, 'storeItem'])->middleware('flow.action:workflow,edit_all')->name('task-packs.items.store');
    Route::put('/task-pack-items/{item}', [TaskPackController::class, 'updateItem'])->middleware('flow.action:workflow,edit_all')->name('task-packs.items.update');
    Route::delete('/task-pack-items/{item}', [TaskPackController::class, 'destroyItem'])->middleware('flow.action:workflow,delete')->name('task-packs.items.destroy');
    Route::patch('/workflow-setup/{workflow}/reorder', [WorkflowController::class, 'reorder'])->middleware('flow.action:workflow,edit_all')->name('workflows.reorder');
    Route::patch('/workflow-setup/{workflow}/publish', [WorkflowController::class, 'publish'])->middleware('flow.action:workflow,edit_all')->name('workflows.publish');

    Route::get('/master-data', [MasterDataController::class, 'index'])->middleware('flow.action:masterdata,view')->name('master-data.index');
    Route::post('/master-data', [MasterDataController::class, 'store'])->middleware('flow.action:masterdata,create')->name('master-data.store');
    Route::put('/master-data/{record}', [MasterDataController::class, 'update'])->middleware('flow.action:masterdata,edit_all')->name('master-data.update');
    Route::patch('/master-data/{record}/toggle', [MasterDataController::class, 'toggle'])->middleware('flow.action:masterdata,edit_all')->name('master-data.toggle');
    Route::delete('/master-data/{record}', [MasterDataController::class, 'destroy'])->middleware('flow.action:masterdata,delete')->name('master-data.destroy');

    Route::get('/administration', [AdministrationController::class, 'index'])->middleware('flow.action:users,view')->name('administration.index');
    Route::post('/administration/roles', [AdministrationController::class, 'storeRole'])->name('administration.roles.store');
    Route::put('/administration/roles/{role}', [AdministrationController::class, 'updateRole'])->name('administration.roles.update');
    Route::delete('/administration/roles/{role}', [AdministrationController::class, 'destroyRole'])->name('administration.roles.destroy');
    Route::post('/administration/members', [AdministrationController::class, 'storeMember'])->name('administration.members.store');
    Route::put('/administration/members/{membership}', [AdministrationController::class, 'updateMember'])->name('administration.members.update');
    Route::delete('/administration/members/{membership}', [AdministrationController::class, 'destroyMember'])->name('administration.members.destroy');
});


require __DIR__.'/settings.php';
