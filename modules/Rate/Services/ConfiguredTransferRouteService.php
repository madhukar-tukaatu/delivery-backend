<?php

declare(strict_types=1);

namespace Modules\Rate\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Rate\Models\BranchTransferLane;
use Modules\Rate\Models\BranchTransferRoute;

final class ConfiguredTransferRouteService
{
    /**
     * Resolve an already configured complete transfer route.
     */
    public function resolve(
        int $originBranchId,
        int $destinationBranchId,
        string $serviceType = 'standard'
    ): array {
        $serviceType = $this->normalizeServiceType($serviceType);

        $route = BranchTransferRoute::query()
            ->with([
                'originBranch',
                'destinationBranch',
                'routeLanes.lane.fromBranch',
                'routeLanes.lane.toBranch',
            ])
            ->where('origin_branch_id', $originBranchId)
            ->where('destination_branch_id', $destinationBranchId)
            ->where('service_type', $serviceType)
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('priority')
            ->orderBy('id')
            ->first();

        if (!$route) {
            throw ValidationException::withMessages([
                'transfer_route' => [
                    sprintf(
                        'No active complete transfer route is configured from branch %d to branch %d for %s service.',
                        $originBranchId,
                        $destinationBranchId,
                        $serviceType
                    ),
                ],
            ]);
        }

        $routeLanes = $route->routeLanes
            ->sortBy('sequence_number')
            ->values();

        if ($routeLanes->isEmpty()) {
            throw ValidationException::withMessages([
                'transfer_route' => [
                    'The configured transfer route has no lane mappings.',
                ],
            ]);
        }

        if (
            $routeLanes->count() !==
            (int) $route->transfer_count
        ) {
            throw ValidationException::withMessages([
                'transfer_route' => [
                    'The configured transfer route contains missing lane mappings.',
                ],
            ]);
        }

        $laneMappings = $routeLanes
            ->map(
                static fn ($routeLane) => $routeLane->lane
            )
            ->filter()
            ->values();

        return $this->validateAndCalculateLanes(
            $laneMappings,
            $originBranchId,
            $destinationBranchId,
            $serviceType
        ) + [
            'route_id' => (int) $route->id,
            'route_code' => (string) $route->route_code,
            'route_name' => (string) $route->name,
        ];
    }

