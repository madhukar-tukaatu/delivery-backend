<?php

namespace Modules\Transport\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Dispatch\Models\DispatchManifest;
use Modules\Transport\Models\TransportDriver;
use Modules\Transport\Models\TransportTrip;
use Modules\Transport\Models\TransportTripManifest;
use Modules\Transport\Models\TransportVehicle;

class TransportTripService
{
    /**
     * Create a scheduled line-haul highway trip.
     */
    public function createTrip(array $data, ?User $creator = null): TransportTrip
    {
        return DB::transaction(function () use ($data, $creator) {
            $tripNumber = $this->generateTripNumber();

            $vehicle = null;
            if (!empty($data['vehicle_id'])) {
                $vehicle = TransportVehicle::query()->find($data['vehicle_id']);
            }

            $driver = null;
            if (!empty($data['driver_id'])) {
                $driver = TransportDriver::query()->find($data['driver_id']);
            }

            $trip = TransportTrip::create([
                'trip_number' => $tripNumber,
                'from_branch_id' => (int) $data['from_branch_id'],
                'to_branch_id' => (int) $data['to_branch_id'],
                'route_id' => !empty($data['route_id']) ? (int) $data['route_id'] : null,
                'vehicle_id' => $vehicle?->id,
                'driver_id' => $driver?->id,
                'vehicle_number' => $vehicle?->vehicle_number ?? ($data['vehicle_number'] ?? null),
                'vehicle_type' => $vehicle?->vehicle_type ?? ($data['vehicle_type'] ?? TransportVehicle::TYPE_PICKUP),
                'driver_name' => $driver?->name ?? ($data['driver_name'] ?? null),
                'driver_phone' => $driver?->phone ?? ($data['driver_phone'] ?? null),
                'status' => TransportTrip::STATUS_SCHEDULED,
                'scheduled_departure_at' => !empty($data['scheduled_departure_at']) ? $data['scheduled_departure_at'] : now(),
                'freight_cost' => (float) ($data['freight_cost'] ?? 0),
                'cost_mode' => $data['cost_mode'] ?? 'per_trip',
                'notes' => $data['notes'] ?? null,
                'created_by' => $creator?->id,
            ]);

            if (!empty($data['manifest_ids']) && is_array($data['manifest_ids'])) {
                $this->attachManifests($trip, $data['manifest_ids']);
            }

            return $trip->fresh(['vehicle', 'driver', 'fromBranch', 'toBranch', 'tripManifests.manifest']);
        });
    }

    /**
     * Attach TR dispatch manifests (master bags) into this trip truck.
     */
    public function attachManifests(TransportTrip $trip, array $manifestIds): void
    {
        $manifests = DispatchManifest::query()->whereIn('id', $manifestIds)->with('items')->get();

        foreach ($manifests as $manifest) {
            TransportTripManifest::updateOrCreate(
                [
                    'transport_trip_id' => $trip->id,
                    'dispatch_manifest_id' => $manifest->id,
                ],
                [
                    'status' => 'loaded',
                ]
            );
        }

        $this->refreshTripTotals($trip);
    }

    /**
     * Depart & dispatch trip across highway.
     */
    public function dispatchTrip(TransportTrip $trip, ?string $sealNumber = null): TransportTrip
    {
        return DB::transaction(function () use ($trip, $sealNumber) {
            if ($trip->status === TransportTrip::STATUS_IN_TRANSIT) {
                return $trip;
            }

            $seal = $sealNumber ?: $trip->departure_seal_number ?: ('SEAL-TRIP-' . strtoupper(substr(md5((string) microtime()), 0, 8)));

            $trip->update([
                'status' => TransportTrip::STATUS_IN_TRANSIT,
                'departure_seal_number' => $seal,
                'departed_at' => now(),
            ]);

            // Update Vehicle & Driver statuses
            if ($trip->vehicle_id) {
                TransportVehicle::query()->where('id', $trip->vehicle_id)->update([
                    'status' => TransportVehicle::STATUS_ON_TRIP,
                ]);
            }
            if ($trip->driver_id) {
                TransportDriver::query()->where('id', $trip->driver_id)->update([
                    'status' => TransportDriver::STATUS_ON_TRIP,
                ]);
            }

            // Sync all attached TRs
            $manifestIds = TransportTripManifest::query()->where('transport_trip_id', $trip->id)->pluck('dispatch_manifest_id');
            DispatchManifest::query()->whereIn('id', $manifestIds)->where('status', 'open')->update([
                'status' => 'dispatched',
                'dispatched_at' => now(),
                'seal_number' => $seal,
            ]);

            return $trip->fresh(['vehicle', 'driver', 'tripManifests.manifest']);
        });
    }

    /**
     * Complete trip upon arrival at receiving hub dock.
     */
    public function completeTrip(TransportTrip $trip, array $arrivalData): TransportTrip
    {
        return DB::transaction(function () use ($trip, $arrivalData) {
            $sealStatus = $arrivalData['arrival_seal_status'] ?? 'ok';

            $trip->update([
                'status' => TransportTrip::STATUS_COMPLETED,
                'arrived_at' => now(),
                'completed_at' => now(),
                'arrival_seal_status' => $sealStatus,
                'notes' => trim(($trip->notes ?? '') . "\n" . ($arrivalData['notes'] ?? '')),
            ]);

            // Release vehicle at destination branch
            if ($trip->vehicle_id) {
                TransportVehicle::query()->where('id', $trip->vehicle_id)->update([
                    'status' => TransportVehicle::STATUS_AVAILABLE,
                    'current_branch_id' => $trip->to_branch_id,
                ]);
            }
            if ($trip->driver_id) {
                TransportDriver::query()->where('id', $trip->driver_id)->update([
                    'status' => TransportDriver::STATUS_AVAILABLE,
                    'base_branch_id' => $trip->to_branch_id,
                ]);
            }

            return $trip->fresh();
        });
    }

    public function refreshTripTotals(TransportTrip $trip): void
    {
        $manifests = DispatchManifest::query()
            ->join('transport_trip_manifests', 'transport_trip_manifests.dispatch_manifest_id', '=', 'dispatch_manifests.id')
            ->where('transport_trip_manifests.transport_trip_id', $trip->id)
            ->withCount('items')
            ->get();

        $totalManifests = $manifests->count();
        $totalParcels = $manifests->sum('items_count');
        $totalWeight = $manifests->sum(fn ($m) => (float) ($m->total_weight_kg ?? ($m->items_count * 1.5)));

        $trip->update([
            'total_manifests_count' => $totalManifests,
            'total_parcels_count' => $totalParcels,
            'total_weight_kg' => round($totalWeight, 2),
        ]);
    }

    private function generateTripNumber(): string
    {
        $date = now()->format('Ymd');
        $count = TransportTrip::query()->whereDate('created_at', now()->toDateString())->count() + 1;
        return sprintf('TRIP-%s-%04d', $date, $count);
    }
}

