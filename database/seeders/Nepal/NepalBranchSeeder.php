<?php

namespace Database\Seeders\Nepal;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Branch\Models\Branch;

class NepalBranchSeeder extends Seeder
{
    public function run(): void
    {
        $main = Branch::firstOrCreate(
            ['code' => 'HO'],
            [
                'name' => 'Kathmandu Main Branch',
                'type' => 'main_branch',
                'phone' => '01-5900000',
                'email' => 'head@courier.test',
                'city' => 'Kathmandu',
                'area' => 'Central',
                'address' => 'Kathmandu, Nepal',
                'status' => 'active',
            ]
        );

        $this->updateBranchExtraFields($main->id, [
            'latitude' => 27.7172,
            'longitude' => 85.3240,
            'is_active' => true,
            'status' => 'active',
        ]);

        DB::table('nepal_districts')
            ->orderBy('name')
            ->get()
            ->each(function ($district) use ($main) {
                $code = 'BR-' . Str::upper(Str::substr(Str::slug($district->name, ''), 0, 10));

                $shouldBeActive = $this->shouldBeActiveBranch($district->name, $district->headquarter);

                $branch = Branch::firstOrCreate(
                    ['code' => $code],
                    [
                        'parent_id' => $main->id,
                        'name' => $district->name . ' Branch',
                        'type' => 'branch',
                        'phone' => '98' . str_pad((string) $district->id, 8, '0', STR_PAD_LEFT),
                        'email' => Str::slug($district->name) . '.branch@courier.test',
                        'city' => $district->name,
                        'area' => $district->headquarter,
                        'address' => $district->headquarter . ', ' . $district->name,
                        'status' => $shouldBeActive ? 'active' : 'inactive',
                    ]
                );

                $this->updateBranchExtraFields($branch->id, array_merge(
                    [
                        'parent_id' => $main->id,
                        'name' => $district->name . ' Branch',
                        'type' => 'branch',
                        'city' => $district->name,
                        'area' => $district->headquarter,
                        'address' => $district->headquarter . ', ' . $district->name,
                        'status' => $shouldBeActive ? 'active' : 'inactive',
                        'is_active' => $shouldBeActive,
                    ],
                    $this->coordinatesForDistrict($district->name, $district->headquarter)
                ));
            });

        echo "Nepal branches seeded successfully.\n";
        echo "Active branches: " . DB::table('branches')->where('status', 'active')->count() . "\n";
        echo "Inactive branches: " . DB::table('branches')->where('status', 'inactive')->count() . "\n";
    }

    private function shouldBeActiveBranch(string $districtName, ?string $headquarter = null): bool
    {
        $district = Str::lower($districtName);
        $hq = Str::lower((string) $headquarter);

        $activeKeywords = [
            'kathmandu',
            'bhaktapur',
            'kaski',
            'pokhara',
            'morang',
            'biratnagar',
            'dhading',
            'bhading',
        ];

        foreach ($activeKeywords as $keyword) {
            if (str_contains($district, $keyword) || str_contains($hq, $keyword)) {
                return true;
            }
        }

        return false;
    }

    private function coordinatesForDistrict(string $districtName, ?string $headquarter = null): array
    {
        $district = Str::lower($districtName);
        $hq = Str::lower((string) $headquarter);

        if (str_contains($district, 'kathmandu') || str_contains($hq, 'kathmandu')) {
            return [
                'latitude' => 27.7172,
                'longitude' => 85.3240,
            ];
        }

        if (str_contains($district, 'bhaktapur') || str_contains($hq, 'bhaktapur')) {
            return [
                'latitude' => 27.6710,
                'longitude' => 85.4298,
            ];
        }

        if (str_contains($district, 'kaski') || str_contains($hq, 'pokhara')) {
            return [
                'latitude' => 28.2096,
                'longitude' => 83.9856,
            ];
        }

        if (str_contains($district, 'morang') || str_contains($hq, 'biratnagar')) {
            return [
                'latitude' => 26.4525,
                'longitude' => 87.2718,
            ];
        }

        if (str_contains($district, 'dhading') || str_contains($hq, 'dhading') || str_contains($district, 'bhading')) {
            return [
                'latitude' => 27.9711,
                'longitude' => 84.8985,
            ];
        }

        return [
            'latitude' => null,
            'longitude' => null,
        ];
    }

    private function updateBranchExtraFields(int $branchId, array $data): void
    {
        $cleanData = collect($data)
            ->filter(fn ($value, $column) => Schema::hasColumn('branches', $column))
            ->toArray();

        if (!empty($cleanData)) {
            $cleanData['updated_at'] = now();

            DB::table('branches')
                ->where('id', $branchId)
                ->update($cleanData);
        }
    }
}