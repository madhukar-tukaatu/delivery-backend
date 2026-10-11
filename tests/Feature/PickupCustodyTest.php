<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Support\CourierStatus;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Modules\Delivery\Services\DeliveryRunService;
use Modules\Dispatch\Models\DispatchManifest;
use Modules\Dispatch\Models\DispatchManifestItem;
use Modules\Dispatch\Services\TransferContainerService;
use Modules\Pickup\Models\PickupRequest;
use Modules\Pickup\Support\PickupCustody;
use Modules\Pickup\Support\PickupStatus;
use Modules\Shipment\Models\Shipment;
use Tests\TestCase;

/**
 * PR-000025 regression: picked-up parcels of a pickup the branch had not yet
 * received were offered on Transfers Outbound, went out on a TR ("in transit"),
 * and the pickup could then never be received or completed.
 *
 * Fixture route KTM (19) -> Bharatpur (25) -> Birendranagar (30), route 174.
 */
final class PickupCustodyTest extends TestCase
{
    use DatabaseTransactions;

    private const KTM = 19;
    private const HOP = 25;
    private const FINAL = 30;
    private const ROUTE_VIA = 174;

    private User $admin;
    private TransferContainerService $containers;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        $this->admin = User::query()->where('role', 'super_admin')->orderBy('id')->firstOrFail();
        $this->containers = $this->app->make(TransferContainerService::class);
        $this->actingAs($this->admin, 'sanctum');
    }

    /** @return array{0: PickupRequest, 1: list<Shipment>} */
    private function pickupWith(int $parcels, string $pickupStatus = PickupStatus::ON_WAY_TO_BRANCH): array
    {
        $merchantId = (int) DB::table('merchants')->orderBy('id')->value('id');
        $pickupId = DB::table('pickup_requests')->insertGetId([
            'request_number' => 'PR-T'.strtoupper(substr(uniqid(), -7)).random_int(10, 99),
            'merchant_id' => $merchantId,
            'branch_id' => self::KTM,
            'pickup_branch_id' => self::KTM,
            'assigned_to' => $this->admin->id,
            'pickup_name' => 'Custody Test Store',
            'pickup_phone' => '9800000000',
            'pickup_address' => 'Test',
            'parcel_quantity' => $parcels,
            'status' => $pickupStatus,
            'requested_at' => now(),
            'picked_up_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $shipments = [];
        for ($i = 0; $i < $parcels; $i++) {
            $sid = DB::table('shipments')->insertGetId([
                'tracking_number' => 'TST-PC-'.strtoupper(uniqid()).random_int(10, 99),
                'merchant_id' => $merchantId,
                'service_type' => 'standard',
                'status' => CourierStatus::PICKED_UP,
                'merchant_status' => CourierStatus::merchantStatus(CourierStatus::PICKED_UP),
                'origin_branch_id' => self::KTM,
                'destination_branch_id' => self::FINAL,
                'current_branch_id' => null,
                'transfer_route_id' => self::ROUTE_VIA,
                'weight' => 1,
                'receiver_name' => 'Pickup Custody',
                'receiver_phone' => '9800000000',
                'delivery_address' => 'Test Address',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('pickup_request_shipments')->insert([
                'pickup_request_id' => $pickupId,
                'shipment_id' => $sid,
                'added_at' => now(),
                'status' => 'pending',
                'collection_status' => 'pending',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $shipments[] = Shipment::query()->findOrFail($sid);
        }

        return [PickupRequest::query()->findOrFail($pickupId), $shipments];
    }

    /** Re-create the PR-000025 state: the parcel left on a TR while its pickup line stayed pending. */
    private function sendOnTrBehindPickupsBack(PickupRequest $pickup, Shipment $shipment): DispatchManifest
    {
        $line = DB::table('pickup_request_shipments')->where('pickup_request_id', $pickup->id)->where('shipment_id', $shipment->id);
        $line->update(['status' => 'received']);
        $out = $this->containers->dispatch(collect([$shipment->fresh()]), self::HOP, self::KTM, [], $this->admin->id);
        $this->assertSame([], $out['skipped']);
        $line->update(['status' => 'pending']);
        $this->assertSame(CourierStatus::IN_TRANSIT, $shipment->fresh()->status);

        return $out['manifests'][0];
    }

    private function outboundIds(): array
    {
        $resp = $this->getJson('/api/v1/admin/transfers?direction=outbound&per_page=100&branch_id='.self::KTM);
        $resp->assertOk();

        return collect($resp->json('data.data'))->pluck('id')->map(fn ($v) => (int) $v)->all();
    }

    public function test_unreceived_pickup_parcel_is_not_outbound_and_cannot_go_on_a_tr(): void
    {
        [$pickup, [$s]] = $this->pickupWith(1);

        $this->assertTrue(PickupCustody::isAwaitingBranchReceive($s));
        $this->assertNotContains($s->id, $this->outboundIds());

        // Service level: dispatch skips it with a clear reason.
        $out = $this->containers->dispatch(collect([$s]), self::HOP, self::KTM, [], $this->admin->id);
        $this->assertSame([], $out['manifests']);
        $this->assertStringContainsString($pickup->request_number, $out['skipped'][$s->id]);
        $this->assertSame(CourierStatus::PICKED_UP, $s->fresh()->status);

        // API dispatch is refused.
        $this->postJson('/api/v1/admin/transfers/dispatch', [
            'shipment_ids' => [$s->id], 'next_hop_branch_id' => self::HOP, 'branch_id' => self::KTM,
        ])->assertStatus(422);

        // Open TR: cannot be added either.
        $open = $this->containers->dispatch(collect([$this->plainReadyParcel()]), self::HOP, self::KTM, ['hold' => true], $this->admin->id)['manifests'][0];
        $res = $this->containers->addToOpen($open, [$s], $this->admin->id);
        $this->assertSame([], $res['added']);
        $this->assertStringContainsString($pickup->request_number, (string) collect($res['skipped'])->flatten()->first());

        // DR runs never see it.
        $this->assertFalse($this->app->make(DeliveryRunService::class)->readyQuery(self::KTM)->whereKey($s->id)->exists());

        // Once the branch receives it on the pickup it is outbound.
        $this->postJson("/api/v1/admin/pickups/{$pickup->id}/shipments/{$s->id}/receive")->assertOk();
        $this->assertSame(CourierStatus::SORTED_FOR_TRANSFER, $s->fresh()->status);
        $this->assertContains($s->id, $this->outboundIds());
    }

    private function plainReadyParcel(): Shipment
    {
        $merchantId = (int) DB::table('merchants')->orderBy('id')->value('id');
        $id = DB::table('shipments')->insertGetId([
            'tracking_number' => 'TST-PC-'.strtoupper(uniqid()).random_int(10, 99),
            'merchant_id' => $merchantId,
            'service_type' => 'standard',
            'status' => CourierStatus::SORTED_FOR_TRANSFER,
            'merchant_status' => CourierStatus::merchantStatus(CourierStatus::SORTED_FOR_TRANSFER),
            'origin_branch_id' => self::KTM,
            'destination_branch_id' => self::FINAL,
            'current_branch_id' => self::KTM,
            'transfer_route_id' => self::ROUTE_VIA,
            'weight' => 1,
            'receiver_name' => 'Ready',
            'receiver_phone' => '9800000000',
            'delivery_address' => 'Test Address',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Shipment::query()->findOrFail($id);
    }

    public function test_branch_receives_all_and_pickup_completes_even_if_rider_never_started_transit(): void
    {
        [$pickup, $parcels] = $this->pickupWith(2, PickupStatus::COLLECTED);

        foreach ($parcels as $s) {
            $this->postJson("/api/v1/admin/pickups/{$pickup->id}/shipments/{$s->id}/receive")->assertOk();
            $this->assertSame(CourierStatus::SORTED_FOR_TRANSFER, $s->fresh()->status);
        }

        $this->assertSame(PickupStatus::COMPLETED, $pickup->fresh()->status);
        $this->assertSame(2, DB::table('pickup_request_shipments')->where('pickup_request_id', $pickup->id)->where('status', 'received')->count());
    }

    public function test_parcel_already_on_a_tr_is_shown_as_left_not_receivable_and_reconcile_completes_pickup(): void
    {
        [$pickup, [$gone, $here]] = $this->pickupWith(2);
        $tr = $this->sendOnTrBehindPickupsBack($pickup, $gone);

        // Detail shows where each parcel really is.
        $show = $this->getJson("/api/v1/admin/pickups/{$pickup->id}")->assertOk();
        $states = collect($show->json('data.shipments'))->keyBy('id');
        $this->assertSame('left_on_tr', $states[$gone->id]['pickup_state']['state']);
        $this->assertFalse($states[$gone->id]['pickup_state']['can_receive']);
        $this->assertSame($tr->display_number, $states[$gone->id]['pickup_state']['tr']['number']);
        $this->assertSame('with_rider', $states[$here->id]['pickup_state']['state']);
        $this->assertTrue($states[$here->id]['pickup_state']['can_receive']);

        // Preview changes nothing.
        $preview = $this->postJson("/api/v1/admin/pickups/{$pickup->id}/reconcile")->assertOk();
        $this->assertFalse($preview->json('data.applied'));
        $this->assertSame(1, $preview->json('data.changes'));
        $this->assertSame('pending', DB::table('pickup_request_shipments')->where('shipment_id', $gone->id)->value('status'));

        // Apply: the line is closed, the parcel stays on its TR untouched.
        $apply = $this->postJson("/api/v1/admin/pickups/{$pickup->id}/reconcile", ['apply' => true])->assertOk();
        $this->assertTrue($apply->json('data.applied'));
        $this->assertSame('received', DB::table('pickup_request_shipments')->where('shipment_id', $gone->id)->value('status'));
        $this->assertSame(CourierStatus::IN_TRANSIT, $gone->fresh()->status);
        $this->assertSame('sent', DispatchManifestItem::query()->where('dispatch_manifest_id', $tr->id)->where('shipment_id', $gone->id)->value('status'));
        $this->assertTrue(DB::table('audit_logs')->where('action', 'pickups.reconcile')->where('auditable_id', $pickup->id)->exists());
        $this->assertSame(PickupStatus::ON_WAY_TO_BRANCH, $pickup->fresh()->status, 'Still waiting for the parcel the rider has');

        // Branch receives the last one -> pickup completes.
        $this->postJson("/api/v1/admin/pickups/{$pickup->id}/shipments/{$here->id}/receive")->assertOk();
        $this->assertSame(PickupStatus::COMPLETED, $pickup->fresh()->status);
    }

    public function test_pressing_receive_on_a_parcel_that_already_left_closes_the_line_without_moving_it(): void
    {
        [$pickup, [$gone]] = $this->pickupWith(1);
        $this->sendOnTrBehindPickupsBack($pickup, $gone);

        $this->postJson("/api/v1/admin/pickups/{$pickup->id}/shipments/{$gone->id}/receive")->assertOk();

        $this->assertSame(CourierStatus::IN_TRANSIT, $gone->fresh()->status);
        $this->assertSame('received', DB::table('pickup_request_shipments')->where('shipment_id', $gone->id)->value('status'));
        $this->assertSame(PickupStatus::COMPLETED, $pickup->fresh()->status);
    }

    public function test_wrong_in_transit_without_tr_can_be_received_at_branch(): void
    {
        [$pickup, [$s]] = $this->pickupWith(1);
        DB::table('shipments')->where('id', $s->id)->update(['status' => CourierStatus::IN_TRANSIT]);

        $show = $this->getJson("/api/v1/admin/pickups/{$pickup->id}")->assertOk();
        $this->assertSame('wrong_in_transit', $show->json('data.shipments.0.pickup_state.state'));
        $this->assertTrue($show->json('data.shipments.0.pickup_state.can_receive'));

        $this->postJson("/api/v1/admin/pickups/{$pickup->id}/shipments/{$s->id}/receive")->assertOk();

        $this->assertSame(CourierStatus::SORTED_FOR_TRANSFER, $s->fresh()->status);
        $this->assertSame(self::KTM, (int) $s->fresh()->current_branch_id);
        $this->assertTrue(DB::table('tracking_events')->where('shipment_id', $s->id)->where('description', 'like', 'Status corrected%')->exists());
        $this->assertSame(PickupStatus::COMPLETED, $pickup->fresh()->status);
    }

    public function test_reconcile_resets_wrong_in_transit_and_takes_parcel_off_open_tr_then_artisan_preview_is_read_only(): void
    {
        [$pickup, [$wrong, $loaded]] = $this->pickupWith(2);
        DB::table('shipments')->where('id', $wrong->id)->update(['status' => CourierStatus::IN_TRANSIT]);

        // Parcel loaded on an OPEN TR before the fix (not dispatched yet).
        $open = $this->containers->dispatch(collect([$this->plainReadyParcel()]), self::HOP, self::KTM, ['hold' => true], $this->admin->id)['manifests'][0];
        DispatchManifestItem::query()->create(['dispatch_manifest_id' => $open->id, 'shipment_id' => $loaded->id, 'status' => 'added']);

        $this->assertSame(0, Artisan::call('pickups:reconcile', ['pickup' => $pickup->request_number]));
        $this->assertSame(CourierStatus::IN_TRANSIT, $wrong->fresh()->status, 'Preview must not write');

        $this->assertSame(0, Artisan::call('pickups:reconcile', ['pickup' => $pickup->request_number, '--apply' => true]));
        $this->assertSame(CourierStatus::PICKED_UP, $wrong->fresh()->status);
        $this->assertSame('cancelled', DispatchManifestItem::query()->where('dispatch_manifest_id', $open->id)->where('shipment_id', $loaded->id)->value('status'));
        $this->assertSame(PickupStatus::ON_WAY_TO_BRANCH, $pickup->fresh()->status);

        // Both are now normal "with rider" parcels the branch can receive.
        foreach ([$wrong, $loaded] as $s) {
            $this->postJson("/api/v1/admin/pickups/{$pickup->id}/shipments/{$s->id}/receive")->assertOk();
        }
        $this->assertSame(PickupStatus::COMPLETED, $pickup->fresh()->status);
    }

    public function test_reconcile_with_receive_option_receives_corrected_parcels(): void
    {
        [$pickup, [$s]] = $this->pickupWith(1);
        DB::table('shipments')->where('id', $s->id)->update(['status' => CourierStatus::IN_TRANSIT]);

        $resp = $this->postJson("/api/v1/admin/pickups/{$pickup->id}/reconcile", ['apply' => true, 'receive_corrected' => true])->assertOk();

        $this->assertSame([$s->id], $resp->json('data.received'));
        $this->assertSame(CourierStatus::SORTED_FOR_TRANSFER, $s->fresh()->status);
        $this->assertSame(PickupStatus::COMPLETED, $resp->json('data.status_after'));
    }
}