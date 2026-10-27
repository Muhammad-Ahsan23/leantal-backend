<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Http\Controllers\Api\SuperAdmin\Concerns\ResolvesCompany;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CompanyNote;
use App\Models\Job;
use App\Models\User;
use App\Services\SuperAdminAuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SuperAdminBillingController extends Controller
{
    use ResolvesCompany;

    public function __construct(protected SuperAdminAuditService $audit) {}

    /** Plan, real usage against the effective limits, the Creem subscription and its payment events, and billing notes. */
    public function show(string $companyId)
    {
        [$company, $connection] = $this->find($companyId);
        if (!$company) {
            return response()->json(['message' => 'Company not found.'], 404);
        }

        $subscription = DB::connection($connection)->table('subscriptions')->where('company_id', $companyId)
            ->first(['plan', 'status', 'current_period_start', 'current_period_end', 'cancelled_at']);

        // Creem events for this company (routing_db keeps every webhook; the company id is in the payload metadata).
        $events = DB::connection('routing_db')->table('billing_webhook_events')
            ->whereRaw("payload->'object'->'metadata'->>'company_id' = ?", [$companyId])
            ->orderByDesc('created_at')->limit(50)
            ->selectRaw("event_id, event_type, created_at, processed_at, payload->'object'->'metadata'->>'plan' as plan")
            ->get();

        return response()->json([
            'company' => [
                'id' => $company->id,
                'name' => $company->name,
                'plan' => $company->plan,
                'subscription_status' => $company->subscription_status,
                'trial_end' => $company->trial_end,
                'past_due_since' => $company->past_due_since,
                'has_creem_subscription' => (bool) $company->getRawOriginal('creem_subscription_id'),
            ],
            'usage' => [
                'users' => User::on($connection)->where('company_id', $companyId)->where('status', 'active')->count(),
                'jobs' => Job::on($connection)->where('company_id', $companyId)->whereIn('status', ['draft', 'published', 'paused'])->count(),
            ],
            'limits' => [
                'seats' => $company->seatLimit(),
                'jobs' => $company->jobLimit(),
                'plan_seats' => $company->planSeatLimit(),
                'plan_jobs' => $company->planJobLimit(),
                'seat_override' => $company->seat_limit_override,
                'job_override' => $company->job_limit_override,
            ],
            'subscription' => $subscription,
            'events' => $events,
            'notes' => CompanyNote::with('superAdmin:id,name')->where('company_id', $companyId)->where('category', 'billing')->latest('created_at')->limit(100)->get(),
            'plans' => Company::PLANS,
        ]);
    }

    /**
     * Manual plan change, e.g. a complimentary or sales-agreed plan. A company with a live Creem subscription is
     * refused: its plan follows what the customer pays for, and a manual change would be overwritten by the next webhook.
     */
    public function changePlan(Request $request, string $companyId)
    {
        $data = $request->validate([
            'plan' => ['required', 'in:'.implode(',', Company::PLANS)],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        [$company, $connection] = $this->find($companyId);
        if (!$company) {
            return response()->json(['message' => 'Company not found.'], 404);
        }

        if (in_array($company->subscription_status, ['active', 'past_due'], true) && $company->getRawOriginal('creem_subscription_id')) {
            return response()->json(['message' => 'This company has a live subscription — its plan follows what it pays for in Creem. Change it there (or in the customer\'s billing page).'], 422);
        }

        if ($data['plan'] === $company->plan) {
            return response()->json(['message' => 'The company is already on this plan.'], 422);
        }

        $before = ['plan' => $company->plan, 'subscription_status' => $company->subscription_status];
        $update = ['plan' => $data['plan']];
        // A paid plan given by hand must not lapse with the free trial: it is a complimentary, active plan.
        if ($data['plan'] !== 'free' && in_array($company->subscription_status, ['trialing', 'read_only'], true)) {
            $update['subscription_status'] = 'active';
        }
        $company->update($update);

        $this->audit->log($request->attributes->get('super_admin'), 'company_plan_changed', $request->ip(), 'company', $companyId, [
            'from' => $before, 'to' => $update, 'reason' => $data['reason'],
        ]);

        return response()->json(['message' => 'Plan changed.', 'plan' => $company->plan, 'subscription_status' => $company->subscription_status]);
    }

    /** Company-specific seat/job limits. A key left out is untouched; null = back to the plan's limit; -1 = unlimited. */
    public function setLimits(Request $request, string $companyId)
    {
        $rule = ['nullable', 'integer', 'min:-1', 'max:100000', 'not_in:0'];
        $data = $request->validate([
            'seat_limit' => $rule,
            'job_limit' => $rule,
            'reason' => ['required', 'string', 'max:500'],
        ]);

        [$company, ] = $this->find($companyId);
        if (!$company) {
            return response()->json(['message' => 'Company not found.'], 404);
        }

        $changes = [];
        foreach (['seat_limit' => 'seat_limit_override', 'job_limit' => 'job_limit_override'] as $input => $column) {
            if ($request->exists($input)) {
                $changes[$column] = ['from' => $company->$column, 'to' => $data[$input]];
                $company->$column = $data[$input];
            }
        }

        if (!$changes) {
            return response()->json(['message' => 'Nothing to change.'], 422);
        }

        $company->save();
        $this->audit->log($request->attributes->get('super_admin'), 'company_limits_changed', $request->ip(), 'company', $companyId, [
            'changes' => $changes, 'reason' => $data['reason'],
        ]);

        return response()->json(['message' => 'Limits updated.', 'seats' => $company->seatLimit(), 'jobs' => $company->jobLimit()]);
    }

    public function addNote(Request $request, string $companyId)
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:2000']]);

        [$company, ] = $this->find($companyId);
        if (!$company) {
            return response()->json(['message' => 'Company not found.'], 404);
        }

        $admin = $request->attributes->get('super_admin');
        $note = CompanyNote::create(['company_id' => $companyId, 'category' => 'billing', 'super_admin_id' => $admin->id, 'body' => $data['body']]);
        $this->audit->log($admin, 'company_note_added', $request->ip(), 'company', $companyId, ['note_id' => $note->id]);

        return response()->json(['note' => $note->load('superAdmin:id,name')], 201);
    }

    /** @return array{0: ?Company, 1: ?string} */
    protected function find(string $companyId): array
    {
        $connection = $this->connectionFor($companyId);

        return [$connection ? Company::on($connection)->find($companyId) : null, $connection];
    }
}
