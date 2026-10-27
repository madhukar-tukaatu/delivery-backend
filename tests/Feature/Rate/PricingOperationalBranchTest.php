<?php

declare(strict_types=1);

namespace Tests\Feature\Rate;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Rate\Services\PricingEngineService;
use Tests\TestCase;

/**
 * Operational delivery pricing:
 * - customer coverage locations (pickup_branch / delivery_branch) are display only;
 * - base rate: route rate between the serving branches' coverage locations;
 * - extra distance: delivery serving branch -> delivery pin;
 * - every rate/threshold comes from the ACTIVE pricing setting, including
 *   the columns written by the admin pricing screen.
 */
final class PricingOperationalBranchTest extends TestCase
{
    private const KTM_ZONE = [27.7050, 85.3150];

    private const GOLBAZAR_ZONE = [26.7926242, 86.3312101];

    private const GOLBAZAR_PIN = [26.8032671, 86.3325657];

    private const BIR_HOSPITAL_PIN = [27.70553, 85.312685];

    private const JANAKPUR = [26.7288, 85.9258];

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

    public function test_golbazar_is_priced_via_serving_janakpur_branch(): void
    {
        $result = $this->engine()->calculate($this->payload(self::GOLBAZAR_PIN));

        // Display: the customer's coverage location is still Golbazar (~1.19 km).
        $this->assertSame(29, $result['delivery_branch']['id']);
        $this->assertSame('Golbazar Main Branch', $result['delivery_branch']['name']);
        $this->assertEqualsWithDelta(1.19, $result['delivery_branch']['zone_distance_km'], 0.01);

        // Pricing: Janakpur branch serves the pin, its coverage location prices the route.
        $serving = $result['delivery_serving_branch'];
        $branchKm = $this->assignmentDistance(self::JANAKPUR, self::GOLBAZAR_PIN);

        $this->assertSame(902, $serving['id']);
        $this->assertSame(30, $serving['coverage_location_id']);
        $this->assertSame('branch_coverage_location_id', $serving['coverage_location_source']);
        $this->assertSame('operational_branch', $serving['distance_basis']);
        $this->assertFalse($serving['fallback']);
        $this->assertEqualsWithDelta($branchKm, $serving['distance_km'], 0.001);
        $this->assertGreaterThan(40.0, $branchKm);

        $this->assertSame('operational_branch', $result['delivery_branch']['distance_basis']);
        $this->assertEqualsWithDelta($branchKm, $result['delivery_branch']['distance_km'], 0.001);

        $expectedExtra = round(($branchKm - 5) * 6, 2);

        $this->assertSame(1, $result['route']['from_coverage_location_id']);
        $this->assertSame(30, $result['route']['to_coverage_location_id']);
        $this->assertEquals(220.0, $result['route']['base_rate']);
        $this->assertEqualsWithDelta($expectedExtra, $result['breakdown']['extra_delivery_distance']['total'], 0.01);
        $this->assertEqualsWithDelta(220 + $expectedExtra, (float) $result['final_price'], 0.01);
    }

    public function test_pickup_side_uses_serving_branch_zone_and_has_no_extra_charge(): void
    {
        $result = $this->engine()->calculate($this->payload(self::GOLBAZAR_PIN));

        $this->assertSame(901, $result['pickup_serving_branch']['id']);
        $this->assertSame(1, $result['pickup_serving_branch']['coverage_location_id']);
        $this->assertSame(1, $result['pickup_branch']['id']);
        $this->assertSame('coverage_location', $result['pickup_branch']['distance_basis']);
        $this->assertEqualsWithDelta(
            $this->haversine(self::KTM_ZONE, self::BIR_HOSPITAL_PIN),
            $result['pickup_branch']['zone_distance_km'],
            0.001
        );
        $this->assertArrayNotHasKey('extra_pickup_distance', $result['breakdown']);
    }

