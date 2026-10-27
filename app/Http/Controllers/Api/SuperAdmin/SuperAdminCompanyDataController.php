<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Http\Controllers\Api\SuperAdmin\Concerns\ResolvesCompany;
use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\CompanyPurgeService;
use App\Services\SuperAdminAuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** GDPR tools: a full data export (access / portability requests) and permanent deletion (erasure requests). */
class SuperAdminCompanyDataController extends Controller
{
    use ResolvesCompany;

    /** Tables that only hold sessions, login secrets, integration tokens or technical logs — not the customer's business data. */
    protected const NOT_EXPORTED = [
        'sessions', 'oauth_tokens', 'login_otps', 'password_reset_tokens', 'gmail_watch_state',
        'microsoft_subscription_state', 'webhook_logs', 'csv_import_jobs',
    ];

    /** Credentials and billing ids are never written to the export. */
    protected const SECRET_COLUMNS = ['password_hash', 'mfa_secret', 'token', 'creem_customer_id', 'creem_subscription_id'];

    public function __construct(protected SuperAdminAuditService $audit) {}

    /**
     * One JSON file with every business table of the company, written row by row (constant memory, any size).
     * Resume files stay in storage: the export lists their paths (resume_path columns) but does not contain the files.
     */
    public function export(Request $request, string $companyId): StreamedResponse|\Illuminate\Http\JsonResponse
    {
        $connection = $this->connectionFor($companyId);
        $company = $connection ? Company::on($connection)->find($companyId) : null;
        if (!$company) {
            return response()->json(['message' => 'Company not found.'], 404);
        }

        $this->audit->log($request->attributes->get('super_admin'), 'company_exported', $request->ip(), 'company', $companyId, ['name' => $company->name]);

        $db = DB::connection($connection);
        $schema = Schema::connection($connection);
        $tables = array_filter(CompanyPurgeService::DELETE_ORDER, fn ($s) => !in_array($s[0], self::NOT_EXPORTED, true) && $schema->hasTable($s[0]));
        $secret = array_flip(self::SECRET_COLUMNS);

        return response()->streamDownload(function () use ($db, $tables, $secret, $companyId) {
            echo '{"exported_at":'.json_encode(now()->utc()->toIso8601String()).',"company_id":'.json_encode($companyId)
                .',"note":"Resume and attachment files are not included; their storage paths are listed in the rows.","tables":{';

            $firstTable = true;
            foreach ($tables as [$table, $column, $parent]) {
                $query = $db->table($table);
                $parent === null
                    ? $query->where($column, $companyId)
                    : $query->whereIn($column, $db->table($parent)->select('id')->where('company_id', $companyId));

                echo ($firstTable ? '' : ',').json_encode($table).':[';
                $firstTable = false;
                $firstRow = true;
                foreach ($query->cursor() as $row) {
                    echo ($firstRow ? '' : ',').json_encode(array_diff_key((array) $row, $secret), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
                    $firstRow = false;
                }
                echo ']';
            }
            echo '}}';
        }, 'company-export-'.now()->utc()->format('Ymd-His').'.json', ['Content-Type' => 'application/json']);
    }

    /**
     * Permanent deletion, immediately and with no way back — the client's rule (PRD Sections 71/72), the same
     * CompanyPurgeService the customer's own "Delete company" uses. The admin must give a reason and type the
     * company's exact name. A company marked active but without a Creem subscription is refused until the admin ticks
     * "billing_checked" (confirming nothing needs cancelling — e.g. a complimentary plan). The audit entry is written only once the deletion has really happened.
     */
    public function destroy(Request $request, string $companyId, CompanyPurgeService $purge)
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
            'confirm_name' => ['required', 'string'],
            'billing_checked' => ['nullable', 'boolean'],
        ]);

        $connection = $this->connectionFor($companyId);
        $company = $connection ? Company::on($connection)->find($companyId) : null;
        if (!$company) {
            return response()->json(['message' => 'Company not found.'], 404);
        }

        if (trim($data['confirm_name']) !== $company->name) {
            return response()->json(['message' => 'The name you typed does not match the company name.'], 422);
        }

        $meta = ['name' => $company->name, 'region' => $company->region, 'plan' => $company->plan, 'reason' => $data['reason'], 'billing_checked_by_admin' => (bool) ($data['billing_checked'] ?? false)];

        try {
            $purge->purge($company, $connection, (bool) ($data['billing_checked'] ?? false));
        } catch (\RuntimeException $e) {
            // code lets the screen offer "I checked: nothing to cancel" for an active company without a Creem subscription id
            return response()->json(['message' => $e->getMessage(), 'code' => 'billing_check_failed'], 422);
        } catch (\Throwable $e) {
            Log::error('Super Admin company deletion failed', ['company_id' => $companyId, 'message' => $e->getMessage()]);

            return response()->json(['message' => 'Deleting the company failed and nothing was deleted. Please try again.'], 500);
        }

        $this->audit->log($request->attributes->get('super_admin'), 'company_deleted', $request->ip(), 'company', $companyId, $meta);

        return response()->json(['message' => 'The company and all of its data have been permanently deleted.']);
    }
}
