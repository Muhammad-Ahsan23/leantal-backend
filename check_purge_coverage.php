<?php
// Run from the project root:  php check_purge_coverage.php
//
// SAFE / READ-ONLY. Before using permanent company deletion on a real database,
// this lists every table that has a foreign key INTO the tenant tables but is
// NOT handled by CompanyPurgeService::DELETE_ORDER. An unhandled table would make
// the deletion fail (and roll back, deleting nothing) or leave data behind.
// Expected output for every connection:  "all covered".

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$covered = array_column(App\Services\CompanyPurgeService::DELETE_ORDER, 0);

$sql = "SELECT DISTINCT c.conrelid::regclass::text AS table_name
        FROM pg_constraint c
        WHERE c.contype = 'f'
          AND c.confrelid::regclass::text IN
              ('companies','users','candidates','jobs','applications','pipeline_stages','application_questions','custom_fields')";

foreach (['pgsql_us', 'pgsql_eu', 'pgsql_uk'] as $connection) {
    try {
        $tables = array_map(fn ($r) => trim($r->table_name, '"'), Illuminate\Support\Facades\DB::connection($connection)->select($sql));
    } catch (Throwable $e) {
        echo "$connection: could not connect (".substr($e->getMessage(), 0, 80).")\n";
        continue;
    }
    $missing = array_values(array_diff($tables, $covered));
    echo $connection.': '.($missing ? 'NOT COVERED -> '.implode(', ', $missing) : 'all covered')."\n";
}
