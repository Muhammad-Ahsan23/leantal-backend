<?php

namespace App\Console\Commands;

use App\Support\CacheVersion;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Removes ONLY rows created by dummy:seed (tagged "[Demo] " / @dummy.leantal.test). */
class ClearDummyData extends Command
{
    protected $signature = 'dummy:clear {email : Email of a user of the company}
        {--force : Allow running when APP_ENV=production}';

    protected $description = 'Delete the fake data created by dummy:seed.';

    public function handle(): int
    {
        if (app()->environment('production') && !$this->option('force')) {
            $this->error('APP_ENV=production: refusing. Add --force if you are sure.');
            return self::FAILURE;
        }

        $email = strtolower(trim($this->argument('email')));
        $conn = null; $user = null;
        foreach (['pgsql_us', 'pgsql_eu', 'pgsql_uk'] as $c) {
            try {
                $u = DB::connection($c)->table('users')->whereRaw('lower(email) = ?', [$email])->first();
            } catch (\Throwable $e) { continue; }
            if ($u) { $conn = $c; $user = $u; break; }
        }
        if (!$user) {
            $this->error("No user with email {$email} found in any region.");
            return self::FAILURE;
        }

        $cid = $user->company_id;
        $db = DB::connection($conn);
        $tag = SeedDummyData::TAG . '%';
        $mail = '%@' . SeedDummyData::EMAIL_DOMAIN;

        $counts = $db->transaction(function () use ($db, $cid, $tag, $mail) {
            $jobIds = $db->table('jobs')->where('company_id', $cid)->where('title', 'like', $tag)->pluck('id');
            $candIds = $db->table('candidates')->where('company_id', $cid)->where('email', 'like', $mail)->pluck('id');

            // tasks first: they reference jobs/candidates without cascade
            $t = $db->table('tasks')->where('company_id', $cid)
                ->where(function ($q) use ($tag, $jobIds, $candIds) {
                    $q->where('title', 'like', $tag);
                    if ($jobIds->isNotEmpty()) $q->orWhereIn('job_id', $jobIds);
                    if ($candIds->isNotEmpty()) $q->orWhereIn('candidate_id', $candIds);
                })->delete();

            // applications cascade from candidates/jobs, stages from jobs
            $c = 0; foreach ($candIds->chunk(500) as $ch) $c += $db->table('candidates')->whereIn('id', $ch)->delete();
            $j = 0; foreach ($jobIds->chunk(500) as $ch) $j += $db->table('jobs')->whereIn('id', $ch)->delete();
            return [$j, $c, $t];
        });

        foreach (['jobs', 'candidates', 'tasks'] as $scope) {
            try { CacheVersion::bump("company:{$cid}:{$scope}"); } catch (\Throwable $e) {}
        }

        $this->info("Removed {$counts[0]} jobs, {$counts[1]} candidates, {$counts[2]} tasks.");
        return self::SUCCESS;
    }
}
