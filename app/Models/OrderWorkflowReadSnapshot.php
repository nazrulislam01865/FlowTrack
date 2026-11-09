<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class OrderWorkflowReadSnapshot extends Model
{
    protected $fillable = [
        'flow_job_id', 'source_version', 'definition_revision',
        'schema_version', 'projection_json', 'generated_at',
    ];

    protected function casts(): array
    {
        return ['generated_at' => 'datetime'];
    }
}
