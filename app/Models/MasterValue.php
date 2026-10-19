<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class MasterValue extends Model { protected $fillable = ['group_key','code','name','description','parent_id','is_active','meta']; protected function casts(): array { return ['is_active'=>'boolean','meta'=>'array']; } }
