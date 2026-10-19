<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class NotificationRule extends Model { protected $fillable = ['name','trigger','recipients','is_active']; protected function casts():array{return ['is_active'=>'boolean'];} }
