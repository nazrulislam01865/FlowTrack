<?php

namespace App\Observers;

use App\Models\Activity;
use App\Models\Client;
use App\Models\ClientContact;
use App\Models\ClientShippingAddress;
use App\Models\CollectionUpdate;
use App\Models\Department;
use App\Models\Document;
use App\Models\FlowJob;
use App\Models\FlowJobItem;
use App\Models\FlowJobMember;
use App\Models\FlowJobPhaseHistory;
use App\Models\FlowTaskChecklistItem;
use App\Models\FlowTaskComment;
use App\Models\Inquiry;
use App\Models\InquiryDocument;
use App\Models\InquiryItem;
use App\Models\InquiryTask;
use App\Models\InquiryTaskComment;
use App\Models\InquiryTaskLink;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\MasterRecord;
use App\Models\MasterValue;
use App\Models\NotificationRule;
use App\Models\OrderCollection;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RoleModuleAccess;
use App\Models\Task;
use App\Models\TaskLink;
use App\Models\TaskPack;
use App\Models\TaskPackItem;
use App\Models\TaskPackTask;
use App\Models\User;
use App\Models\Workflow;
use App\Models\WorkflowPhase;
use App\Models\WorkflowTemplate;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Services\WorkspaceRefreshService;
use Illuminate\Database\Eloquent\Model;

class WorkspaceDataObserver
{
    /**
     * Models whose changes affect record-backed screens somewhere in FlowTrack.
     *
     * FlowNotification is intentionally excluded: personal notification delivery
     * already uses the private user Reverb channel and broadcasting every private
     * notification to the whole workspace would create noisy duplicate refreshes.
     */
    public static function observedModels(): array
    {
        return [
            Activity::class,
            Client::class,
            ClientContact::class,
            ClientShippingAddress::class,
            CollectionUpdate::class,
            Department::class,
            Document::class,
            FlowJob::class,
            FlowJobItem::class,
            FlowJobMember::class,
            FlowJobPhaseHistory::class,
            FlowTaskChecklistItem::class,
            FlowTaskComment::class,
            Inquiry::class,
            InquiryDocument::class,
            InquiryItem::class,
            InquiryTask::class,
            InquiryTaskComment::class,
            InquiryTaskLink::class,
            Invoice::class,
            InvoiceItem::class,
            MasterRecord::class,
            MasterValue::class,
            NotificationRule::class,
            OrderCollection::class,
            Payment::class,
            Permission::class,
            Role::class,
            RoleModuleAccess::class,
            Task::class,
            TaskLink::class,
            TaskPack::class,
            TaskPackItem::class,
            TaskPackTask::class,
            User::class,
            Workflow::class,
            WorkflowPhase::class,
            WorkflowTemplate::class,
            Workspace::class,
            WorkspaceMembership::class,
        ];
    }

    public function created(Model $model): void
    {
        $this->changed($model, 'created');
    }

    public function updated(Model $model): void
    {
        $this->changed($model, 'updated');
    }

    public function deleted(Model $model): void
    {
        $this->changed($model, 'deleted');
    }

    public function restored(Model $model): void
    {
        $this->changed($model, 'restored');
    }

    public function forceDeleted(Model $model): void
    {
        $this->changed($model, 'force-deleted');
    }

    private function changed(Model $model, string $action): void
    {
        app(WorkspaceRefreshService::class)->touch(class_basename($model).':'.$action);
    }
}
