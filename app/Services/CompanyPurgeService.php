<?php

namespace App\Services;

use App\Models\FeatureFlag;
use App\Models\MicrosoftSubscriptionState;
use App\Models\OAuthToken;
use App\Models\Company;
use App\Models\SystemEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * PRD Section 71 / 72 — permanent company deletion. The client's decision:
 * deleting a company erases EVERYTHING, immediately, with no soft delete,
 * no grace period and no retention options.
 *
 * What "everything" means here:
 *  - every row of the company in its regional database (users, jobs,
 *    candidates, applications, notes, emails, interviews, tasks, activity,
 *    notifications, templates, custom fields, invitations, billing records,
 *    login/session/OTP/OAuth rows ...)
 *  - candidate resume files in object storage (R2)
 *  - login tokens (Sanctum) for every member
 *  - the slug and email lookups in routing_db (so the slug and the emails
 *    can be registered again)
 *  - references to the company in admin_db (feature-flag targets, debug events)
 *  - the Creem subscription (cancelled immediately, so nobody keeps being billed)
 *  - Google/Microsoft access granted by the members (revoked)
 *
 * Order matters and is deliberate:
 *  1. Billing first. If the subscription cannot be cancelled, NOTHING is
 *     deleted — otherwise the id needed to cancel it later would be gone and
 *     the customer would keep being charged for a company that no longer exists.
 *  2. Everything the later steps need (user ids, file paths) is read BEFORE rows disappear.
 *  3. All regional rows are deleted in ONE transaction: all-or-nothing.
 *  4. Only after that commits are files / tokens / routing / admin data cleaned,
 *     each best-effort: a failure there is logged for Super Admin (Debugging)
 *     and never undoes or blocks the deletion.
 */
class CompanyPurgeService
{
    /**
     * Every tenant table holding company data, children before parents.
     * [table, column, parentTable|null]:
     *   parentTable null  -> WHERE column = :company
     *   parentTable set   -> WHERE column IN (SELECT id FROM parentTable WHERE company_id = :company)
     *
     * Why an explicit order and not just "DELETE FROM companies" + ON DELETE CASCADE:
     * several foreign keys are NO ACTION (e.g. jobs.created_by -> users,
     * tasks.candidate_id -> candidates, applications.stage_id -> pipeline_stages).
     * With two cascade paths into the same table, whether Postgres succeeds can
     * depend on trigger order. Deleting leaf-to-root removes that dependency;
     * the final "companies" row (and the cascades) are only a safety net.
     */
    public const DELETE_ORDER = [
        ['application_answers', 'application_id', 'applications'],
        ['notes', 'candidate_id', 'candidates'],
        ['applications', 'company_id', null],
        ['interviews', 'company_id', null],
        ['emails', 'company_id', null],
        ['tasks', 'company_id', null],
        ['custom_field_values', 'custom_field_id', 'custom_fields'],
        ['custom_fields', 'company_id', null],
        ['activity', 'company_id', null],
        ['notifications', 'company_id', null],
        ['email_templates', 'company_id', null],
        ['csv_import_jobs', 'company_id', null],
        ['invitations', 'company_id', null],
        ['webhook_logs', 'company_id', null],
        ['subscriptions', 'company_id', null],
        ['departments', 'company_id', null],
        ['pipeline_stages', 'job_id', 'jobs'],
        ['application_questions', 'job_id', 'jobs'],
        ['candidates', 'company_id', null],
        ['jobs', 'company_id', null],
        ['sessions', 'user_id', 'users'],
        ['oauth_tokens', 'user_id', 'users'],
        ['notification_preferences', 'user_id', 'users'],
        ['login_otps', 'user_id', 'users'],
        ['password_reset_tokens', 'user_id', 'users'],
        ['gmail_watch_state', 'user_id', 'users'],
        ['microsoft_subscription_state', 'user_id', 'users'],
        ['users', 'company_id', null],
        ['companies', 'id', null],
    ];

    public function __construct(
        protected CreemService $creem,
        protected GmailWatchService $gmailWatch,
        protected GoogleCalendarOAuthService $googleOAuth,
        protected MicrosoftSubscriptionService $microsoftSubscription,
    ) {}

