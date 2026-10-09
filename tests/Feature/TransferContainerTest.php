<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Support\CourierStatus;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Modules\Dispatch\Models\DispatchManifest;
use Modules\Dispatch\Models\DispatchManifestItem;
use Modules\Dispatch\Services\TransferContainerService;
use Modules\Rate\Models\BranchTransferRoute;
use Modules\Shipment\Models\Shipment;
use Tests\TestCase;

/**
 * TR transfer containers on the fixture route KTM (19) -> Bharatpur (25) -> Birendranagar (30).
 *
 * Same shape as the ops example KTM -> Butwal -> Dhangadi: KTM sends ONE TR to
 * the next hop carrying both last-mile parcels for that hop and onward
 * parcels; the hop checks the TR in, last-mile parcels go to delivery and the
 * onward ones go out on the hop's next TR.
 */
final class TransferContainerTest extends TestCase
{
    use DatabaseTransactions;

    private const ROUTE_VIA = 174;   // KTM -> BHA -> BRN
    private const ROUTE_DIRECT = 126; // KTM -> BHA
    private const KTM = 19;
    private const HOP = 25;          // Bharatpur ("Butwal" in the ops example)
    private const FINAL = 30;        // Birendranagar ("Dhangadi")

    private TransferContainerService $svc;
    private int $actor;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        $this->svc = $this->app->make(TransferContainerService::class);
        $this->actor = (int) User::query()->where('role', 'super_admin')->orderBy('id')->value('id');
        $this->assertNotNull(BranchTransferRoute::query()->find(self::ROUTE_VIA), 'Fixture route 174 must exist');
        $this->assertNotNull(BranchTransferRoute::query()->find(self::ROUTE_DIRECT), 'Fixture route 126 must exist');
    }

    private function parcel(array $overrides = []): Shipment
    {
        $merchantId = DB::table('merchants')->orderBy('id')->value('id');
        $id = DB::table('shipments')->insertGetId(array_merge([
            'tracking_number' => 'TST-TR-'.strtoupper(uniqid()).random_int(10, 99),
            'merchant_id' => $merchantId,
            'service_type' => 'standard',
            'status' => CourierStatus::SORTED_FOR_TRANSFER,
            'merchant_status' => CourierStatus::merchantStatus(CourierStatus::SORTED_FOR_TRANSFER),
            'origin_branch_id' => self::KTM,
            'destination_branch_id' => self::FINAL,
            'current_branch_id' => self::KTM,
            'transfer_route_id' => self::ROUTE_VIA,
            'weight' => 1,
            'receiver_name' => 'TR Test',
            'receiver_phone' => '9800000000',
            'delivery_address' => 'Test Address',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));

        return Shipment::query()->findOrFail($id);
    }

    /** @return array{0: list<Shipment>, 1: list<Shipment>} [last-mile at hop, onward to final] */
    private function mixedBatch(int $lastMile = 4, int $onward = 2): array
    {
        $lm = [];
        for ($i = 0; $i < $lastMile; $i++) {
            $lm[] = $this->parcel(['destination_branch_id' => self::HOP, 'transfer_route_id' => self::ROUTE_DIRECT]);
        }
        $on = [];
        for ($i = 0; $i < $onward; $i++) {
            $on[] = $this->parcel();
        }

        return [$lm, $on];
    }

    private function dispatchFromKtm(array $shipments, array $meta = []): DispatchManifest
    {
        $out = $this->svc->dispatch(collect($shipments), self::HOP, self::KTM, $meta, $this->actor);
        $this->assertSame([], $out['skipped'], 'Nothing should be skipped: '.json_encode($out['skipped']));
        $this->assertCount(1, $out['manifests']);

        return $out['manifests'][0];
    }

    public function test_one_tr_for_last_mile_and_onward_with_cost_split(): void
    {
        [$lm, $on] = $this->mixedBatch(4, 2);

        $tr = $this->dispatchFromKtm(array_merge($lm, $on), [
            'vehicle_type' => 'van',
            'vehicle_number' => 'BA 1 PA 1234',
            'driver_name' => 'Ram',
            'transport_cost' => 600,
            'transport_cost_split_mode' => 'equal',
        ]);

        $this->assertMatchesRegularExpression('/^TR-\d{6}$/', (string) $tr->transfer_number);
        $this->assertSame('dispatched', $tr->status);
        $this->assertSame(self::KTM, (int) $tr->from_branch_id);
        $this->assertSame(self::HOP, (int) $tr->to_branch_id);
        $this->assertSame('van', $tr->vehicle_type);
        $this->assertSame(6, (int) $tr->expected_count);
        $this->assertEqualsWithDelta(600.0, (float) $tr->transport_cost, 0.001);

        $items = DispatchManifestItem::query()->where('dispatch_manifest_id', $tr->id)->get();
        $this->assertCount(6, $items);
        foreach ($items as $item) {
            $this->assertSame('sent', $item->status);
            $this->assertEqualsWithDelta(100.0, (float) $item->transport_cost, 0.001);
        }

        foreach (array_merge($lm, $on) as $s) {
            $fresh = $s->fresh();
            $this->assertSame(CourierStatus::IN_TRANSIT, $fresh->status);
            $this->assertSame(self::HOP, (int) $fresh->next_hop_branch_id);
            $this->assertNull($fresh->current_branch_id);
            $this->assertSame(1, DB::table('shipment_route_steps')->where('shipment_id', $s->id)->where('dispatch_manifest_id', $tr->id)->count());
        }

        $summary = $this->svc->summarize($tr->fresh());
        $this->assertSame(4, $summary['last_mile_count']);
        $this->assertSame(2, $summary['onward_count']);
    }

    public function test_receive_sorts_last_mile_and_onward_then_onward_ride_next_tr(): void
    {
        [$lm, $on] = $this->mixedBatch(3, 2);
        $tr = $this->dispatchFromKtm(array_merge($lm, $on));

        $scanned = array_map(fn ($s) => $s->tracking_number, array_merge($lm, $on));
        $res = $this->svc->receive($tr, $scanned, null, $this->actor, self::HOP);

        $this->assertSame('received', $res['container']->status);
        $this->assertSame(5, (int) $res['container']->received_count);
        $this->assertSame(0, (int) $res['container']->missing_count);
        $this->assertCount(5, $res['received']);

        foreach ($lm as $s) {
            $f = $s->fresh();
            $this->assertSame(CourierStatus::SORTED_FOR_DELIVERY, $f->status, 'last-mile parcel goes to delivery');
            $this->assertSame(self::HOP, (int) $f->current_branch_id);
        }
        foreach ($on as $s) {
            $f = $s->fresh();
            $this->assertSame(CourierStatus::SORTED_FOR_TRANSFER, $f->status, 'onward parcel is sorted for the next TR');
            $this->assertSame(self::HOP, (int) $f->current_branch_id);
            $this->assertSame(self::FINAL, (int) $f->next_hop_branch_id);
        }

        // Bharatpur's next TR to Birendranagar carries exactly the onward parcels.
        $next = $this->svc->dispatch(collect(array_map(fn ($s) => $s->fresh(), $on)), null, self::HOP, ['transport_cost' => 300], $this->actor);
        $this->assertSame([], $next['skipped']);
        $this->assertCount(1, $next['manifests']);
        $tr2 = $next['manifests'][0];
        $this->assertSame(self::HOP, (int) $tr2->from_branch_id);
        $this->assertSame(self::FINAL, (int) $tr2->to_branch_id);
        $this->assertSame(2, (int) $tr2->expected_count);

        // Sequential numbering.
        $n1 = (int) substr((string) $tr->transfer_number, 3);
        $n2 = (int) substr((string) $tr2->transfer_number, 3);
        $this->assertSame($n1 + 1, $n2);

        // Two hops recorded for each onward parcel, each with its TR's cost share.
        foreach ($on as $s) {
            $hops = app(\Modules\Dispatch\Services\ManifestHopService::class)->hopsForShipment((int) $s->id);
            $this->assertCount(2, $hops);
            $this->assertSame($tr->transfer_number, $hops[0]['transfer_number']);
            $this->assertSame($tr2->transfer_number, $hops[1]['transfer_number']);
            $this->assertEqualsWithDelta(150.0, $hops[1]['transport_cost'], 0.001);
        }
    }

    public function test_missing_parcel_partially_received_then_found(): void
    {
        [$lm, $on] = $this->mixedBatch(2, 1);
        $tr = $this->dispatchFromKtm(array_merge($lm, $on));

        // Second last-mile parcel did not arrive.
        $res = $this->svc->receive($tr, [$lm[0]->id, $on[0]->tracking_number], 'seal intact', $this->actor, self::HOP);
        $c = $res['container'];
        $this->assertSame('partially_received', $c->status);
        $this->assertSame(2, (int) $c->received_count);
        $this->assertSame(1, (int) $c->missing_count);
        $this->assertCount(1, $res['missing']);
        $this->assertSame(CourierStatus::IN_TRANSIT, $lm[1]->fresh()->status, 'missing parcel is not sorted');

        $item = DispatchManifestItem::query()->where('dispatch_manifest_id', $tr->id)->where('shipment_id', $lm[1]->id)->firstOrFail();
        $this->assertSame('missing', $item->status);
        $this->assertSame('missing', $item->discrepancy);

        // Receiving the same TR again is refused; resolve instead.
        try {
            $this->svc->receive($c, [$lm[1]->id], null, $this->actor, self::HOP);
            $this->fail('Expected re-receive to be refused');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('Resolve', collect($e->errors())->flatten()->implode(' '));
        }

        $out = $this->svc->resolveItem($c, $item, 'found', 'under seat', $this->actor, self::HOP);
        $this->assertSame('received', $out['container']->status);
        $this->assertSame(0, (int) $out['container']->missing_count);
        $this->assertSame(CourierStatus::SORTED_FOR_DELIVERY, $lm[1]->fresh()->status);
    }

    public function test_missing_parcel_declared_lost_keeps_partial(): void
    {
        [$lm] = $this->mixedBatch(2, 0);
        $tr = $this->dispatchFromKtm($lm);
        $res = $this->svc->receive($tr, [$lm[0]->tracking_number], null, $this->actor, self::HOP);
        $item = DispatchManifestItem::query()->where('dispatch_manifest_id', $tr->id)->where('shipment_id', $lm[1]->id)->firstOrFail();

        $out = $this->svc->resolveItem($res['container'], $item, 'lost', 'not on truck', $this->actor, self::HOP);
        $this->assertSame('partially_received', $out['container']->status);
        $this->assertSame('lost', $item->fresh()->status);
    }

    public function test_extra_parcel_from_another_tr_is_received_and_rejected_when_unrelated(): void
    {
        [$a] = $this->mixedBatch(1, 0);
        [$b] = $this->mixedBatch(1, 0);
        $trA = $this->dispatchFromKtm($a);
        $trB = $this->dispatchFromKtm($b); // second trip KTM -> BHA
        $this->assertNotSame($trA->id, $trB->id);

        $unrelated = $this->parcel(['status' => CourierStatus::SORTED_FOR_DELIVERY, 'destination_branch_id' => self::KTM]);

        // B was loaded on truck A by mistake.
        $res = $this->svc->receive($trA, [$a[0]->tracking_number, $b[0]->tracking_number, $unrelated->tracking_number, 'NOPE-123'], null, $this->actor, self::HOP);

        $this->assertSame('received', $res['container']->status);
        $this->assertSame(1, (int) $res['container']->extra_count);
        $this->assertCount(1, $res['extras']);
        $this->assertCount(2, $res['rejected']);
        $this->assertSame(CourierStatus::SORTED_FOR_DELIVERY, $b[0]->fresh()->status);

        $moved = DispatchManifestItem::query()->where('dispatch_manifest_id', $trB->id)->where('shipment_id', $b[0]->id)->firstOrFail();
        $this->assertSame('moved', $moved->status);
        $this->assertNotSame('dispatched', $trB->fresh()->status, 'TR B has nothing left in transit');
    }

    public function test_open_tr_can_be_dispatched_or_cancelled_only_before_dispatch(): void
    {
        [$lm] = $this->mixedBatch(2, 0);
        $out = $this->svc->dispatch(collect($lm), self::HOP, self::KTM, ['hold' => true], $this->actor);
        $open = $out['manifests'][0];
        $this->assertSame('open', $open->status);
        $this->assertSame(CourierStatus::SORTED_FOR_TRANSFER, $lm[0]->fresh()->status, 'held parcels have not left');

        // Loading more parcels to the same hop reuses the open TR.
        [$more] = $this->mixedBatch(1, 0);
        $again = $this->svc->dispatch(collect($more), self::HOP, self::KTM, ['hold' => true], $this->actor);
        $this->assertSame($open->id, $again['manifests'][0]->id);

        $cancelled = $this->svc->cancel($open, 'truck broke down', $this->actor);
        $this->assertSame('cancelled', $cancelled->status);
        $this->assertSame(0, DispatchManifestItem::query()->where('dispatch_manifest_id', $open->id)->where('status', '!=', 'cancelled')->count());
        $this->assertSame(CourierStatus::SORTED_FOR_TRANSFER, $lm[0]->fresh()->status);

        // Held then sent with cost.
        $held = $this->svc->dispatch(collect(array_map(fn ($s) => $s->fresh(), $lm)), self::HOP, self::KTM, ['hold' => true], $this->actor)['manifests'][0];
        $this->assertNotSame($open->id, $held->id);
        $sent = $this->svc->dispatchOpen($held, ['vehicle_type' => 'bike', 'transport_cost' => 100], $this->actor);
        $this->assertSame('dispatched', $sent->status);
        $this->assertSame(CourierStatus::IN_TRANSIT, $lm[0]->fresh()->status);

        $this->expectException(ValidationException::class);
        $this->svc->cancel($sent, 'too late', $this->actor);
    }

    public function test_skip_hop_is_rejected(): void
    {
        $s = $this->parcel();
        $out = $this->svc->dispatch(collect([$s]), self::FINAL, self::KTM, [], $this->actor);
        $this->assertSame([], $out['manifests']);
        $this->assertStringContainsStringIgnoringCase('skip-hop', (string) ($out['skipped'][$s->id] ?? ''));
        $this->assertSame(CourierStatus::SORTED_FOR_TRANSFER, $s->fresh()->status);
    }

    public function test_api_dispatch_and_transit_redispatch_use_one_tr_per_next_hop(): void
    {
        $admin = User::query()->findOrFail($this->actor);
        $this->actingAs($admin, 'sanctum');

        [$lm, $on] = $this->mixedBatch(2, 3);
        $ids = array_map(fn ($s) => $s->id, array_merge($lm, $on));

        $resp = $this->postJson('/api/v1/admin/transfers/dispatch', [
            'shipment_ids' => $ids,
            'next_hop_branch_id' => self::HOP,
            'branch_id' => self::KTM,
            'vehicle_type' => 'truck',
            'transport_cost' => 500,
        ]);
        $resp->assertOk();
        $this->assertCount(1, $resp->json('data.containers'));
        $this->assertSame(5, $resp->json('data.dispatched_count'));
        $trNo = $resp->json('data.transfer_number');
        $this->assertMatchesRegularExpression('/^TR-\d{6}$/', (string) $trNo);

        // Inbound list at the hop shows the TR.
        $list = $this->getJson('/api/v1/admin/transfers/containers?direction=inbound&branch_id='.self::HOP);
        $list->assertOk();
        $this->assertContains($trNo, collect($list->json('data.data'))->pluck('display_number')->all());

        // Transit hub receives the 3 onward parcels and re-dispatches: ONE TR, not three.
        $resp2 = $this->postJson('/api/v1/admin/transfers/'.$on[0]->id.'/receive-transit', [
            'branch_id' => self::HOP,
            'received_shipment_ids' => array_map(fn ($s) => $s->id, $on),
            're_dispatch' => true,
            'vehicle_type' => 'bus_cargo',
        ]);
        $resp2->assertOk();
        $this->assertCount(1, $resp2->json('data.re_dispatched_manifests'));
        $tr2 = DispatchManifest::query()->findOrFail($resp2->json('data.re_dispatched_manifests.0.id'));
        $this->assertSame(self::FINAL, (int) $tr2->to_branch_id);
        $this->assertSame(3, DispatchManifestItem::query()->where('dispatch_manifest_id', $tr2->id)->count());

        // Remaining last-mile parcels are checked in through the TR receive endpoint.
        $first = DispatchManifest::query()->where('transfer_number', $trNo)->firstOrFail();
        $show = $this->getJson('/api/v1/admin/transfers/containers/'.$first->id.'?branch_id='.self::HOP);
        $show->assertOk();
        $this->assertTrue($show->json('data.can_receive'));

        $recv = $this->postJson('/api/v1/admin/transfers/containers/'.$first->id.'/receive', [
            'branch_id' => self::HOP,
            'scanned' => array_map(fn ($s) => $s->tracking_number, $lm),
        ]);
        $recv->assertOk();
        $this->assertSame('received', $recv->json('data.container.status'));
        $this->assertSame(5, $recv->json('data.container.received_count'));

        $hops = $this->getJson('/api/v1/admin/transfers/shipments/'.$on[0]->id.'/hops');
        $hops->assertOk();
        $this->assertCount(2, $hops->json('data.hops'));
    }

    public function test_transfer_numbers_are_sequential(): void
    {
        $a = $this->svc->nextTransferNumber();
        DB::table('dispatch_manifests')->insert([
            'manifest_number' => $a, 'transfer_number' => $a, 'status' => 'open', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $b = $this->svc->nextTransferNumber();
        $this->assertSame((int) substr($a, 3) + 1, (int) substr($b, 3));
    }
    /* ---------------- open TR: add / remove before dispatch ---------------- */

    private function openTr(array $shipments): DispatchManifest
    {
        $out = $this->svc->dispatch(collect($shipments), self::HOP, self::KTM, ['hold' => true], $this->actor);
        $this->assertSame([], $out['skipped'], json_encode($out['skipped']));

        return $out['manifests'][0];
    }

    private function bareOpenTr(int $from, int $to): DispatchManifest
    {
        $no = $this->svc->nextTransferNumber();

        return DispatchManifest::query()->create([
            'manifest_number' => $no, 'transfer_number' => $no,
            'from_branch_id' => $from, 'to_branch_id' => $to, 'status' => 'open', 'created_by' => $this->actor,
        ]);
    }

    public function test_new_parcel_appends_to_open_tr_remove_and_cost_split_over_final_set(): void
    {
        [$first] = $this->mixedBatch(2, 0);
        $open = $this->openTr($first);

        // New parcels sorted later for the same next hop join the same open TR.
        [$lm, $on] = $this->mixedBatch(1, 1);
        $res = $this->svc->addToOpen($open, array_merge($lm, $on), $this->actor);
        $this->assertSame([], $res['skipped']);
        $this->assertCount(2, $res['added']);
        $this->assertSame('open', $res['container']->status);
        $this->assertSame(1, DispatchManifest::query()->where('from_branch_id', self::KTM)->where('to_branch_id', self::HOP)->where('status', 'open')->count(), 'one open TR per branch -> next hop');

        // Loading again through "Load only" still reuses it.
        [$more] = $this->mixedBatch(1, 0);
        $again = $this->svc->dispatch(collect($more), self::HOP, self::KTM, ['hold' => true], $this->actor);
        $this->assertSame($open->id, $again['manifests'][0]->id);

        // Remove one before dispatch: it stays ready at KTM.
        $rm = $this->svc->removeFromOpen($open, [$first[1]->id], 'goes tomorrow', $this->actor);
        $this->assertSame([(int) $first[1]->id], $rm['removed']);
        $this->assertSame(CourierStatus::SORTED_FOR_TRANSFER, $first[1]->fresh()->status);

        // Dispatch: cost split over the FINAL 4 parcels (2 + 1 + 1 + 1 - 1).
        $sent = $this->svc->dispatchOpen($open, ['vehicle_type' => 'van', 'transport_cost' => 400], $this->actor);
        $this->assertSame('dispatched', $sent->status);
        $this->assertSame(4, (int) $sent->expected_count);
        $this->assertEqualsWithDelta(400.0, (float) $sent->transport_cost, 0.001);
        $items = DispatchManifestItem::query()->where('dispatch_manifest_id', $open->id)->get()->keyBy('shipment_id');
        foreach ([$first[0], $lm[0], $on[0], $more[0]] as $s) {
            $this->assertSame('sent', $items[$s->id]->status);
            $this->assertEqualsWithDelta(100.0, (float) $items[$s->id]->transport_cost, 0.001);
            $this->assertSame(CourierStatus::IN_TRANSIT, $s->fresh()->status);
        }
        $this->assertSame('cancelled', $items[$first[1]->id]->status);
        $this->assertEqualsWithDelta(0.0, (float) $items[$first[1]->id]->transport_cost, 0.001);

        // The removed parcel can ride the next TR.
        $next = $this->svc->dispatch(collect([$first[1]->fresh()]), self::HOP, self::KTM, [], $this->actor);
        $this->assertSame([], $next['skipped']);
        $this->assertNotSame($open->id, $next['manifests'][0]->id);
    }

    public function test_add_validates_next_hop_branch_and_other_active_tr(): void
    {
        // Wrong next hop: KTM parcel via Bharatpur cannot go on a KTM -> Birendranagar TR.
        $skip = $this->bareOpenTr(self::KTM, self::FINAL);
        $p = $this->parcel();
        $res = $this->svc->addToOpen($skip, [$p], $this->actor);
        $this->assertSame([], $res['added']);
        $this->assertStringContainsStringIgnoringCase('skip-hop', (string) $res['skipped'][$p->id]);

        // Parcel already on another open TR is refused.
        $open = $this->openTr([$p2 = $this->parcel()]);
        $other = $this->bareOpenTr(self::KTM, self::HOP);
        $res = $this->svc->addToOpen($other, [$p2], $this->actor);
        $this->assertSame([], $res['added']);
        $this->assertStringContainsString('active transfer', (string) $res['skipped'][$p2->id]);
        $this->assertNotSame($open->id, $other->id);

        // Parcel not at the sending branch is refused.
        $atHop = $this->parcel(['current_branch_id' => self::HOP]);
        $res = $this->svc->addToOpen($open, [$atHop], $this->actor);
        $this->assertSame([], $res['added']);
        $this->assertArrayHasKey($atHop->id, $res['skipped']);
    }

    public function test_add_and_remove_blocked_after_dispatch(): void
    {
        [$lm] = $this->mixedBatch(2, 0);
        $tr = $this->dispatchFromKtm($lm);
        [$late] = $this->mixedBatch(1, 0);

        try {
            $this->svc->addToOpen($tr, $late, $this->actor);
            $this->fail('add after dispatch must be refused');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('before dispatch', collect($e->errors())->flatten()->implode(' '));
        }
        try {
            $this->svc->removeFromOpen($tr, [$lm[0]->id], null, $this->actor);
            $this->fail('remove after dispatch must be refused');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('before dispatch', collect($e->errors())->flatten()->implode(' '));
        }
        $this->assertSame(CourierStatus::SORTED_FOR_TRANSFER, $late[0]->fresh()->status);
        $this->assertSame(2, DispatchManifestItem::query()->where('dispatch_manifest_id', $tr->id)->where('status', 'sent')->count());
    }

    public function test_auto_append_puts_newly_sorted_parcel_on_open_tr(): void
    {
        [$lm] = $this->mixedBatch(1, 0);
        $open = $this->openTr($lm);
        $p = $this->parcel();
        $this->assertNull($this->svc->autoAppend($p, $this->actor), 'off by default');

        $this->svc->setAutoAppend($open, true);
        $hit = $this->svc->autoAppend($p->fresh(), $this->actor);
        $this->assertNotNull($hit);
        $this->assertSame($open->id, $hit->id);
        $this->assertSame('added', DispatchManifestItem::query()->where('dispatch_manifest_id', $open->id)->where('shipment_id', $p->id)->value('status'));
    }

    public function test_api_add_remove_items_on_open_tr(): void
    {
        $this->actingAs(User::query()->findOrFail($this->actor), 'sanctum');
        [$lm] = $this->mixedBatch(1, 0);
        $open = $this->openTr($lm);
        $new = $this->parcel();

        $cand = $this->getJson('/api/v1/admin/transfers/containers/'.$open->id.'/candidates?branch_id='.self::KTM);
        $cand->assertOk();
        $this->assertContains($new->id, collect($cand->json('data'))->pluck('shipment_id')->all());

        $add = $this->postJson('/api/v1/admin/transfers/containers/'.$open->id.'/items', ['branch_id' => self::KTM, 'shipment_ids' => [$new->id]]);
        $add->assertOk();
        $this->assertSame([$new->id], $add->json('data.added'));
        $this->assertSame(2, $add->json('data.container.parcel_count'));

        $rm = $this->postJson('/api/v1/admin/transfers/containers/'.$open->id.'/items/remove', ['branch_id' => self::KTM, 'shipment_ids' => [$new->id]]);
        $rm->assertOk();
        $this->assertSame(1, $rm->json('data.container.parcel_count'));

        $this->svc->dispatchOpen($open, ['transport_cost' => 0], $this->actor);
        $this->postJson('/api/v1/admin/transfers/containers/'.$open->id.'/items', ['branch_id' => self::KTM, 'shipment_ids' => [$new->id]])
            ->assertStatus(422);
    }

    /* ---------------- seal check + lookup ---------------- */

    public function test_seal_ok_is_recorded(): void
    {
        [$lm] = $this->mixedBatch(2, 0);
        $tr = $this->dispatchFromKtm($lm, ['seal_number' => 'SEAL-77']);
        $res = $this->svc->receive($tr, array_map(fn ($s) => $s->id, $lm), null, $this->actor, self::HOP, ['value' => ' seal-77 ', 'intact' => true]);

        $c = $res['container'];
        $this->assertSame('received', $c->status);
        $this->assertSame('ok', $c->seal_status);
        $this->assertSame('seal-77', $c->seal_checked_value);
        $this->assertSame($this->actor, (int) $c->seal_checked_by);
        $this->assertNotNull($c->seal_checked_at);
    }

    public function test_seal_mismatch_or_tampered_flags_and_notifies_sender(): void
    {
        [$lm] = $this->mixedBatch(2, 0);
        $tr = $this->dispatchFromKtm($lm, ['seal_number' => 'SEAL-1']);

        // Remark is required on a mismatch; nothing is received without it.
        try {
            $this->svc->receive($tr, [$lm[0]->id], null, $this->actor, self::HOP, ['value' => 'SEAL-9', 'intact' => true]);
            $this->fail('remark must be required');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('seal_remark', $e->errors());
        }
        $this->assertSame(CourierStatus::IN_TRANSIT, $lm[0]->fresh()->status);

        $before = DB::table('staff_notifications')->where('branch_id', self::KTM)->count();
        $res = $this->svc->receive($tr, [$lm[0]->id, $lm[1]->id], null, $this->actor, self::HOP, ['value' => 'SEAL-9', 'intact' => true, 'remark' => 'different seal on bag']);
        $c = $res['container'];
        $this->assertSame('mismatch', $c->seal_status);
        $this->assertSame('different seal on bag', $c->seal_remark);
        $this->assertSame('received', $c->status, 'parcel check still runs');
        $this->assertSame(CourierStatus::SORTED_FOR_DELIVERY, $lm[0]->fresh()->status);
        $this->assertGreaterThan($before, DB::table('staff_notifications')->where('branch_id', self::KTM)->count());
        $this->assertTrue(DB::table('staff_notifications')->where('branch_id', self::KTM)->where('title', 'like', '%'.$tr->transfer_number.'%seal%')->exists());

        // Seal not intact -> tampered (even with the right number).
        [$lm2] = $this->mixedBatch(1, 0);
        $tr2 = $this->dispatchFromKtm($lm2, ['seal_number' => 'SEAL-2']);
        $res2 = $this->svc->receive($tr2, [$lm2[0]->id], null, $this->actor, self::HOP, ['value' => 'SEAL-2', 'intact' => false, 'remark' => 'seal cut']);
        $this->assertSame('tampered', $res2['container']->seal_status);
    }

    public function test_lookup_by_tr_number_only_for_receiving_branch_and_receive_api_with_seal(): void
    {
        $this->actingAs(User::query()->findOrFail($this->actor), 'sanctum');
        [$lm] = $this->mixedBatch(1, 0);
        $tr = $this->dispatchFromKtm($lm, ['seal_number' => 'BAG-5']);

        $ok = $this->getJson('/api/v1/admin/transfers/containers/lookup?number='.strtolower($tr->transfer_number).'&branch_id='.self::HOP);
        $ok->assertOk();
        $this->assertSame($tr->id, $ok->json('data.id'));
        $this->assertTrue($ok->json('data.can_receive'));
        $this->assertTrue($ok->json('data.seal_check_required'));

        $this->getJson('/api/v1/admin/transfers/containers/lookup?number='.$tr->transfer_number.'&branch_id='.self::KTM)->assertStatus(403);
        $this->getJson('/api/v1/admin/transfers/containers/lookup?number=TR-999999999&branch_id='.self::HOP)->assertStatus(404);

        $recv = $this->postJson('/api/v1/admin/transfers/containers/'.$tr->id.'/receive', [
            'branch_id' => self::HOP,
            'scanned' => [$lm[0]->tracking_number],
            'seal_value' => 'BAG-5',
            'seal_intact' => true,
        ]);
        $recv->assertOk();
        $this->assertSame('ok', $recv->json('data.container.seal_status'));
        $this->assertSame('ok', $recv->json('data.seal.status'));
    }
}
