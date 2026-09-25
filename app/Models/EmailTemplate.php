<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class EmailTemplate extends Model
{
    use HasUuids;

    protected $fillable = ['company_id', 'user_id', 'name', 'subject', 'body'];

    public function creator()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
