<?php

namespace App\Modules\OrderFlow\Data\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class FlowDocument extends Model
{
    use SoftDeletes;

    protected $table = 'flow_documents';
    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_final' => 'boolean', 'linked_at' => 'datetime'];
    }

    public function workspace(): BelongsTo { return $this->belongsTo(Workspace::class); }
    public function job(): BelongsTo { return $this->belongsTo(FlowJob::class, 'flow_job_id'); }
    public function phase(): BelongsTo { return $this->belongsTo(WorkflowPhase::class, 'workflow_phase_id'); }
    public function taskPackItem(): BelongsTo { return $this->belongsTo(TaskPackItem::class, 'task_pack_item_id'); }
    public function category(): BelongsTo { return $this->belongsTo(MasterRecord::class, 'document_category_id'); }
    public function uploader(): BelongsTo { return $this->belongsTo(User::class, 'uploaded_by'); }
    public function linker(): BelongsTo { return $this->belongsTo(User::class, 'linked_by'); }
    public function versions(): HasMany { return $this->hasMany(FlowDocumentVersion::class)->latest('version'); }
    public function currentVersion(): HasOne { return $this->hasOne(FlowDocumentVersion::class)->latestOfMany('version'); }
}
