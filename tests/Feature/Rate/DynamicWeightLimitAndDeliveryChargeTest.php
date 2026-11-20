<?php

declare(strict_types=1);

namespace Tests\Feature\Rate;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Modules\Rate\Services\PricingEngineService;
use Tests\TestCase;

final class DynamicWeightLimitAndDeliveryChargeTest extends TestCase
{
    private const KTM_ZONE = [27.7050, 85.3150];

    private const POKHARA_ZONE = [28.2096, 83.9856];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'pricing_limits_test',
            'database.connections.pricing_limits_test' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => false,
            ],
        ]);
        DB::purge('pricing_limits_test');

        $this->createSchema();
        $this->seedBaseData();
    }

    public function test_rejects_shipment_exceeding_dynamic_max_weight_kg(): void
    {
        DB::table('pricing_settings')->where('id', 1)->update([
            'max_weight_kg' => 25.00,
            'weight_limit_enabled' => true,
        ]);

        try {
            $this->engine()->calculate($this->payload(actualWeightKg: 26.5));
            $this->fail('Expected ValidationException when chargeable weight exceeds max_weight_kg.');
        } catch (ValidationException $e) {
            $errors = $e->errors();
            $this->assertArrayHasKey('parcel_weight', $errors);
            $this->assertArrayHasKey('weight_limit', $errors);
            $this->assertStringContainsString('25 kg', $errors['weight_limit'][0]);
        }
    }

    public function test_allows_shipment_exceeding_max_weight_when_limit_disabled(): void
    {
        DB::table('pricing_settings')->where('id', 1)->update([
            'max_weight_kg' => 25.00,
            'weight_limit_enabled' => false,
        ]);

        $result = $this->engine()->calculate($this->payload(actualWeightKg: 40.0));

        $this->assertEquals(40.0, $result['weight_summary']['total_chargeable_weight_kg']);
        $this->assertFalse($result['pricing_limits']['weight_limit_enabled']);
    }

    public function test_applies_min_delivery_charge_floor_when_bounds_enabled(): void
    {
        DB::table('pricing_settings')->where('id', 1)->update([
            'min_delivery_charge' => 350.00,
            'max_delivery_charge' => null,
            'delivery_charge_bounds_enabled' => true,
        ]);

        // Base rate is 200 for 1 kg -> clamped up to 350.
        $result = $this->engine()->calculate($this->payload(actualWeightKg: 1.0));

        $this->assertEquals(350.00, (float) $result['final_price']);
        $this->assertTrue($result['breakdown']['delivery_charge_bounds']['applied']);
        $this->assertSame('min_floor', $result['breakdown']['delivery_charge_bounds']['adjustment_type']);
        $this->assertEquals(200.00, $result['breakdown']['delivery_charge_bounds']['price_before_bounds']);
    }

    public function test_applies_max_delivery_charge_cap_when_bounds_enabled(): void
    {
        DB::table('pricing_settings')->where('id', 1)->update([
            'min_delivery_charge' => 100.00,
            'max_delivery_charge' => 500.00,
            'delivery_charge_bounds_enabled' => true,
            'weight_limit_enabled' => false,
        ]);

        // Base 200 + (28.5 * 30 = 855) = 1055 -> capped at 500.
        $result = $this->engine()->calculate($this->payload(actualWeightKg: 30.0));

        $this->assertEquals(500.00, (float) $result['final_price']);
        $this->assertTrue($result['breakdown']['delivery_charge_bounds']['applied']);
        $this->assertSame('max_cap', $result['breakdown']['delivery_charge_bounds']['adjustment_type']);
        $this->assertEquals(1055.00, $result['breakdown']['delivery_charge_bounds']['price_before_bounds']);
    }

    public function test_public_pricing_limits_endpoint_returns_active_dynamic_values(): void
    {
        DB::table('pricing_settings')->where('id', 1)->update([
            'base_weight_kg' => 2.00,
            'max_weight_kg' => 35.00,
            'weight_limit_enabled' => true,
            'min_delivery_charge' => 150.00,
            'max_delivery_charge' => 2500.00,
            'delivery_charge_bounds_enabled' => true,
        ]);

        $response = $this->getJson('/api/v1/public/pricing/limits');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.base_weight_kg', 2)
            ->assertJsonPath('data.max_weight_kg', 35)
            ->assertJsonPath('data.weight_limit_enabled', true)
            ->assertJsonPath('data.min_delivery_charge', 150)
            ->assertJsonPath('data.max_delivery_charge', 2500)
            ->assertJsonPath('data.delivery_charge_bounds_enabled', true);
    }

    private function engine(): PricingEngineService
    {
        return $this->app->make(PricingEngineService::class);
    }

    private function payload(float $actualWeightKg): array
    {
        return [
            'pickup_latitude' => self::KTM_ZONE[0],
            'pickup_longitude' => self::KTM_ZONE[1],
            'delivery_latitude' => self::POKHARA_ZONE[0],
            'delivery_longitude' => self::POKHARA_ZONE[1],
            'service_type' => 'standard',
            'parcel_type' => 'non_fragile',
            'parcel_weight' => $actualWeightKg,
            'packets' => [
                [
                    'packet_reference' => 'TEST-PKT-1',
                    'name' => 'Test Parcel',
                    'quantity' => 1,
                    'actual_weight_kg' => $actualWeightKg,
                    'parcel_type' => 'non_fragile',
                ],
            ],
        ];
    }

    private function createSchema(): void
    {
        Schema::create('pricing_settings', function (Blueprint $t) {
            $t->id();
            $t->string('name')->nullable();
            $t->boolean('is_active')->default(true);
            $t->decimal('base_weight_kg', 8, 2)->default(1.50);
            $t->decimal('max_weight_kg', 10, 2)->nullable()->default(30.00);
            $t->boolean('weight_limit_enabled')->default(false);
            $t->decimal('base_distance_km', 8, 2)->default(5.00);
            $t->decimal('local_extra_weight_rate', 8, 2)->default(20.00);
            $t->decimal('transfer_extra_weight_rate', 8, 2)->default(30.00);
            $t->decimal('extra_distance_rate', 8, 2)->default(6.00);
            $t->decimal('min_delivery_charge', 10, 2)->default(0.00);
            $t->decimal('max_delivery_charge', 10, 2)->nullable()->default(null);
            $t->boolean('delivery_charge_bounds_enabled')->default(false);
            $t->boolean('fragile_enabled')->default(true);
            $t->decimal('fragile_multiplier', 8, 4)->default(1.0500);
            $t->decimal('volumetric_divisor', 10, 2)->default(5000);
            $t->boolean('vat_inclusive')->default(true);
            $t->decimal('vat_percentage', 5, 2)->default(13.00);
            $t->timestamps();
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
        DB::table('pricing_settings')->insert([
            'id' => 1,
            'name' => 'Dynamic Limits Test',
            'is_active' => true,
        ]);

        DB::table('service_types')->insert([
            'code' => 'standard',
            'name' => 'Standard',
            'estimated_hours' => 24,
        ]);

        DB::table('coverage_locations')->insert([
            ['id' => 1, 'name' => 'Kathmandu', 'code' => 'KTM', 'type' => 'main_branch_zone', 'latitude' => self::KTM_ZONE[0], 'longitude' => self::KTM_ZONE[1], 'coverage_radius_km' => 15],
            ['id' => 2, 'name' => 'Pokhara', 'code' => 'PKR', 'type' => 'main_branch_zone', 'latitude' => self::POKHARA_ZONE[0], 'longitude' => self::POKHARA_ZONE[1], 'coverage_radius_km' => 15],
        ]);

        DB::table('branches')->insert([
            ['id' => 101, 'name' => 'KTM Branch', 'coverage_location_id' => 1, 'latitude' => self::KTM_ZONE[0], 'longitude' => self::KTM_ZONE[1]],
            ['id' => 102, 'name' => 'PKR Branch', 'coverage_location_id' => 2, 'latitude' => self::POKHARA_ZONE[0], 'longitude' => self::POKHARA_ZONE[1]],
        ]);

        DB::table('branch_route_rates')->insert([
            ['pickup_coverage_location_id' => 1, 'delivery_coverage_location_id' => 2, 'base_rate' => 200.00],
        ]);
    }
}
