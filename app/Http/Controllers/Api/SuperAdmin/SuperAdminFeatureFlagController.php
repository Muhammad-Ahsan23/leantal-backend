<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SuperAdmin\SaveFeatureFlagRequest;
use App\Models\FeatureFlag;
use App\Services\SuperAdminAuditService;
use Illuminate\Http\Request;

class SuperAdminFeatureFlagController extends Controller
{
    public function __construct(protected SuperAdminAuditService $audit) {}

    public function index()
    {
        return response()->json(['feature_flags' => FeatureFlag::orderBy('key')->get()]);
    }

    /**
     * PRD Section 159 — "feature flag changes" must be audit-logged.
     * Create and update share this one upsert-by-'key' method.
     */
    public function save(SaveFeatureFlagRequest $request)
    {
        $data = $request->validated();

        $flag = FeatureFlag::updateOrCreate(
            ['key' => $data['key']],
            [
                'description' => $data['description'] ?? null,
                'enabled' => $data['enabled'],
                'scope' => $data['scope'],
                'company_ids' => $data['scope'] === 'company' ? ($data['company_ids'] ?? []) : null,
            ]
        );

        $admin = $request->attributes->get('super_admin');
        $this->audit->log($admin, 'feature_flag_changed', $request->ip(), 'feature_flag', $flag->id, [
            'key' => $flag->key,
            'enabled' => $flag->enabled,
            'scope' => $flag->scope,
        ]);

        return response()->json(['feature_flag' => $flag->fresh()]);
    }

    public function destroy(Request $request, string $id)
    {
        $flag = FeatureFlag::find($id);
        if (!$flag) {
            return response()->json(['message' => 'Feature flag not found.'], 404);
        }

        $admin = $request->attributes->get('super_admin');
        $this->audit->log($admin, 'feature_flag_changed', $request->ip(), 'feature_flag', $id, ['action' => 'deleted', 'key' => $flag->key]);

        $flag->delete();

        return response()->json(['message' => 'Feature flag deleted.']);
    }
}
