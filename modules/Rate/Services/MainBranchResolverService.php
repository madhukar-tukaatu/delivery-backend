<?php

declare(strict_types=1);

namespace Modules\Rate\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use stdClass;

final class MainBranchResolverService
{
    /**
     * Resolve coordinates to the main coverage location responsible for pricing.
     *
     * Returns the main_branch_zone coverage location directly.
     * Sub-zones resolve up to their parent main zone.
     */
    public function resolve(float $latitude, float $longitude): object
    {
        $this->validateCoordinates($latitude, $longitude);

        $zones = $this->activeZones();

        if ($zones->isEmpty()) {
            throw ValidationException::withMessages([
                'address' => [
                    'No active coverage locations are configured.',
                ],
            ]);
        }

        $zonesById = $zones->keyBy(
            static fn (object $zone): int => (int) $zone->id
        );

        $matches = [];
        $nearest = null;

        foreach ($zones as $zone) {
            $distanceKm = $this->distanceKm(
                $latitude,
                $longitude,
                (float) $zone->latitude,
                (float) $zone->longitude
            );

            $radiusKm = max(0.0, (float) $zone->coverage_radius_km);

            if ($nearest === null || $distanceKm < $nearest['distance_km']) {
                $nearest = ['zone' => $zone, 'distance_km' => $distanceKm];
            }

            if ($distanceKm > $radiusKm) {
                continue;
            }

            $mainZone = $this->resolveMainZone($zone, $zonesById);

            $matches[] = [
                'matched_zone'       => $zone,
                'matched_distance_km'=> $distanceKm,
                'main_zone'          => $mainZone,
                'specificity'        => $zone->type === 'sub_branch_zone' ? 0 : 1,
            ];
        }

        if ($matches === []) {
            // Fall back to nearest zone
            if ($nearest !== null) {
                $mainZone = $this->resolveMainZone($nearest['zone'], $zonesById);
                $matches[] = [
                    'matched_zone'        => $nearest['zone'],
                    'matched_distance_km' => $nearest['distance_km'],
                    'main_zone'           => $mainZone,
                    'specificity'         => 1,
                ];
            }

            if ($matches === []) {
                throw ValidationException::withMessages([
                    'address' => ['No coverage location could be resolved for this location.'],
                ]);
            }
        }

        usort(
            $matches,
            static function (array $first, array $second): int {
                $cmp = $first['matched_distance_km'] <=> $second['matched_distance_km'];
                return $cmp !== 0 ? $cmp : $first['specificity'] <=> $second['specificity'];
            }
        );

        $selected    = $matches[0];
        $matchedZone = $selected['matched_zone'];
        $mainZone    = $selected['main_zone'];

        $responsibleDistanceKm = $this->distanceKm(
            $latitude,
            $longitude,
            (float) $mainZone->latitude,
            (float) $mainZone->longitude
        );

        $result = new stdClass();

        // Primary pricing identity — coverage location ID
        $result->id   = (int) $mainZone->id;
        $result->name = (string) $mainZone->name;
        $result->code = (string) $mainZone->code;
        $result->type = (string) $mainZone->type;
        $result->coverage_radius_km = (float) $mainZone->coverage_radius_km;

        $result->matched_coverage_location_id = (int) $matchedZone->id;
        $result->matched_coverage_name        = (string) $matchedZone->name;
        $result->matched_coverage_code        = (string) $matchedZone->code;
        $result->matched_coverage_type        = (string) $matchedZone->type;
        $result->matched_distance_km          = round((float) $selected['matched_distance_km'], 3);
        $result->resolved_distance_km         = round($responsibleDistanceKm, 3);

        return $result;
    }

    private function activeZones(): Collection
    {
        return DB::table('coverage_locations')
            ->where('status', 'active')
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->whereIn('type', [
                'main_branch_zone',
                'sub_branch_zone',
            ])
            ->orderBy('id')
            ->get([
                'id',
                'name',
                'code',
                'type',
                'parent_id',
                'latitude',
                'longitude',
                'coverage_radius_km',
            ]);
    }

    /**
     * Walk up parent_id chain to find the main_branch_zone.
     * If the zone itself is a main_branch_zone, return it directly.
     */
    private function resolveMainZone(
        object $zone,
        Collection $zonesById
    ): object {
        if ($zone->type === 'main_branch_zone') {
            return $zone;
        }

        if ($zone->parent_id !== null) {
            $parent = $zonesById->get((int) $zone->parent_id);

            if ($parent !== null && $parent->type === 'main_branch_zone') {
                return $parent;
            }
        }

        // No parent found — treat this zone as its own pricing entity
        return $zone;
    }

    private function validateCoordinates(
        float $latitude,
        float $longitude
    ): void {
        if ($latitude < -90 || $latitude > 90) {
            throw ValidationException::withMessages([
                'latitude' => [
                    'Latitude must be between -90 and 90.',
                ],
            ]);
        }

        if ($longitude < -180 || $longitude > 180) {
            throw ValidationException::withMessages([
                'longitude' => [
                    'Longitude must be between -180 and 180.',
                ],
            ]);
        }
    }

    private function distanceKm(
        float $latitudeOne,
        float $longitudeOne,
        float $latitudeTwo,
        float $longitudeTwo
    ): float {
        $earthRadiusKm = 6371.0088;

        $latitudeDelta = deg2rad(
            $latitudeTwo - $latitudeOne
        );

        $longitudeDelta = deg2rad(
            $longitudeTwo - $longitudeOne
        );

        $firstLatitude = deg2rad($latitudeOne);
        $secondLatitude = deg2rad($latitudeTwo);

        $a = sin($latitudeDelta / 2) ** 2
            + cos($firstLatitude)
            * cos($secondLatitude)
            * sin($longitudeDelta / 2) ** 2;

        $a = min(1.0, max(0.0, $a));

        return $earthRadiusKm
            * 2
            * atan2(sqrt($a), sqrt(1 - $a));
    }
}