    /**
     * @throws \RuntimeException with a user-safe message when deletion must not proceed
     *         (billing cannot be cancelled). Any other Throwable means the database
     *         step failed and, because it is one transaction, nothing was deleted.
     */
    public function purge(Company $company, string $connection): void
    {
        @set_time_limit(300);

        $companyId = $company->id;
        $region = str_replace('pgsql_', '', $connection);

        // 1. Billing — abort before touching any data if it cannot be cancelled.
        $this->cancelBilling($company);
        $creemCustomerId = $company->creem_customer_id;

        // 2. Read what we will no longer be able to read afterwards.
        $userIds = User::on($connection)->where('company_id', $companyId)->pluck('id')->all();
        $files = DB::connection($connection)->table('candidates')
            ->where('company_id', $companyId)
            ->whereNotNull('resume_path')
            ->get(['resume_disk', 'resume_path']);

        // M19: file-upload answers are stored in the region bucket too, so delete them with the company.
        $answerFiles = DB::connection($connection)->table('application_answers as aa')
            ->join('applications as a', 'a.id', '=', 'aa.application_id')
            ->where('a.company_id', $companyId)
            ->whereNotNull('aa.answer_file_path')
            ->get(['aa.answer_file_disk as resume_disk', 'aa.answer_file_path as resume_path']);
        $files = $files->concat($answerFiles);

        // 3. Revoke Google/Microsoft access the members granted (best-effort).
        $this->teardownIntegrations($userIds, $connection, $region);

        // 4. The point of no return: all regional rows, one transaction.
        $this->deleteTenantRows($connection, $companyId);

        // 5. Everything below runs AFTER the commit and must never throw.
        $this->safely($region, 'resume files', fn() => $this->deleteFiles($files));
        $this->safely($region, 'login tokens', fn() => $this->deleteTokens($userIds));
        $this->safely($region, 'routing lookups', fn() => $this->cleanRouting($companyId));
        $this->safely($region, 'billing webhook records', fn() => $this->deleteWebhookRecords($companyId, $creemCustomerId));
        $this->safely($region, 'admin references', fn() => $this->cleanAdmin($companyId));

        // Minimal platform-level proof that the deletion happened. Deliberately holds
        // only the opaque company id + region — no name, no emails, no personal data.
        // Written last, and resolved immediately so it is not shown as a failure.
        SystemEvent::create([
            'category' => 'company_purged',
            'region' => $region,
            'company_id' => null,
            'message' => 'Company permanently deleted.',
            'context' => ['company_id' => $companyId],
            'resolved_at' => now(),
        ]);
    }

    protected function cancelBilling(Company $company): void
    {
        if ($company->creem_subscription_id) {
            $this->creem->cancelSubscription($company->creem_subscription_id);

            return;
        }

        // A paying company whose subscription id was never stored cannot be cancelled
        // from here. Refuse rather than delete it and leave the customer being billed.
        if ($company->subscription_status === 'active') {
            throw new \RuntimeException(
                "We couldn't find the billing subscription for this company, so it can't be cancelled automatically. "
                    . 'Please contact support to cancel it first, then delete the company.'
            );
        }
    }

    protected function teardownIntegrations(array $userIds, string $connection, string $region): void
    {
        if (empty($userIds)) {
            return;
        }

        $tokens = OAuthToken::on($connection)->whereIn('user_id', $userIds)->whereNull('disconnected_at')->get();

        foreach ($tokens as $token) {
            if ($token->provider === 'google') {
                $this->safely($region, 'google watch stop', fn() => $this->gmailWatch->stopWatch($token));
                $this->safely($region, 'google token revoke', function () use ($token) {
                    $raw = $token->refresh_token ?? $token->access_token;
                    if ($raw) {
                        $this->googleOAuth->revokeToken($raw);
                    }
                });
            } elseif ($token->provider === 'microsoft') {
                $this->safely($region, 'microsoft subscription delete', function () use ($token, $connection) {
                    $state = MicrosoftSubscriptionState::on($connection)->where('user_id', $token->user_id)->first();
                    if ($state) {
                        $this->microsoftSubscription->deleteSubscription($token, $state->subscription_id);
                    }
                });
            }
        }
    }

