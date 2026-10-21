<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class TaskAttachment extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'company_id', 'task_id', 'uploaded_by', 'original_name', 'disk', 'path', 'mime_type', 'size_bytes', 'created_at',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
        'created_at' => 'datetime',
    ];

    // The storage disk and path are internal; the API never sends them.
    protected $hidden = ['disk', 'path', 'company_id'];

    public function task()
    {
        return $this->belongsTo(Task::class);
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
