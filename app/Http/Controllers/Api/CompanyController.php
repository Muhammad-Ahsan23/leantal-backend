<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Company\DeleteCompanyRequest;
use App\Http\Requests\Company\TransferOwnershipRequest;
use App\Http\Requests\Company\UpdateCompanyRequest;
use App\Models\Company;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CompanyController extends Controller
{
    /**
     * PRD Section 68 — any authenticated user can VIEW their company's
     * profile (needed for basic UI display) — only editing is Owner-only.
     */
    public function show(Request $request)
    {
        $user = $request->user();
        $connection = $user->getConnectionName();
        $company = Company::on($connection)->find($user->company_id);

        return response()->json(['company' => $company]);
    }

    /**
     * PRD Section 68 — "Company (Owner only): Company name, website,
     * location, careers slug." Explicit role check here rather than
     * routing through CompanyPolicy — this endpoint's scope is narrow
     * and PRD-quoted directly, so it's clearer to assert it inline than
     * trust a policy method whose exact rule can drift unnoticed.
     */
    public function update(UpdateCompanyRequest $request)
    {
        $user = $request->user();
        $connection = $user->getConnectionName();

        if ($user->role !== 'owner') {
            return response()->json(['message' => 'Only the Owner can update company settings.'], 403);
        }

        $company = Company::on($connection)->find($user->company_id);
        $data = $request->validated();

        if (isset($data['slug']) && $data['slug'] !== $company->slug) {
            $slugTaken = Company::on($connection)->where('slug', $data['slug'])->where('id', '!=', $company->id)->exists();
            if ($slugTaken) {
                return response()->json(['message' => 'This careers slug is already taken.', 'errors' => ['slug' => ['Already taken.']]], 422);
            }
        }

        $company->update($data);

        return response()->json(['company' => $company->fresh()]);
    }

    /**
     * PRD Section 69 — Ownership Transfer. "Exactly one Owner exists at
     * all times" — the outgoing Owner is demoted, never left without a
     * role. ASSUMPTION: demoted to 'hiring_manager' (the highest non-
     * owner role) since PRD doesn't state what they become, only that
     * they stop being Owner — flag for client confirmation if a
     * different landing role is intended.
     */
    public function transferOwnership(TransferOwnershipRequest $request)
    {
        $user = $request->user();
        $connection = $user->getConnectionName();

        if ($user->role !== 'owner') {
            return response()->json(['message' => 'Only the current Owner can transfer ownership.'], 403);
        }

        $data = $request->validated();

        $newOwner = User::on($connection)->where('company_id', $user->company_id)->find($data['new_owner_id']);
        if (!$newOwner) {
            return response()->json(['message' => 'That user was not found in your company.'], 422);
        }
        if ($newOwner->id === $user->id) {
            return response()->json(['message' => 'You are already the Owner.'], 422);
        }

        DB::connection($connection)->transaction(function () use ($user, $newOwner, $connection) {
            User::on($connection)->where('id', $user->id)->update(['role' => 'hiring_manager']);
            User::on($connection)->where('id', $newOwner->id)->update(['role' => 'owner']);

            DB::connection($connection)->table('activity')->insert([
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'company_id' => $user->company_id,
                'actor_id' => $user->id,
                'action' => 'company.ownership_transferred',
                'object_type' => 'company',
                'object_id' => $user->company_id,
                'metadata' => json_encode(['from_user_id' => $user->id, 'to_user_id' => $newOwner->id]),
                'created_at' => now(),
            ]);
        });

        return response()->json(['message' => 'Ownership transferred successfully.']);
    }

    /**
     * PRD Section 71 — Company Deletion. Requires typing "DELETE" (PRD's
     * exact confirmation word) + a data-retention preference. This marks
     * the company for deletion (deletion_requested_at, data_retention_
     * choice, deleted_at — all pre-existing columns on this table) —
     * it does NOT synchronously purge every candidate/job/application
     * row here. "Execute deletion safely" (PRD's own wording) reads as
     * a careful, likely background process, not an instant cascade —
     * actually purging data is a follow-up job, not built in this pass.
     */
    public function delete(DeleteCompanyRequest $request)
    {
        $user = $request->user();
        $connection = $user->getConnectionName();

        if ($user->role !== 'owner') {
            return response()->json(['message' => 'Only the Owner can delete the company.'], 403);
        }

        $company = Company::on($connection)->find($user->company_id);
        $data = $request->validated();

        $company->update([
            'deletion_requested_at' => now(),
            'data_retention_choice' => $data['data_retention_choice'],
            'deleted_at' => now(),
        ]);

        DB::connection($connection)->table('activity')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'company_id' => $user->company_id,
            'actor_id' => $user->id,
            'action' => 'company.deletion_requested',
            'object_type' => 'company',
            'object_id' => $user->company_id,
            'metadata' => json_encode(['data_retention_choice' => $data['data_retention_choice']]),
            'created_at' => now(),
        ]);

        return response()->json(['message' => 'Company deletion has been initiated.']);
    }
}