    /**
     * @return array<string,int> rows deleted per table (useful in logs/tests)
     */
    public function deleteTenantRows(string $connection, string $companyId): array
    {
        $db = DB::connection($connection);
        $schema = Schema::connection($connection);

        // Resolved before the transaction so a table that does not exist in a
        // given environment is skipped rather than aborting the whole delete.
        $steps = array_values(array_filter(self::DELETE_ORDER, fn($s) => $schema->hasTable($s[0])));
        $counts = [];

        $db->transaction(function () use ($db, $steps, $companyId, &$counts) {
            foreach ($steps as [$table, $column, $parent]) {
                $query = $db->table($table);

                if ($parent === null) {
                    $query->where($column, $companyId);
                } else {
                    $query->whereIn($column, $db->table($parent)->select('id')->where('company_id', $companyId));
                }

                $counts[$table] = $query->delete();
            }
        });

        return $counts;
    }

    protected function deleteFiles($files): void
    {
        foreach ($files->groupBy('resume_disk') as $disk => $rows) {
            if (!$disk) {
                continue;
            }
            Storage::disk($disk)->delete($rows->pluck('resume_path')->all());
        }
    }

    protected function deleteTokens(array $userIds): void
    {
        if (empty($userIds)) {
            return;
        }

        // Sanctum tokens live in routing_db (see PersonalAccessToken::$connection) and
        // have no foreign key to users, so they are not removed with the user rows.
        DB::connection('routing_db')->table('personal_access_tokens')
            ->where('tokenable_type', User::class)
            ->whereIn('tokenable_id', $userIds)
            ->delete();
    }

    protected function cleanRouting(string $companyId): void
    {
        DB::connection('routing_db')->table('user_email_region_lookup')->where('company_id', $companyId)->delete();
        DB::connection('routing_db')->table('company_region_lookup')->where('company_id', $companyId)->delete();
    }

    /**
     * routing_db.billing_webhook_events keeps the FULL payload of every Creem
     * webhook, and a payload contains the customer's email and name — so it is
     * personal data that belongs to the deleted company. Matched by the company id
     * we put in the checkout metadata, and (for events that carry no metadata, such
     * as refunds) by the Creem customer id.
     */
    protected function deleteWebhookRecords(string $companyId, ?string $creemCustomerId): void
    {
        $query = DB::connection('routing_db')->table('billing_webhook_events')
            ->whereRaw("payload->'object'->'metadata'->>'company_id' = ?", [$companyId]);

        if ($creemCustomerId) {
            $query->orWhereRaw("payload->'object'->'customer'->>'id' = ?", [$creemCustomerId])
                ->orWhereRaw("payload->'object'->>'customer' = ?", [$creemCustomerId]);
        }

        $query->delete();
    }

    protected function cleanAdmin(string $companyId): void
    {
        SystemEvent::where('company_id', $companyId)->delete();

        FeatureFlag::where('scope', 'company')->get()->each(function (FeatureFlag $flag) use ($companyId) {
            $ids = $flag->company_ids ?? [];
            if (in_array($companyId, $ids, true)) {
                $flag->update(['company_ids' => array_values(array_diff($ids, [$companyId]))]);
            }
        });
    }

    /**
     * Runs a post-commit / best-effort step. A failure is recorded as an
     * UNRESOLVED system event (visible in Super Admin -> Debugging) and swallowed:
     * by then the company is already gone and must not be reported as "not deleted".
     * Event context is kept free of personal data.
     */
    protected function safely(string $region, string $what, \Closure $step): void
    {
        try {
            $step();
        } catch (\Throwable $e) {
            try {
                SystemEvent::create([
                    'category' => 'company_purge',
                    'region' => $region,
                    'company_id' => null,
                    'message' => "Company deleted, but cleanup step failed: {$what}.",
                    'context' => ['step' => $what, 'error' => substr($e->getMessage(), 0, 300)],
                ]);
            } catch (\Throwable) {
                // Nothing more can be done; the deletion itself already succeeded.
            }
        }
    }
}
