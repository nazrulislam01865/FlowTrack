<?php

namespace App\Modules\OrderFlow\Data\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Modules\OrderFlow\Business\Services\FlowAuthorizationService;

class FlowJob extends Model
{
    use SoftDeletes;

    protected static function booted(): void
    {
        static::saving(function (FlowJob $job): void {
            $isCompleted = strtolower((string) $job->status) === 'completed';
            if ($isCompleted && ! $job->completed_at) {
                $job->completed_at = now();
            } elseif (! $isCompleted && $job->isDirty('status')) {
                $job->completed_at = null;
            }
        });
    }

    protected $table = 'flow_jobs';
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'delivery_date' => 'date',
            'completed_at' => 'datetime',
            'quoted_amount' => 'decimal:2',
            'attention_required' => 'boolean',
            'health_updated_at' => 'datetime',
        ];
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return app(FlowAuthorizationService::class)->applyJobVisibility($query, $user);
    }

    public function workspace(): BelongsTo { return $this->belongsTo(Workspace::class); }
    public function client(): BelongsTo { return $this->belongsTo(Client::class); }
    public function workflow(): BelongsTo { return $this->belongsTo(WorkflowTemplate::class, 'workflow_template_id'); }
    public function currentPhase(): BelongsTo { return $this->belongsTo(WorkflowPhase::class, 'current_phase_id'); }
    public function startedPhase(): BelongsTo { return $this->belongsTo(WorkflowPhase::class, 'started_phase_id'); }
    public function product(): BelongsTo { return $this->belongsTo(MasterRecord::class, 'product_id'); }
    public function productCategory(): BelongsTo { return $this->belongsTo(MasterRecord::class, 'product_category_id'); }
    public function currency(): BelongsTo { return $this->belongsTo(MasterRecord::class, 'currency_id'); }
    public function priority(): BelongsTo { return $this->belongsTo(MasterRecord::class, 'priority_id'); }
    public function owner(): BelongsTo { return $this->belongsTo(User::class, 'owner_id'); }
    public function coordinator(): BelongsTo { return $this->belongsTo(User::class, 'coordinator_id'); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function items(): HasMany { return $this->hasMany(FlowJobItem::class, 'flow_job_id')->orderBy('sort_order')->orderBy('id'); }
    public function members(): HasMany { return $this->hasMany(FlowJobMember::class); }
    public function tasks(): HasMany { return $this->hasMany(FlowTask::class); }
    public function excludedPhases(): HasMany { return $this->hasMany(FlowJobPhaseExclusion::class, 'flow_job_id'); }
    public function phaseHistory(): HasMany { return $this->hasMany(FlowJobPhaseHistory::class); }
    public function documents(): HasMany { return $this->hasMany(FlowDocument::class); }
    public function transitionEvents(): HasMany { return $this->hasMany(FlowTransitionEvent::class, 'flow_job_id')->latest('occurred_at'); }
    public function activities(): MorphMany { return $this->morphMany(FlowActivity::class, 'subject')->latest(); }
}
