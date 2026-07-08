<?php

namespace Modules\Staff\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class StaffController extends Controller
{
    public function index(Request $request)
    {
        $query = User::with('branch')->whereIn('role', ['super_admin', 'main_admin', 'branch_manager', 'sub_branch_manager', 'booking_staff', 'pickup_staff', 'dispatch_staff', 'rider', 'accounts_staff'])->latest();
        if ($request->filled('role')) $query->where('role', $request->role);
        return ApiResponse::success($query->paginate((int) $request->get('per_page', 20)));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'branch_id' => ['nullable', 'exists:branches,id'],
            'name' => ['required', 'string'],
            'email' => ['required', 'email', 'unique:users,email'],
            'phone' => ['nullable', 'string'],
            'role' => ['required', 'string'],
            'password' => ['required', 'string', 'min:6'],
        ]);
        $data['password'] = Hash::make($data['password']);
        $user = User::create($data);
        return ApiResponse::success($user, 'Staff created.', 201);
    }
}
