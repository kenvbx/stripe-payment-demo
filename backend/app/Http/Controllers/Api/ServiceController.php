<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ServiceResource;
use App\Models\AuditLog;
use App\Models\Service;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class ServiceController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return ServiceResource::collection(Service::query()->latest()->paginate(20));
    }

    public function store(Request $request): ServiceResource
    {
        $service = Service::create($request->validate([
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string'],
            'duration_minutes' => ['required', 'integer', 'min:5', 'max:1440'],
            'price' => ['required', 'numeric', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ]));
        AuditLog::record('service.created', $service);

        return new ServiceResource($service);
    }

    public function show(Service $service): ServiceResource
    {
        return new ServiceResource($service);
    }

    public function update(Request $request, Service $service): ServiceResource
    {
        $service->update($request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'description' => ['nullable', 'string'],
            'duration_minutes' => ['sometimes', 'required', 'integer', 'min:5', 'max:1440'],
            'price' => ['sometimes', 'required', 'numeric', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ]));
        AuditLog::record('service.updated', $service, ['changed_fields' => array_keys($service->getChanges())]);

        return new ServiceResource($service->refresh());
    }

    public function destroy(Service $service): Response
    {
        $service->update(['is_active' => false]);
        AuditLog::record('service.deactivated', $service);

        return response()->noContent();
    }
}
