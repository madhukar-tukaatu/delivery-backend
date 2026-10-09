<?php

declare(strict_types=1);

namespace Tests\Unit;

use Modules\Rate\Services\TransferLanePathFinder;
use PHPUnit\Framework\TestCase;

/**
 * Route builder suggestions must include chains made of existing lanes, e.g.
 * Kathmandu (1) -> Bharatpur (35) -> Arghakhanchi (54), even when the older
 * KTM -> Bharatpur lane has a low id and there are hundreds of other lanes.
 */
final class TransferLanePathFinderTest extends TestCase
{
    private const KTM = 1;
    private const BHARATPUR = 35;
    private const ARGHAKHANCHI = 54;

    private static function lane(int $id, int $from, int $to, float $km): array
    {
        return [
            'id' => $id, 'from_branch_id' => $from, 'to_branch_id' => $to,
            'distance_km' => $km, 'estimated_hours' => 1,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function network(): array
    {
        $lanes = [
            self::lane(7, self::KTM, self::KTM, 0),             // self-loop noise
            self::lane(52, self::KTM, self::BHARATPUR, 150),     // old, low id
            self::lane(166, self::KTM, self::ARGHAKHANCHI, 337), // direct
            self::lane(500, self::BHARATPUR, self::ARGHAKHANCHI, 200),
            // Alternatives via other transits (longer).
            self::lane(601, self::KTM, 70, 200),
            self::lane(602, 70, self::ARGHAKHANCHI, 210),
            self::lane(603, self::KTM, 71, 230),
            self::lane(604, 71, self::ARGHAKHANCHI, 260),
            // 2-transit chain KTM -> 80 -> 81 -> AGK
            self::lane(701, self::KTM, 80, 50),
            self::lane(702, 80, 81, 50),
            self::lane(703, 81, self::ARGHAKHANCHI, 50),
        ];

        // Hundreds of unrelated lanes with higher ids (what pushed lane 52 out
        // of the newest-500 page in the admin UI).
        for ($i = 0; $i < 600; $i++) {
            $lanes[] = self::lane(1000 + $i, 200 + ($i % 40), 300 + ($i % 37), 10);
        }

        return $lanes;
    }

    public function test_includes_ktm_bharatpur_arghakhanchi_from_existing_lanes(): void
    {
        $paths = TransferLanePathFinder::findPaths(
            $this->network(), [], self::KTM, self::ARGHAKHANCHI,
        );

        $laneChains = array_map(fn ($p) => $p['lane_ids'], $paths);

        $this->assertContains([166], $laneChains, 'direct lane suggested');
        $this->assertContains([52, 500], $laneChains, 'KTM -> Bharatpur -> Arghakhanchi suggested');
        $this->assertContains([701, 702, 703], $laneChains, '2-transit chain suggested');

        $viaBharatpur = array_values(array_filter($paths, fn ($p) => $p['lane_ids'] === [52, 500]))[0];
        $this->assertSame([self::KTM, self::BHARATPUR, self::ARGHAKHANCHI], $viaBharatpur['branch_ids']);
        $this->assertSame(1, $viaBharatpur['transit_count']);
        $this->assertEquals(350.0, $viaBharatpur['total_distance_km']);

        // No self-loop lanes and no repeated branches.
        foreach ($paths as $p) {
            $this->assertNotContains(7, $p['lane_ids']);
            $this->assertSame(count($p['branch_ids']), count(array_unique($p['branch_ids'])));
        }
    }

    public function test_paths_with_existing_routes_rank_first(): void
    {
        $routes = [
            self::KTM . '-' . self::BHARATPUR => true,
            self::BHARATPUR . '-' . self::ARGHAKHANCHI => true,
        ];

        $paths = TransferLanePathFinder::findPaths(
            $this->network(), $routes, self::KTM, self::ARGHAKHANCHI,
        );

        $this->assertSame([52, 500], $paths[0]['lane_ids']);
        $this->assertSame('existing_routes', $paths[0]['source']);
        $this->assertTrue($paths[0]['all_hops_have_routes']);
        $this->assertSame('lanes', $paths[1]['source']);
    }

    public function test_direct_then_shortest_one_transit_without_routes(): void
    {
        $paths = TransferLanePathFinder::findPaths(
            $this->network(), [], self::KTM, self::ARGHAKHANCHI,
        );

        $this->assertSame([166], $paths[0]['lane_ids']);
        $this->assertSame([52, 500], $paths[1]['lane_ids']);
    }

    public function test_max_lanes_limits_transits(): void
    {
        $paths = TransferLanePathFinder::findPaths(
            $this->network(), [], self::KTM, self::ARGHAKHANCHI, 2,
        );

        foreach ($paths as $p) {
            $this->assertLessThanOrEqual(1, $p['transit_count']);
        }
        $this->assertNotContains([701, 702, 703], array_map(fn ($p) => $p['lane_ids'], $paths));
    }
}