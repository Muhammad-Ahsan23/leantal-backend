<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class CustomFieldValue extends Model
{
    use HasUuids;

    public $timestamps = false; // migration has no timestamps on this table

    protected $fillable = ['custom_field_id', 'entity_id', 'value'];

    public function field()
    {
        return $this->belongsTo(CustomField::class, 'custom_field_id');
    }
}
