<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a renewal payment fails Creem reports the subscription as past_due. The company keeps full
 * access during a grace period, and this column records when that period started so the banner can
 * show the deadline and the daily job can move the company to read_only once it has run out.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->timestampTz('past_due_since')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('past_due_since');
        });
    }
};
