<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Modules\Rate\Services\TransferLanePathFinder;
use Tests\TestCase;

/**
 * Against the local data set: KTM (coverage 1) -> Arghakhanchi (54) must be
 * suggested via Bharatpur (35) using lanes 52 + 500, even though there are
 * more than 500 lanes and lane 52 is one of the oldest.
 */
final class TransferLanePathSuggestionsTest extends TestCase
{
    use DatabaseTransactions;

    private function fixturePresent(): bool
    {
        return DB::table('branch_transfer_lanes')->where('id', 52)
            ->where('from_branch_id', 1)->where('to_branch_id', 35)->where('is_active', 1)->exists()
            && DB::table('branch_transfer_lanes')->where('id', 500)
                ->where('from_branch_id', 35)->where('to_branch_id', 54)->where('is_active', 1)->exists();
    }

    public function test_ktm_to_arghakhanchi_suggests_via_bharatpur(): void
    {
        if (! $this->fixturePresent()) {
            $this->markTestSkipped('Local fixture lanes 52 (KTM->Bharatpur) / 500 (Bharatpur->Arghakhanchi) not present.');
        }

        $paths = $this->app->make(TransferLanePathFinder::class)
            ->suggest(1, 54, 'standard');

        $chains = array_map(fn ($p) => $p['lane_ids'], $paths);
        $this->assertContains([52, 500], $chains);

        $via = array_values(array_filter($paths, fn ($p) => $p['lane_ids'] === [52, 500]))[0];
        $this->assertSame([1, 35, 54], $via['branch_ids']);
        $this->assertStringContainsString('Bharatpur', $via['branch_names'][1]);
        $this->assertStringContainsString('Bharatpur', (string) $via['lanes'][0]['to_branch']['name']);

        // Best 1-transit option (shortest, both hops have active routes).
        $oneTransit = array_values(array_filter($paths, fn ($p) => $p['transit_count'] === 1));
        $this->assertSame([52, 500], $oneTransit[0]['lane_ids']);
    }

    public function test_endpoint_returns_suggestions(): void
    {
        if (! $this->fixturePresent()) {
            $this->markTestSkipped('Local fixture lanes not present.');
        }

        $user = \App\Models\User::query()->orderBy('id')->first();
        if (! $user) {
            $this->markTestSkipped('No local user to authenticate.');
        }

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/admin/branch-transfer-lanes/path-suggestions?from_branch_id=1&to_branch_id=54&service_type=standard')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => [['lane_ids', 'branch_ids', 'branch_names', 'transit_count', 'total_distance_km', 'source', 'lanes']]]);

        $chains = array_map(fn ($p) => $p['lane_ids'], $response->json('data'));
        $this->assertContains([52, 500], $chains);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/admin/branch-transfer-lanes/path-suggestions?from_branch_id=1&to_branch_id=1')
            ->assertStatus(422);
    }
}