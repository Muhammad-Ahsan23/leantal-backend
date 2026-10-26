<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** The invited person's name, so the Users tab can show it and a re-send can greet them. Old rows stay NULL. */
    public function up(): void
    {
        Schema::table('invitations', function (Blueprint $table) {
            $table->string('name')->nullable()->after('company_id');
        });
    }

    public function down(): void
    {
        Schema::table('invitations', function (Blueprint $table) {
            $table->dropColumn('name');
        });
    }
};
