<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\CourierStatus;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Validation\ValidationException;
use Modules\Dispatch\Models\DispatchManifest;
use Modules\Dispatch\Models\DispatchManifestItem;
use Modules\Rate\Models\BranchTransferRoute;
use Modules\Shipment\Models\Shipment;
use Modules\Shipment\Services\ShipmentSortingService;
use Modules\Shipment\Services\TransferRouteProgressService;
use Modules\Shipment\Services\TransferService;
use Tests\TestCase;

/**
 * Six multi-hop transfer scenarios for KTM → Bharatpur → Birendranagar (route 174).
 *
 * Branch IDs (franchise): KTM=19, Bharatpur=25, Birendranagar=30
 * Coverage IDs on route path: 1 → 35 → 63
 */
final class TransferMultiHopTest extends TestCase
{
    use DatabaseTransactions;

    private const ROUTE_ID = 174;
    private const KTM = 19;
    private const BHARATPUR = 25;
    private const BIRENDRANAGAR = 30;

    private TransferRouteProgressService $progress;

    protected function setUp(): void
    {
        parent::setUp();
        $this->progress = $this->app->make(TransferRouteProgressService::class);

        $route = BranchTransferRoute::query()->find(self::ROUTE_ID);
        $this->assertNotNull($route, 'Fixture route 174 (KTM-BHA-BRN-STANDARD) must exist');
        $this->assertTrue((bool) $route->is_active);
    }

