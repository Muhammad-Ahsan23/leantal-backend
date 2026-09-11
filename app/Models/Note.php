<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Note extends Model
{
    use HasUuids;

    const UPDATED_AT = null; // migration only has created_at

    protected $fillable = ['candidate_id', 'author_id', 'body'];

    public function candidate()
    {
        return $this->belongsTo(Candidate::class);
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
