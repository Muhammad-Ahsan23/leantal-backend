<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// How the company is billed: 'monthly' or 'yearly'. NULL = not known yet (subscriptions made before this column existed;
// it is filled by the next plan change or Creem payment event). Runs on every regional database
// (--database=pgsql_us / pgsql_eu / pgsql_uk), like the other tenant migrations.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('billing_interval', 10)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('billing_interval');
        });
    }
};
