<?php

namespace App\Console\Commands;

use App\Support\TextFormat;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-off cleanup for rows saved BEFORE automatic Title Case existed
 * ("developer" -> "Developer", "pakistan" -> "Pakistan", "john smith" -> "John Smith").
 * Same rules as TextFormat, so old and new data match. Always run with --dry-run first.
 *
 *   php artisan data:normalize-case --dry-run
 *   php artisan data:normalize-case
 *   php artisan data:normalize-case --connection=pgsql_us
 */
class NormalizeTextCase extends Command
{
    protected $signature = 'data:normalize-case {--dry-run : Show what would change and write nothing} {--connection=* : Only these regions (default: pgsql_us, pgsql_eu, pgsql_uk)}';

    protected $description = 'Apply Title Case to existing job titles, departments, locations and names';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $connections = $this->option('connection') ?: ['pgsql_us', 'pgsql_eu', 'pgsql_uk'];
        $this->info($dry ? 'DRY RUN - nothing will be written.' : 'Writing changes.');

        foreach ($connections as $connection) {
            $this->line('');
            $this->info("== {$connection} ==");
            try {
                DB::connection($connection)->getPdo();
            } catch (\Throwable $e) {
                $this->warn("Skipped (cannot connect): {$e->getMessage()}");
                continue;
            }

            foreach (TextFormat::FIELDS as $model => $fields) {
                $table = (new $model)->getTable();
                $changed = 0;
                $samples = [];
                $skipped = [];
                $taken = $table === 'departments' ? $this->existingDepartmentNames($connection) : [];

                DB::connection($connection)->table($table)->select(array_merge(['id'], $table === 'departments' ? ['company_id'] : [], array_keys($fields)))
                    ->orderBy('id')
                    ->chunkById(500, function ($rows) use ($connection, $table, $fields, $dry, &$changed, &$samples, &$skipped, &$taken) {
                        foreach ($rows as $row) {
                            $update = [];
                            foreach ($fields as $column => $mode) {
                                $old = $row->{$column};
                                if (!is_string($old) || $old === '') {
                                    continue;
                                }
                                $new = TextFormat::apply($mode, $old);
                                if ($new !== $old) {
                                    $update[$column] = $new;
                                    if (count($samples) < 5) {
                                        $samples[] = "{$column}: '{$old}' -> '{$new}'";
                                    }
                                }
                            }
                            if (!$update) {
                                continue;
                            }

                            if ($table === 'departments') {
                                $key = $row->company_id.'|'.mb_strtolower($update['name']);
                                if (isset($taken[$key]) && $taken[$key] !== $row->id) {
                                    $skipped[] = "'{$row->name}' would duplicate an existing department";
                                    continue;
                                }
                                $taken[$key] = $row->id;
                            }

                            $changed++;
                            if (!$dry) {
                                // Raw update on purpose: no updated_at bump, no model events.
                                DB::connection($connection)->table($table)->where('id', $row->id)->update($update);
                            }
                        }
                    }, 'id');

                $this->line(sprintf('%-12s %d row(s) %s', $table, $changed, $dry ? 'would change' : 'changed'));
                foreach ($samples as $s) {
                    $this->line("    e.g. {$s}");
                }
                foreach ($skipped as $s) {
                    $this->warn("    skipped: {$s}");
                }
            }
        }

        return self::SUCCESS;
    }

    /**
     * "companyId|lowercase name" => the department id that keeps that name, so a rename never collides
     * with another department. A department already in Title Case always wins over a lowercase twin.
     */
    private function existingDepartmentNames(string $connection): array
    {
        $taken = [];
        foreach (DB::connection($connection)->table('departments')->select('id', 'company_id', 'name')->orderBy('id')->get() as $d) {
            $key = $d->company_id.'|'.mb_strtolower(TextFormat::title($d->name));
            if (!isset($taken[$key]) || TextFormat::title($d->name) === $d->name) {
                $taken[$key] = $d->id;
            }
        }

        return $taken;
    }
}
