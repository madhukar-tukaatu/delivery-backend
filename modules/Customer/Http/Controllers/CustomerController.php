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
        self::syncFromShipments();

        $query = Customer::query()->with('merchant')->latest();
        if ($request->filled('_scope_merchant_id')) $query->where('merchant_id', $request->get('_scope_merchant_id'));
        if ($request->filled('merchant_id')) $query->where('merchant_id', $request->merchant_id);
        
        $search = $request->get('q') ?: $request->get('search');
        if (!empty($search)) {
            $query->where(fn ($x) => $x->where('name', 'like', "%$search%")
                ->orWhere('phone', 'like', "%$search%")
                ->orWhere('email', 'like', "%$search%"));
        }

        $paginated = $query->paginate((int) $request->get('per_page', 20));

        // Attach shipments count by phone for each customer
        $phones = collect($paginated->items())->pluck('phone')->filter()->unique()->values();
        $counts = [];
        if ($phones->isNotEmpty()) {
            $counts = \Illuminate\Support\Facades\DB::table('shipments')
                ->whereIn('receiver_phone', $phones)
                ->groupBy('receiver_phone')
                ->select('receiver_phone', \Illuminate\Support\Facades\DB::raw('count(*) as total'))
                ->pluck('total', 'receiver_phone')
                ->all();
        }

        foreach ($paginated->items() as $item) {
            $item->shipments_count = $counts[$item->phone] ?? 0;
        }

        return ApiResponse::success($paginated);
    }

    public static function syncFromShipments(): void
    {
        try {
            $existingCount = Customer::count();
            if ($existingCount > 0) {
                return;
            }

            if (!\Illuminate\Support\Facades\Schema::hasTable('shipments')) {
                return;
            }

            $hasDeliveryAddr = \Illuminate\Support\Facades\Schema::hasColumn('shipments', 'delivery_address');
            $addrCol = $hasDeliveryAddr ? 'delivery_address' : 'receiver_address';

            $records = \Illuminate\Support\Facades\DB::table('shipments')
                ->select([
                    'receiver_name as name',
                    'receiver_phone as phone',
                    'receiver_email as email',
                    'receiver_city as city',
                    'receiver_area as area',
                    "{$addrCol} as address",
                    'merchant_id',
                    'created_at',
                ])
                ->whereNotNull('receiver_phone')
                ->where('receiver_phone', '!=', '')
                ->orderBy('id', 'desc')
                ->get();

            if ($records->isEmpty()) {
                return;
            }

            $seen = [];
            $toInsert = [];
            $now = now();
            foreach ($records as $s) {
                $phone = trim((string) $s->phone);
                if (!$phone || isset($seen[$phone])) {
                    continue;
                }
                $seen[$phone] = true;
                $toInsert[] = [
                    'merchant_id' => $s->merchant_id,
                    'name' => trim((string) $s->name) ?: 'Customer',
                    'phone' => $phone,
                    'email' => $s->email ? trim((string) $s->email) : null,
                    'city' => $s->city,
                    'area' => $s->area,
                    'address' => $s->address,
                    'type' => 'individual',
                    'created_at' => $s->created_at ?: $now,
                    'updated_at' => $now,
                ];
                if (count($toInsert) >= 200) {
                    \Illuminate\Support\Facades\DB::table('customers')->insert($toInsert);
                    $toInsert = [];
                }
            }
            if (!empty($toInsert)) {
                \Illuminate\Support\Facades\DB::table('customers')->insert($toInsert);
            }
        } catch (\Throwable $e) {
            report($e);
        }
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
