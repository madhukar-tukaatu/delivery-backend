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
     * Resolve coordinates to the main branch responsible for pricing.
     *
     * Supported ownership layouts:
     * 1. coverage_locations.branch_id -> branches.id
     * 2. branches.coverage_location_id -> coverage_locations.id
     * 3. sub-branch branch.parent_id -> main branch
     * 4. sub-zone coverage_locations.parent_id -> main coverage zone
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

        $branches = $this->branches();

        $zonesById = $zones->keyBy(
            static fn (object $zone): int => (int) $zone->id
        );

        $branchesById = $branches->keyBy(
            static fn (object $branch): int => (int) $branch->id
        );

        $branchesByCoverageLocationId = $branches
            ->filter(
                static fn (object $branch): bool =>
                    $branch->coverage_location_id !== null
            )
            ->keyBy(
                static fn (object $branch): int =>
                    (int) $branch->coverage_location_id
            );

        $matches = [];
        $insideButUnmapped = [];
        $nearest = null;

        foreach ($zones as $zone) {
            $distanceKm = $this->distanceKm(
                $latitude,
                $longitude,
                (float) $zone->latitude,
                (float) $zone->longitude
            );

            $radiusKm = max(
                0.0,
                (float) $zone->coverage_radius_km
            );

            if (
                $nearest === null ||
                $distanceKm < $nearest['distance_km']
            ) {
                $nearest = [
                    'zone' => $zone,
                    'distance_km' => $distanceKm,
                ];
            }

            if ($distanceKm > $radiusKm) {
                continue;
            }

            $responsibility = $this->resolveResponsibility(
                zone: $zone,
                zones: $zones,
                zonesById: $zonesById,
                branchesById: $branchesById,
                branchesByCoverageLocationId: $branchesByCoverageLocationId
            );

            if ($responsibility === null) {
                $insideButUnmapped[] = [
                    'zone' => $zone,
                    'distance_km' => $distanceKm,
                ];

                continue;
            }

            $matches[] = [
                'matched_zone' => $zone,
                'matched_distance_km' => $distanceKm,
                'main_zone' => $responsibility['main_zone'],
                'main_branch' => $responsibility['main_branch'],
                'specificity' =>
                    $zone->type === 'sub_branch_zone' ? 0 : 1,
            ];
        }

        /*
         * A point inside a radius must not be reported as outside coverage.
         * Report the actual configuration problem instead.
         */
        if ($matches === [] && $insideButUnmapped !== []) {
            usort(
                $insideButUnmapped,
                static fn (array $first, array $second): int =>
                    $first['distance_km'] <=> $second['distance_km']
            );

            $unmapped = $insideButUnmapped[0];
            $zone = $unmapped['zone'];

            throw ValidationException::withMessages([
                'address' => [
                    sprintf(
                        'The location is inside %s (%s), %.2f km away within its %.2f km radius, but that coverage zone is not linked to a valid main pricing branch.',
                        (string) $zone->name,
                        (string) $zone->code,
                        (float) $unmapped['distance_km'],
                        (float) $zone->coverage_radius_km
                    ),
                ],
            ]);
        }

        if ($matches === []) {
            // Location is outside all coverage radii.
            // Fall back to the nearest zone's branch — that branch will
            // handle pickup and delivery regardless of distance.
            if ($nearest !== null) {
                $nearestZone = $nearest['zone'];

                $responsibility = $this->resolveResponsibility(
                    zone: $nearestZone,
                    zones: $zones,
                    zonesById: $zonesById,
                    branchesById: $branchesById,
                    branchesByCoverageLocationId: $branchesByCoverageLocationId
                );

                if ($responsibility !== null) {
                    $matches[] = [
                        'matched_zone'        => $nearestZone,
                        'matched_distance_km' => $nearest['distance_km'],
                        'main_zone'           => $responsibility['main_zone'],
                        'main_branch'         => $responsibility['main_branch'],
                        'specificity'         => 1,
                    ];
                }
            }

            // If the nearest zone is also unmapped, nothing can be done
            if ($matches === []) {
                throw ValidationException::withMessages([
                    'address' => [
                        'No branch could be resolved for this location.',
                    ],
                ]);
            }
        }

        usort(
            $matches,
            static function (array $first, array $second): int {
                $distanceComparison =
                    $first['matched_distance_km']
                    <=> $second['matched_distance_km'];

                if ($distanceComparison !== 0) {
                    return $distanceComparison;
                }

                return $first['specificity']
                    <=> $second['specificity'];
            }
        );

        $selected = $matches[0];
        $matchedZone = $selected['matched_zone'];
        $mainZone = $selected['main_zone'];
        $mainBranch = $selected['main_branch'];

        $responsibleDistanceKm = $mainZone
            ? $this->distanceKm(
                $latitude,
                $longitude,
                (float) $mainZone->latitude,
                (float) $mainZone->longitude
            )
            : (float) $selected['matched_distance_km'];

        $result = new stdClass();

        $result->id = (int) $mainBranch->id;
        $result->name = (string) $mainBranch->name;
        $result->code = (string) ($mainBranch->code ?? '');
        $result->parent_id = $mainBranch->parent_id !== null
            ? (int) $mainBranch->parent_id
            : null;

        $result->coverage_location_id = $mainZone
            ? (int) $mainZone->id
            : (int) $matchedZone->id;

        $result->coverage_name = $mainZone
            ? (string) $mainZone->name
            : (string) $matchedZone->name;

        $result->coverage_code = $mainZone
            ? (string) $mainZone->code
            : (string) $matchedZone->code;

        $result->coverage_type = $mainZone
            ? (string) $mainZone->type
            : (string) $matchedZone->type;

        $result->coverage_radius_km = $mainZone
            ? (float) $mainZone->coverage_radius_km
            : (float) $matchedZone->coverage_radius_km;

        $result->matched_coverage_location_id =
            (int) $matchedZone->id;
        $result->matched_coverage_name =
            (string) $matchedZone->name;
        $result->matched_coverage_code =
            (string) $matchedZone->code;
        $result->matched_coverage_type =
            (string) $matchedZone->type;
        $result->matched_distance_km = round(
            (float) $selected['matched_distance_km'],
            3
        );

        $result->resolved_distance_km = round(
            $responsibleDistanceKm,
            3
        );

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
                'branch_id',
                'latitude',
                'longitude',
                'coverage_radius_km',
                'status',
            ]);
    }

    private function branches(): Collection
    {
        return DB::table('branches')
            ->get([
                'id',
                'name',
                'code',
                'parent_id',
                'coverage_location_id',
                'status',
            ]);
    }

    /**
     * @return array{main_branch: object, main_zone: object|null}|null
     */
    private function resolveResponsibility(
        object $zone,
        Collection $zones,
        Collection $zonesById,
        Collection $branchesById,
        Collection $branchesByCoverageLocationId
    ): ?array {
        $allocatedBranch = $this->branchForZone(
            zone: $zone,
            branchesById: $branchesById,
            branchesByCoverageLocationId: $branchesByCoverageLocationId
        );

        /*
         * First choice: resolve through the branch allocated to this zone.
         */
        if ($allocatedBranch !== null) {
            $mainBranch = $this->rootBranch(
                $allocatedBranch,
                $branchesById
            );

            if ($mainBranch !== null) {
                return [
                    'main_branch' => $mainBranch,
                    'main_zone' => $this->mainZoneForBranch(
                        branch: $mainBranch,
                        zones: $zones,
                        zonesById: $zonesById
                    ),
                ];
            }
        }

        /*
         * Second choice: parent_id may reference a parent coverage zone.
         */
        if ($zone->parent_id !== null) {
            $parentZone = $zonesById->get(
                (int) $zone->parent_id
            );

            if ($parentZone !== null) {
                $parentZoneBranch = $this->branchForZone(
                    zone: $parentZone,
                    branchesById: $branchesById,
                    branchesByCoverageLocationId: $branchesByCoverageLocationId
                );

                if ($parentZoneBranch !== null) {
                    $mainBranch = $this->rootBranch(
                        $parentZoneBranch,
                        $branchesById
                    );

                    if ($mainBranch !== null) {
                        return [
                            'main_branch' => $mainBranch,
                            'main_zone' => $parentZone,
                        ];
                    }
                }
            }

            /*
             * Compatibility: parent_id may reference a parent branch.
             */
            $parentBranch = $branchesById->get(
                (int) $zone->parent_id
            );

            if ($parentBranch !== null) {
                $mainBranch = $this->rootBranch(
                    $parentBranch,
                    $branchesById
                );

                if ($mainBranch !== null) {
                    return [
                        'main_branch' => $mainBranch,
                        'main_zone' => $this->mainZoneForBranch(
                            branch: $mainBranch,
                            zones: $zones,
                            zonesById: $zonesById
                        ),
                    ];
                }
            }
        }

        return null;
    }

    private function branchForZone(
        object $zone,
        Collection $branchesById,
        Collection $branchesByCoverageLocationId
    ): ?object {
        if ($zone->branch_id !== null) {
            $branch = $branchesById->get(
                (int) $zone->branch_id
            );

            if ($branch !== null) {
                return $branch;
            }
        }

        return $branchesByCoverageLocationId->get(
            (int) $zone->id
        );
    }

    private function rootBranch(
        object $branch,
        Collection $branchesById
    ): ?object {
        $current = $branch;
        $visited = [];

        for ($depth = 0; $depth < 20; $depth++) {
            $currentId = (int) $current->id;

            if (isset($visited[$currentId])) {
                return null;
            }

            $visited[$currentId] = true;

            if ($current->parent_id === null) {
                return $current;
            }

            $parent = $branchesById->get(
                (int) $current->parent_id
            );

            if ($parent === null) {
                return null;
            }

            $current = $parent;
        }

        return null;
    }

    private function mainZoneForBranch(
        object $branch,
        Collection $zones,
        Collection $zonesById
    ): ?object {
        if ($branch->coverage_location_id !== null) {
            $zone = $zonesById->get(
                (int) $branch->coverage_location_id
            );

            if (
                $zone !== null &&
                $zone->type === 'main_branch_zone'
            ) {
                return $zone;
            }
        }

        return $zones->first(
            static fn (object $candidate): bool =>
                $candidate->type === 'main_branch_zone' &&
                $candidate->branch_id !== null &&
                (int) $candidate->branch_id === (int) $branch->id
        );
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