    private function makeShipment(array $overrides = []): Shipment
    {
        $tracking = 'TST-MH-' . uniqid();
        $merchantId = \Illuminate\Support\Facades\DB::table('merchants')->orderBy('id')->value('id');

        $id = \Illuminate\Support\Facades\DB::table('shipments')->insertGetId(array_merge([
            'tracking_number' => $tracking,
            'merchant_id' => $merchantId,
            'service_type' => 'standard',
            'status' => CourierStatus::SORTED_FOR_TRANSFER,
            'merchant_status' => CourierStatus::merchantStatus(CourierStatus::SORTED_FOR_TRANSFER),
            'origin_branch_id' => self::KTM,
            'destination_branch_id' => self::BIRENDRANAGAR,
            'current_branch_id' => self::KTM,
            'transfer_route_id' => self::ROUTE_ID,
            'receiver_name' => 'Test Receiver',
            'receiver_phone' => '9800000000',
            'delivery_address' => 'Test Address, Kathmandu',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));

        return Shipment::query()->findOrFail($id);
    }

    /** Scenario 1: At KTM on route 174, next hop is Bharatpur (not Birendranagar). */
    public function test_scenario1_next_hop_from_ktm_is_bharatpur(): void
    {
        $shipment = $this->makeShipment();
        $p = $this->progress->resolveForShipment($shipment, self::KTM);

        $this->assertTrue($p['has_route']);
        $this->assertSame(self::ROUTE_ID, $p['transfer_route_id']);
        $this->assertSame(self::BHARATPUR, (int) $p['next_hop_branch_id']);
        $this->assertNotSame(self::BIRENDRANAGAR, (int) $p['next_hop_branch_id']);
        $this->assertFalse($p['ready_for_last_mile']);
        $this->assertContains(self::BHARATPUR, $p['remaining_path_branch_ids']);
        $this->assertContains(self::BIRENDRANAGAR, $p['remaining_path_branch_ids']);
    }

    /** Scenario 2: Next-hop grouping — Bharatpur bag includes final Bharatpur AND via-to-Birendranagar. */
    public function test_scenario2_next_hop_group_includes_multiple_finals(): void
    {
        $viaBiren = $this->makeShipment([
            'destination_branch_id' => self::BIRENDRANAGAR,
            'tracking_number' => 'TST-MH-VIA-' . uniqid(),
        ]);
        $finalBha = $this->makeShipment([
            'destination_branch_id' => self::BHARATPUR,
            'transfer_route_id' => 126,
            'tracking_number' => 'TST-MH-FIN-' . uniqid(),
        ]);

        // Assign / resolve a KTM→Bharatpur standard route if present; otherwise progress
        // still yields Bharatpur as next hop when current!=destination for a 1-leg match.
        $pVia = $this->progress->resolveForShipment($viaBiren, self::KTM);
        $pFin = $this->progress->resolveForShipment($finalBha, self::KTM);

        $this->assertSame(self::BHARATPUR, (int) $pVia['next_hop_branch_id']);
        $this->assertSame(self::BHARATPUR, (int) $pFin['next_hop_branch_id'],
            'Final-Bharatpur parcel from KTM should also next-hop to Bharatpur');

        // Simulate hub-bag key grouping used by getOutboundGroupedByNextHop
        $groups = [];
        foreach ([$viaBiren, $finalBha] as $s) {
            $p = $this->progress->resolveForShipment($s, self::KTM);
            $key = (int) $p['next_hop_branch_id'] . '|' . ($p['service_type'] ?? 'standard');
            $groups[$key]['finals'][(int) $s->destination_branch_id] = true;
            $groups[$key]['count'] = ($groups[$key]['count'] ?? 0) + 1;
        }

        $bagKey = self::BHARATPUR . '|standard';
        $this->assertArrayHasKey($bagKey, $groups);
        $this->assertSame(2, $groups[$bagKey]['count']);
        $this->assertArrayHasKey(self::BHARATPUR, $groups[$bagKey]['finals']);
        $this->assertArrayHasKey(self::BIRENDRANAGAR, $groups[$bagKey]['finals']);
    }

    /** Scenario 3: Backend rejects skip-hop (KTM → Birendranagar when next is Bharatpur). */
    public function test_scenario3_reject_skip_hop_to_birendranagar(): void
    {
        $shipment = $this->makeShipment();

        try {
            $this->progress->assertNextHopMatches($shipment, self::BIRENDRANAGAR, self::KTM);
            $this->fail('Expected ValidationException for skip-hop');
        } catch (ValidationException $e) {
            $flat = collect($e->errors())->flatten()->implode(' ');
            $this->assertStringContainsStringIgnoringCase('skip-hop', $flat);
            $this->assertStringContainsString((string) self::BHARATPUR, $flat);
        }

        // Correct next hop must pass
        $this->progress->assertNextHopMatches($shipment, self::BHARATPUR, self::KTM);
        $this->assertTrue(true);
    }

    /** Scenario 4: Receive at Bharatpur transit → recalculate next hop → sorted_for_transfer. */
    public function test_scenario4_transit_receive_recalculates_next_hop(): void
    {
        $shipment = $this->makeShipment([
            'status' => CourierStatus::IN_TRANSIT,
            'merchant_status' => CourierStatus::merchantStatus(CourierStatus::IN_TRANSIT),
            'current_branch_id' => null,
            'next_hop_branch_id' => self::BHARATPUR,
        ]);

        // Open manifest KTM → Bharatpur so isExpectedNextHop / mark received work
        $manifest = DispatchManifest::query()->create([
            'manifest_number' => 'MF-TEST-' . uniqid(),
            'from_branch_id' => self::KTM,
            'to_branch_id' => self::BHARATPUR,
            'status' => 'dispatched',
            'dispatched_at' => now(),
            'final_destination_branch_id' => self::BIRENDRANAGAR,
            'is_multi_hop' => true,
            'route_id' => self::ROUTE_ID,
            'route_code' => 'KTM-BHA-BRN-STANDARD',
        ]);
        DispatchManifestItem::query()->create([
            'dispatch_manifest_id' => $manifest->id,
            'shipment_id' => $shipment->id,
            'status' => 'sent',
        ]);

        // Simulate transit receive: set current branch, apply progress, sort
        $shipment->update([
            'status' => CourierStatus::RECEIVED_AT_TRANSIT_HUB,
            'merchant_status' => CourierStatus::merchantStatus(CourierStatus::RECEIVED_AT_TRANSIT_HUB),
            'current_branch_id' => self::BHARATPUR,
        ]);
        $this->progress->applyProgressToShipment($shipment->fresh(), self::BHARATPUR);

        $after = $shipment->fresh();
        $this->assertSame(self::BHARATPUR, (int) $after->current_branch_id);
        $this->assertSame(self::BIRENDRANAGAR, (int) $after->destination_branch_id,
            'Final destination must not be overwritten at transit');
        $this->assertSame(self::BIRENDRANAGAR, (int) $after->next_hop_branch_id);

        $sorted = $this->app->make(ShipmentSortingService::class)->sort($after, null);
        $this->assertSame(CourierStatus::SORTED_FOR_TRANSFER, $sorted->status);
        $this->assertSame(self::BIRENDRANAGAR, (int) $sorted->next_hop_branch_id);
    }

    /** Scenario 5: Receive at final destination → last-mile (sorted_for_delivery). */
    public function test_scenario5_final_receive_goes_to_last_mile(): void
    {
        $shipment = $this->makeShipment([
            'status' => CourierStatus::IN_TRANSIT,
            'merchant_status' => CourierStatus::merchantStatus(CourierStatus::IN_TRANSIT),
            'current_branch_id' => null,
            'next_hop_branch_id' => self::BIRENDRANAGAR,
            'origin_branch_id' => self::KTM,
            'destination_branch_id' => self::BIRENDRANAGAR,
        ]);

        $received = $this->app->make(TransferService::class)
            ->receiveAtDestination($shipment, null);

        $this->assertSame(self::BIRENDRANAGAR, (int) $received->current_branch_id);
        $this->assertSame(CourierStatus::SORTED_FOR_DELIVERY, $received->status);
        $this->assertTrue(
            $received->next_hop_branch_id === null || (int) $received->next_hop_branch_id === 0
        );
    }

    /** Scenario 6: Shipment cannot be on two active manifests. */
    public function test_scenario6_prevent_duplicate_active_manifest(): void
    {
        $shipment = $this->makeShipment([
            'status' => CourierStatus::IN_TRANSIT,
            'current_branch_id' => null,
            'next_hop_branch_id' => self::BHARATPUR,
        ]);

        $manifest = DispatchManifest::query()->create([
            'manifest_number' => 'MF-DUP-' . uniqid(),
            'from_branch_id' => self::KTM,
            'to_branch_id' => self::BHARATPUR,
            'status' => 'dispatched',
            'dispatched_at' => now(),
        ]);
        DispatchManifestItem::query()->create([
            'dispatch_manifest_id' => $manifest->id,
            'shipment_id' => $shipment->id,
            'status' => 'sent',
        ]);

        try {
            $this->progress->assertNotInActiveManifest($shipment->fresh());
            $this->fail('Expected ValidationException for duplicate active manifest');
        } catch (ValidationException $e) {
            $flat = collect($e->errors())->flatten()->implode(' ');
            $this->assertStringContainsStringIgnoringCase('active transfer manifest', $flat);
        }
    }


    /** Scenario 7: Sorted with next_hop stuck on final; after route match, next becomes transit hub. */
    public function test_scenario7_route_assigned_after_sort_recalculates_next_hop(): void
    {
        // Simulate "route added after sort": next_hop wrongly persisted as final destination.
        $shipment = $this->makeShipment([
            'transfer_route_id' => null,
            'next_hop_branch_id' => self::BIRENDRANAGAR,
            'path_text' => null,
            'route_code' => null,
        ]);

        $this->assertSame(self::BIRENDRANAGAR, (int) $shipment->next_hop_branch_id);

        // Matching assigned route (as outbound board / reconcile does) must move next hop to Bharatpur.
        $shipment->update(['transfer_route_id' => self::ROUTE_ID]);
        $this->progress->applyProgressToShipment($shipment->fresh(), self::KTM);

        $after = $shipment->fresh();
        $this->assertSame(self::BHARATPUR, (int) $after->next_hop_branch_id);
        $this->assertNotSame(self::BIRENDRANAGAR, (int) $after->next_hop_branch_id);
        $this->assertSame(self::ROUTE_ID, (int) $after->transfer_route_id);

        $skips = $this->progress->nextHopSkipsPath(
            self::BIRENDRANAGAR,
            [self::KTM, self::BHARATPUR, self::BIRENDRANAGAR],
            self::KTM,
            self::BIRENDRANAGAR,
        );
        $this->assertTrue($skips);
    }
}
