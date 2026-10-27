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

    /**
     * Pricing coverage location for an operational branch node.
     *
     * Order:
     *  1. branches.coverage_location_id of the node (source: branch_coverage_location_id)
     *  2. coverage_locations.branch_id = node id   (source: coverage_location_branch_id)
     *  3. the parent branch's coverage_location_id (sub-branches only)
     *  4. the coverage location nearest the branch's own coordinates
     *     (source: nearest_to_branch, fallback = true)
     *
     * Sub-branch zones resolve up to their main zone, the same as resolve().
     * Returns null when nothing can be resolved (no link and no coordinates).
     */
    public function resolveZoneForBranch(
        object $branch,
        ?object $parentBranch = null,
        ?float $branchLatitude = null,
        ?float $branchLongitude = null
    ): ?object {
        $zones = $this->activeZones();

        if ($zones->isEmpty()) {
            return null;
        }

        $zonesById = $zones->keyBy(
            static fn (object $zone): int => (int) $zone->id
        );

        $candidates = [];

        if (! empty($branch->coverage_location_id)) {
            $candidates[] = [(int) $branch->coverage_location_id, 'branch_coverage_location_id'];
        }

        $linked = $zones->first(
            static fn (object $zone): bool =>
                isset($zone->branch_id) && (int) $zone->branch_id === (int) ($branch->id ?? 0)
        );

        if ($linked !== null) {
            $candidates[] = [(int) $linked->id, 'coverage_location_branch_id'];
        }

        if ($parentBranch !== null && ! empty($parentBranch->coverage_location_id)) {
            $candidates[] = [(int) $parentBranch->coverage_location_id, 'parent_branch_coverage_location_id'];
        }

        foreach ($candidates as [$zoneId, $source]) {
            $zone = $zonesById->get($zoneId);

            if ($zone === null) {
                continue;
            }

            $main = $this->resolveMainZone($zone, $zonesById);

            return $this->zonePayload($main, $source, false);
        }

        if ($branchLatitude === null || $branchLongitude === null) {
            return null;
        }

        $nearest = $this->resolve($branchLatitude, $branchLongitude);
        $main = $zonesById->get((int) $nearest->id);

        if ($main === null) {
            return null;
        }

        $payload = $this->zonePayload($main, 'nearest_to_branch', true);
        $payload->branch_to_zone_distance_km = (float) $nearest->resolved_distance_km;

        return $payload;
    }

    private function zonePayload(object $zone, string $source, bool $fallback): object
    {
        $payload = new stdClass();
        $payload->id = (int) $zone->id;
        $payload->name = (string) $zone->name;
        $payload->code = (string) $zone->code;
        $payload->type = (string) $zone->type;
        $payload->source = $source;
        $payload->fallback = $fallback;

        return $payload;
    }

    private function activeZones(): Collection
    {
        return DB::table('coverage_locations as cl')
            ->where('cl.status', 'active')
            ->whereNotNull('cl.latitude')
            ->whereNotNull('cl.longitude')
            ->whereIn('cl.type', [
                'main_branch_zone',
                'sub_branch_zone',
            ])
            ->where(function ($query) {
                // If a branch is associated with this coverage location, it must be active/operable.
                // Franchise branches require status = 'active' OR ('approved' with 'account_configured').
                // Other branches require status = 'active'.
                $query->whereNotExists(function ($sub) {
                    $sub->select(DB::raw(1))
                        ->from('branches as b')
                        ->where(function ($match) {
                            $match->whereColumn('b.id', 'cl.branch_id')
                                ->orWhereColumn('b.coverage_location_id', 'cl.id');
                        })
                        ->where(function ($inactive) {
                            $inactive->where(function ($franchise) {
                                $franchise->where('b.type', 'franchise_branch')
                                    ->where(function ($notOk) {
                                        $notOk->where('b.status', '!=', 'active')
                                            ->where(function ($appr) {
                                                $appr->where('b.status', '!=', 'approved')
                                                    ->orWhere('b.account_invitation_status', '!=', 'account_configured')
                                                    ->orWhereNull('b.account_invitation_status');
                                            });
                                    });
                            })->orWhere(function ($other) {
                                $other->where('b.type', '!=', 'franchise_branch')
                                    ->where('b.status', '!=', 'active');
                            });
                        });
                });
            })
            ->orderBy('cl.id')
            ->get([
                'cl.id',
                'cl.name',
                'cl.code',
                'cl.type',
                'cl.parent_id',
                'cl.branch_id',
                'cl.latitude',
                'cl.longitude',
                'cl.coverage_radius_km',
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
