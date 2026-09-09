<?php
// database/migrations/2024_01_01_000001_create_companies_table.php
// Ye migration TEENO regional DBs (pgsql_us, pgsql_eu, pgsql_uk) par chalegi
// -- isi wajah se hum --database=pgsql_us / eu / uk se run karte hain,
// migration file ke andar khud connection() specify NAHI karte
// (taake wahi file teeno DBs ke liye reusable rahe)

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ENUM types Postgres mein pehle create karne padte hain
        DB::statement("CREATE TYPE company_region AS ENUM ('us', 'eu', 'uk')");
        DB::statement("CREATE TYPE company_plan AS ENUM ('free', 'starter', 'team', 'scale')");
        DB::statement("CREATE TYPE subscription_status AS ENUM ('trialing', 'active', 'past_due', 'canceled', 'suspended', 'read_only')");

        Schema::create('companies', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('gen_random_uuid()'));
            $table->string('name');
            $table->string('website');
            $table->string('location');
            $table->char('country_code', 2);
            $table->enum('region', ['us', 'eu', 'uk']); // matches company_region type
            $table->string('slug')->unique();
            $table->text('careers_description')->nullable();

            $table->string('plan')->default('free');
            $table->string('subscription_status')->default('trialing');
            $table->timestampTz('trial_start')->useCurrent();
            $table->timestampTz('trial_end');

            $table->string('creem_customer_id')->nullable();
            $table->string('creem_subscription_id')->nullable();

            $table->timestampTz('deletion_requested_at')->nullable();
            $table->string('data_retention_choice')->nullable();
            $table->timestampTz('deleted_at')->nullable();

            $table->timestampTz('suspended_at')->nullable();
            $table->text('suspended_reason')->nullable();

            $table->timestampsTz();

            $table->index('slug');
            $table->index('subscription_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
        DB::statement('DROP TYPE IF EXISTS company_region');
        DB::statement('DROP TYPE IF EXISTS company_plan');
        DB::statement('DROP TYPE IF EXISTS subscription_status');
    }
};
