<?php

namespace Modules\Customer\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Modules\Customer\Models\Customer;

class CustomerController extends Controller
{
    public function index(Request $request)
    {
        $query = Customer::query()->with('merchant')->latest();
        if ($request->filled('_scope_merchant_id')) $query->where('merchant_id', $request->get('_scope_merchant_id'));
        if ($request->filled('merchant_id')) $query->where('merchant_id', $request->merchant_id);
        if ($request->filled('q')) {
            $q = $request->q;
            $query->where(fn ($x) => $x->where('name', 'like', "%$q%")->orWhere('phone', 'like', "%$q%")->orWhere('email', 'like', "%$q%"));
        }
        return ApiResponse::success($query->paginate((int) $request->get('per_page', 20)));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'merchant_id' => ['nullable', 'exists:merchants,id'],
            'name' => ['required', 'string'],
            'phone' => ['required', 'string'],
            'email' => ['nullable', 'email'],
            'city' => ['nullable', 'string'],
            'area' => ['nullable', 'string'],
            'address' => ['nullable', 'string'],
            'type' => ['nullable', 'string'],
        ]);
        $data['merchant_id'] = $request->get('_scope_merchant_id', $data['merchant_id'] ?? null);
        return ApiResponse::success(Customer::create($data), 'Customer created.', 201);
    }

    public function show(Customer $customer)
    {
        return ApiResponse::success($customer->load('merchant'));
    }

    public function update(Request $request, Customer $customer)
    {
        $data = $request->validate([
            'merchant_id' => ['nullable', 'exists:merchants,id'],
            'name' => ['sometimes', 'string'],
            'phone' => ['sometimes', 'string'],
            'email' => ['nullable', 'email'],
            'city' => ['nullable', 'string'],
            'area' => ['nullable', 'string'],
            'address' => ['nullable', 'string'],
            'type' => ['nullable', 'string'],
        ]);
        $customer->update($data);
        return ApiResponse::success($customer->fresh('merchant'), 'Customer updated.');
    }

    public function destroy(Customer $customer)
    {
        $customer->delete();
        return ApiResponse::success(null, 'Customer deleted.');
    }
}
