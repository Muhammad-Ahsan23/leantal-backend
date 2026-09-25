<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Emails\CreateEmailTemplateRequest;
use App\Http\Requests\Emails\UpdateEmailTemplateRequest;
use App\Models\EmailTemplate;
use Illuminate\Http\Request;

class EmailTemplateController extends Controller
{
    /**
     * PRD Section 56 — company-wide templates, not per-user. Any role
     * can create/use them (PRD doesn't restrict this like Job creation).
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $connection = $user->getConnectionName();

        $templates = EmailTemplate::on($connection)
            ->where('company_id', $user->company_id)
            ->orderBy('name')
            ->get();

        return response()->json(['templates' => $templates]);
    }

    public function store(CreateEmailTemplateRequest $request)
    {
        $user = $request->user();
        $connection = $user->getConnectionName();

        $template = EmailTemplate::on($connection)->create([
            ...$request->validated(),
            'company_id' => $user->company_id,
            'user_id' => $user->id,
        ]);

        return response()->json(['template' => $template], 201);
    }

    public function show(Request $request, string $id)
    {
        $user = $request->user();
        $connection = $user->getConnectionName();
        $template = EmailTemplate::on($connection)->where('company_id', $user->company_id)->find($id);

        if (!$template) {
            return response()->json(['message' => 'Template not found.'], 404);
        }

        return response()->json(['template' => $template]);
    }

    public function update(UpdateEmailTemplateRequest $request, string $id)
    {
        $user = $request->user();
        $connection = $user->getConnectionName();
        $template = EmailTemplate::on($connection)->where('company_id', $user->company_id)->find($id);

        if (!$template) {
            return response()->json(['message' => 'Template not found.'], 404);
        }

        $template->update($request->validated());

        return response()->json(['template' => $template->fresh()]);
    }

    public function destroy(Request $request, string $id)
    {
        $user = $request->user();
        $connection = $user->getConnectionName();
        $template = EmailTemplate::on($connection)->where('company_id', $user->company_id)->find($id);

        if (!$template) {
            return response()->json(['message' => 'Template not found.'], 404);
        }

        $template->delete();

        return response()->json(['message' => 'Template deleted.']);
    }
}
