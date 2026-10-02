<?php

namespace App\Http\Requests\SuperAdmin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveBlogPostRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        $postId = $this->route('id');

        return [
            'title' => ['required', 'string', 'max:255'],
            'slug' => [
                'required', 'string', 'max:255', 'regex:/^[a-z0-9-]+$/',
                Rule::unique('admin_db.blog_posts', 'slug')->ignore($postId),
            ],
            'content' => ['nullable', 'string'],
            'status' => ['required', 'in:draft,published,archived'],
            // Matches the Resume-upload disk/path pattern already used
            // elsewhere (R2 storage) — the actual file-upload endpoint
            // itself is a separate, future concern; this just accepts
            // where an already-uploaded cover image ended up.
            'cover_image_disk' => ['nullable', 'string', 'max:50'],
            'cover_image_path' => ['nullable', 'string', 'max:500'],
            'seo_title' => ['nullable', 'string', 'max:255'],
            'seo_description' => ['nullable', 'string', 'max:500'],
        ];
    }
}
