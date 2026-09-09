<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Candidate;
use App\Models\Company;
use App\Models\Job;
use App\Models\Task;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Diagnostic endpoint — lets us verify every Policy rule works correctly
 * BEFORE the actual Jobs/Candidates/Tasks CRUD controllers exist. Not a
 * permanent feature; safe to delete once real endpoints make it redundant
 * (or keep it — a "what can I do" endpoint is genuinely useful for a
 * frontend to build role-aware UI, matching PRD Section 143).
 */
class PermissionsController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $company = Company::on($user->getConnectionName())->find($user->company_id);

        return response()->json([
            'role' => $user->role,
            'abilities' => [
                'jobs' => [
                    'create' => $user->can('create', Job::class),
                ],
                'candidates' => [
                    'create' => $user->can('create', Candidate::class),
                ],
                'tasks' => [
                    'create' => $user->can('create', Task::class),
                ],
                'users' => [
                    'invite' => $user->can('invite', User::class),
                ],
                'company' => [
                    'view' => $user->can('view', $company),
                    'update' => $user->can('update', $company),
                    'manage_billing' => $user->can('manageBilling', $company),
                    'manage_custom_fields' => $user->can('manageCustomFields', $company),
                    'delete' => $user->can('delete', $company),
                    'transfer_ownership' => $user->can('transferOwnership', $company),
                    'view_full_activity_log' => $user->can('viewFullActivityLog', $company),
                ],
            ],
        ]);
    }
}
