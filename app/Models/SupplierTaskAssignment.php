<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class SupplierTaskAssignment extends Model
{
    protected $fillable = ['task_id', 'flow_job_id', 'supplier_id', 'assigned_by', 'assignment_source'];
}
