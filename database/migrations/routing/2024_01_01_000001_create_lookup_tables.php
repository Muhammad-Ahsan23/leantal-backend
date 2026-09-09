<?php
// database/migrations/routing_db/2024_01_01_000001_create_lookup_tables.php
// Ye migration SIRF "routing_db" par chalegi (--database=routing_db)
// Isliye separate migrations folder rakhna best practice hai (config mein path set karo)

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // Migration khud batati hai ye kis connection par chale (extra safety)
    protected $connection = 'routing_db';

    public function up(): void
    {
        DB::statement("CREATE TYPE routing_region AS ENUM ('us', 'eu', 'uk')");

        Schema::connection('routing_db')->create('company_region_lookup', function (Blueprint $table) {
            $table->uuid('company_id')->primary();
            $table->string('company_slug')->unique();
            $table->string('region'); // 'us' | 'eu' | 'uk'
            $table->timestampTz('created_at')->useCurrent();

            $table->index('company_slug');
        });

        Schema::connection('routing_db')->create('user_email_region_lookup', function (Blueprint $table) {
            $table->string('email')->primary(); // normalized: lowercase, trimmed
            $table->uuid('company_id');
            $table->string('region');
            $table->timestampTz('updated_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::connection('routing_db')->dropIfExists('company_region_lookup');
        Schema::connection('routing_db')->dropIfExists('user_email_region_lookup');
        DB::statement('DROP TYPE IF EXISTS routing_region');
    }
};
