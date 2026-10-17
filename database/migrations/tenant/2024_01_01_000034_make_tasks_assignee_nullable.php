<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * PRD Section 70 — when a user is removed, "assigned items become Unassigned or can optionally be
 * reassigned". tasks.assigned_user_id was NOT NULL, so unassigning a removed user's open tasks
 * failed with a database error. It must be nullable for "Unassigned" to be representable.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE tasks ALTER COLUMN assigned_user_id DROP NOT NULL');
    }

    public function down(): void
    {
        // Rows with a NULL assignee cannot satisfy NOT NULL; leave the column nullable on rollback
        // rather than fail or invent an owner for them.
    }
};
