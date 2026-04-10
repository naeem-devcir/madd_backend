<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Role extends Model
{
    use HasUuids;

    protected $fillable = [
        'name',
        'display_name',
        'description',
        'guard_name',
    ];


    public function users()
    {
        return $this->morphedByMany(
            \App\Models\User::class,
            'model',
            'model_has_roles',
            'role_id',
            'model_id',
            'id',
            'uuid'
        );
    }
}
