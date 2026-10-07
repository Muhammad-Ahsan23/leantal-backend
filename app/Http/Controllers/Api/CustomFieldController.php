<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CustomFields\CreateCustomFieldRequest;
use App\Http\Requests\CustomFields\SetCustomFieldValuesRequest;
use App\Http\Requests\CustomFields\UpdateCustomFieldRequest;
use App\Models\Candidate;
use App\Models\CustomField;
use App\Models\Job;
use App\Services\CustomFieldService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CustomFieldController extends Controller
{
    public function __construct(protected CustomFieldService $customFields) {}

    /**
     * PRD Section 68 — Company Settings > Custom Fields tab.
     * "Custom Fields (Owner only)". Explicit role
     * check (not a policy method) for the same clarity/certainty reason
     * as CompanyController::update(). Viewing field DEFINITIONS stays
     * open to everyone (needed just to render extra fields on a
     * Job/Candidate form).
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $connection = $user->getConnectionName();

        $query = CustomField::on($connection)->where('company_id', $user->company_id);

        if ($entityType = $request->query('entity_type')) {
            $query->where('entity_type', $entityType);
        }

        return response()->json(['fields' => $query->get()]);
    }

    public function store(CreateCustomFieldRequest $request)
    {
        $user = $request->user();
        $connection = $user->getConnectionName();

        if ($user->role !== 'owner') {
            return response()->json(['message' => 'Only the Owner can manage custom fields.'], 403);
        }

        $field = CustomField::on($connection)->create([
            ...$request->validated(),
            'company_id' => $user->company_id,
        ]);

        return response()->json(['field' => $field], 201);
    }

    public function update(UpdateCustomFieldRequest $request, string $id)
    {
        $user = $request->user();
        $connection = $user->getConnectionName();

        if ($user->role !== 'owner') {
            return response()->json(['message' => 'Only the Owner can manage custom fields.'], 403);
        }

        $field = CustomField::on($connection)->where('company_id', $user->company_id)->find($id);
        if (!$field) {
            return response()->json(['message' => 'Custom field not found.'], 404);
        }

        $field->update($request->validated());

        return response()->json(['field' => $field->fresh()]);
    }

    public function destroy(Request $request, string $id)
    {
        $user = $request->user();
        $connection = $user->getConnectionName();

        if ($user->role !== 'owner') {
            return response()->json(['message' => 'Only the Owner can manage custom fields.'], 403);
        }

        $field = CustomField::on($connection)->where('company_id', $user->company_id)->find($id);
        if (!$field) {
            return response()->json(['message' => 'Custom field not found.'], 404);
        }

        // custom_field_values cascadeOnDelete at the DB level — deleting
        // the field definition also removes every recorded value for it.
        $field->delete();

        return response()->json(['message' => 'Custom field deleted.']);
    }

    /**
     * Values are set by whoever can already edit the underlying Job or
     * Candidate — no separate custom-field-value permission, it rides
     * on JobPolicy/CandidatePolicy's existing 'update' rule.
     */
    public function setValues(SetCustomFieldValuesRequest $request)
    {
        $user = $request->user();
        $connection = $user->getConnectionName();
        $data = $request->validated();

        $entity = $data['entity_type'] === 'job'
            ? \App\Models\Job::on($connection)->where('company_id', $user->company_id)->find($data['entity_id'])
            : \App\Models\Candidate::on($connection)->where('company_id', $user->company_id)->find($data['entity_id']);

        if (!$entity) {
            return response()->json(['message' => ucfirst($data['entity_type']).' not found.'], 404);
        }

        if (!$user->can('update', $entity)) {
            return response()->json(['message' => 'You do not have permission to edit this.'], 403);
        }

        $errors = $this->customFields->validateValues($data['entity_type'], $data['values'], $user->company_id, $connection);
        if (!empty($errors)) {
            return response()->json(['message' => 'Validation failed.', 'errors' => $errors], 422);
        }

        $this->customFields->setValues($data['entity_id'], $data['values'], $connection);

        return response()->json(['values' => $this->customFields->getValues($data['entity_type'], $data['entity_id'], $user->company_id, $connection)]);
    }

    /**
     * SECURITY FIX — this method previously passed the URL's entityType/
     * entityId straight to the service with NO company scoping and NO
     * permission check, unlike setValues() above. Two real holes:
     * (1) anyone holding another company's candidate/job UUID could read
     *     that entity's custom values (cross-tenant leak, PRD Section 104);
     * (2) a Recruiter could read custom values of candidates/jobs NOT
     *     assigned to them, bypassing the "only what is assigned to you"
     *     rule (PRD Section 7).
     * Reading a value is gated by the same rule as viewing the underlying
     * Job/Candidate itself — 'view', not 'update' (this endpoint only reads).
     *
     * Unlike setValues(), whose FormRequest validates entity_type/entity_id,
     * here both come from the URL unvalidated — so they are checked
     * explicitly. A malformed UUID is answered with 404 rather than being
     * passed to Postgres, which would raise a 500 on an invalid uuid.
     */
    public function getValues(Request $request, string $entityType, string $entityId)
    {
        $user = $request->user();
        $connection = $user->getConnectionName();

        if (!in_array($entityType, ['job', 'candidate'], true) || !Str::isUuid($entityId)) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        $model = $entityType === 'job' ? Job::class : Candidate::class;

        // Scoped to the caller's own company: another tenant's entity is
        // simply "not found" — its existence is never confirmed.
        $entity = $model::on($connection)->where('company_id', $user->company_id)->find($entityId);

        if (!$entity) {
            return response()->json(['message' => ucfirst($entityType).' not found.'], 404);
        }

        if (!$user->can('view', $entity)) {
            return response()->json(['message' => 'You do not have permission to view this.'], 403);
        }

        return response()->json(['values' => $this->customFields->getValues($entityType, $entityId, $user->company_id, $connection)]);
    }
}
