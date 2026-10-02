<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class BlogPost extends Model
{
    use HasUuids;

    protected $connection = 'admin_db';

    protected $fillable = [
        'title', 'slug', 'content', 'status', 'author_id',
        'cover_image_disk', 'cover_image_path', 'seo_title', 'seo_description', 'published_at',
    ];

    protected $casts = ['published_at' => 'datetime'];

    public function author()
    {
        return $this->belongsTo(SuperAdmin::class, 'author_id');
    }
}
