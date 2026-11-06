<?php

namespace Modules\Transport\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Transport\Models\TransportTrip;
use Modules\Transport\Services\TransportTripService;

class TransportTripController extends Controller
{
    public function __construct(
        private readonly TransportTripService $tripService
    ) {}

    /**
     * List transport trips.
     */
    public function index(Request $request): JsonResponse
    {
        $query = TransportTrip::query()->with([
            'fromBranch',
            'toBranch',
            'vehicle',
            'driver',
            'tripManifests.manifest',
        ]);

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        if ($request->filled('from_branch_id')) {
            $query->where('from_branch_id', (int) $request->input('from_branch_id'));
        }

        if ($request->filled('to_branch_id')) {
            $query->where('to_branch_id', (int) $request->input('to_branch_id'));
        }

        if ($request->filled('search')) {
            $search = trim($request->string('search')->toString());
            $query->where(function ($q) use ($search) {
                $q->where('trip_number', 'like', "%{$search}%")
                    ->orWhere('vehicle_number', 'like', "%{$search}%")
                    ->orWhere('driver_name', 'like', "%{$search}%")
                    ->orWhere('departure_seal_number', 'like', "%{$search}%");
            });
        }

        $trips = $query->latest('id')->paginate($request->integer('per_page', 20));

        return ApiResponse::success($trips);
    }

    /**
     * Create scheduled line-haul trip.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from_branch_id' => 'required|integer|exists:branches,id',
            'to_branch_id' => 'required|integer|exists:branches,id|different:from_branch_id',
            'route_id' => 'nullable|integer',
            'vehicle_id' => 'nullable|integer|exists:transport_vehicles,id',
            'driver_id' => 'nullable|integer|exists:transport_drivers,id',
            'vehicle_number' => 'nullable|string|max:60',
            'vehicle_type' => 'nullable|string|max:40',
            'driver_name' => 'nullable|string|max:120',
            'driver_phone' => 'nullable|string|max:40',
            'scheduled_departure_at' => 'nullable|date',
            'freight_cost' => 'nullable|numeric|min:0',
            'cost_mode' => 'nullable|string|in:per_trip,per_km,per_kg,free_internal',
            'manifest_ids' => 'nullable|array',
            'manifest_ids.*' => 'integer|exists:dispatch_manifests,id',
            'notes' => 'nullable|string',
        ]);

        $trip = $this->tripService->createTrip($validated, $request->user());

        return ApiResponse::success($trip, 'Transport trip scheduled successfully.', 201);
    }

    /**
     * Show trip details.
     */
    public function show(int $id): JsonResponse
    {
        $trip = TransportTrip::query()->with([
            'fromBranch',
            'toBranch',
            'route',
            'vehicle',
            'driver',
            'tripManifests.manifest.items.shipment',
        ])->findOrFail($id);

        return ApiResponse::success($trip);
    }

    /**
     * Dispatch trip with seal.
     */
    public function dispatch(Request $request, int $id): JsonResponse
    {
        $trip = TransportTrip::query()->findOrFail($id);

        $validated = $request->validate([
            'departure_seal_number' => 'nullable|string|max:60',
        ]);

        $trip = $this->tripService->dispatchTrip($trip, $validated['departure_seal_number'] ?? null);

        return ApiResponse::success($trip, 'Trip dispatched successfully.');
    }

    /**
     * Complete trip upon arrival.
     */
    public function complete(Request $request, int $id): JsonResponse
    {
        $trip = TransportTrip::query()->findOrFail($id);

        $validated = $request->validate([
            'arrival_seal_status' => 'required|string|in:ok,mismatch,tampered',
            'notes' => 'nullable|string',
        ]);

        $trip = $this->tripService->completeTrip($trip, $validated);

        return ApiResponse::success($trip, 'Trip arrived and completed successfully.');
    }

    /**
     * Transport summary pulse for Control Tower.
     */
    public function summary(): JsonResponse
    {
        $activeTrips = TransportTrip::query()->whereIn('status', [
            TransportTrip::STATUS_SCHEDULED,
            TransportTrip::STATUS_LOADING,
            TransportTrip::STATUS_IN_TRANSIT,
        ])->count();

        $tripsOnRoad = TransportTrip::query()->where('status', TransportTrip::STATUS_IN_TRANSIT)->count();

        $movingParcels = TransportTrip::query()->where('status', TransportTrip::STATUS_IN_TRANSIT)->sum('total_parcels_count');
        $movingTonnageKg = TransportTrip::query()->where('status', TransportTrip::STATUS_IN_TRANSIT)->sum('total_weight_kg');

        return ApiResponse::success([
            'active_trips' => $activeTrips,
            'trips_on_road' => $tripsOnRoad,
            'moving_parcels' => (int) $movingParcels,
            'moving_weight_kg' => round((float) $movingTonnageKg, 2),
        ]);
    }
}

