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
use Modules\Shipment\Models\Shipment;
use Tests\TestCase;

/**
 * Phase 6 doorstep online POD via HamroPay gateway (not Store Manager).
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
            "hamropay.api_base_url" => "https://hamropay.test",
            "hamropay.gateway_url" => "https://gateway.hamropay.test",
            "hamropay.client_id" => "client-test",
            "hamropay.client_api_key" => "key-test",
            "hamropay.secret" => "secret-test",
            "hamropay.merchant_id" => "platform-merchant",
            "hamropay.verify_ssl" => false,
        ]);

        $this->rider = User::query()->findOrFail(175);
        $this->assertTrue($this->rider->can("deliveries.status"), "Fixture rider 175 needs deliveries.status");

        $this->merchant = Merchant::query()
            ->whereNotNull("external_store_id")
            ->where("external_store_id", "!=", "")
            ->orderBy("id")
            ->first();
        $this->assertNotNull($this->merchant, "Need a merchant with external_store_id");
    }

    public function test_create_payment_session_accepts_empty_body_and_uses_hamropay(): void
    {
        [$shipment, $delivery] = $this->makePodDelivery();
        Http::fake(['*' => $this->hamroPayResponder()]);

        $this->actingAs($this->rider, "sanctum");

        $response = $this->postJson("/api/v1/staff/deliveries/{$delivery->id}/payment-session", []);

        $response->assertOk()
            ->assertJsonPath("success", true)
            ->assertJsonPath("data.status", "pending")
            ->assertJsonPath("data.settlement_destination", "merchant");

        $txn = (string) $response->json("data.merchant_txn_id");
        $this->assertNotSame("", $txn);
        $this->assertNotSame("", (string) $response->json("data.qr_string"));
        $this->assertNotSame("", (string) $response->json("data.payment_url"));
        $this->assertIsArray($response->json("data.params"));
        $this->assertStringNotContainsString("complete hamropay kyb", strtolower((string) $response->getContent()));

        Http::assertSent(function ($request) {
            return str_contains($request->url(), "/v1/checkout/sessionId")
                && $request->method() === "POST"
                && (int) $request["transactionAmount"] === 110000;
        });

        $this->assertDatabaseHas("pod_payment_sessions", [
            "shipment_id" => $shipment->id,
            "payment_session_id" => $txn,
            "status" => "pending",
        ]);
    }

    public function test_create_payment_session_rejects_client_amount_override(): void
    {
        [, $delivery] = $this->makePodDelivery();

        $this->actingAs($this->rider, "sanctum");

        $this->postJson(
            "/api/v1/staff/deliveries/{$delivery->id}/payment-session",
            ["amount" => 1, "payment_method" => "online"]
        )->assertStatus(422)
            ->assertJsonValidationErrors(["amount", "payment_method"]);
    }

    public function test_refresh_poll_marks_paid_then_delivered_online_and_qr(): void
    {
        [$shipment, $delivery] = $this->makePodDelivery();
        Http::fake(['*' => $this->hamroPayResponder(paid: true)]);

        $this->actingAs($this->rider, "sanctum");

        $created = $this->postJson("/api/v1/staff/deliveries/{$delivery->id}/payment-session", [])
            ->assertOk()
            ->assertJsonPath("data.status", "pending");

        $txn = (string) $created->json("data.merchant_txn_id");

        $this->getJson("/api/v1/staff/deliveries/{$delivery->id}/payment-session?refresh=1")
            ->assertOk()
            ->assertJsonPath("data.status", "paid")
            ->assertJsonPath("data.merchant_txn_id", $txn);

        $tinyPng = "data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==";

        $this->postJson("/api/v1/staff/deliveries/{$delivery->id}/delivered", [
            "payment_method" => "qr",
            "merchant_txn_id" => $txn,
            "pod_collected_amount" => 1100.00,
            "customer_confirmed" => true,
            "customer_name" => "Phase6 Tester",
            "customer_signature" => $tinyPng,
        ])->assertOk()->assertJsonPath("success", true);

        $shipment->refresh();
        $this->assertSame(CourierStatus::DELIVERED, $shipment->status);
        $this->assertSame("paid_direct", strtolower((string) $shipment->pod_status));
        $this->assertNotSame("pending_deposit", strtolower((string) $shipment->settlement_status));

        Http::assertSent(fn ($request) => str_contains($request->url(), "/v1/checkout/transaction"));
    }

    public function test_cash_pod_delivered_does_not_require_payment_session(): void
    {
        [$shipment, $delivery] = $this->makePodDelivery();

        Http::fake();
        $this->actingAs($this->rider, "sanctum");

        $tinyPng = "data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==";

        $this->postJson("/api/v1/staff/deliveries/{$delivery->id}/delivered", [
            "payment_method" => "cash",
            "pod_collected_amount" => 1100.00,
            "customer_confirmed" => true,
            "customer_name" => "Cash Customer",
            "customer_signature" => $tinyPng,
        ])->assertOk();

        $shipment->refresh();
        $this->assertSame(CourierStatus::DELIVERED, $shipment->status);
        $this->assertSame("collected", strtolower((string) $shipment->pod_status));
        $this->assertSame("pending_deposit", strtolower((string) $shipment->settlement_status));

        Http::assertNothingSent();
    }

    public function test_missing_sub_merchant_requires_kyb(): void
    {
        [$shipment, $delivery] = $this->makePodDelivery();
        $this->merchant->forceFill([
            "external_store_id" => null,
            "hamropay_merchant_id" => null,
            "hamropay_business_id" => null,
        ])->save();

        $this->actingAs($this->rider, "sanctum");

        $response = $this->postJson("/api/v1/staff/deliveries/{$delivery->id}/payment-session", []);
        $response->assertStatus(422);
        $this->assertStringContainsString(
            "HamroPay KYB",
            json_encode($response->json("errors"))
        );
    }


    public function test_payment_session_uses_marketplace_account_without_env(): void
    {
        // Empty env/config must not block when marketplace HamroPay is saved in admin.
        // Local APP_KEY may be non-32-byte; use a valid key so credentials encrypt in-test.
        config([
            "app.key" => "base64:" . base64_encode(random_bytes(32)),
            "hamropay.api_base_url" => "",
            "hamropay.gateway_url" => "",
            "hamropay.client_id" => "",
            "hamropay.client_api_key" => "",
            "hamropay.secret" => "",
            "hamropay.merchant_id" => "",
        ]);

        $marketplaceId = \Illuminate\Support\Facades\DB::table("marketplaces")->where("is_active", 1)->value("id");
        $this->assertNotNull($marketplaceId, "Need an active marketplace row");

        if (\Illuminate\Support\Facades\Schema::hasColumn("merchants", "marketplace_id")) {
            $this->merchant->forceFill(["marketplace_id" => $marketplaceId])->save();
        }

        app(\Modules\Billing\Services\PaymentGatewayAccountService::class)->upsert(
            "marketplace",
            (int) $marketplaceId,
            "hamropay",
            [
                "api_base_url" => "https://hamropay.test",
                "gateway_url" => "https://gateway.hamropay.test",
                "merchant_id" => "platform-merchant",
                "client_id" => "client-test",
                "client_api_key" => "key-test",
                "secret" => "secret-test",
                "verify_ssl" => false,
            ],
            ["label" => "default", "is_enabled" => true, "is_default" => true],
        );

        [$shipment, $delivery] = $this->makePodDelivery();
        Http::fake(["*" => $this->hamroPayResponder()]);

        $this->actingAs($this->rider, "sanctum");

        $this->postJson("/api/v1/staff/deliveries/{$delivery->id}/payment-session", [])
            ->assertOk()
            ->assertJsonPath("success", true)
            ->assertJsonPath("data.status", "pending");

        Http::assertSent(fn ($request) => str_contains($request->url(), "/v1/checkout/sessionId"));
    }

    /**
     * @return array{0: Shipment, 1: DeliveryAssignment}
     */
    private function makePodDelivery(): array
    {
        $tracking = "TST-POD6-" . uniqid();
        $payload = [
            "tracking_number" => $tracking,
            "merchant_id" => $this->merchant->id,
            "merchant_order_id" => "ORD-" . uniqid(),
            "service_type" => "standard",
            "status" => CourierStatus::OUT_FOR_DELIVERY,
            "merchant_status" => CourierStatus::merchantStatus(CourierStatus::OUT_FOR_DELIVERY),
            "payment_type" => "pod",
            "pod_amount" => 1000.00,
            "delivery_charge" => 100.00,
            "delivery_charge_paid_by" => "customer",
            "total_collectable_amount" => 1100.00,
            "receiver_name" => "POD Receiver",
            "receiver_phone" => "9800000099",
            "delivery_address" => "Test Address",
            "created_at" => now(),
            "updated_at" => now(),
        ];

        if (Schema::hasColumn("shipments", "external_order_id")) {
            $payload["external_order_id"] = $payload["merchant_order_id"];
        }
        if (Schema::hasColumn("shipments", "external_platform")) {
            $payload["external_platform"] = $this->merchant->external_platform;
        }

        $shipmentId = DB::table("shipments")->insertGetId($payload);
        $shipment = Shipment::query()->findOrFail($shipmentId);

        $deliveryId = DB::table("delivery_assignments")->insertGetId([
            "shipment_id" => $shipment->id,
            "rider_id" => $this->rider->id,
            "branch_id" => $this->rider->branch_id,
            "status" => "out_for_delivery",
            "assigned_at" => now()->subHour(),
            "accepted_at" => now()->subMinutes(50),
            "out_for_delivery_at" => now()->subMinutes(40),
            "arrived_at" => now()->subMinutes(5),
            "created_at" => now(),
            "updated_at" => now(),
        ]);

        return [$shipment, DeliveryAssignment::query()->findOrFail($deliveryId)];
    }

    private function hamroPayResponder(bool $paid = false)
    {
        return Http::response([
            "sessionId" => "hp-session-phase6",
            "merchantId" => "platform-merchant",
            "status" => $paid ? "SUCCESS" : "PENDING",
            "transactionId" => $paid ? "TXN-PHASE6" : null,
            "qr_string" => "hamropay://pay/phase6",
        ], 200);
    }
}