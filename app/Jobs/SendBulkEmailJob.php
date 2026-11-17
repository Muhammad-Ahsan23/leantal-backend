<?php

namespace App\Jobs;

use App\Models\Application;
use App\Models\Company;
use App\Models\Job;
use App\Models\User;
use App\Services\EmailService;
use App\Services\SystemEventLogger;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * ONE email of a bulk send. The controller queues one of these per candidate, spaced out in time, so a 100-candidate send
 * never holds a web request open and never hits Gmail / Outlook / SMTP in one burst. Only ids are stored in the queue
 * (never models, never the Gmail token); everything is re-read when the job runs, so a candidate who was archived or
 * re-assigned in the meantime is checked again.
 *
 * Retries (nothing here ever blocks a send for good):
 *  - A provider RATE LIMIT (Gmail "Quota exceeded", Microsoft 429) is expected under load and clears by itself, so the job
 *    waits ~70s and tries again. These waits are counted separately and do NOT use up the normal tries; the job only gives
 *    up after MAX_RATE_LIMIT_RETRIES waits (about half an hour of a limit that never clears).
 *  - Any other provider / transport error is retried 3 times, 1 minute then 5 minutes apart.
 *  - A message problem (a template variable with no value) cannot be fixed by trying again, so it is logged to the
 *    Debugging view and dropped.
 * A cache key makes a retry after a half-finished send (mail went out, bookkeeping failed) skip the candidate instead of
 * mailing twice. The retries are driven by release() inside handle(), so the worker's own tries limit is replaced by
 * retryUntil() plus a hard attempts ceiling.
 */
class SendBulkEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 60;

    private const MAX_RATE_LIMIT_RETRIES = 30;
    private const MAX_ERROR_TRIES = 3;
    private const ERROR_BACKOFF = [60, 300];
    private const HARD_ATTEMPT_CEILING = 60;
    private const RATE_LIMIT_PATTERN = '/rateLimitExceeded|RATE_LIMIT_EXCEEDED|userRateLimit|Quota exceeded|TooManyRequests|Too Many Requests|throttl|\\b429\\b/i';
    private const TEMPLATE_PATTERN = '/unfilled variables|template not found|required/i';

    public function __construct(
        public string $dbConnection,
        public string $batchId,
        public string $applicationId,
        public string $actorId,
        public string $mode,
        public ?string $templateId,
        public ?string $subject,
        public ?string $body,
    ) {}

    // The window in which release()-driven retries are allowed (rate-limit waits can be long on a big batch).
    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHours(3);
    }

    public function handle(EmailService $emails): void
    {
        $doneKey = "bulk-email:{$this->batchId}:{$this->applicationId}";
        if (Cache::has($doneKey)) {
            return;
        }

        $application = Application::on($this->dbConnection)->with('candidate')->find($this->applicationId);
        $actor = User::on($this->dbConnection)->find($this->actorId);
        $candidate = $application?->candidate;

        if (!$application || !$actor || !$candidate || !$candidate->email || !$actor->can('update', $candidate)) {
            return; // gone, or no longer allowed: nothing to send
        }

        $company = Company::on($this->dbConnection)->find($actor->company_id);
        $job = Job::on($this->dbConnection)->find($application->job_id);

        try {
            if ($this->mode === 'send_personal') {
                $emails->sendPersonalEmail($candidate, $actor, $this->templateId, $this->subject, $this->body, $job, null, $company->name ?? '', $this->dbConnection);
            } else {
                $emails->sendSystemEmail($candidate, $this->templateId, $this->subject, $this->body, $job, $actor, null, $company->name ?? '', $actor, $this->dbConnection);
            }
        } catch (\Throwable $e) {
            $this->handleFailure($e);
            return;
        }

        Cache::put($doneKey, 1, now()->addDay());
    }

    protected function handleFailure(\Throwable $e): void
    {
        if ($this->attempts() >= self::HARD_ATTEMPT_CEILING) {
            $this->fail($e); // safety net: never loop forever
            return;
        }

        $message = $e->getMessage();

        if (preg_match(self::RATE_LIMIT_PATTERN, $message)) {
            if ($this->bump('rl') > self::MAX_RATE_LIMIT_RETRIES) {
                $this->fail($e);
                return;
            }
            $this->release(70 + random_int(0, 20)); // jitter so a batch does not all retry in the same second
            return;
        }

        // A template problem will not fix itself: record it and stop.
        if ($e instanceof \RuntimeException && !($e instanceof TransportExceptionInterface) && preg_match(self::TEMPLATE_PATTERN, $message)) {
            $this->record($message);
            return;
        }

        $n = $this->bump('err');
        if ($n >= self::MAX_ERROR_TRIES) {
            $this->fail($e);
            return;
        }
        $this->release(self::ERROR_BACKOFF[$n - 1] ?? 300);
    }

    protected function bump(string $kind): int
    {
        $key = "bulk-email-{$kind}:{$this->batchId}:{$this->applicationId}";
        Cache::add($key, 0, now()->addDay());

        return (int) Cache::increment($key);
    }

    // Called once, when the job is given up on.
    public function failed(\Throwable $e): void
    {
        $message = $e->getMessage();
        if (preg_match(self::RATE_LIMIT_PATTERN, $message)) {
            $this->record('The provider rate limit did not clear in time, so this email was not sent. Retry the send later.');
        } elseif ($e instanceof TransportExceptionInterface) {
            $this->record('Mail transport failed after '.self::MAX_ERROR_TRIES.' tries.');
        } else {
            $this->record(mb_substr($message, 0, 500));
        }
    }

    protected function record(string $message): void
    {
        try {
            $actor = User::on($this->dbConnection)->find($this->actorId);
            app(SystemEventLogger::class)->log('email', 'Bulk email: '.$message, $actor?->company_id, str_replace('pgsql_', '', $this->dbConnection), [
                'batch_id' => $this->batchId,
                'application_id' => $this->applicationId,
                'mode' => $this->mode,
                'template_id' => $this->templateId,
                'user_id' => $this->actorId,
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
