<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Permission extends Model
{
    use HasUuids, SoftDeletes;

    protected $table = 'permissions';

    protected $fillable = [
        'name',
        'display_name',
        'permission_description',
        'module',
        'guard_name',
        'is_system',
    ];
}
