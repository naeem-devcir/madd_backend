<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SystemSetting extends Model
{
    protected $table = 'system_settings';
    
    protected $fillable = [
        'group_name',
        'key_name',
        'value',
        'type',
        'description',
        'is_encrypted'
    ];
    
    protected $casts = [
        'is_encrypted' => 'boolean',
    ];
}