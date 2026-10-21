<?php

namespace App\Services;

use App\Models\Task;
use App\Models\TaskAttachment;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class TaskAttachmentService
{
    public const MAX_PER_TASK = 10;

    /**
     * Stores the files on the company's regional disk and records them. Returns the new rows.
     * The per-task limit is checked by the caller before this runs.
     *
     * @param  UploadedFile[]  $files
     * @return TaskAttachment[]
     */
    public function add(Task $task, array $files, User $uploader, string $connection): array
    {
        $disk = RegionResolver::storageDiskFor(RegionResolver::regionForConnection($connection));
        $created = [];

        try {
            foreach ($files as $file) {
                $path = $file->store("task-attachments/{$task->id}", $disk);
                if ($path === false) {
                    throw new \RuntimeException('Attachment upload failed. Please check storage configuration.');
                }

                $created[] = TaskAttachment::on($connection)->create([
                    'company_id' => $task->company_id,
                    'task_id' => $task->id,
                    'uploaded_by' => $uploader->id,
                    'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
                    'disk' => $disk,
                    'path' => $path,
                    'mime_type' => $file->getClientMimeType(),
                    'size_bytes' => $file->getSize() ?: 0,
                    'created_at' => now(),
                ]);
            }
        } catch (\Throwable $e) {
            // All or nothing: a half-uploaded batch is removed again.
            foreach ($created as $row) {
                $this->removeFile($row);
                $row->delete();
            }
            throw $e;
        }

        return $created;
    }

    public function remove(TaskAttachment $attachment): void
    {
        $this->removeFile($attachment);
        $attachment->delete();
    }

    /** Removes every file of a task (rows go with the task via the foreign key). */
    public function removeAllFor(Task $task): void
    {
        foreach ($task->attachments()->get() as $attachment) {
            $this->removeFile($attachment);
        }
    }

    public function temporaryUrl(TaskAttachment $attachment): string
    {
        return Storage::disk($attachment->disk)->temporaryUrl(
            $attachment->path,
            now()->addMinutes(10),
            ['ResponseContentDisposition' => 'attachment; filename="'.addcslashes($attachment->original_name, '"\\').'"'],
        );
    }

    protected function removeFile(TaskAttachment $attachment): void
    {
        try {
            Storage::disk($attachment->disk)->delete($attachment->path);
        } catch (\Throwable $e) {
            report($e); // a stuck file never blocks removing the record
        }
    }
}