    public function test_branch_without_coverage_location_falls_back_to_nearest_zone(): void
    {
        // Janakpur branch has no coverage_location_id and no Janakpur zone exists,
        // so the zone nearest the branch (Golbazar) prices the route.
        DB::table('branches')->where('id', 902)->update(['coverage_location_id' => null]);
        DB::table('coverage_locations')->where('id', 30)->delete();

        $result = $this->engine()->calculate($this->payload(self::GOLBAZAR_PIN));
        $serving = $result['delivery_serving_branch'];
        $branchKm = $this->assignmentDistance(self::JANAKPUR, self::GOLBAZAR_PIN);

        $this->assertSame(902, $serving['id']);
        $this->assertSame(29, $serving['coverage_location_id']);
        $this->assertSame('nearest_to_branch', $serving['coverage_location_source']);
        $this->assertTrue($serving['coverage_location_fallback']);
        $this->assertEquals(199.0, $result['route']['base_rate']);
        $this->assertEqualsWithDelta(199 + round(($branchKm - 5) * 6, 2), (float) $result['final_price'], 0.01);
    }

    public function test_coverage_location_branch_link_is_used_when_branch_column_is_null(): void
    {
        DB::table('branches')->where('id', 902)->update(['coverage_location_id' => null]);
        DB::table('coverage_locations')->where('id', 30)->update(['branch_id' => 902]);

        $serving = $this->engine()->calculate($this->payload(self::GOLBAZAR_PIN))['delivery_serving_branch'];

        $this->assertSame(30, $serving['coverage_location_id']);
        $this->assertSame('coverage_location_branch_id', $serving['coverage_location_source']);
        $this->assertFalse($serving['coverage_location_fallback']);
    }

    public function test_zone_with_its_own_branch_has_small_distance_and_no_extra(): void
    {
        $pin = [self::POKHARA[0] + 0.01, self::POKHARA[1]];

        $result = $this->engine()->calculate($this->payload($pin));

        $this->assertSame(40, $result['delivery_branch']['id']);
        $this->assertSame(904, $result['delivery_serving_branch']['id']);
        $this->assertSame(40, $result['delivery_serving_branch']['coverage_location_id']);
        $this->assertLessThan(2.0, $result['delivery_serving_branch']['distance_km']);
        $this->assertEquals(0.0, $result['breakdown']['extra_delivery_distance']['total']);
        $this->assertEquals(300.0, (float) $result['final_price']);
    }

    public function test_no_operational_branch_falls_back_to_customer_coverage_location(): void
    {
        DB::table('branches')->delete();

        $result = $this->engine()->calculate($this->payload(self::GOLBAZAR_PIN));

        $this->assertTrue($result['delivery_serving_branch']['fallback']);
        $this->assertSame('no_operational_branch', $result['delivery_serving_branch']['fallback_reason']);
        $this->assertSame('coverage_location', $result['delivery_branch']['distance_basis']);
        $this->assertSame(29, $result['route']['to_coverage_location_id']);
        $this->assertEquals(199.0, (float) $result['final_price']);
    }

    public function test_active_setting_admin_columns_are_used_and_inactive_rows_ignored(): void
    {
        DB::table('pricing_settings')->update(['is_active' => false]);

        // A newer but INACTIVE version must not be used.
        DB::table('pricing_settings')->insert([
            'is_active' => false, 'name' => 'Draft', 'base_distance_km' => 0, 'extra_distance_rate' => 100,
        ]);

        // Active version saved by the admin screen: only the admin columns
        // carry the real values; legacy columns keep their defaults (5 / 6).
        $activeId = DB::table('pricing_settings')->insertGetId([
            'is_active' => true, 'name' => 'Admin v2', 'base_distance_km' => 10, 'extra_distance_rate' => 8,
        ]);

        DB::table('pricing_settings')->insert([
            'is_active' => false, 'name' => 'Old draft', 'base_distance_km' => 0, 'extra_distance_rate' => 50,
        ]);

        $result = $this->engine()->calculate($this->payload(self::GOLBAZAR_PIN));
        $branchKm = $this->assignmentDistance(self::JANAKPUR, self::GOLBAZAR_PIN);
        $expectedExtra = round(($branchKm - 10) * 8, 2);

        $this->assertSame($activeId, $result['pricing_setting']['id']);
        $this->assertSame('base_distance_km', $result['pricing_setting']['value_sources']['included_delivery_distance_km']);
        $this->assertSame('extra_distance_rate', $result['pricing_setting']['value_sources']['extra_distance_rate_per_km']);
        $this->assertEquals(10.0, $result['breakdown']['extra_delivery_distance']['included_distance_km']);
        $this->assertEquals(8.0, $result['breakdown']['extra_delivery_distance']['rate_per_km']);
        $this->assertEqualsWithDelta($expectedExtra, $result['breakdown']['extra_delivery_distance']['total'], 0.01);
        $this->assertEqualsWithDelta(220 + $expectedExtra, (float) $result['final_price'], 0.01);
    }

