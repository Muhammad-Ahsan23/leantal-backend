<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ApplicationAnswer extends Model
{
    use HasUuids;

    const UPDATED_AT = null;

    protected $fillable = [
        'application_id', 'question_id', 'answer_text', 'answer_file_disk', 'answer_file_path',
    ];

    public function application()
    {
        return $this->belongsTo(Application::class);
    }

    public function question()
    {
        return $this->belongsTo(ApplicationQuestion::class, 'question_id');
    }
}
