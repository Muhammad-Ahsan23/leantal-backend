<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * More than one person can own a Job, a Candidate or a Task.
 *
 * Each table lists ALL the people assigned. The existing `assigned_user_id` column stays and always holds the FIRST
 * (primary) one, so everything that reads that column keeps working. Existing assignments are copied across here.
 */
return new class extends Migration
{
    /** pivot table => [owner table, owner key column] */
    private const TABLES = [
        'job_assignees' => ['jobs', 'job_id'],
        'candidate_assignees' => ['candidates', 'candidate_id'],
        'task_assignees' => ['tasks', 'task_id'],
    ];

    public function up(): void
    {
        foreach (self::TABLES as $pivot => [$owner, $key]) {
            if (Schema::hasTable($pivot)) {
                continue;
            }

            Schema::create($pivot, function (Blueprint $table) use ($owner, $key) {
                $table->foreignUuid($key)->constrained($owner)->cascadeOnDelete();
                $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
                $table->timestamp('created_at')->useCurrent();
                $table->primary([$key, 'user_id']);
                $table->index('user_id');
            });

            DB::statement("INSERT INTO {$pivot} ({$key}, user_id) SELECT id, assigned_user_id FROM {$owner} WHERE assigned_user_id IS NOT NULL ON CONFLICT DO NOTHING");
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::TABLES) as $pivot) {
            Schema::dropIfExists($pivot);
        }
    }
};
