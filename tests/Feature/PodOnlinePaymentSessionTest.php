<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Support\CourierStatus;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Modules\Delivery\Models\DeliveryAssignment;
use Modules\Merchant\Models\Merchant;
use Modules\POD\Jobs\RequestTukaatuPodPaymentJob;
use Modules\Setting\Models\Marketplace;
use Modules\Shipment\Models\Shipment;
use Tests\TestCase;

/**
 * Phase 6 doorstep online POD.
 *
 * Express does not call HamroPay. The store's marketplace (managed in
 * Admin -> Marketplaces: api.tukaatu.com, api.fca.com.np, ...) creates the
 * QR through POST {api_base_url}/api/v1/gateway/payments/pod-qr and later
 * confirms payment on POST /api/v1/express/callback (pod_payment.paid).
 *
 * Session lifecycle: pending (request queued) -> ready (QR / payment URL
 * returned, still unpaid) -> paid (marketplace callback). Delivery as
 * online / QR is allowed only once the session is paid.
 *
 * Self-contained: each test creates its own marketplace row and links the
 * fixture store to it (rolled back), so local marketplace data and .env
 * values do not change the outcome.
 */
final class PodOnlinePaymentSessionTest extends TestCase
{
    use DatabaseTransactions;

    private const MARKET_BASE = 'https://pod-market.test';
    private const POD_QR_URL = self::MARKET_BASE.'/api/v1/gateway/payments/pod-qr';

    private User $rider;
    private Merchant $merchant;
    private Marketplace $marketplace;

    protected function setUp(): void
    {
        parent::setUp();

        // Env-level gateway settings are not used by this flow: keep them empty
        // so the test proves the admin marketplace config is what counts.
        config([
            'hamropay.api_base_url' => '',
            'hamropay.gateway_url' => '',
            'hamropay.client_id' => '',
            'hamropay.client_api_key' => '',
            'hamropay.secret' => '',
            'hamropay.merchant_id' => '',
            // Unsigned callbacks accepted (signature only checked when a secret is set).
            'services.tukaatu.callback_secret' => '',
            'services.tukaatu.callback_require_signature' => false,
            'services.store_manager.payment.shared_secret' => '',
        ]);

        $this->rider = User::query()->findOrFail(175);
        $this->assertTrue($this->rider->can('deliveries.status'), 'Fixture rider 175 needs deliveries.status');

        $this->merchant = Merchant::query()
            ->whereNotNull('external_store_id')
            ->where('external_store_id', '!=', '')
            ->orderBy('id')
            ->first();
        $this->assertNotNull($this->merchant, 'Need a merchant with external_store_id');

        $this->marketplace = $this->makeMarketplace(self::MARKET_BASE);
        $this->merchant->forceFill(['marketplace_id' => $this->marketplace->id])->save();
    }

    public function test_create_payment_session_asks_store_marketplace_for_qr(): void
    {
        [$shipment, $delivery] = $this->makePodDelivery();
        Http::fake([self::MARKET_BASE.'/*' => $this->podQrResponse()]);

        $this->actingAs($this->rider, 'sanctum');

        $response = $this->postJson("/api/v1/staff/deliveries/{$delivery->id}/payment-session", []);

        // QR is back from the marketplace; the customer has not paid yet.
        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'ready')
            ->assertJsonPath('data.paid', false)
            ->assertJsonPath('data.settlement_destination', 'merchant')
            ->assertJsonPath('data.qr_string', 'hamropay://pay/phase6')
            ->assertJsonPath('data.payment_url', 'https://pay.pod-market.test/c/phase6')
            ->assertJsonPath('data.merchant_txn_id', 'MTXN-PHASE6')
            ->assertJsonPath('data.amount', '1100.00')
            ->assertJsonPath('data.gateway.marketplace_id', $this->marketplace->id)
            ->assertJsonPath('data.gateway.api_url', self::POD_QR_URL);
        $this->assertNull($response->json('data.paid_at'));

