<?php

namespace App\Http\Controllers\Api\SuperAdmin;

use App\Http\Controllers\Api\SuperAdmin\Concerns\ResolvesCompany;
use App\Http\Controllers\Controller;
use App\Models\CompanyNote;
use App\Services\SuperAdminAuditService;
use Illuminate\Http\Request;

/** Internal support notes about a company ("customer called about X"). Same table as billing notes, category 'support'. */
class SuperAdminSupportNoteController extends Controller
{
    use ResolvesCompany;

    protected const CATEGORY = 'support';

    public function __construct(protected SuperAdminAuditService $audit) {}

    public function index(string $companyId)
    {
        if (!$this->connectionFor($companyId)) {
            return response()->json(['message' => 'Company not found.'], 404);
        }

        return response()->json([
            'notes' => CompanyNote::with('superAdmin:id,name')
                ->where('company_id', $companyId)->where('category', self::CATEGORY)
                ->latest('created_at')->limit(100)->get(),
        ]);
    }

    public function store(Request $request, string $companyId)
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:2000']]);

        if (!$this->connectionFor($companyId)) {
            return response()->json(['message' => 'Company not found.'], 404);
        }

        $admin = $request->attributes->get('super_admin');
        $note = CompanyNote::create([
            'company_id' => $companyId, 'category' => self::CATEGORY, 'super_admin_id' => $admin->id, 'body' => trim($data['body']),
        ]);
        $this->audit->log($admin, 'company_note_added', $request->ip(), 'company', $companyId, ['note_id' => $note->id, 'category' => self::CATEGORY]);

        return response()->json(['note' => $note->load('superAdmin:id,name')], 201);
    }
}
