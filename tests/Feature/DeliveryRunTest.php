<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Support\CourierStatus;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Modules\Delivery\Models\DeliveryAssignment;
use Modules\Delivery\Models\DeliveryRun;
use Modules\Delivery\Models\DeliveryRunItem;
use Modules\Delivery\Services\DeliveryRunService;
use Modules\Delivery\Services\DeliveryWorkflowService;
use Modules\POD\Models\PodDeposit;
use Modules\POD\Models\PodRecord;
use Modules\Shipment\Models\Shipment;
use Tests\TestCase;

/**
 * DR delivery runs at the Kathmandu fixture branch (19) with fixture rider 175.
 * Rider 176 belongs to Itahari (18) and must be refused.
 */
final class DeliveryRunTest extends TestCase
{
    use DatabaseTransactions;

    private const KTM = 19;
    private const RIDER = 175;
    private const OTHER_BRANCH_RIDER = 176;
    private const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private DeliveryRunService $runs;
    private DeliveryWorkflowService $workflow;
    private User $admin;
    private User $rider;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Storage::fake('local');
        $this->runs = $this->app->make(DeliveryRunService::class);
        $this->workflow = $this->app->make(DeliveryWorkflowService::class);
        $this->admin = User::query()->where('role', 'super_admin')->orderBy('id')->firstOrFail();
        $this->rider = User::query()->findOrFail(self::RIDER);
        $this->assertSame(self::KTM, (int) $this->rider->branch_id, 'Fixture rider 175 must belong to branch 19');
    }

    private function parcel(array $overrides = []): Shipment
    {
        $merchantId = DB::table('merchants')->orderBy('id')->value('id');
        $id = DB::table('shipments')->insertGetId(array_merge([
            'tracking_number' => 'TST-DR-'.strtoupper(uniqid()).random_int(10, 99),
            'merchant_id' => $merchantId,
            'service_type' => 'standard',
            'status' => CourierStatus::SORTED_FOR_DELIVERY,
            'merchant_status' => CourierStatus::merchantStatus(CourierStatus::SORTED_FOR_DELIVERY),
            'origin_branch_id' => self::KTM,
            'destination_branch_id' => self::KTM,
            'current_branch_id' => self::KTM,
            'weight' => 1,
            'payment_type' => 'prepaid',
            'pod_amount' => 0,
            'total_collectable_amount' => 0,
            'receiver_name' => 'DR Test',
            'receiver_phone' => '9800000000',
            'delivery_address' => 'Test Address',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
        $shipment = Shipment::query()->findOrFail($id);
        $this->workflow->createPendingForShipment($shipment, $this->admin->id);

        return $shipment;
    }

    private function pod(float $amount, array $overrides = []): Shipment
    {
        return $this->parcel(array_merge([
            'payment_type' => 'pod',
            'pod_amount' => $amount,
            'total_collectable_amount' => $amount,
        ], $overrides));
    }

    private function newRun(array $shipments, array $meta = []): DeliveryRun
    {
        $out = $this->runs->create(array_map(fn (Shipment $s) => $s->id, $shipments), self::KTM, $meta, $this->admin);
        $this->assertSame([], $out['skipped'], json_encode($out['skipped']));

        return $out['run'];
    }

    private function deliveryOf(Shipment $s): DeliveryAssignment
    {
        return DeliveryAssignment::query()->where('shipment_id', $s->id)->latest('id')->firstOrFail();
    }

    private function deliverCash(Shipment $s, ?float $amount = null): void
    {
        $d = $this->deliveryOf($s);
        $this->workflow->arrived($d, $this->rider);
        $body = ['customer_confirmed' => true, 'customer_name' => 'Receiver', 'customer_signature' => self::PNG];
        if ($amount !== null) {
            $body['payment_method'] = 'cash';
            $body['pod_collected_amount'] = $amount;
        }
        $this->workflow->delivered($d->fresh(), $this->rider, $body);
    }

    public function test_create_numbers_sequences_by_area_and_totals_cod(): void
    {
        $b = $this->pod(500, ['receiver_area' => 'Baneshwor']);
        $a1 = $this->pod(300, ['receiver_area' => 'Anamnagar']);
        $a2 = $this->parcel(['receiver_area' => 'Anamnagar']);

        $run = $this->newRun([$b, $a1, $a2], ['vehicle_type' => 'bike', 'vehicle_number' => 'BA 2 PA 1']);

        $this->assertMatchesRegularExpression('/^DR-[A-Z0-9]+-\d{6}$/', $run->run_number);
        $this->assertSame(DeliveryRun::STATUS_OPEN, $run->status);
        $this->assertSame(3, $run->expected_count);
        $this->assertEqualsWithDelta(800.0, $run->cod_expected, 0.001);

        $order = DeliveryRunItem::query()->where('delivery_run_id', $run->id)->orderBy('sequence')->pluck('shipment_id')->all();
        $this->assertSame($b->id, $order[2], 'Baneshwor stop comes after the Anamnagar stops');

        $next = $this->newRun([$this->parcel()]);
        $this->assertNotSame($run->run_number, $next->run_number);
        $this->assertSame((int) substr($run->run_number, -6) + 1, (int) substr($next->run_number, -6));
    }

    public function test_add_remove_and_parcel_cannot_be_on_two_runs(): void
    {
        $p1 = $this->parcel();
        $p2 = $this->parcel();
        $p3 = $this->parcel();
        $run = $this->newRun([$p1, $p2]);

        // Same parcel in a second run: refused.
        try {
            $this->runs->create([$p1->id], self::KTM, [], $this->admin);
            $this->fail('A parcel already on a run must not join another run.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString($run->run_number, json_encode($e->errors()));
        }
        $mixed = $this->runs->create([$p1->id, $p3->id], self::KTM, [], $this->admin);
        $this->assertArrayHasKey($p1->id, $mixed['skipped']);
        $this->assertSame(1, $mixed['run']->expected_count);
        $this->runs->cancel($mixed['run'], 'test', $this->admin);

        $added = $this->runs->addItems($run, [$p3->id, $p1->id], $this->admin);
        $this->assertSame([$p3->id], $added['added']);
        $this->assertArrayHasKey($p1->id, $added['skipped']);
        $this->assertSame(3, $added['run']->expected_count);

        $removed = $this->runs->removeItems($run, [$p2->id], 'Customer asked for tomorrow', $this->admin);
        $this->assertSame([$p2->id], $removed['removed']);
        $this->assertSame(2, $removed['run']->expected_count);
        $this->assertTrue($this->runs->readyQuery(self::KTM)->whereKey($p2->id)->exists(), 'Removed parcel is ready again');
        $this->assertFalse($this->runs->readyQuery(self::KTM)->whereKey($p1->id)->exists(), 'Parcel on a run is not ready');
        $this->assertSame([1, 2], DeliveryRunItem::query()->where('delivery_run_id', $run->id)->where('status', '!=', 'removed')->orderBy('sequence')->pluck('sequence')->all());
    }

    public function test_assign_rider_checks_branch_and_old_assign_is_blocked(): void
    {
        $p = $this->parcel();
        $run = $this->newRun([$p]);

        $other = User::query()->find(self::OTHER_BRANCH_RIDER);
        if ($other && (int) $other->branch_id !== self::KTM) {
            $this->expectExceptionOnce(fn () => $this->runs->assignRider($run, $other->id, [], $this->admin), 'not');
        }

        $run = $this->runs->assignRider($run, self::RIDER, ['vehicle_type' => 'bike'], $this->admin);
        $this->assertSame(DeliveryRun::STATUS_ASSIGNED, $run->status);
        $this->assertSame(self::RIDER, (int) $run->rider_user_id);
        $this->assertSame(1, DB::table('staff_notifications')->where('type', 'delivery_run')->where('data_json->delivery_run_id', $run->id)->count());

        // The per-delivery assign on the Deliveries board must not bypass the run.
        $this->expectExceptionOnce(fn () => $this->workflow->assign($this->deliveryOf($p), $this->rider, $this->admin), $run->run_number);
    }

    public function test_dispatch_assigns_deliveries_to_rider_and_locks_the_list(): void
    {
        $p1 = $this->pod(1200);
        $p2 = $this->parcel();
        $run = $this->newRun([$p1, $p2]);

        $this->expectExceptionOnce(fn () => $this->runs->dispatch($run, $this->admin), 'Assign a rider');

        $run = $this->runs->dispatch($run, $this->admin, ['rider_user_id' => self::RIDER]);
        $this->assertSame(DeliveryRun::STATUS_OUT, $run->status);
        $this->assertNotNull($run->dispatched_at);

        foreach ([$p1, $p2] as $s) {
            $d = $this->deliveryOf($s);
            $this->assertSame('out_for_delivery', $d->status);
            $this->assertSame(self::RIDER, (int) $d->rider_id);
            $this->assertSame(CourierStatus::OUT_FOR_DELIVERY, $s->fresh()->status);
        }
        $this->assertSame(2, DeliveryRunItem::query()->where('delivery_run_id', $run->id)->where('status', 'out_for_delivery')->count());

        $this->expectExceptionOnce(fn () => $this->runs->addItems($run, [$this->parcel()->id], $this->admin), 'before dispatch');
        $this->expectExceptionOnce(fn () => $this->runs->cancel($run, null, $this->admin), 'not been dispatched');
    }

    public function test_outcomes_roll_up_and_close_requeues_and_deposits_cash(): void
    {
        $cash = $this->pod(1500);
        $prepaid = $this->parcel();
        $notHome = $this->pod(700);
        $run = $this->newRun([$cash, $prepaid, $notHome], ['rider_user_id' => self::RIDER]);
        $run = $this->runs->dispatch($run, $this->admin);

        $this->deliverCash($cash, 1500);
        $this->deliverCash($prepaid);

        $run->refresh();
        $this->assertSame(2, $run->delivered_count);
        $this->assertSame(DeliveryRun::STATUS_OUT, $run->status, 'One parcel is still with the rider');
        $this->assertEqualsWithDelta(1500.0, $run->cod_collected, 0.001);
        $this->assertEqualsWithDelta(2200.0, $run->cod_expected, 0.001);
        $item = DeliveryRunItem::query()->where('delivery_run_id', $run->id)->where('shipment_id', $cash->id)->first();
        $this->assertSame('cash', $item->payment_method);
        $this->assertNotNull($item->arrived_at);

        // Rider back: the undelivered parcel is failed and queued again; cash deposited.
        $run = $this->runs->close($run, ['cash_received' => 1500], $this->admin);
        $this->assertSame(DeliveryRun::STATUS_CLOSED, $run->status);
        $this->assertSame(1, $run->failed_count);
        $this->assertSame(1, $run->reattempt_count);
        $this->assertEqualsWithDelta(0.0, (float) $run->cash_variance, 0.001);
        $this->assertTrue($run->cash_deposited);

        $this->assertSame(CourierStatus::SORTED_FOR_DELIVERY, $notHome->fresh()->status);
        $next = $this->deliveryOf($notHome);
        $this->assertSame('pending', $next->status);
        $this->assertSame(2, (int) $next->attempt_no);
        $this->assertTrue($this->runs->readyQuery(self::KTM)->whereKey($notHome->id)->exists(), 'Requeued parcel can go on the next run');

        $deposit = PodDeposit::query()->findOrFail($run->pod_deposit_id);
        $this->assertEqualsWithDelta(1500.0, (float) $deposit->amount, 0.001);
        $this->assertSame('deposited', PodRecord::query()->where('shipment_id', $cash->id)->value('status'));
        $this->assertSame('deposited', $cash->fresh()->pod_status);
    }

    public function test_completed_run_short_cash_needs_note_and_return_action(): void
    {
        $cash = $this->pod(1000);
        $refused = $this->pod(400);
        $run = $this->newRun([$cash, $refused], ['rider_user_id' => self::RIDER]);
        $run = $this->runs->dispatch($run, $this->admin);

        $this->deliverCash($cash, 1000);
        $this->workflow->failed($this->deliveryOf($refused), $this->rider, 'Customer refused');

        $run->refresh();
        $this->assertSame(DeliveryRun::STATUS_COMPLETED, $run->status, 'All parcels done on the road');
        $this->assertSame(1, $run->failed_count);

        $refusedItem = DeliveryRunItem::query()->where('delivery_run_id', $run->id)->where('shipment_id', $refused->id)->firstOrFail();
        $this->assertSame('Customer refused', $refusedItem->reason);

        $this->expectExceptionOnce(fn () => $this->runs->close($run, ['cash_received' => 900], $this->admin), 'differs');

        $run = $this->runs->close($run, [
            'cash_received' => 900,
            'notes' => 'Rider short by Rs 100, to pay tomorrow',
            'items' => [['item_id' => $refusedItem->id, 'action' => 'return']],
        ], $this->admin);

        $this->assertSame(DeliveryRun::STATUS_CLOSED, $run->status);
        $this->assertEqualsWithDelta(-100.0, (float) $run->cash_variance, 0.001);
        $this->assertSame(1, $run->returned_count);
        $this->assertSame(CourierStatus::RETURN_INITIATED, $refused->fresh()->status);
        $this->assertSame('returned', $refusedItem->fresh()->outcome);
    }

    public function test_failed_parcel_from_old_flow_can_be_put_on_a_run(): void
    {
        $p = $this->parcel();
        $d = $this->deliveryOf($p);
        $this->workflow->assign($d, $this->rider, $this->admin);
        $this->workflow->failed($d->fresh(), $this->rider, 'Address closed');
        $this->assertSame(CourierStatus::DELIVERY_FAILED, $p->fresh()->status);

        $this->assertTrue($this->runs->readyQuery(self::KTM)->whereKey($p->id)->exists());
        $run = $this->newRun([$p]);
        $this->assertSame(CourierStatus::SORTED_FOR_DELIVERY, $p->fresh()->status);
        $item = DeliveryRunItem::query()->where('delivery_run_id', $run->id)->firstOrFail();
        $this->assertSame(2, (int) $item->attempt);
    }

    public function test_http_board_endpoints_and_rider_view(): void
    {
        $p1 = $this->pod(650, ['receiver_area' => 'Kalanki']);
        $p2 = $this->parcel(['receiver_area' => 'Kalanki']);

        $this->actingAs($this->admin, 'sanctum');
        $this->getJson('/api/v1/admin/deliveries/runs/ready?branch_id='.self::KTM)
            ->assertOk()
            ->assertJsonFragment(['tracking_number' => $p1->tracking_number]);
        $this->getJson('/api/v1/admin/deliveries/runs/riders?branch_id='.self::KTM)
            ->assertOk()
            ->assertJsonFragment(['id' => self::RIDER]);

        $created = $this->postJson('/api/v1/admin/deliveries/runs', [
            'branch_id' => self::KTM,
            'shipment_ids' => [$p1->id, $p2->id],
            'rider_user_id' => self::RIDER,
            'vehicle_type' => 'bike',
        ])->assertOk();
        $runId = (int) $created->json('data.run.id');
        $this->assertSame('assigned', $created->json('data.run.status'));
        $this->assertCount(2, $created->json('data.run.items'));

        $number = (string) $created->json('data.run.run_number');
        $this->getJson('/api/v1/admin/deliveries/runs/lookup?code='.$number)->assertOk()->assertJsonPath('data.id', $runId);
        $this->getJson('/api/v1/admin/deliveries/runs?branch_id='.self::KTM.'&status=active')->assertOk()->assertJsonFragment(['run_number' => $number]);
        $this->getJson('/api/v1/admin/deliveries/runs/stats?branch_id='.self::KTM)->assertOk();

        // Rider sees the run and starts it after collecting the parcels.
        $this->actingAs($this->rider, 'sanctum');
        $this->getJson('/api/v1/staff/deliveries/runs')->assertOk()->assertJsonPath('data.active.0.run_number', $number);
        $this->postJson("/api/v1/staff/deliveries/runs/{$runId}/start")->assertOk()->assertJsonPath('data.status', 'out_for_delivery');
        $this->assertSame('out_for_delivery', $this->deliveryOf($p1)->status);

        $this->actingAs($this->admin, 'sanctum');
        $this->postJson("/api/v1/admin/deliveries/runs/{$runId}/close", ['cash_received' => 0])
            ->assertOk()->assertJsonPath('data.status', 'closed')->assertJsonPath('data.reattempt_count', 2);
    }

    public function test_delivery_list_shows_run_number_and_sidebar_badges(): void
    {
        $p1 = $this->parcel(['receiver_area' => 'Baneshwor']);
        $p2 = $this->parcel(['receiver_area' => 'Baneshwor']);

        $this->actingAs($this->admin, 'sanctum');
        $before = $this->getJson('/api/v1/me/menu-badges?section=admin')->assertOk();
        $ready = collect($before->json('data.badges./admin/deliveries') ?? [])->firstWhere('key', 'ready');
        $this->assertNotNull($ready, 'Deliveries menu should carry a ready badge');
        $this->assertGreaterThanOrEqual(2, (int) $ready['count']);

        $run = $this->newRun([$p1, $p2], ['rider_user_id' => self::RIDER]);

        // The delivery board rows carry the DR, and DR number search / run filter work.
        $rows = $this->getJson('/api/v1/admin/deliveries?branch_id='.self::KTM.'&run_id='.$run->id)
            ->assertOk()->json('data.data');
        $this->assertCount(2, $rows);
        $this->assertSame($run->run_number, $rows[0]['delivery_run']['run_number']);
        $this->assertTrue($rows[0]['delivery_run']['active']);
        $this->getJson('/api/v1/admin/deliveries?search='.$run->run_number)
            ->assertOk()->assertJsonPath('data.total', 2);
        $summary = $this->getJson('/api/v1/admin/deliveries/summary?search='.$run->run_number)->assertOk();
        $this->assertSame(2, (int) $summary->json('data.total'));
        $this->assertSame(0, (int) $summary->json('data.ready'), 'Parcels on a run are not ready to assign');
        $this->getJson('/api/v1/admin/deliveries?status=pending&on_run=0&search='.$p1->tracking_number)
            ->assertOk()->assertJsonPath('data.total', 0);
        $this->getJson('/api/v1/admin/deliveries?status=pending&on_run=1&search='.$p1->tracking_number)
            ->assertOk()->assertJsonPath('data.total', 1);

        // Parcels on the run leave the ready count; the run shows as active.
        $after = $this->getJson('/api/v1/me/menu-badges?section=admin')->assertOk();
        $readyAfter = collect($after->json('data.badges./admin/deliveries') ?? [])->firstWhere('key', 'ready');
        $this->assertSame((int) $ready['count'] - 2, (int) ($readyAfter['count'] ?? 0));
        $runs = collect($after->json('data.badges./admin/deliveries') ?? [])->firstWhere('key', 'runs');
        $this->assertGreaterThanOrEqual(1, (int) ($runs['count'] ?? 0));

        // Rider: own deliveries badge after dispatch, and run info on the staff list.
        $this->runs->dispatch($run->fresh(), $this->admin);
        $this->actingAs($this->rider, 'sanctum');
        $staff = $this->getJson('/api/v1/me/menu-badges?section=staff')->assertOk();
        $mine = collect($staff->json('data.badges./staff/deliveries') ?? [])->firstWhere('key', 'active');
        $this->assertGreaterThanOrEqual(2, (int) ($mine['count'] ?? 0));
        $list = $this->getJson('/api/v1/staff/deliveries?per_page=100')->assertOk()->json('data.data');
        $onRun = collect($list)->filter(fn ($row) => ($row['delivery_run']['id'] ?? null) === $run->id);
        $this->assertCount(2, $onRun);
        $this->assertSame('out_for_delivery', $list[0]['status'], 'Active work is listed first');
    }

    public function test_deliveries_board_create_dr_and_assign_rider_in_one_step(): void
    {
        $p1 = $this->pod(400, ['receiver_area' => 'Chabahil']);
        $p2 = $this->parcel(['receiver_area' => 'Chabahil']);
        $tracking = [$p1->tracking_number, $p2->tracking_number];

        $this->actingAs($this->admin, 'sanctum');

        // Ready to Assign (default chip) lists the parcels; counts come from the summary.
        foreach ($tracking as $t) {
            $this->getJson('/api/v1/admin/deliveries?status=ready&search='.$t)->assertOk()->assertJsonPath('data.total', 1);
        }
        $before = $this->getJson('/api/v1/admin/deliveries/summary?branch_id='.self::KTM)->assertOk();
        $readyBefore = (int) $before->json('data.ready');
        $plannedBefore = (int) $before->json('data.planned');

        // Selection bar -> "Create DR & assign rider".
        $created = $this->postJson('/api/v1/admin/deliveries/runs', [
            'branch_id' => self::KTM,
            'shipment_ids' => [$p1->id, $p2->id],
            'rider_user_id' => self::RIDER,
            'vehicle_type' => 'bike',
            'notes' => 'Board flow',
        ])->assertOk();
        $number = (string) $created->json('data.run.run_number');
        $this->assertSame('assigned', $created->json('data.run.status'));
        $this->assertSame(self::RIDER, (int) $created->json('data.run.rider.id'));

        // They leave Ready to Assign and show under Assigned with the DR chip + rider.
        foreach ($tracking as $t) {
            $this->getJson('/api/v1/admin/deliveries?status=ready&search='.$t)->assertOk()->assertJsonPath('data.total', 0);
            $row = $this->getJson('/api/v1/admin/deliveries?status=assigned,planned&search='.$t)
                ->assertOk()->assertJsonPath('data.total', 1)->json('data.data.0');
            $this->assertSame($number, $row['delivery_run']['run_number']);
            $this->assertTrue($row['delivery_run']['active']);
            $this->assertSame(self::RIDER, (int) $row['delivery_run']['rider']['id']);
        }
        $after = $this->getJson('/api/v1/admin/deliveries/summary?branch_id='.self::KTM)->assertOk();
        $this->assertSame($readyBefore - 2, (int) $after->json('data.ready'));
        $this->assertSame($plannedBefore + 2, (int) $after->json('data.planned'));

        // Dispatch hands them to the rider: real assigned/out-for-delivery status.
        $runId = (int) $created->json('data.run.id');
        $this->postJson("/api/v1/admin/deliveries/runs/{$runId}/dispatch", ['branch_id' => self::KTM])->assertOk();
        $this->assertSame(self::RIDER, (int) $this->deliveryOf($p1)->rider_id);
        $this->assertNotSame('pending', $this->deliveryOf($p1)->status);
    }

    private function expectExceptionOnce(callable $fn, string $contains): void
    {
        try {
            $fn();
            $this->fail('Expected a validation error containing: '.$contains);
        } catch (ValidationException $e) {
            $this->assertStringContainsString($contains, json_encode($e->errors()));
        }
    }
}
