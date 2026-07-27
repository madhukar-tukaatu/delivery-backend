<?php

namespace Modules\Rate\Services\Pricing;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Branch\Models\Branch;
use Modules\Rate\Models\BranchTransferLane;
use Modules\Rate\Models\BranchTransferRoute;
use Modules\Rate\Models\BranchTransferRouteLane;
use Modules\Rate\Models\PricingReturnRule;

final class DefaultPricingImporter
{
    public function preview(): array
    {
        $config = config('pricing_defaults');
        $branches = Branch::query()->get(['id', 'name', 'code']);

        $source = $this->resolveBranch(
            $branches,
            (array) ($config['source_branch_aliases'] ?? [])
        );

        $rows = collect($config['route_rates'] ?? [])
            ->map(function (array $rate) use ($branches, $source): array {
                $destination = $this->resolveBranch(
                    $branches,
                    (array) ($rate['destination_aliases'] ?? [])
                );

                $existingRoute = null;
                $directLane = null;

                if ($source && $destination && $source->id !== $destination->id) {
                    $existingRoute = BranchTransferRoute::query()
                        ->where('origin_branch_id', $source->id)
                        ->where('destination_branch_id', $destination->id)
                        ->where(
                            'service_type',
                            (string) config('pricing_defaults.service_type', 'standard')
                        )
                        ->orderByDesc('is_default')
                        ->orderBy('priority')
                        ->first();

                    $directLane = BranchTransferLane::query()
                        ->where('from_branch_id', $source->id)
                        ->where('to_branch_id', $destination->id)
                        ->where(
                            'service_type',
                            (string) config('pricing_defaults.service_type', 'standard')
                        )
                        ->where('is_active', true)
                        ->first();
                }

                return [
                    'destination_aliases' => $rate['destination_aliases'] ?? [],
                    'base_rate' => (float) ($rate['base_rate'] ?? 0),
                    'source_branch' => $source
                        ? $this->branchArray($source)
                        : null,
                    'destination_branch' => $destination
                        ? $this->branchArray($destination)
                        : null,
                    'same_branch' =>
                        $source && $destination &&
                        (int) $source->id === (int) $destination->id,
                    'existing_route_id' => $existingRoute?->id,
                    'direct_lane_id' => $directLane?->id,
                    'action' => match (true) {
                        !$source => 'source_branch_missing',
                        !$destination => 'destination_branch_missing',
                        (int) $source->id === (int) $destination->id =>
                            'upsert_local_rate',
                        (bool) $existingRoute => 'update_existing_route',
                        (bool) $directLane => 'create_direct_route',
                        default => 'skip_missing_route_path',
                    },
                ];
            })
            ->values()
            ->all();

        return [
            'preset_name' => (string) ($config['preset_name'] ?? 'Default Pricing'),
            'settings' => $config['settings'] ?? [],
            'return_rules' => $config['return_rules'] ?? [],
            'route_rates' => $rows,
        ];
    }

