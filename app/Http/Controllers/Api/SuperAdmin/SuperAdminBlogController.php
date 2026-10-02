<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SuperAdmin\SaveBlogPostRequest;
use App\Models\BlogPost;
use Illuminate\Http\Request;

class SuperAdminBlogController extends Controller
{
    /**
     * PRD Section 84/121 — "Title, Slug, Content, Status (Draft,
     * Published, Archived), Author, Cover Image, SEO metadata."
     */
    public function index(Request $request)
    {
        $query = BlogPost::with('author:id,name')->orderByDesc('created_at');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        return response()->json(['posts' => $query->get()]);
    }

    public function show(string $id)
    {
        $post = BlogPost::with('author:id,name')->find($id);
        if (!$post) {
            return response()->json(['message' => 'Post not found.'], 404);
        }

        return response()->json(['post' => $post]);
    }

    public function store(SaveBlogPostRequest $request)
    {
        $admin = $request->attributes->get('super_admin');
        $data = $request->validated();

        $post = BlogPost::create([
            ...$data,
            'author_id' => $admin->id,
            'published_at' => $data['status'] === 'published' ? now() : null,
        ]);

        return response()->json(['post' => $post->load('author:id,name')], 201);
    }

    public function update(SaveBlogPostRequest $request, string $id)
    {
        $post = BlogPost::find($id);
        if (!$post) {
            return response()->json(['message' => 'Post not found.'], 404);
        }

        $data = $request->validated();

        // Set published_at the FIRST time a post transitions into
        // 'published' — re-saving an already-published post (e.g. a
        // typo fix) shouldn't bump its original publish date.
        if ($data['status'] === 'published' && !$post->published_at) {
            $data['published_at'] = now();
        }

        $post->update($data);

        return response()->json(['post' => $post->fresh('author:id,name')]);
    }

    public function destroy(string $id)
    {
        $post = BlogPost::find($id);
        if (!$post) {
            return response()->json(['message' => 'Post not found.'], 404);
        }

        $post->delete();

        return response()->json(['message' => 'Post deleted.']);
    }
}
