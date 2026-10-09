<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * DELETE /api/v1/admin/rate/branch-transfer-routes/{id} is a real delete when
 * the route is unused, and is blocked (422 + usage) when referenced.
 * Disable/enable stays on PATCH /{id}/status.
 */
final class BranchTransferRouteDeleteTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;
    private int $laneId;

    protected function setUp(): void
    {
        parent::setUp();

        $lane = DB::table('branch_transfer_lanes')
            ->whereColumn('from_branch_id', '!=', 'to_branch_id')
            ->orderBy('id')
            ->first();
        $user = $this->findAdmin();

        if (! $lane || ! $user) {
            $this->markTestSkipped('Needs at least one lane and an admin user with transfer route permissions.');
        }

        $this->admin = $user;
        $this->laneId = (int) $lane->id;
    }

    private function findAdmin(): ?User
    {
        foreach (User::query()->orderBy('id')->limit(50)->get() as $u) {
            if (method_exists($u, 'hasPermissionTo')) {
                try {
                    if ($u->hasPermissionTo('pricing.transfer_routes.delete')
                        && $u->hasPermissionTo('pricing.transfer_routes.status')) {
                        return $u;
                    }
                } catch (\Throwable) {
                    // permission not registered for this guard
                }
            }
            if (method_exists($u, 'hasRole') && $u->hasRole(['super-admin', 'super_admin', 'Super Admin', 'admin'])) {
                return $u;
            }
        }

        return null;
    }

    private function makeRoute(): int
    {
        $lane = DB::table('branch_transfer_lanes')->find($this->laneId);
        $code = 'TST-DEL-' . strtoupper(substr(uniqid(), -8));

        $id = (int) DB::table('branch_transfer_routes')->insertGetId([
            'route_code'              => $code,
            'name'                    => 'Delete test ' . $code,
            'branch_transfer_lane_id' => $this->laneId,
            'origin_branch_id'        => $lane->from_branch_id,
            'destination_branch_id'   => $lane->to_branch_id,
            'service_type'            => $lane->service_type,
            'base_rate'               => 0,
            'currency'                => 'NPR',
            'distance_km'             => $lane->distance_km,
            'estimated_hours'         => 1,
            'transfer_count'          => 0,
            'transit_count'           => 0,
            'total_distance_km'       => 0,
            'total_estimated_hours'   => 0,
            'priority'                => 999,
            'is_default'              => 0,
            'is_active'               => 1,
            'created_at'              => now(),
            'updated_at'              => now(),
        ]);

        DB::table('branch_transfer_route_lanes')->insert([
            'branch_transfer_route_id' => $id,
            'branch_transfer_lane_id'  => $this->laneId,
            'sequence_number'          => 1,
            'created_at'               => now(),
            'updated_at'               => now(),
        ]);

        return $id;
    }

    public function test_unreferenced_route_is_hard_deleted_with_lane_rows(): void
    {
        $id = $this->makeRoute();

        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/v1/admin/rate/branch-transfer-routes/{$id}")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.deleted', true);

        $this->assertDatabaseMissing('branch_transfer_routes', ['id' => $id]);
        $this->assertSame(0, DB::table('branch_transfer_route_lanes')->where('branch_transfer_route_id', $id)->count());
    }

    public function test_referenced_route_is_blocked_with_usage(): void
    {
        $id = $this->makeRoute();
        $shipmentId = DB::table('shipments')->orderBy('id')->value('id');
        if (! $shipmentId) {
            $this->markTestSkipped('Needs a shipment row.');
        }
        DB::table('shipments')->where('id', $shipmentId)->update(['transfer_route_id' => $id]);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/v1/admin/rate/branch-transfer-routes/{$id}")
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('data.usage.shipments', 1)
            ->assertJsonPath('data.can_disable', true);

        $this->assertStringContainsString('Used by 1 shipment', (string) $response->json('message'));
        $this->assertStringContainsString('disable it instead', (string) $response->json('message'));
        $this->assertDatabaseHas('branch_transfer_routes', ['id' => $id, 'is_active' => 1]);
        $this->assertSame(1, DB::table('branch_transfer_route_lanes')->where('branch_transfer_route_id', $id)->count());
    }

    public function test_disable_and_enable_still_work(): void
    {
        $id = $this->makeRoute();

        $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/v1/admin/rate/branch-transfer-routes/{$id}/status", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);
        $this->assertDatabaseHas('branch_transfer_routes', ['id' => $id, 'is_active' => 0]);

        $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/v1/admin/rate/branch-transfer-routes/{$id}/status", ['is_active' => true])
            ->assertOk()
            ->assertJsonPath('data.is_active', true);
        $this->assertDatabaseHas('branch_transfer_routes', ['id' => $id, 'is_active' => 1]);
    }

    public function test_deleting_default_route_promotes_another(): void
    {
        $a = $this->makeRoute();
        $b = $this->makeRoute();
        DB::table('branch_transfer_routes')->where('id', $a)->update(['is_default' => 1, 'priority' => 1]);

        $lane = DB::table('branch_transfer_lanes')->find($this->laneId);
        // Only our two test routes compete for the default in this check.
        DB::table('branch_transfer_routes')
            ->where('origin_branch_id', $lane->from_branch_id)
            ->where('destination_branch_id', $lane->to_branch_id)
            ->where('service_type', $lane->service_type)
            ->whereNotIn('id', [$a, $b])
            ->update(['is_active' => 0]);

        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/v1/admin/rate/branch-transfer-routes/{$a}")
            ->assertOk();

        $this->assertDatabaseHas('branch_transfer_routes', ['id' => $b, 'is_default' => 1]);
    }
}