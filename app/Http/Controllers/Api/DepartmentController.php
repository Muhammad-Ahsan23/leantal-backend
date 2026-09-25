<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Departments\CreateDepartmentRequest;
use App\Http\Requests\Departments\UpdateDepartmentRequest;
use App\Models\Department;
use Illuminate\Http\Request;

class DepartmentController extends Controller
{
    /**
     * PRD Section 68 — "Careers Page (Owner only): ... departments."
     * Viewing the list is open to everyone (needed to populate the
     * Job-creation dropdown); only Owner can add/rename/remove entries.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $connection = $user->getConnectionName();

        $departments = Department::on($connection)
            ->where('company_id', $user->company_id)
            ->orderBy('name')
            ->get();

        return response()->json(['departments' => $departments]);
    }

    public function store(CreateDepartmentRequest $request)
    {
        $user = $request->user();
        $connection = $user->getConnectionName();

        if ($user->role !== 'owner') {
            return response()->json(['message' => 'Only the Owner can manage departments.'], 403);
        }

        $exists = Department::on($connection)->where('company_id', $user->company_id)->where('name', $request->validated()['name'])->exists();
        if ($exists) {
            return response()->json(['message' => 'A department with this name already exists.'], 422);
        }

        $department = Department::on($connection)->create([
            ...$request->validated(),
            'company_id' => $user->company_id,
        ]);

        return response()->json(['department' => $department], 201);
    }

    public function update(UpdateDepartmentRequest $request, string $id)
    {
        $user = $request->user();
        $connection = $user->getConnectionName();

        if ($user->role !== 'owner') {
            return response()->json(['message' => 'Only the Owner can manage departments.'], 403);
        }

        $department = Department::on($connection)->where('company_id', $user->company_id)->find($id);
        if (!$department) {
            return response()->json(['message' => 'Department not found.'], 404);
        }

        $department->update($request->validated());

        return response()->json(['department' => $department->fresh()]);
    }

    public function destroy(Request $request, string $id)
    {
        $user = $request->user();
        $connection = $user->getConnectionName();

        if ($user->role !== 'owner') {
            return response()->json(['message' => 'Only the Owner can manage departments.'], 403);
        }

        $department = Department::on($connection)->where('company_id', $user->company_id)->find($id);
        if (!$department) {
            return response()->json(['message' => 'Department not found.'], 404);
        }

        // Deliberately NOT blocked by existing Jobs using this name —
        // Jobs.department is a plain string (unchanged), so removing a
        // Department list entry never breaks historical job data, it
        // just stops appearing as a dropdown option for NEW jobs.
        $department->delete();

        return response()->json(['message' => 'Department deleted.']);
    }
}
