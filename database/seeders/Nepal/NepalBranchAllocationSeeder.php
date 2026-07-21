<?php

namespace Database\Seeders\Nepal;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

class NepalBranchAllocationSeeder extends Seeder
{
    private const COVERAGE_TABLE = 'coverage_locations';

    public function run(): void
    {
        if (!Schema::hasTable(self::COVERAGE_TABLE)) {
            throw new RuntimeException(
                'The coverage_locations table does not exist. Run the coverage location migration first.'
            );
        }

        $mainBranches = DB::table('branches')
            ->whereIn('type', [
                'branch',
                'main_branch',
                'franchise_branch',
            ])
            ->where(function ($query) {
                $query
                    ->where('status', 'active')
                    ->orWhere('is_active', true);
            })
            ->orderBy('id')
            ->get();

        foreach ($mainBranches as $mainBranch) {
            $mainAllocation = $this->createMainBranchAllocation($mainBranch);

            $this->createSubBranchAllocations(
                mainBranch: $mainBranch,
                mainAllocation: $mainAllocation,
            );
        }

        $mainCount = DB::table(self::COVERAGE_TABLE)
            ->where('type', 'main_branch_zone')
            ->count();

        $subCount = DB::table(self::COVERAGE_TABLE)
            ->where('type', 'sub_branch_zone')
            ->count();

        $this->command?->info('Branch allocations seeded successfully.');
        $this->command?->line("Main branch allocations: {$mainCount}");
        $this->command?->line("Sub-branch allocations: {$subCount}");
    }

    private function createMainBranchAllocation(object $branch): object
    {
        $code = $this->makeAllocationCode(
            prefix: 'MAIN',
            sourceCode: $branch->code ?? null,
            sourceName: $branch->name ?? 'BRANCH',
        );

        $existing = DB::table(self::COVERAGE_TABLE)
            ->where('code', $code)
            ->first();

        $coordinates = $this->resolveBranchCoordinates($branch);

        $payload = $this->filterColumns(self::COVERAGE_TABLE, [
            'parent_id' => null,

            'name' => $this->cleanBranchName($branch->name)
                . ' Main Coverage Zone',

            'code' => $code,
            'type' => 'main_branch_zone',

            'branch_id' => $branch->id,

            'country' => $branch->country ?? 'Nepal',
            'province' => $branch->province ?? null,
            'district' => $branch->district ?? $branch->city ?? null,
            'city' => $branch->city ?? null,
            'area' => $branch->area ?? null,
            'address' => $branch->address ?? null,
            'landmark' => $branch->landmark ?? null,

            'latitude' => $coordinates['latitude'],
            'longitude' => $coordinates['longitude'],

            'coverage_radius_km' => 5,
            'status' => 'active',
            'is_active' => true,

            'created_at' => $existing?->created_at ?? now(),
            'updated_at' => now(),
        ]);

        DB::table(self::COVERAGE_TABLE)->updateOrInsert(
            ['code' => $code],
            $payload,
        );

        return DB::table(self::COVERAGE_TABLE)
            ->where('code', $code)
            ->first();
    }

    private function createSubBranchAllocations(
        object $mainBranch,
        object $mainAllocation,
    ): void {
        $subBranches = DB::table('branches')
            ->where('parent_id', $mainBranch->id)
            ->where('type', 'sub_branch')
            ->where(function ($query) {
                $query
                    ->where('status', 'active')
                    ->orWhere('is_active', true);
            })
            ->orderBy('id')
            ->get();

        foreach ($subBranches as $index => $subBranch) {
            $code = $this->makeAllocationCode(
                prefix: 'SUB',
                sourceCode: $subBranch->code ?? null,
                sourceName: $subBranch->name ?? 'SUB-BRANCH',
            );

            $existing = DB::table(self::COVERAGE_TABLE)
                ->where('code', $code)
                ->first();

            $coordinates = $this->resolveSubBranchCoordinates(
                subBranch: $subBranch,
                parentBranch: $mainBranch,
                offsetIndex: $index,
            );

            $payload = $this->filterColumns(self::COVERAGE_TABLE, [
                'parent_id' => $mainAllocation->id,

                'name' => $this->cleanBranchName($subBranch->name)
                    . ' Coverage Zone',

                'code' => $code,
                'type' => 'sub_branch_zone',

                'branch_id' => $subBranch->id,

                'country' => $subBranch->country
                    ?? $mainBranch->country
                    ?? 'Nepal',

                'province' => $subBranch->province
                    ?? $mainBranch->province
                    ?? null,

                'district' => $subBranch->district
                    ?? $mainBranch->district
                    ?? $subBranch->city
                    ?? null,

                'city' => $subBranch->city
                    ?? $mainBranch->city
                    ?? null,

                'area' => $subBranch->area ?? null,
                'address' => $subBranch->address ?? null,
                'landmark' => $subBranch->landmark ?? null,

                'latitude' => $coordinates['latitude'],
                'longitude' => $coordinates['longitude'],

                'coverage_radius_km' => 2,
                'status' => 'active',
                'is_active' => true,

                'created_at' => $existing?->created_at ?? now(),
                'updated_at' => now(),
            ]);

            DB::table(self::COVERAGE_TABLE)->updateOrInsert(
                ['code' => $code],
                $payload,
            );
        }
    }

    private function resolveBranchCoordinates(object $branch): array
    {
        if (
            isset($branch->latitude, $branch->longitude)
            && $branch->latitude !== null
            && $branch->longitude !== null
        ) {
            return [
                'latitude' => (float) $branch->latitude,
                'longitude' => (float) $branch->longitude,
            ];
        }

        return $this->coordinatesForLocation(
            city: $branch->city ?? null,
            area: $branch->area ?? null,
            name: $branch->name ?? null,
        );
    }

