<?php

declare(strict_types=1);

namespace Tests\Feature\Rate;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Rate\Services\PricingEngineService;
use Tests\TestCase;

/**
 * Delivery pricing must be driven purely by coverage locations:
 * - base rate: pickup zone -> delivery zone route rate
 * - extra distance: matched delivery coverage location -> delivery pin
 * The operational `branches` table is reported only and never changes price.
 */
final class PricingCoverageZoneDistanceTest extends TestCase
{
    private const KTM_ZONE = [27.7050, 85.3150];

    private const GOLBAZAR_ZONE = [26.7926242, 86.3312101];

    private const GOLBAZAR_PIN = [26.8032671, 86.3325657];

    private const BIR_HOSPITAL_PIN = [27.70553, 85.312685];

    private const JANAKPUR_BRANCH = [26.7288, 85.9258];

    private const POKHARA = [28.2096, 83.9856];

    private const BAGLUNG = [28.2713, 83.5898];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'pricing_test',
            'database.connections.pricing_test' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => false,
            ],
        ]);
        DB::purge('pricing_test');

        $this->createSchema();
        $this->seedBaseData();
    }

    public function test_golbazar_uses_zone_distance_not_far_operational_branch(): void
    {
        $result = $this->engine()->calculate($this->payload(self::GOLBAZAR_PIN));

        $zoneKm = $this->haversine(self::GOLBAZAR_ZONE, self::GOLBAZAR_PIN);
        $this->assertEqualsWithDelta(1.19, $zoneKm, 0.01);

        $this->assertSame(1, $result['pickup_branch']['id']);
        $this->assertSame(29, $result['delivery_branch']['id']);
        $this->assertEqualsWithDelta($zoneKm, $result['delivery_branch']['distance_km'], 0.001);
        $this->assertEqualsWithDelta($zoneKm, $result['delivery_branch']['zone_distance_km'], 0.001);
        $this->assertSame('coverage_location', $result['delivery_branch']['distance_basis']);

        // The nearest operational branch (Janakpur, ~41 km) is reported only.
        $this->assertSame(902, $result['delivery_branch']['operational_branch_id']);
        $this->assertGreaterThan(40.0, $result['delivery_branch']['operational_branch_distance_km']);

        $this->assertEquals(0.0, $result['breakdown']['extra_delivery_distance']['total']);
        $this->assertEquals(199.0, $result['route']['base_rate']);
        $this->assertEquals(199.0, (float) $result['final_price']);
    }

    public function test_pickup_distance_is_from_pickup_coverage_location(): void
    {
        $result = $this->engine()->calculate($this->payload(self::GOLBAZAR_PIN));

        $this->assertEqualsWithDelta(
            $this->haversine(self::KTM_ZONE, self::BIR_HOSPITAL_PIN),
            $result['pickup_branch']['distance_km'],
            0.001
        );
        $this->assertSame('coverage_location', $result['pickup_branch']['distance_basis']);
    }

    public function test_pin_beyond_free_radius_charges_extra_from_zone_distance(): void
    {
        // ~9 km north of the Golbazar zone, still inside its 15 km radius.
        $pin = [self::GOLBAZAR_ZONE[0] + 0.081, self::GOLBAZAR_ZONE[1]];
        $zoneKm = $this->haversine(self::GOLBAZAR_ZONE, $pin);
        $this->assertGreaterThan(5.0, $zoneKm);

        $result = $this->engine()->calculate($this->payload($pin));

        $expectedExtra = round(($zoneKm - 5) * 6, 2);

        $this->assertSame(29, $result['delivery_branch']['id']);
        $this->assertEqualsWithDelta($zoneKm, $result['delivery_branch']['distance_km'], 0.001);
        $this->assertEqualsWithDelta($expectedExtra, $result['breakdown']['extra_delivery_distance']['total'], 0.01);
        $this->assertEqualsWithDelta(199 + $expectedExtra, (float) $result['final_price'], 0.01);
    }

    public function test_zone_without_own_branch_is_priced_from_its_zone_not_the_serving_branch(): void
    {
        // Baglung zone has no branch; Pokhara branch serves it operationally.
        $pin = [self::BAGLUNG[0] + 0.01, self::BAGLUNG[1]];
        $zoneKm = $this->haversine(self::BAGLUNG, $pin);

        $result = $this->engine()->calculate($this->payload($pin));

        $this->assertSame(41, $result['delivery_branch']['id']);
        $this->assertEqualsWithDelta($zoneKm, $result['delivery_branch']['distance_km'], 0.001);
        $this->assertSame(904, $result['delivery_branch']['operational_branch_id']);
        $this->assertGreaterThan(35.0, $result['delivery_branch']['operational_branch_distance_km']);
        $this->assertEquals(0.0, $result['breakdown']['extra_delivery_distance']['total']);
        $this->assertEquals(250.0, (float) $result['final_price']);
    }

    public function test_branch_route_reports_straight_line_total_distance(): void
    {
        $result = $this->engine()->calculate($this->payload(self::GOLBAZAR_PIN));

        $this->assertSame('straight_line', $result['route']['total_distance_basis']);
        $this->assertEqualsWithDelta(
            $this->haversine(self::BIR_HOSPITAL_PIN, self::GOLBAZAR_PIN),
            $result['route']['total_distance_km'],
            0.001
        );
    }

    public function test_public_estimate_endpoint_returns_zone_distance(): void
    {
        $response = $this->postJson('/api/v1/public/pricing/estimate', [
            'delivery_address' => 'Golbazar, Siraha',
            'delivery_latitude' => self::GOLBAZAR_PIN[0],
            'delivery_longitude' => self::GOLBAZAR_PIN[1],
            'parcel_type' => 'non_fragile',
            'pickup_address' => 'Bir Hospital, Kanti Path, Kathmandu',
            'pickup_latitude' => self::BIR_HOSPITAL_PIN[0],
            'pickup_longitude' => self::BIR_HOSPITAL_PIN[1],
            'service_type' => 'standard',
        ]);

        $response->assertOk();
        $data = $response->json('data');

        $this->assertEquals(199.0, $data['price']);
        $this->assertSame(29, $data['delivery_branch']['id']);
        $this->assertEqualsWithDelta(1.19, $data['delivery_branch']['distance_to_delivery_location_km'], 0.01);
        $this->assertEqualsWithDelta(1.19, $data['delivery_branch']['zone_distance_km'], 0.01);
        $this->assertSame('coverage_location', $data['delivery_branch']['distance_basis']);
        $this->assertSame('straight_line', $data['route']['total_distance_basis']);
        $this->assertGreaterThan(100.0, $data['route']['total_distance_km']);
    }

    private function engine(): PricingEngineService
    {
        return app(PricingEngineService::class);
    }

    private function payload(array $deliveryPin): array
    {
        return [
            'pickup_address' => 'Bir Hospital, Kathmandu',
            'pickup_latitude' => self::BIR_HOSPITAL_PIN[0],
            'pickup_longitude' => self::BIR_HOSPITAL_PIN[1],
            'delivery_address' => 'Test delivery',
            'delivery_latitude' => $deliveryPin[0],
            'delivery_longitude' => $deliveryPin[1],
            'service_type' => 'standard',
            'payment_type' => 'prepaid',
            'pod_amount' => 0.0,
            'packets' => [[
                'packet_reference' => 'T-1',
                'product_id' => null,
                'name' => 'Test parcel',
                'quantity' => 1,
                'actual_weight_kg' => 1.5,
                'unit_price' => 0.0,
                'declared_value' => 0.0,
                'parcel_type' => 'non_fragile',
                'length_cm' => null,
                'width_cm' => null,
                'height_cm' => null,
            ]],
            'packet_count' => 1,
            'parcel_weight' => 1.5,
            'parcel_value' => 0.0,
            'parcel_type' => 'non_fragile',
        ];
    }

    private function haversine(array $a, array $b): float
    {
        $r = 6371.0088;
        $x = sin(deg2rad($b[0] - $a[0]) / 2) ** 2
            + cos(deg2rad($a[0])) * cos(deg2rad($b[0])) * sin(deg2rad($b[1] - $a[1]) / 2) ** 2;

        return $r * 2 * atan2(sqrt($x), sqrt(1 - $x));
    }

    private function createSchema(): void
    {
        Schema::create('pricing_settings', function (Blueprint $t) {
            $t->id();
            $t->boolean('is_active')->default(true);
            $t->decimal('included_weight_kg', 8, 3)->default(1.5);
            $t->decimal('same_branch_weight_rate', 8, 2)->default(20);
            $t->decimal('other_branch_weight_rate', 8, 2)->default(30);
            $t->decimal('fragile_multiplier', 8, 3)->default(1.05);
            $t->decimal('included_delivery_distance_km', 8, 2)->default(5);
            $t->decimal('extra_distance_rate_per_km', 8, 2)->default(6);
            $t->decimal('volumetric_divisor', 10, 2)->default(5000);
            $t->boolean('vat_inclusive')->default(true);
            $t->decimal('vat_percentage', 5, 2)->default(13);
        });

        Schema::create('service_types', function (Blueprint $t) {
            $t->id();
            $t->string('code');
            $t->string('name');
            $t->boolean('is_active')->default(true);
            $t->integer('estimated_hours')->nullable();
        });

        Schema::create('coverage_locations', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('code');
            $t->string('type');
            $t->unsignedBigInteger('parent_id')->nullable();
            $t->unsignedBigInteger('branch_id')->nullable();
            $t->decimal('latitude', 10, 7)->nullable();
            $t->decimal('longitude', 10, 7)->nullable();
            $t->decimal('coverage_radius_km', 8, 2)->default(10);
            $t->string('status')->default('active');
        });

        Schema::create('branches', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('type')->default('main_branch');
            $t->unsignedBigInteger('parent_id')->nullable();
            $t->unsignedBigInteger('coverage_location_id')->nullable();
            $t->string('status')->default('active');
            $t->string('account_invitation_status')->nullable();
            $t->decimal('latitude', 10, 7)->nullable();
            $t->decimal('longitude', 10, 7)->nullable();
        });

        Schema::create('branch_route_rates', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('pickup_coverage_location_id');
            $t->unsignedBigInteger('delivery_coverage_location_id');
            $t->decimal('base_rate', 10, 2);
            $t->boolean('is_active')->default(true);
            $t->boolean('same_day_enabled')->default(false);
            $t->boolean('express_enabled')->default(false);
        });
    }

    private function seedBaseData(): void
    {
        DB::table('pricing_settings')->insert(['is_active' => true]);
        DB::table('service_types')->insert(['code' => 'standard', 'name' => 'Standard', 'estimated_hours' => 24]);

        DB::table('coverage_locations')->insert([
            ['id' => 1, 'name' => 'Kathmandu main', 'code' => 'KTM', 'type' => 'main_branch_zone', 'latitude' => self::KTM_ZONE[0], 'longitude' => self::KTM_ZONE[1], 'coverage_radius_km' => 15, 'branch_id' => null],
            ['id' => 29, 'name' => 'Golbazar Main Branch', 'code' => 'TUK-GOL-MAIN-2', 'type' => 'main_branch_zone', 'latitude' => self::GOLBAZAR_ZONE[0], 'longitude' => self::GOLBAZAR_ZONE[1], 'coverage_radius_km' => 15, 'branch_id' => null],
            ['id' => 40, 'name' => 'Pokhara main', 'code' => 'PKR', 'type' => 'main_branch_zone', 'latitude' => self::POKHARA[0], 'longitude' => self::POKHARA[1], 'coverage_radius_km' => 15, 'branch_id' => null],
            ['id' => 41, 'name' => 'Baglung', 'code' => 'BGL', 'type' => 'main_branch_zone', 'latitude' => self::BAGLUNG[0], 'longitude' => self::BAGLUNG[1], 'coverage_radius_km' => 15, 'branch_id' => null],
        ]);

        // Operational branches: no Golbazar or Baglung branch exists.
        DB::table('branches')->insert([
            ['id' => 901, 'name' => 'Kathmandu branch', 'coverage_location_id' => 1, 'latitude' => self::KTM_ZONE[0], 'longitude' => self::KTM_ZONE[1]],
            ['id' => 902, 'name' => 'Janakpur branch', 'coverage_location_id' => null, 'latitude' => self::JANAKPUR_BRANCH[0], 'longitude' => self::JANAKPUR_BRANCH[1]],
            ['id' => 904, 'name' => 'Pokhara branch', 'coverage_location_id' => 40, 'latitude' => self::POKHARA[0], 'longitude' => self::POKHARA[1]],
        ]);

        DB::table('branch_route_rates')->insert([
            ['pickup_coverage_location_id' => 1, 'delivery_coverage_location_id' => 29, 'base_rate' => 199],
            ['pickup_coverage_location_id' => 1, 'delivery_coverage_location_id' => 41, 'base_rate' => 250],
        ]);
    }
}
