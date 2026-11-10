<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CustomerResource;
use App\Models\AuditLog;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class CustomerController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $customers = Customer::query()
            ->when($request->string('search')->isNotEmpty(), fn ($query) => $query->where('name', 'like', '%'.$request->string('search')->toString().'%'))
            ->latest()
            ->paginate(20);

        return CustomerResource::collection($customers);
    }

    public function store(Request $request): CustomerResource
    {
        $customer = Customer::create($request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:255', 'unique:customers,email'],
            'phone' => ['nullable', 'string', 'max:32'],
            'notes' => ['nullable', 'string'],
        ]));
        AuditLog::record('customer.created', $customer);

        return new CustomerResource($customer);
    }

    public function show(Customer $customer): CustomerResource
    {
        return new CustomerResource($customer);
    }

    public function update(Request $request, Customer $customer): CustomerResource
    {
        $customer->update($request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:255', 'unique:customers,email,'.$customer->id],
            'phone' => ['nullable', 'string', 'max:32'],
            'notes' => ['nullable', 'string'],
        ]));
        AuditLog::record('customer.updated', $customer, ['changed_fields' => array_keys($customer->getChanges())]);

        return new CustomerResource($customer->refresh());
    }

    public function destroy(Customer $customer): Response
    {
        $customer->delete();
        AuditLog::record('customer.deleted', $customer);

        return response()->noContent();
    }
}