    private function resolveSubBranchCoordinates(
        object $subBranch,
        object $parentBranch,
        int $offsetIndex,
    ): array {
        if (
            isset($subBranch->latitude, $subBranch->longitude)
            && $subBranch->latitude !== null
            && $subBranch->longitude !== null
        ) {
            return [
                'latitude' => (float) $subBranch->latitude,
                'longitude' => (float) $subBranch->longitude,
            ];
        }

        $knownCoordinates = $this->coordinatesForLocation(
            city: $subBranch->city ?? null,
            area: $subBranch->area ?? null,
            name: $subBranch->name ?? null,
        );

        if (
            $knownCoordinates['latitude'] !== null
            && $knownCoordinates['longitude'] !== null
        ) {
            return $knownCoordinates;
        }

        $parentCoordinates = $this->resolveBranchCoordinates($parentBranch);

        if (
            $parentCoordinates['latitude'] === null
            || $parentCoordinates['longitude'] === null
        ) {
            return [
                'latitude' => null,
                'longitude' => null,
            ];
        }

        /*
         * Development fallback only.
         *
         * It separates sub-branch map markers slightly from their parent.
         * Replace these generated positions with actual office coordinates
         * before using them in production routing or distance calculations.
         */
        $offset = (($offsetIndex % 10) + 1) * 0.0015;

        return [
            'latitude' => round(
                $parentCoordinates['latitude'] + $offset,
                7,
            ),

            'longitude' => round(
                $parentCoordinates['longitude'] + $offset,
                7,
            ),
        ];
    }

    private function coordinatesForLocation(
        ?string $city,
        ?string $area,
        ?string $name,
    ): array {
        $searchableText = Str::lower(
            implode(' ', array_filter([
                $city,
                $area,
                $name,
            ]))
        );

        $locations = [
            'kathmandu' => [27.7172, 85.3240],
            'bhaktapur' => [27.6710, 85.4298],
            'lalitpur' => [27.6588, 85.3247],
            'patan' => [27.6588, 85.3247],

            'pokhara' => [28.2096, 83.9856],
            'kaski' => [28.2096, 83.9856],

            'biratnagar' => [26.4525, 87.2718],
            'morang' => [26.4525, 87.2718],

            'itahari' => [26.6631, 87.2749],
            'sunsari' => [26.6270, 87.1572],

            'birtamode' => [26.6434, 87.9891],
            'jhapa' => [26.5455, 87.8942],

            'damak' => [26.6588, 87.7015],
            'dharan' => [26.8125, 87.2835],

            'birgunj' => [27.0104, 84.8774],
            'parsa' => [27.0104, 84.8774],

            'chitwan' => [27.5291, 84.3542],
            'bharatpur' => [27.6766, 84.4359],

            'butwal' => [27.7006, 83.4484],
            'rupandehi' => [27.5000, 83.4500],

            'bhairahawa' => [27.5057, 83.4163],
            'siddharthanagar' => [27.5057, 83.4163],

            'hetauda' => [27.4287, 85.0322],
            'makwanpur' => [27.4287, 85.0322],

            'janakpur' => [26.7288, 85.9258],
            'dhanusha' => [26.7288, 85.9258],

            'nepalgunj' => [28.0500, 81.6167],
            'banke' => [28.0500, 81.6167],

            'dhangadhi' => [28.6950, 80.5930],
            'kailali' => [28.6950, 80.5930],

            'dhading' => [27.9711, 84.8985],
            'banepa' => [27.6325, 85.5216],
            'kavrepalanchok' => [27.5450, 85.5218],

            'mahendranagar' => [28.9636, 80.1772],
            'kanchanpur' => [28.9636, 80.1772],

            'birendranagar' => [28.6019, 81.6339],
            'surkhet' => [28.6019, 81.6339],

            'dharan' => [26.8125, 87.2835],
            'dhankuta' => [26.9833, 87.3333],

            'lahan' => [26.7202, 86.4820],
            'siraha' => [26.6542, 86.2075],

            'inaruwa' => [26.6067, 87.1478],
            'bardibas' => [26.9908, 85.8935],
            'mahottari' => [26.8560, 85.8062],

            'ilam' => [26.9110, 87.9282],
            'baglung' => [28.2713, 83.5898],
        ];

        foreach ($locations as $keyword => [$latitude, $longitude]) {
            if (str_contains($searchableText, $keyword)) {
                return [
                    'latitude' => $latitude,
                    'longitude' => $longitude,
                ];
            }
        }

        return [
            'latitude' => null,
            'longitude' => null,
        ];
    }

    private function makeAllocationCode(
        string $prefix,
        ?string $sourceCode,
        string $sourceName,
    ): string {
        $baseCode = $sourceCode
            ? Str::upper($sourceCode)
            : Str::upper(
                Str::substr(
                    Str::slug($sourceName, ''),
                    0,
                    15,
                )
            );

        return $prefix . '-' . $baseCode . '-ZONE';
    }

    private function cleanBranchName(?string $name): string
    {
        return trim(
            preg_replace(
                '/\s+(Main Branch|Branch|Sub-Branch)$/i',
                '',
                (string) $name,
            )
        );
    }

    private function filterColumns(string $table, array $data): array
    {
        return collect($data)
            ->filter(
                fn ($value, string $column) =>
                    Schema::hasColumn($table, $column)
            )
            ->toArray();
    }
}