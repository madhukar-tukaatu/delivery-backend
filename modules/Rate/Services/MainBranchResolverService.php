<?php

namespace Modules\Rate\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

final class MainBranchResolverService
{
    public function resolve(
        float $latitude,
        float $longitude
    ): object {
        $latitudeColumn = $this->latitudeColumn();
        $longitudeColumn = $this->longitudeColumn();

        if (!$latitudeColumn || !$longitudeColumn) {
            throw ValidationException::withMessages([
                'branch' => [
                    'Branch coordinate columns are not configured.',
                ],
            ]);
        }

        $query = DB::table('branches')
            ->whereNotNull($latitudeColumn)
            ->whereNotNull($longitudeColumn);

        $this->applyMainBranchFilter($query);

        $branches = $query->get();

        if ($branches->isEmpty()) {
            throw ValidationException::withMessages([
                'branch' => [
                    'No active main branches with coordinates were found.',
                ],
            ]);
        }

        $nearest = null;
        $nearestDistance = PHP_FLOAT_MAX;

        foreach ($branches as $branch) {
            $distance = $this->distanceKm(
                $latitude,
                $longitude,
                (float) $branch->{$latitudeColumn},
                (float) $branch->{$longitudeColumn}
            );

            if ($distance < $nearestDistance) {
                $nearest = $branch;
                $nearestDistance = $distance;
            }
        }

        if (!$nearest) {
            throw ValidationException::withMessages([
                'branch' => [
                    'Unable to resolve the nearest branch.',
                ],
            ]);
        }

        $nearest->resolved_distance_km = round(
            $nearestDistance,
            3
        );

        return $nearest;
    }

    public function distanceKm(
        float $latitudeOne,
        float $longitudeOne,
        float $latitudeTwo,
        float $longitudeTwo
    ): float {
        $earthRadius = 6371.0088;

        $latitudeDelta = deg2rad(
            $latitudeTwo - $latitudeOne
        );

        $longitudeDelta = deg2rad(
            $longitudeTwo - $longitudeOne
        );

        $a =
            sin($latitudeDelta / 2) ** 2 +
            cos(deg2rad($latitudeOne)) *
            cos(deg2rad($latitudeTwo)) *
            sin($longitudeDelta / 2) ** 2;

        return $earthRadius * (
            2 * atan2(
                sqrt($a),
                sqrt(1 - $a)
            )
        );
    }

    private function applyMainBranchFilter(
        Builder $query
    ): void {
        if (Schema::hasColumn('branches', 'is_active')) {
            $query->where('is_active', true);
        }

        if (Schema::hasColumn('branches', 'is_main')) {
            $query->where('is_main', true);

            return;
        }

        if (Schema::hasColumn('branches', 'branch_type')) {
            $query->where('branch_type', 'main');

            return;
        }

        if (Schema::hasColumn('branches', 'type')) {
            $query->where('type', 'main');

            return;
        }

        if (Schema::hasColumn('branches', 'parent_id')) {
            $query->whereNull('parent_id');
        }
    }

    private function latitudeColumn(): ?string
    {
        foreach (
            [
                'latitude',
                'lat',
                'branch_latitude',
            ] as $column
        ) {
            if (Schema::hasColumn('branches', $column)) {
                return $column;
            }
        }

        return null;
    }

    private function longitudeColumn(): ?string
    {
        foreach (
            [
                'longitude',
                'lng',
                'lon',
                'branch_longitude',
            ] as $column
        ) {
            if (Schema::hasColumn('branches', $column)) {
                return $column;
            }
        }

        return null;
    }
}