    public function test_legacy_columns_are_used_when_admin_columns_are_empty(): void
    {
        DB::table('pricing_settings')->update([
            'base_distance_km' => null,
            'extra_distance_rate' => null,
            'included_delivery_distance_km' => 3,
            'extra_distance_rate_per_km' => 7,
        ]);

        $result = $this->engine()->calculate($this->payload(self::GOLBAZAR_PIN));

        $this->assertSame('included_delivery_distance_km', $result['pricing_setting']['value_sources']['included_delivery_distance_km']);
        $this->assertEquals(3.0, $result['breakdown']['extra_delivery_distance']['included_distance_km']);
        $this->assertEquals(7.0, $result['breakdown']['extra_delivery_distance']['rate_per_km']);
    }

    public function test_fragile_disabled_on_active_setting_removes_fragile_surcharge(): void
    {
        $pin = [self::POKHARA[0] + 0.01, self::POKHARA[1]];
        $payload = $this->payload($pin);
        $payload['packets'][0]['parcel_type'] = 'fragile';
        $payload['parcel_type'] = 'fragile';

        $enabled = $this->engine()->calculate($payload);
        $this->assertEqualsWithDelta(315.0, (float) $enabled['final_price'], 0.01);

        DB::table('pricing_settings')->update(['fragile_enabled' => false]);

        $disabled = $this->engine()->calculate($payload);
        $this->assertEquals(300.0, (float) $disabled['final_price']);
    }

    public function test_public_estimate_endpoint_reports_serving_branch(): void
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
        $branchKm = $this->assignmentDistance(self::JANAKPUR, self::GOLBAZAR_PIN);
        $expectedExtra = round(($branchKm - 5) * 6, 2);

        $this->assertEqualsWithDelta(220 + $expectedExtra, $data['price'], 0.01);
        $this->assertSame(29, $data['delivery_branch']['id']);
        $this->assertEqualsWithDelta(1.19, $data['delivery_branch']['distance_to_delivery_location_km'], 0.01);
        $this->assertSame(902, $data['delivery_serving_branch']['id']);
        $this->assertSame(30, $data['delivery_serving_branch']['coverage_location_id']);
        $this->assertSame('operational_branch', $data['delivery_serving_branch']['distance_basis']);
        $this->assertEqualsWithDelta($branchKm, $data['extra_delivery_distance']['distance_km'], 0.001);
        $this->assertEqualsWithDelta($expectedExtra, $data['extra_delivery_distance']['charge'], 0.01);
        $this->assertSame(1, $data['route']['from_coverage_location_id']);
        $this->assertSame(30, $data['route']['to_coverage_location_id']);
        $this->assertSame('straight_line', $data['route']['total_distance_basis']);
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

    /** Same formula as BranchAssignmentService::distanceKm (R = 6371, 2 dp). */
    private function assignmentDistance(array $a, array $b): float
    {
        $r = 6371;
        $x = sin(deg2rad($b[0] - $a[0]) / 2) ** 2
            + cos(deg2rad($a[0])) * cos(deg2rad($b[0])) * sin(deg2rad($b[1] - $a[1]) / 2) ** 2;

        return round($r * 2 * atan2(sqrt($x), sqrt(1 - $x)), 2);
    }