    public function import(
        ?int $userId = null,
        bool $activate = true,
        bool $createDirectRoutes = true
    ): array {
        return DB::transaction(function () use (
            $userId,
            $activate,
            $createDirectRoutes
        ): array {
            $config = config('pricing_defaults');
            $branches = Branch::query()->get(['id', 'name', 'code']);

            $source = $this->resolveBranch(
                $branches,
                (array) ($config['source_branch_aliases'] ?? [])
            );

            if (!$source) {
                throw ValidationException::withMessages([
                    'source_branch' => [
                        'The Kathmandu source branch could not be resolved. Update pricing_defaults.source_branch_aliases.',
                    ],
                ]);
            }

            $settingsId = $this->createSettingsVersion(
                (array) ($config['settings'] ?? []),
                $userId,
                $activate
            );

            $returnRules = $this->upsertReturnRules(
                (array) ($config['return_rules'] ?? []),
                $userId
            );

            $results = [];

            foreach ((array) ($config['route_rates'] ?? []) as $rate) {
                $destination = $this->resolveBranch(
                    $branches,
                    (array) ($rate['destination_aliases'] ?? [])
                );

                if (!$destination) {
                    $results[] = [
                        'aliases' => $rate['destination_aliases'] ?? [],
                        'base_rate' => (float) ($rate['base_rate'] ?? 0),
                        'status' => 'skipped',
                        'reason' => 'destination_branch_missing',
                    ];

                    continue;
                }

                $baseRate = max(0, (float) ($rate['base_rate'] ?? 0));

                if ((int) $source->id === (int) $destination->id) {
                    DB::table('branch_route_rates')->updateOrInsert(
                        [
                            'pickup_branch_id' => $source->id,
                            'delivery_branch_id' => $destination->id,
                        ],
                        [
                            'base_rate' => $baseRate,
                            'is_active' => true,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]
                    );

                    $results[] = [
                        'destination_branch' => $this->branchArray($destination),
                        'base_rate' => $baseRate,
                        'status' => 'imported',
                        'action' => 'local_rate_upserted',
                    ];

                    continue;
                }

                $serviceType = (string) ($config['service_type'] ?? 'standard');

                $route = BranchTransferRoute::query()
                    ->where('origin_branch_id', $source->id)
                    ->where('destination_branch_id', $destination->id)
                    ->where('service_type', $serviceType)
                    ->orderByDesc('is_default')
                    ->orderBy('priority')
                    ->first();

                if ($route) {
                    $route->update([
                        'base_rate' => $baseRate,
                        'currency' => (string) ($config['currency'] ?? 'NPR'),
                        'is_active' => true,
                    ]);

                    $results[] = [
                        'destination_branch' => $this->branchArray($destination),
                        'route_id' => $route->id,
                        'route_code' => $route->route_code,
                        'base_rate' => $baseRate,
                        'status' => 'imported',
                        'action' => 'existing_route_updated',
                    ];

                    continue;
                }

                if (!$createDirectRoutes) {
                    $results[] = [
                        'destination_branch' => $this->branchArray($destination),
                        'base_rate' => $baseRate,
                        'status' => 'skipped',
                        'reason' => 'configured_route_missing',
                    ];

                    continue;
                }

                $lane = BranchTransferLane::query()
                    ->where('from_branch_id', $source->id)
                    ->where('to_branch_id', $destination->id)
                    ->where('service_type', $serviceType)
                    ->where('is_active', true)
                    ->first();

                if (!$lane) {
                    $results[] = [
                        'destination_branch' => $this->branchArray($destination),
                        'base_rate' => $baseRate,
                        'status' => 'skipped',
                        'reason' => 'configured_route_and_direct_lane_missing',
                    ];

                    continue;
                }

                $route = BranchTransferRoute::query()->create([
                    'route_code' => $this->routeCode(
                        (string) $source->code,
                        (string) $destination->code,
                        $serviceType
                    ),
                    'name' => "{$source->name} to {$destination->name}",
                    'origin_branch_id' => $source->id,
                    'destination_branch_id' => $destination->id,
                    'service_type' => $serviceType,
                    'base_rate' => $baseRate,
                    'currency' => (string) ($config['currency'] ?? 'NPR'),
                    'transfer_count' => 1,
                    'transit_count' => 0,
                    'total_distance_km' => $lane->distance_km,
                    'total_estimated_hours' => $lane->estimated_hours,
                    'priority' => 100,
                    'is_default' => true,
                    'is_active' => true,
                    'notes' => 'Created by default pricing importer from an existing direct lane.',
                ]);

                BranchTransferRouteLane::query()->create([
                    'branch_transfer_route_id' => $route->id,
                    'branch_transfer_lane_id' => $lane->id,
                    'sequence_number' => 1,
                ]);

                $results[] = [
                    'destination_branch' => $this->branchArray($destination),
                    'route_id' => $route->id,
                    'route_code' => $route->route_code,
                    'base_rate' => $baseRate,
                    'status' => 'imported',
                    'action' => 'direct_route_created',
                ];
            }

            return [
                'settings_id' => $settingsId,
                'return_rules_updated' => $returnRules,
                'routes' => $results,
                'summary' => [
                    'imported' => collect($results)
                        ->where('status', 'imported')
                        ->count(),
                    'skipped' => collect($results)
                        ->where('status', 'skipped')
                        ->count(),
                ],
            ];
        });
    }

    private function createSettingsVersion(
        array $settings,
        ?int $userId,
        bool $activate
    ): int {
        if ($activate) {
            DB::table('pricing_settings')
                ->where('is_active', true)
                ->update([
                    'is_active' => false,
                    'effective_until' => now(),
                    'updated_at' => now(),
                    'updated_by' => $userId,
                ]);
        }

        $payload = array_merge($settings, [
            'is_active' => $activate,
            'effective_from' => now(),
            'effective_until' => null,
            'created_by' => $userId,
            'updated_by' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $columns = collect(Schema::getColumnListing('pricing_settings'))
            ->flip();

        $payload = collect($payload)
            ->filter(
                static fn (mixed $value, string $key): bool =>
                    $columns->has($key)
            )
            ->all();

        return (int) DB::table('pricing_settings')->insertGetId($payload);
    }

    private function upsertReturnRules(array $rules, ?int $userId): int
    {
        foreach ($rules as $rule) {
            PricingReturnRule::query()->updateOrCreate(
                [
                    'scenario_code' => (string) $rule['scenario_code'],
                ],
                [
                    'name' => (string) $rule['name'],
                    'base_rate_percentage' => (float) $rule['base_rate_percentage'],
                    'distance_rate_per_km' => (float) $rule['distance_rate_per_km'],
                    'fixed_charge' => (float) $rule['fixed_charge'],
                    'is_active' => true,
                    'updated_by' => $userId,
                ]
            );
        }

        return count($rules);
    }

    private function resolveBranch(
        Collection $branches,
        array $aliases
    ): ?Branch {
        $normalizedAliases = collect($aliases)
            ->map(fn (mixed $alias): string => $this->normalize((string) $alias))
            ->filter()
            ->unique()
            ->values();

        return $branches->first(function (Branch $branch) use (
            $normalizedAliases
        ): bool {
            $candidates = collect([
                $branch->code,
                $branch->name,
            ])->map(fn (mixed $value): string => $this->normalize((string) $value));

            return $candidates->contains(
                static fn (string $candidate): bool =>
                    $normalizedAliases->contains($candidate)
            );
        });
    }

    private function normalize(string $value): string
    {
        return Str::of($value)
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', '')
            ->toString();
    }

    private function routeCode(
        string $originCode,
        string $destinationCode,
        string $serviceType
    ): string {
        $base = Str::upper(
            Str::slug("{$originCode}-{$destinationCode}-{$serviceType}", '-')
        );

        $candidate = $base;
        $suffix = 2;

        while (
            BranchTransferRoute::query()
                ->where('route_code', $candidate)
                ->exists()
        ) {
            $candidate = "{$base}-{$suffix}";
            $suffix++;
        }

        return $candidate;
    }

    private function branchArray(Branch $branch): array
    {
        return [
            'id' => (int) $branch->id,
            'name' => $branch->name,
            'code' => $branch->code,
        ];
    }
}
