<?php

namespace App\Models;

use App\Models\Vendor\Vendor;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AttributeGroupMapping extends Model
{
    use HasFactory;

    protected $table = 'attribute_group_mappings';

    protected $fillable = [
        'vendor_id',
        'attribute_set_id',
        'attribute_group_id',
        'attribute_id',
        'sort_order',
        'attribute_code',
        'frontend_label',
        'is_system',
        'is_required',
    ];

    protected $casts = [
        'vendor_id'          => 'integer',
        'attribute_set_id'   => 'integer',
        'attribute_group_id' => 'integer',
        'attribute_id'       => 'integer',
        'sort_order'         => 'integer',
        'is_system'          => 'boolean',
        'is_required'        => 'boolean',
    ];

    public function attributeSet()
    {
        return $this->belongsTo(AttributeSets::class, 'attribute_set_id');
    }

    public function vendor()
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }
}