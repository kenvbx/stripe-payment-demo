<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\StaffResource;
use App\Models\AuditLog;
use App\Models\StaffProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class StaffController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return StaffResource::collection(StaffProfile::query()->where('is_active', true)->orderBy('display_name')->get());
    }

    public function store(Request $request): StaffResource
    {
        $staff = StaffProfile::create($request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id', 'unique:staff_profiles,user_id'],
            'display_name' => ['required', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:32'],
            'color' => ['nullable', 'string', 'max:32'],
            'is_active' => ['sometimes', 'boolean'],
        ]));
        AuditLog::record('staff.created', $staff);

        return new StaffResource($staff);
    }

    public function show(StaffProfile $staff): StaffResource
    {
        return new StaffResource($staff);
    }

    public function update(Request $request, StaffProfile $staff): StaffResource
    {
        $staff->update($request->validate([
            'user_id' => ['sometimes', 'required', 'integer', 'exists:users,id', 'unique:staff_profiles,user_id,'.$staff->id],
            'display_name' => ['sometimes', 'required', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:32'],
            'color' => ['nullable', 'string', 'max:32'],
            'is_active' => ['sometimes', 'boolean'],
        ]));
        AuditLog::record('staff.updated', $staff, ['changed_fields' => array_keys($staff->getChanges())]);

        return new StaffResource($staff->refresh());
    }

    public function destroy(StaffProfile $staff): Response
    {
        $staff->update(['is_active' => false]);
        AuditLog::record('staff.deactivated', $staff);

        return response()->noContent();
    }
}
