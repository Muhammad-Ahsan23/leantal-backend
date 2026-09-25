<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class CustomField extends Model
{
    use HasUuids;

    const UPDATED_AT = null; // migration only has created_at

    protected $fillable = [
        'company_id', 'entity_type', 'field_name', 'field_type', 'options', 'required',
    ];

    protected $casts = [
        'options' => 'array',
        'required' => 'boolean',
    ];

    public function values()
    {
        return $this->hasMany(CustomFieldValue::class);
    }
}
