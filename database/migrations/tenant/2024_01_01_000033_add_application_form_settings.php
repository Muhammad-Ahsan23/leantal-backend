<?php
// Client request (Breezy-HR-style application form): every job can switch the optional
// application form fields on/off or make them required, and every application stores
// the extra details the candidate filled in.
//
//   jobs.application_fields    {"phone":"optional","resume":"required","address":"disabled", ...}
//                              NULL = use the defaults (Phone + Resume optional, the rest disabled).
//   applications.application_data   address, desired_salary, experience_summary, cover_letter,
//                              work_history[], education[]  (per application, not per candidate:
//                              the same person can apply to different jobs with different answers).
//
// Guarded with hasColumn() so it is safe to run more than once, and on every region.

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('jobs', 'application_fields')) {
            Schema::table('jobs', function (Blueprint $table) {
                $table->jsonb('application_fields')->nullable();
            });
        }

        if (!Schema::hasColumn('applications', 'application_data')) {
            Schema::table('applications', function (Blueprint $table) {
                $table->jsonb('application_data')->nullable();
            });
        }
    }

    public function down(): void
    {
        // Deliberately not dropping: by then real candidate data would be lost.
    }
};
