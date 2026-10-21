<?php

namespace App\Console\Commands;

use App\Support\CacheVersion;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Fake data for testing pagination / list performance.
 *
 *   php artisan dummy:seed owner@company.com
 *   php artisan dummy:seed owner@company.com --jobs=80 --candidates=500 --tasks=150
 *   php artisan dummy:clear owner@company.com        (removes ONLY what this created)
 *
 * Everything is tagged so it can be removed safely:
 *   jobs   -> title starts with "[Demo] "
 *   tasks  -> title starts with "[Demo] "
 *   candidates -> email ends with "@dummy.leantal.test"
 * Nothing real is touched. Bypasses the plan job limit on purpose.
 */
class SeedDummyData extends Command
{
    protected $signature = 'dummy:seed {email : Email of an existing user (owner) of the company}
        {--jobs=60} {--candidates=300} {--tasks=120}
        {--force : Allow running when APP_ENV=production}';

    protected $description = 'Insert fake jobs, candidates (with applications) and tasks for pagination testing.';

    public const TAG = '[Demo] ';
    public const EMAIL_DOMAIN = 'dummy.leantal.test';

    public function handle(): int
    {
        if (app()->environment('production') && !$this->option('force')) {
            $this->error('APP_ENV=production: refusing. Use a test company and add --force if you are sure.');
            return self::FAILURE;
        }

        $email = strtolower(trim($this->argument('email')));
        $conn = null;
        $user = null;
        foreach (['pgsql_us', 'pgsql_eu', 'pgsql_uk'] as $c) {
            try {
                $u = DB::connection($c)->table('users')->whereRaw('lower(email) = ?', [$email])->first();
            } catch (\Throwable $e) {
                continue; // region DB not configured here
            }
            if ($u) { $conn = $c; $user = $u; break; }
        }
        if (!$user) {
            $this->error("No user with email {$email} found in any region.");
            return self::FAILURE;
        }

        $nJobs = max(0, (int) $this->option('jobs'));
        $nCand = max(0, (int) $this->option('candidates'));
        $nTask = max(0, (int) $this->option('tasks'));
        $cid = $user->company_id;
        $db = DB::connection($conn);
        $users = $db->table('users')->where('company_id', $cid)->whereNull('removed_at')->pluck('id')->all();
        $now = now();
        $run = strtolower(Str::random(5));

        $this->info("Company {$cid} on {$conn}. Creating {$nJobs} jobs, {$nCand} candidates, {$nTask} tasks...");

        // ---------- Jobs (+ the 3 protected stages each) ----------
        $titles = ['Software Engineer', 'Product Designer', 'Sales Executive', 'Marketing Manager', 'Data Analyst',
            'DevOps Engineer', 'HR Generalist', 'Customer Success Rep', 'QA Engineer', 'Project Manager',
            'Finance Associate', 'Content Writer', 'Support Agent', 'Backend Developer', 'Frontend Developer'];
        $depts = ['Engineering', 'Design', 'Sales', 'Marketing', 'Operations', 'HR', 'Finance', 'Support'];
        $locs = ['Remote', 'Karachi', 'Lahore', 'London', 'Berlin', 'New York', 'Dubai'];
        $jobStatuses = ['published', 'published', 'published', 'draft', 'closed'];
        $jobIds = []; $appliedStage = [];
        $jobRows = []; $stageRows = [];
        for ($i = 1; $i <= $nJobs; $i++) {
            $id = (string) Str::uuid();
            $st = $jobStatuses[array_rand($jobStatuses)];
            $created = $now->copy()->subMinutes($i * 37);
            $jobRows[] = [
                'id' => $id, 'company_id' => $cid,
                'title' => self::TAG . $titles[array_rand($titles)] . " #{$i}",
                'department' => $depts[array_rand($depts)],
                'description' => 'Demo job created for pagination testing.',
                'location' => $locs[array_rand($locs)], 'location_type' => 'onsite', 'employment_type' => 'full_time',
                'status' => $st, 'created_by' => $user->id,
                'assigned_user_id' => $users[array_rand($users)],
                'published_at' => $st === 'draft' ? null : $created, 'closed_at' => $st === 'closed' ? $created : null,
                'created_at' => $created, 'updated_at' => $created,
            ];
            foreach (['Applied', 'Hired', 'Rejected'] as $o => $name) {
                $sid = (string) Str::uuid();
                if ($name === 'Applied') $appliedStage[$id] = $sid;
                $stageRows[] = ['id' => $sid, 'job_id' => $id, 'name' => $name, 'order' => $o + 1,
                    'protected' => true, 'created_at' => $created];
            }
            $jobIds[] = $id;
        }
        $db->transaction(function () use ($db, $jobRows, $stageRows) {
            foreach (array_chunk($jobRows, 200) as $c) $db->table('jobs')->insert($c);
            foreach (array_chunk($stageRows, 500) as $c) $db->table('pipeline_stages')->insert($c);
        });

        // ---------- Candidates (+ 0-2 applications each) ----------
        $first = ['Ali', 'Sara', 'Ahmed', 'Fatima', 'John', 'Emma', 'Omar', 'Zainab', 'Liam', 'Noor', 'Hamza', 'Ayesha', 'Daniel', 'Maria', 'Usman'];
        $last = ['Khan', 'Shaikh', 'Malik', 'Smith', 'Johnson', 'Ahmed', 'Raza', 'Brown', 'Siddiqui', 'Miller', 'Iqbal', 'Davis'];
        $candIds = [];
        $db->transaction(function () use ($db, $nCand, $first, $last, $locs, $cid, $users, $now, $run, $jobIds, $appliedStage, &$candIds) {
            $rows = []; $apps = [];
            for ($i = 1; $i <= $nCand; $i++) {
                $id = (string) Str::uuid();
                $name = $first[array_rand($first)] . ' ' . $last[array_rand($last)];
                $mail = strtolower(str_replace(' ', '.', $name)) . ".{$run}{$i}@" . self::EMAIL_DOMAIN;
                $created = $now->copy()->subMinutes($i * 11);
                $rows[] = [
                    'id' => $id, 'company_id' => $cid, 'name' => $name, 'email' => $mail, 'normalized_email' => $mail,
                    'phone' => '+92300' . str_pad((string) random_int(0, 9999999), 7, '0', STR_PAD_LEFT),
                    'location' => $locs[array_rand($locs)],
                    'current_title' => 'Demo Candidate', 'current_company' => 'Acme Demo',
                    'assigned_user_id' => random_int(0, 2) ? $users[array_rand($users)] : null,
                    'status' => 'active', 'created_at' => $created, 'updated_at' => $created,
                ];
                $candIds[] = $id;
                if ($jobIds && random_int(1, 10) <= 7) {
                    foreach ((array) array_rand($jobIds, min(random_int(1, 2), count($jobIds))) as $k) {
                        $jid = $jobIds[$k];
                        $apps[] = ['id' => (string) Str::uuid(), 'company_id' => $cid, 'candidate_id' => $id,
                            'job_id' => $jid, 'stage_id' => $appliedStage[$jid], 'status' => 'active',
                            'applied_at' => $created, 'created_at' => $created, 'updated_at' => $created, 'lock_version' => 1];
                    }
                }
            }
            foreach (array_chunk($rows, 200) as $c) $db->table('candidates')->insert($c);
            foreach (array_chunk($apps, 200) as $c) $db->table('applications')->insert($c);
        });

        // ---------- Tasks ----------
        $verbs = ['Call', 'Email', 'Review resume of', 'Schedule interview with', 'Send offer to', 'Follow up with', 'Prepare feedback for'];
        $taskStatuses = ['to_do', 'to_do', 'to_do', 'in_progress', 'done', 'done'];
        $taskRows = [];
        for ($i = 1; $i <= $nTask; $i++) {
            $created = $now->copy()->subMinutes($i * 23);
            $type = ['normal', 'assign_candidate', 'assign_job'][random_int(0, 2)];
            $taskRows[] = [
                'id' => (string) Str::uuid(), 'company_id' => $cid, 'type' => $type,
                'title' => self::TAG . $verbs[array_rand($verbs)] . " candidate #{$i}",
                'assigned_user_id' => $users[array_rand($users)], 'created_by' => $user->id,
                'candidate_id' => ($type === 'assign_candidate' && $candIds) ? $candIds[array_rand($candIds)] : null,
                'job_id' => ($type === 'assign_job' && $jobIds) ? $jobIds[array_rand($jobIds)] : null,
                'due_date' => $now->copy()->addDays(random_int(-5, 20))->toDateString(),
                'status' => $taskStatuses[array_rand($taskStatuses)],
                'notes' => 'Demo task for pagination testing.', 'auto_generated' => false,
                'created_at' => $created, 'updated_at' => $created,
            ];
        }
        $db->transaction(function () use ($db, $taskRows) {
            foreach (array_chunk($taskRows, 200) as $c) $db->table('tasks')->insert($c);
        });

        // Invalidate cached job/candidate lists for this company.
        foreach (['jobs', 'candidates', 'tasks'] as $scope) {
            try { CacheVersion::bump("company:{$cid}:{$scope}"); } catch (\Throwable $e) {}
        }

        $this->info('Done. Remove later with: php artisan dummy:clear ' . $email);
        return self::SUCCESS;
    }
}