    private function createSchema(): void
    {
        Schema::create('pricing_settings', function (Blueprint $t) {
            $t->id();
            $t->string('name')->nullable();
            $t->boolean('is_active')->default(true);
            // Legacy columns (original migration / seeder).
            $t->decimal('included_weight_kg', 8, 3)->default(1.5);
            $t->decimal('same_branch_weight_rate', 8, 2)->default(20);
            $t->decimal('other_branch_weight_rate', 8, 2)->default(30);
            $t->decimal('included_delivery_distance_km', 8, 2)->default(5);
            $t->decimal('extra_distance_rate_per_km', 8, 2)->default(6);
            // Admin-managed columns (StorePricingSettingsRequest).
            $t->decimal('base_weight_kg', 8, 3)->nullable()->default(1.5);
            $t->decimal('base_distance_km', 8, 2)->nullable()->default(5);
            $t->decimal('local_extra_weight_rate', 8, 2)->nullable()->default(20);
            $t->decimal('transfer_extra_weight_rate', 8, 2)->nullable()->default(30);
            $t->decimal('extra_distance_rate', 8, 2)->nullable()->default(6);
            $t->boolean('fragile_enabled')->default(true);
            $t->decimal('fragile_multiplier', 8, 3)->default(1.05);
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
        DB::table('pricing_settings')->insert(['is_active' => true, 'name' => 'Default']);
        DB::table('service_types')->insert(['code' => 'standard', 'name' => 'Standard', 'estimated_hours' => 24]);

        DB::table('coverage_locations')->insert([
            ['id' => 1, 'name' => 'Kathmandu main', 'code' => 'KTM', 'type' => 'main_branch_zone', 'latitude' => self::KTM_ZONE[0], 'longitude' => self::KTM_ZONE[1], 'coverage_radius_km' => 15, 'branch_id' => null],
            ['id' => 29, 'name' => 'Golbazar Main Branch', 'code' => 'TUK-GOL-MAIN-2', 'type' => 'main_branch_zone', 'latitude' => self::GOLBAZAR_ZONE[0], 'longitude' => self::GOLBAZAR_ZONE[1], 'coverage_radius_km' => 15, 'branch_id' => null],
            ['id' => 30, 'name' => 'Janakpur main', 'code' => 'JNK', 'type' => 'main_branch_zone', 'latitude' => self::JANAKPUR[0], 'longitude' => self::JANAKPUR[1], 'coverage_radius_km' => 15, 'branch_id' => null],
            ['id' => 40, 'name' => 'Pokhara main', 'code' => 'PKR', 'type' => 'main_branch_zone', 'latitude' => self::POKHARA[0], 'longitude' => self::POKHARA[1], 'coverage_radius_km' => 15, 'branch_id' => null],
            ['id' => 41, 'name' => 'Baglung', 'code' => 'BGL', 'type' => 'main_branch_zone', 'latitude' => self::BAGLUNG[0], 'longitude' => self::BAGLUNG[1], 'coverage_radius_km' => 15, 'branch_id' => null],
        ]);

        // Operational branches: no Golbazar branch exists; Janakpur serves it.
        DB::table('branches')->insert([
            ['id' => 901, 'name' => 'Kathmandu branch', 'coverage_location_id' => 1, 'latitude' => self::KTM_ZONE[0], 'longitude' => self::KTM_ZONE[1]],
            ['id' => 902, 'name' => 'Janakpur branch', 'coverage_location_id' => 30, 'latitude' => self::JANAKPUR[0], 'longitude' => self::JANAKPUR[1]],
            ['id' => 904, 'name' => 'Pokhara branch', 'coverage_location_id' => 40, 'latitude' => self::POKHARA[0], 'longitude' => self::POKHARA[1]],
        ]);

        DB::table('branch_route_rates')->insert([
            ['pickup_coverage_location_id' => 1, 'delivery_coverage_location_id' => 29, 'base_rate' => 199],
            ['pickup_coverage_location_id' => 1, 'delivery_coverage_location_id' => 30, 'base_rate' => 220],
            ['pickup_coverage_location_id' => 1, 'delivery_coverage_location_id' => 40, 'base_rate' => 300],
            ['pickup_coverage_location_id' => 1, 'delivery_coverage_location_id' => 41, 'base_rate' => 250],
        ]);
    }
}
