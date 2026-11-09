<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Task;
use App\Models\TaskAttachment;
use App\Services\TaskAttachmentService;
use App\Support\Roles;
use Illuminate\Http\Request;

class TaskAttachmentController extends Controller
{
    public function __construct(protected TaskAttachmentService $attachments) {}

    /** Short-lived signed download link, same idea as the candidate resume link. */
    public function url(Request $request, string $id, string $attachmentId)
    {
        $user = $request->user();
        $connection = $user->getConnectionName();
        $task = Task::on($connection)->find($id);

        if (!$task) {
            return response()->json(['message' => 'Task not found.'], 404);
        }

        if (!$user->can('view', $task)) {
            return response()->json(['message' => 'You do not have permission to view this task.'], 403);
        }

        $attachment = TaskAttachment::on($connection)->where('task_id', $task->id)->find($attachmentId);
        if (!$attachment) {
            return response()->json(['message' => 'Attachment not found.'], 404);
        }

        return response()->json(['url' => $this->attachments->temporaryUrl($attachment), 'filename' => $attachment->original_name]);
    }

    /** Owner/HM can remove any file; anyone else only a file they uploaded themselves (and still may update the task). */
    public function destroy(Request $request, string $id, string $attachmentId)
    {
        $user = $request->user();
        $connection = $user->getConnectionName();
        $task = Task::on($connection)->find($id);

        if (!$task) {
            return response()->json(['message' => 'Task not found.'], 404);
        }

        $attachment = TaskAttachment::on($connection)->where('task_id', $task->id)->find($attachmentId);
        if (!$attachment) {
            return response()->json(['message' => 'Attachment not found.'], 404);
        }

        $isManagement = in_array($user->role, Roles::MANAGEMENT, true) && $task->company_id === $user->company_id;
        $isOwnUpload = $attachment->uploaded_by === $user->id && $user->can('update', $task);
        if (!$isManagement && !$isOwnUpload) {
            return response()->json(['message' => 'You do not have permission to remove this file.'], 403);
        }

        $this->attachments->remove($attachment);

        return response()->json(['message' => 'Attachment removed.']);
    }
}