    /**
     * Validate direct transfer lanes and calculate:
     *
     * - route path
     * - distance
     * - ETA
     * - branch pricing
     * - total base rate
     */
    public function validateAndCalculateLanes(
        Collection|array $laneMappings,
        int $originBranchId,
        int $destinationBranchId,
        string $serviceType = 'standard'
    ): array {
        $serviceType = $this->normalizeServiceType($serviceType);

        $lanes = $laneMappings instanceof Collection
            ? $laneMappings->values()
            : collect($laneMappings)->values();

        if ($lanes->isEmpty()) {
            throw ValidationException::withMessages([
                'transfer_route' => [
                    'At least one active transfer lane is required.',
                ],
            ]);
        }

        $path = [];
        $pricing = [];
        $laneResponse = [];

        $totalDistance = 0.0;
        $totalEstimatedHours = 0;
        $calculatedBaseRate = 0.0;

        $expectedFromBranchId = $originBranchId;

        foreach ($lanes as $index => $lane) {
            if (!$lane instanceof BranchTransferLane) {
                throw ValidationException::withMessages([
                    'transfer_route' => [
                        'Invalid transfer lane supplied.',
                    ],
                ]);
            }

            /*
             * ----------------------------------------------------------
             * Active lane check
             * ----------------------------------------------------------
             */
            if (!(bool) $lane->is_active) {
                throw ValidationException::withMessages([
                    'transfer_route' => [
                        sprintf(
                            'Transfer lane %d is inactive.',
                            (int) $lane->id
                        ),
                    ],
                ]);
            }

            /*
             * ----------------------------------------------------------
             * Service type check
             * ----------------------------------------------------------
             */
            if (
                strtolower(
                    trim(
                        (string) $lane->service_type
                    )
                ) !== $serviceType
            ) {
                throw ValidationException::withMessages([
                    'transfer_route' => [
                        sprintf(
                            'Transfer lane %d uses service type %s instead of %s.',
                            (int) $lane->id,
                            (string) $lane->service_type,
                            $serviceType
                        ),
                    ],
                ]);
            }

            /*
             * ----------------------------------------------------------
             * Connected path check
             * ----------------------------------------------------------
             */
            if (
                (int) $lane->from_branch_id !==
                $expectedFromBranchId
            ) {
                throw ValidationException::withMessages([
                    'transfer_route' => [
                        sprintf(
                            'Transfer lane sequence is disconnected. Expected branch %d but lane %d starts from branch %d.',
                            $expectedFromBranchId,
                            (int) $lane->id,
                            (int) $lane->from_branch_id
                        ),
                    ],
                ]);
            }

            /*
             * ----------------------------------------------------------
             * Add origin once
             * ----------------------------------------------------------
             */
            if ($index === 0) {
                $path[] = [
                    'id' => (int) $lane->from_branch_id,
                    'name' => $lane->fromBranch?->name,
                    'code' => $lane->fromBranch?->code,
                ];
            }

            /*
             * ----------------------------------------------------------
             * Add destination of this lane
             * ----------------------------------------------------------
             */
            $path[] = [
                'id' => (int) $lane->to_branch_id,
                'name' => $lane->toBranch?->name,
                'code' => $lane->toBranch?->code,
            ];

            /*
             * ----------------------------------------------------------
             * Distance / ETA
             * ----------------------------------------------------------
             */
            $distanceKm = (float) (
                $lane->distance_km ?? 0
            );

            $estimatedHours = (int) (
                $lane->estimated_hours ?? 0
            );

            $totalDistance += $distanceKm;
            $totalEstimatedHours += $estimatedHours;

            /*
             * ----------------------------------------------------------
             * REAL BRANCH PRICING LOOKUP
             * ----------------------------------------------------------
             *
             * Actual branch_route_rates schema:
             *
             * pickup_branch_id
             * delivery_branch_id
             * base_rate
             * is_active
             *
             * DO NOT use:
             *
             * origin_branch_id
             * destination_branch_id
             * effective_from
             * effective_to
             */
            $lanePricing = $this->resolveLaneBaseRate(
                (int) $lane->from_branch_id,
                (int) $lane->to_branch_id
            );

            $laneRate = (float) $lanePricing['base_rate'];

            $calculatedBaseRate += $laneRate;

            /*
             * ----------------------------------------------------------
             * Pricing response
             * ----------------------------------------------------------
             */
            $pricing[] = [
                'lane_id' => (int) $lane->id,

                'from_branch_id' => (int) $lane->from_branch_id,

                'from_branch_name' => $lane->fromBranch?->name,

                'to_branch_id' => (int) $lane->to_branch_id,

                'to_branch_name' => $lane->toBranch?->name,

                'service_type' => $serviceType,

                'base_rate' => $laneRate,

                'currency' => $lanePricing['currency'],
            ];

            /*
             * ----------------------------------------------------------
             * Lane response
             * ----------------------------------------------------------
             */
            $laneResponse[] = [
                'sequence' => $index + 1,

                'lane_id' => (int) $lane->id,

                'from_branch_id' => (int) $lane->from_branch_id,

                'from_branch_name' => $lane->fromBranch?->name,

                'to_branch_id' => (int) $lane->to_branch_id,

                'to_branch_name' => $lane->toBranch?->name,

                'service_type' => $serviceType,

                'transport_mode' => $lane->transport_mode,

                'distance_km' => $distanceKm,

                'estimated_hours' => $estimatedHours,

                'base_rate' => $laneRate,
            ];

            /*
             * Next lane must start where this lane ended.
             */
            $expectedFromBranchId =
                (int) $lane->to_branch_id;
        }

        /*
         * --------------------------------------------------------------
         * Final destination check
         * --------------------------------------------------------------
         */
        if (
            $expectedFromBranchId !==
            $destinationBranchId
        ) {
            throw ValidationException::withMessages([
                'transfer_route' => [
                    sprintf(
                        'The transfer route ends at branch %d but destination branch %d was requested.',
                        $expectedFromBranchId,
                        $destinationBranchId
                    ),
                ],
            ]);
        }

        /*
         * --------------------------------------------------------------
         * Transit branches
         * --------------------------------------------------------------
         */
        $transitBranches = [];

        if (count($path) > 2) {
            $transitBranches = array_slice(
                $path,
                1,
                count($path) - 2
            );

            $transitBranches = array_values(
                array_map(
                    static function (
                        array $branch,
                        int $index
                    ): array {
                        return [
                            'sequence' => $index + 1,
                            ...$branch,
                        ];
                    },
                    $transitBranches,
                    array_keys($transitBranches)
                )
            );
        }

        /*
         * --------------------------------------------------------------
         * Path text
         * --------------------------------------------------------------
         */
        $pathText = implode(
            ' -> ',
            array_values(
                array_filter(
                    array_column(
                        $path,
                        'name'
                    )
                )
            )
        );

        /*
         * --------------------------------------------------------------
         * FINAL CALCULATION
         * --------------------------------------------------------------
         */
        $calculatedBaseRate = round(
            $calculatedBaseRate,
            2
        );

        return [
            'origin_branch_id' => $originBranchId,

            'destination_branch_id' => $destinationBranchId,

            'service_type' => $serviceType,

            'calculated_base_rate' => $calculatedBaseRate,

            'base_rate' => $calculatedBaseRate,

            'currency' => 'NPR',

            'lane_count' => $lanes->count(),

            'transfer_count' => $lanes->count(),

            'transit_count' => count($transitBranches),

            'total_distance_km' => round(
                $totalDistance,
                2
            ),

            'total_estimated_hours' =>
                $totalEstimatedHours,

            'path' => $path,

            'path_text' => $pathText,

            'transit_branches' =>
                $transitBranches,

            'lanes' =>
                $laneResponse,

            'pricing' =>
                $pricing,
        ];
    }

