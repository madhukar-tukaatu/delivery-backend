<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Support\CourierStatus;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Modules\Delivery\Models\DeliveryAssignment;
use Modules\Merchant\Models\Merchant;
use Modules\POD\Models\PodPaymentSession;
use Modules\Shipment\Models\Shipment;
use Tests\TestCase;

/**
 * Phase 6 — doorstep online POD via Store Manager (not HamroPay).
 *
 * Covers staff payment-session create with {}, poll/refresh paid, and delivered
 * with payment_method=online. Cash POD remains a separate path.
 */
final class PodOnlinePaymentSessionTest extends TestCase
{
    use DatabaseTransactions;

    private User $rider;
    private Merchant $merchant;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.store_manager.payment.base_url' => 'https://store-manager.test',
            'services.store_manager.payment.integration_id' => 'tukaatu-express',
            'services.store_manager.payment.shared_secret' => 'test-shared-secret',
            'services.store_manager.payment.create_path' => '/api/v1/integrations/tukaatu-express/pod-payment-sessions',
            'services.store_manager.payment.status_path' => '/api/v1/integrations/tukaatu-express/pod-payment-sessions/{payment_session_id}',
            'services.store_manager.payment.timeout' => 5,
            'services.store_manager.payment.webhook_tolerance' => 300,
            'services.store_manager.payment.enabled' => false, // must NOT gate create
        ]);

        $this->rider = User::query()->findOrFail(175);
        $this->assertTrue($this->rider->can('deliveries.status'), 'Fixture rider 175 needs deliveries.status');

        $this->merchant = Merchant::query()
            ->whereNotNull('external_store_id')
            ->where('external_store_id', '!=', '')
            ->orderBy('id')
            ->first();
        $this->assertNotNull($this->merchant, 'Need a merchant with external_store_id');
    }

    public function test_create_payment_session_accepts_empty_body_and_uses_store_manager(): void
    {
        [$shipment, $delivery] = $this->makePodDelivery();

        Http::fake([
            'store-manager.test/api/v1/integrations/tukaatu-express/pod-payment-sessions' => Http::response(
                $this->storeManagerSessionPayload($shipment, 'pending'),
                201
            ),
        ]);

        $this->actingAs($this->rider, 'sanctum');

        $response = $this->postJson(
            "/api/v1/staff/deliveries/{$delivery->id}/payment-session",
            []
        );

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.payment_session_id', 'ps_test_phase6_001')
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.settlement_destination', 'merchant');

        $body = strtolower((string) $response->getContent());
        $this->assertStringNotContainsString('hamropay', $body);
        $this->assertStringNotContainsString('kyb', $body);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://store-manager.test/api/v1/integrations/tukaatu-express/pod-payment-sessions'
                && $request->method() === 'POST'
                && $request->hasHeader('X-Tukaatu-Integration-Id', 'tukaatu-express')
                && $request->hasHeader('X-Tukaatu-Signature')
                && $request->hasHeader('Idempotency-Key');
        });

        $this->assertDatabaseHas('pod_payment_sessions', [
            'shipment_id' => $shipment->id,
            'payment_session_id' => 'ps_test_phase6_001',
            'status' => 'pending',
        ]);
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

    public function test_refresh_poll_marks_paid_then_delivered_online_works(): void
    {
        [$shipment, $delivery] = $this->makePodDelivery();

        Http::fake([
            'store-manager.test/api/v1/integrations/tukaatu-express/pod-payment-sessions' => Http::response(
                $this->storeManagerSessionPayload($shipment, 'pending'),
                201
            ),
            'store-manager.test/api/v1/integrations/tukaatu-express/pod-payment-sessions/ps_test_phase6_001' => Http::response(
                $this->storeManagerSessionPayload($shipment, 'paid'),
                200
            ),
        ]);

        $this->actingAs($this->rider, 'sanctum');

        $this->postJson("/api/v1/staff/deliveries/{$delivery->id}/payment-session", [])
            ->assertOk()
            ->assertJsonPath('data.status', 'pending');

        $this->getJson("/api/v1/staff/deliveries/{$delivery->id}/payment-session?refresh=1")
            ->assertOk()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.payment_session_id', 'ps_test_phase6_001');

        $tinyPng = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

        $delivered = $this->postJson("/api/v1/staff/deliveries/{$delivery->id}/delivered", [
            'payment_method' => 'online',
            'payment_session_id' => 'ps_test_phase6_001',
            'pod_collected_amount' => 1100.00,
            'customer_confirmed' => true,
            'customer_name' => 'Phase6 Tester',
            'customer_signature' => $tinyPng,
        ]);

        $delivered->assertOk()->assertJsonPath('success', true);

        $shipment->refresh();
        $this->assertSame(CourierStatus::DELIVERED, $shipment->status);
        $this->assertSame('paid_direct', strtolower((string) $shipment->pod_status));
        $this->assertNotSame('pending_deposit', strtolower((string) $shipment->settlement_status));
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

    /**
     * @return array{0: Shipment, 1: DeliveryAssignment}
     */
    private function makePodDelivery(): array
    {
        $tracking = 'TST-POD6-' . uniqid();
        $payload = [
            'tracking_number' => $tracking,
            'merchant_id' => $this->merchant->id,
            'merchant_order_id' => 'ORD-' . uniqid(),
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

        if (Schema::hasColumn('shipments', 'external_platform')) {
            $payload['external_platform'] = $this->merchant->external_platform;
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

        $delivery = DeliveryAssignment::query()->findOrFail($deliveryId);

        return [$shipment, $delivery];
    }

    private function storeManagerSessionPayload(Shipment $shipment, string $status): array
    {
        $paid = $status === 'paid';

        return [
            'success' => true,
            'data' => [
                'payment_session_id' => 'ps_test_phase6_001',
                'status' => $status,
                'merchant' => [
                    'external_store_id' => $this->merchant->external_store_id,
                    'external_platform' => $this->merchant->external_platform,
                    'name' => $this->merchant->name,
                ],
                'shipment' => [
                    'tracking_number' => $shipment->tracking_number,
                    'merchant_order_id' => $shipment->merchant_order_id,
                ],
                'amount' => '1100.00',
                'currency' => 'NPR',
                'settlement_destination' => 'merchant',
                'provider_reference' => $paid ? 'FONEPAY-TEST-001' : null,
                'paid_at' => $paid ? now()->toIso8601String() : null,
                'payment' => [
                    'channel' => 'qr',
                    'purpose' => 'pod',
                    'qr' => [
                        'format' => 'payload',
                        'image_url' => null,
                        'payload' => 'https://pay.example/ps_test_phase6_001',
                    ],
                    'checkout_url' => 'https://pay.example/ps_test_phase6_001',
                ],
                'expires_at' => now()->addMinutes(15)->toIso8601String(),
                'created_at' => now()->toIso8601String(),
            ],
        ];
    }
}