        Http::assertSent(function ($request) use ($shipment) {
            return $request->url() === self::POD_QR_URL
                && $request->method() === 'POST'
                && (float) $request['amount'] === 1100.0
                && $request['tracking_number'] === $shipment->tracking_number
                && $request['external_store_id'] === (string) $this->merchant->external_store_id;
        });
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'hamropay'));

        $this->assertDatabaseHas('pod_payment_sessions', [
            'shipment_id' => $shipment->id,
            'status' => 'ready',
            'merchant_txn_id' => 'MTXN-PHASE6',
        ]);
    }

    public function test_session_is_pending_while_marketplace_request_is_queued(): void
    {
        [$shipment, $delivery] = $this->makePodDelivery();
        Queue::fake();
        Http::fake();

        $this->actingAs($this->rider, 'sanctum');

        $this->postJson("/api/v1/staff/deliveries/{$delivery->id}/payment-session", [])
            ->assertOk()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.paid', false)
            ->assertJsonPath('data.qr_string', null);

        Queue::assertPushed(RequestTukaatuPodPaymentJob::class);
        Http::assertNothingSent();
        $this->assertDatabaseHas('pod_payment_sessions', ['shipment_id' => $shipment->id, 'status' => 'pending']);
    }

    public function test_create_payment_session_rejects_client_amount_override(): void
    {
        [, $delivery] = $this->makePodDelivery();

        $this->actingAs($this->rider, 'sanctum');

        $this->postJson(
            "/api/v1/staff/deliveries/{$delivery->id}/payment-session",
            ['amount' => 1, 'payment_method' => 'online']
        )->assertStatus(422)
            ->assertJsonValidationErrors(['amount', 'payment_method']);
    }

    public function test_paid_callback_then_delivered_online_and_qr(): void
    {
        [$shipment, $delivery] = $this->makePodDelivery();
        Http::fake([self::MARKET_BASE.'/*' => $this->podQrResponse()]);

        $this->actingAs($this->rider, 'sanctum');

        $created = $this->postJson("/api/v1/staff/deliveries/{$delivery->id}/payment-session", [])
            ->assertOk()
            ->assertJsonPath('data.status', 'ready');
        $sessionId = (string) $created->json('data.payment_session_id');
        $txn = (string) $created->json('data.merchant_txn_id');
        $this->assertSame('MTXN-PHASE6', $txn);

        $tinyPng = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
        $deliveredBody = [
            'payment_method' => 'qr',
            'merchant_txn_id' => $txn,
            'pod_collected_amount' => 1100.00,
            'customer_confirmed' => true,
            'customer_name' => 'Phase6 Tester',
            'customer_signature' => $tinyPng,
        ];

        // Not paid yet: polling stays unpaid and delivery as QR is refused.
        $this->getJson("/api/v1/staff/deliveries/{$delivery->id}/payment-session?refresh=1")
            ->assertOk()
            ->assertJsonPath('data.status', 'ready')
            ->assertJsonPath('data.paid', false);
        $this->postJson("/api/v1/staff/deliveries/{$delivery->id}/delivered", $deliveredBody)
            ->assertStatus(422);
        $this->assertNotSame(CourierStatus::DELIVERED, $shipment->fresh()->status);

        // Marketplace confirms payment on the Express callback URL.
        $this->postJson('/api/v1/express/callback', [
            'payment_session_id' => $sessionId,
            'merchant_txn_id' => $txn,
            'transaction_id' => 'TXN-PHASE6',
            'amount' => 1100.00,
            'tracking_number' => $shipment->tracking_number,
        ], ['X-Tukaatu-Event' => 'pod_payment.paid'])
            ->assertOk()
            ->assertJsonPath('status', 'paid');

        $this->getJson("/api/v1/staff/deliveries/{$delivery->id}/payment-session")
            ->assertOk()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.paid', true)
            ->assertJsonPath('data.merchant_txn_id', $txn)
            ->assertJsonPath('data.transaction_id', 'TXN-PHASE6');

        $this->postJson("/api/v1/staff/deliveries/{$delivery->id}/delivered", $deliveredBody)
            ->assertOk()
            ->assertJsonPath('success', true);

        $shipment->refresh();
        $this->assertSame(CourierStatus::DELIVERED, $shipment->status);
        $this->assertSame('paid_direct', strtolower((string) $shipment->pod_status));
        $this->assertNotSame('pending_deposit', strtolower((string) $shipment->settlement_status));
    }

    public function test_paid_callback_with_wrong_amount_is_rejected(): void
    {
        [, $delivery] = $this->makePodDelivery();
        Http::fake([self::MARKET_BASE.'/*' => $this->podQrResponse()]);
        $this->actingAs($this->rider, 'sanctum');

        $sessionId = (string) $this->postJson("/api/v1/staff/deliveries/{$delivery->id}/payment-session", [])
            ->assertOk()
            ->json('data.payment_session_id');

        $this->postJson('/api/v1/express/callback', [
            'payment_session_id' => $sessionId,
            'amount' => 10.00,
        ], ['X-Tukaatu-Event' => 'pod_payment.paid'])->assertStatus(422);

        $this->assertDatabaseHas('pod_payment_sessions', ['payment_session_id' => $sessionId, 'status' => 'ready']);
    }

    public function test_cash_pod_delivered_does_not_require_payment_session(): void
    {
        [$shipment, $delivery] = $this->makePodDelivery();

        Http::fake();
        $this->actingAs($this->rider, 'sanctum');

        $tinyPng = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

        $this->postJson("/api/v1/staff/deliveries/{$delivery->id}/delivered", [
            'payment_method' => 'cash',
            'pod_collected_amount' => 1100.00,
            'customer_confirmed' => true,
            'customer_name' => 'Cash Customer',
            'customer_signature' => $tinyPng,
        ])->assertOk();

        $shipment->refresh();
        $this->assertSame(CourierStatus::DELIVERED, $shipment->status);
        $this->assertSame('collected', strtolower((string) $shipment->pod_status));
        $this->assertSame('pending_deposit', strtolower((string) $shipment->settlement_status));

        Http::assertNothingSent();
    }

    public function test_marketplace_without_api_base_url_blocks_online_pod(): void
    {
        $this->marketplace->forceFill(['api_base_url' => null])->save();
        [$shipment, $delivery] = $this->makePodDelivery();
        Http::fake();

        $this->actingAs($this->rider, 'sanctum');

        $response = $this->postJson("/api/v1/staff/deliveries/{$delivery->id}/payment-session", []);
        $response->assertStatus(422);
        $this->assertStringContainsString(
            'marketplace API base URL',
            json_encode($response->json('errors'))
        );

        Http::assertNothingSent();
        $this->assertDatabaseMissing('pod_payment_sessions', ['shipment_id' => $shipment->id]);
    }

    public function test_payment_request_goes_to_the_stores_current_marketplace_without_env(): void
    {
        // Store moved to another marketplace in admin (e.g. Tukaatu -> FCA):
        // the POD request must follow it, with no .env gateway values set.
        $other = $this->makeMarketplace('https://pod-other-market.test');
        $this->merchant->forceFill(['marketplace_id' => $other->id])->save();

        [, $delivery] = $this->makePodDelivery();
        Http::fake(['https://pod-other-market.test/*' => $this->podQrResponse()]);

        $this->actingAs($this->rider, 'sanctum');

        $this->postJson("/api/v1/staff/deliveries/{$delivery->id}/payment-session", [])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'ready')
            ->assertJsonPath('data.gateway.marketplace_id', $other->id);

        Http::assertSent(fn ($request) => $request->url() === 'https://pod-other-market.test/api/v1/gateway/payments/pod-qr');
        Http::assertNotSent(fn ($request) => str_starts_with($request->url(), self::MARKET_BASE));
    }

    private function makeMarketplace(string $baseUrl): Marketplace
    {
        return Marketplace::query()->create([
            'name' => 'POD Test Marketplace '.substr(md5($baseUrl), 0, 6),
            'code' => 'pod-test-'.uniqid(),
            'api_base_url' => $baseUrl,
            'is_active' => true,
            'is_default' => false,
        ]);
    }

    /**
     * @return array{0: Shipment, 1: DeliveryAssignment}
     */
    private function makePodDelivery(): array
    {
        $tracking = 'TST-POD6-'.uniqid();
        $payload = [
            'tracking_number' => $tracking,
            'merchant_id' => $this->merchant->id,
            'merchant_order_id' => 'ORD-'.uniqid(),
            'service_type' => 'standard',
            'status' => CourierStatus::OUT_FOR_DELIVERY,
            'merchant_status' => CourierStatus::merchantStatus(CourierStatus::OUT_FOR_DELIVERY),
            'payment_type' => 'pod',
            'pod_amount' => 1000.00,
            'delivery_charge' => 100.00,
            'delivery_charge_paid_by' => 'customer',
            'total_collectable_amount' => 1100.00,
            'receiver_name' => 'POD Receiver',
            'receiver_phone' => '9800000099',
            'delivery_address' => 'Test Address',
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if (Schema::hasColumn('shipments', 'external_order_id')) {
            $payload['external_order_id'] = $payload['merchant_order_id'];
        }
        if (Schema::hasColumn('shipments', 'external_platform')) {
            $payload['external_platform'] = $this->merchant->external_platform;
        }
        if (Schema::hasColumn('shipments', 'marketplace_id')) {
            $payload['marketplace_id'] = $this->merchant->marketplace_id;
        }

        $shipmentId = DB::table('shipments')->insertGetId($payload);
        $shipment = Shipment::query()->findOrFail($shipmentId);

        $deliveryId = DB::table('delivery_assignments')->insertGetId([
            'shipment_id' => $shipment->id,
            'rider_id' => $this->rider->id,
            'branch_id' => $this->rider->branch_id,
            'status' => 'out_for_delivery',
            'assigned_at' => now()->subHour(),
            'accepted_at' => now()->subMinutes(50),
            'out_for_delivery_at' => now()->subMinutes(40),
            'arrived_at' => now()->subMinutes(5),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$shipment, DeliveryAssignment::query()->findOrFail($deliveryId)];
    }

    /** Live pod-qr response shape: success + data.{payment_url, qr_string, merchant_txn_id}. */
    private function podQrResponse()
    {
        return Http::response([
            'success' => true,
            'message' => 'POD QR created',
            'data' => [
                'merchant_txn_id' => 'MTXN-PHASE6',
                'amount' => 1100.00,
                'qr_string' => 'hamropay://pay/phase6',
                'payment_url' => 'https://pay.pod-market.test/c/phase6',
                'params' => ['merchant_transaction_id' => 'MTXN-PHASE6'],
            ],
        ], 200);
    }
}