    /**
     * Resolve Branch Pricing for one direct lane.
     *
     * REAL DATABASE SCHEMA:
     *
     * branch_route_rates
     * -------------------
     * pickup_branch_id
     * delivery_branch_id
     * base_rate
     * is_active
     *
     * Example:
     *
     * pickup_branch_id  = 185
     * delivery_branch_id = 190
     * base_rate = 89
     * is_active = 1
     *
     * Result:
     *
     * NPR 89
     */
    private function resolveLaneBaseRate(
        int $pickupBranchId,
        int $deliveryBranchId
    ): array {
        $rate = DB::table('branch_route_rates')
            ->where(
                'pickup_branch_id',
                $pickupBranchId
            )
            ->where(
                'delivery_branch_id',
                $deliveryBranchId
            )
            ->where(
                'is_active',
                true
            )
            ->orderByDesc('id')
            ->first();

        if (!$rate) {
            throw ValidationException::withMessages([
                'transfer_route' => [
                    sprintf(
                        'No active Branch Pricing exists from branch %d to branch %d.',
                        $pickupBranchId,
                        $deliveryBranchId
                    ),
                ],
            ]);
        }

        if (
            $rate->base_rate === null ||
            $rate->base_rate === ''
        ) {
            throw ValidationException::withMessages([
                'transfer_route' => [
                    sprintf(
                        'Branch Pricing from branch %d to branch %d has no base rate configured.',
                        $pickupBranchId,
                        $deliveryBranchId
                    ),
                ],
            ]);
        }

        return [
            'base_rate' => round(
                (float) $rate->base_rate,
                2
            ),

            'currency' => 'NPR',
        ];
    }

    /**
     * Normalize service type.
     */
    private function normalizeServiceType(
        string $serviceType
    ): string {
        $serviceType = strtolower(
            trim($serviceType)
        );

        if (
            !in_array(
                $serviceType,
                [
                    'standard',
                    'express',
                    'same_day',
                ],
                true
            )
        ) {
            throw ValidationException::withMessages([
                'service_type' => [
                    'Invalid transfer service type.',
                ],
            ]);
        }

        return $serviceType;
    }
}