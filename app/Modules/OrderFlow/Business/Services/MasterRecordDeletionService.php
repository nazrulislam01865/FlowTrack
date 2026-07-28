<?php

namespace App\Modules\OrderFlow\Business\Services;

use App\Modules\OrderFlow\Data\Models\MasterRecord;
use Illuminate\Support\Facades\DB;

class MasterRecordDeletionService
{
    /**
     * Remove a master-data record without deleting historical Jobs, tasks, users,
     * workflows or documents. References are detached first, then the record is
     * soft deleted so its immutable code is never reused.
     *
     * @return array<string, int>
     */
    public function delete(MasterRecord $record): array
    {
        return DB::transaction(function () use ($record): array {
            $id = $record->id;
            $detached = [
                'child_master_records' => DB::table('master_records')->where('parent_id', $id)->update(['parent_id' => null]),
                'workspace_memberships' => DB::table('workspace_memberships')->where('department_id', $id)->update(['department_id' => null]),
                'task_pack_departments' => DB::table('task_pack_items')->where('default_department_id', $id)->update(['default_department_id' => null]),
                'task_pack_priorities' => DB::table('task_pack_items')->where('priority_id', $id)->update(['priority_id' => null]),
                'workflow_documents' => DB::table('workflow_phases')->where('document_category_id', $id)->update(['document_category_id' => null]),
                'job_products' => DB::table('flow_jobs')->where('product_id', $id)->update(['product_id' => null]),
                'job_categories' => DB::table('flow_jobs')->where('product_category_id', $id)->update(['product_category_id' => null]),
                'job_currencies' => DB::table('flow_jobs')->where('currency_id', $id)->update(['currency_id' => null]),
                'job_priorities' => DB::table('flow_jobs')->where('priority_id', $id)->update(['priority_id' => null]),
                'task_statuses' => DB::table('flow_tasks')->where('task_status_id', $id)->update(['task_status_id' => null]),
                'task_priorities' => DB::table('flow_tasks')->where('priority_id', $id)->update(['priority_id' => null]),
                'document_categories' => DB::table('flow_documents')->where('document_category_id', $id)->update(['document_category_id' => null]),
            ];

            $record->delete();

            return $detached;
        });
    }
}
