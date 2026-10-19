<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class TaskPackTask extends Model { protected $fillable = ['id','task_pack_id','title','sequence','is_required','default_department_id','source_task_pack_task_id','color']; protected function casts(): array { return ['is_required'=>'boolean']; } public function taskPack(): BelongsTo { return $this->belongsTo(TaskPack::class); } public function defaultDepartment(): BelongsTo { return $this->belongsTo(Department::class,'default_department_id'); } }
