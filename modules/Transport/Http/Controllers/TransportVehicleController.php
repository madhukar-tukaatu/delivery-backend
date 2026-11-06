<?php

namespace Modules\Transport\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Transport\Models\TransportDriver;
use Modules\Transport\Models\TransportVehicle;
use Modules\Transport\Services\FleetAllocationService;

class TransportVehicleController extends Controller
{
    public function __construct(
        private readonly FleetAllocationService $fleetService
    ) {}

    /**
     * List registered vehicles with filters.
     */
    public function index(Request $request): JsonResponse
    {
        $query = TransportVehicle::query()->with(['currentBranch', 'drivers']);

        if ($request->filled('type')) {
            $query->where('vehicle_type', $request->string('type')->toString());
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        if ($request->filled('branch_id')) {
            $branchId = (int) $request->input('branch_id');
            $query->where(function ($q) use ($branchId) {
                $q->where('current_branch_id', $branchId)->orWhereNull('current_branch_id');
            });
        }

        if ($request->filled('search')) {
            $search = trim($request->string('search')->toString());
            $query->where(function ($q) use ($search) {
                $q->where('vehicle_number', 'like', "%{$search}%")
                    ->orWhere('owner_name', 'like', "%{$search}%")
                    ->orWhere('driver_name', 'like', "%{$search}%");
            });
        }

        $vehicles = $query->orderBy('status')->latest('id')->paginate($request->integer('per_page', 20));

        return ApiResponse::success($vehicles);
    }

    /**
     * Store new vehicle.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'vehicle_number' => 'required|string|max:60|unique:transport_vehicles,vehicle_number',
            'vehicle_type' => 'required|string|in:' . implode(',', TransportVehicle::TYPES),
            'ownership_type' => 'nullable|string|in:owned,contractor,on_demand',
            'owner_name' => 'nullable|string|max:120',
            'owner_phone' => 'nullable|string|max:40',
            'owner_pan_vat' => 'nullable|string|max:40',
            'driver_name' => 'nullable|string|max:120',
            'driver_phone' => 'nullable|string|max:40',
            'driver_license_number' => 'nullable|string|max:60',
            'capacity_kg' => 'nullable|numeric|min:0',
            'capacity_cbm' => 'nullable|numeric|min:0',
            'max_parcels' => 'nullable|integer|min:1',
            'current_branch_id' => 'nullable|integer|exists:branches,id',
            'fixed_rate_per_trip' => 'nullable|numeric|min:0',
            'rate_per_km' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
        ]);

        $vehicle = TransportVehicle::create($validated);

        return ApiResponse::success($vehicle, 'Vehicle registered successfully.', 201);
    }

    /**
     * Show vehicle details.
     */
    public function show(int $id): JsonResponse
    {
        $vehicle = TransportVehicle::query()->with(['currentBranch', 'drivers', 'trips' => fn ($q) => $q->latest()->limit(10)])->findOrFail($id);

        return ApiResponse::success($vehicle);
    }

    /**
     * Update vehicle details.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $vehicle = TransportVehicle::query()->findOrFail($id);

        $validated = $request->validate([
            'vehicle_number' => "sometimes|required|string|max:60|unique:transport_vehicles,vehicle_number,{$id}",
            'vehicle_type' => 'sometimes|required|string|in:' . implode(',', TransportVehicle::TYPES),
            'ownership_type' => 'nullable|string|in:owned,contractor,on_demand',
            'owner_name' => 'nullable|string|max:120',
            'owner_phone' => 'nullable|string|max:40',
            'owner_pan_vat' => 'nullable|string|max:40',
            'driver_name' => 'nullable|string|max:120',
            'driver_phone' => 'nullable|string|max:40',
            'driver_license_number' => 'nullable|string|max:60',
            'capacity_kg' => 'nullable|numeric|min:0',
            'capacity_cbm' => 'nullable|numeric|min:0',
            'max_parcels' => 'nullable|integer|min:1',
            'current_branch_id' => 'nullable|integer|exists:branches,id',
            'status' => 'sometimes|string|in:available,on_trip,maintenance,inactive',
            'is_active' => 'sometimes|boolean',
            'fixed_rate_per_trip' => 'nullable|numeric|min:0',
            'rate_per_km' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
        ]);

        $vehicle->update($validated);

        return ApiResponse::success($vehicle, 'Vehicle updated successfully.');
    }

    /**
     * Fleet recommendation API for a given load.
     */
    public function recommend(Request $request): JsonResponse
    {
        $request->validate([
            'parcel_count' => 'required|integer|min:1',
            'weight_kg' => 'nullable|numeric|min:0',
            'from_branch_id' => 'nullable|integer',
        ]);

        $recommendation = $this->fleetService->recommendVehicleType(
            (int) $request->input('parcel_count'),
            (float) $request->input('weight_kg', 0)
        );

        $fromBranchId = (int) $request->input('from_branch_id', 0);
        $available = $fromBranchId > 0
            ? $this->fleetService->findAvailableVehicles($fromBranchId, $recommendation['vehicle_type'])
            : [];

        return ApiResponse::success([
            'recommendation' => $recommendation,
            'available_vehicles' => $available,
        ]);
    }

    /**
     * List drivers.
     */
    public function drivers(Request $request): JsonResponse
    {
        $query = TransportDriver::query()->with(['vehicle', 'baseBranch']);

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        if ($request->filled('search')) {
            $search = trim($request->string('search')->toString());
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('license_number', 'like', "%{$search}%");
            });
        }

        $drivers = $query->orderBy('name')->paginate($request->integer('per_page', 20));

        return ApiResponse::success($drivers);
    }

    /**
     * Store new driver.
     */
    public function storeDriver(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:120',
            'phone' => 'required|string|max:40|unique:transport_drivers,phone',
            'license_number' => 'nullable|string|max:60',
            'citizenship_number' => 'nullable|string|max:60',
            'assigned_vehicle_id' => 'nullable|integer|exists:transport_vehicles,id',
            'base_branch_id' => 'nullable|integer|exists:branches,id',
        ]);

        $driver = TransportDriver::create($validated);

        return ApiResponse::success($driver, 'Driver registered successfully.', 201);
    }
}

