<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('branches')) {
            return;
        }

        Schema::table('branches', function (Blueprint $table) {
            if (!Schema::hasColumn('branches', 'parent_id')) {
                $table->unsignedBigInteger('parent_id')->nullable()->index()->after('id');
            }

            if (!Schema::hasColumn('branches', 'type')) {
                $table->string('type', 50)->default('head_branch')->index()->after('parent_id');
            }

            if (!Schema::hasColumn('branches', 'code')) {
                $table->string('code', 80)->nullable()->unique()->after('name');
            }

            if (!Schema::hasColumn('branches', 'legal_name')) {
                $table->string('legal_name')->nullable()->after('code');
            }

            if (!Schema::hasColumn('branches', 'owner_name')) {
                $table->string('owner_name')->nullable()->after('legal_name');
            }

            if (!Schema::hasColumn('branches', 'contact_person')) {
                $table->string('contact_person')->nullable()->after('owner_name');
            }

            if (!Schema::hasColumn('branches', 'email')) {
                $table->string('email')->nullable()->after('contact_person');
            }

            if (!Schema::hasColumn('branches', 'phone')) {
                $table->string('phone', 50)->nullable()->after('email');
            }

            if (!Schema::hasColumn('branches', 'alternative_phone')) {
                $table->string('alternative_phone', 50)->nullable()->after('phone');
            }

            if (!Schema::hasColumn('branches', 'pan_vat_number')) {
                $table->string('pan_vat_number')->nullable()->after('alternative_phone');
            }

            if (!Schema::hasColumn('branches', 'registration_number')) {
                $table->string('registration_number')->nullable()->after('pan_vat_number');
            }

            if (!Schema::hasColumn('branches', 'business_type')) {
                $table->string('business_type')->nullable()->after('registration_number');
            }

            if (!Schema::hasColumn('branches', 'status')) {
                $table->string('status', 50)->default('draft')->index()->after('business_type');
            }

            foreach (['country', 'province', 'district', 'city', 'area', 'address', 'landmark'] as $column) {
                if (!Schema::hasColumn('branches', $column)) {
                    $table->string($column)->nullable();
                }
            }

            if (!Schema::hasColumn('branches', 'latitude')) {
                $table->decimal('latitude', 10, 7)->nullable()->index();
            }

            if (!Schema::hasColumn('branches', 'longitude')) {
                $table->decimal('longitude', 10, 7)->nullable()->index();
            }

            if (!Schema::hasColumn('branches', 'coverage_radius_km')) {
                $table->decimal('coverage_radius_km', 8, 2)->nullable();
            }

            if (!Schema::hasColumn('branches', 'covered_areas')) {
                $table->json('covered_areas')->nullable();
            }

            if (!Schema::hasColumn('branches', 'opening_time')) {
                $table->time('opening_time')->nullable();
            }

            if (!Schema::hasColumn('branches', 'closing_time')) {
                $table->time('closing_time')->nullable();
            }

            if (!Schema::hasColumn('branches', 'operating_days')) {
                $table->json('operating_days')->nullable();
            }

            if (!Schema::hasColumn('branches', 'daily_shipment_capacity')) {
                $table->unsignedInteger('daily_shipment_capacity')->nullable();
            }

            foreach (['pickup_enabled', 'delivery_enabled', 'pod_enabled', 'return_enabled'] as $column) {
                if (!Schema::hasColumn('branches', $column)) {
                    $table->boolean($column)->default(false)->index();
                }
            }

            foreach (['manager_user_id', 'approved_by', 'rejected_by'] as $column) {
                if (!Schema::hasColumn('branches', $column)) {
                    $table->unsignedBigInteger($column)->nullable()->index();
                }
            }

            if (!Schema::hasColumn('branches', 'approved_at')) {
                $table->timestamp('approved_at')->nullable();
            }

            if (!Schema::hasColumn('branches', 'rejected_at')) {
                $table->timestamp('rejected_at')->nullable();
            }

            if (!Schema::hasColumn('branches', 'rejection_reason')) {
                $table->text('rejection_reason')->nullable();
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('branches')) {
            return;
        }

        Schema::table('branches', function (Blueprint $table) {
            $columns = [
                'parent_id', 'type', 'code', 'legal_name', 'owner_name', 'contact_person',
                'email', 'phone', 'alternative_phone', 'pan_vat_number', 'registration_number',
                'business_type', 'status', 'country', 'province', 'district', 'city', 'area',
                'address', 'landmark', 'latitude', 'longitude', 'coverage_radius_km', 'covered_areas',
                'opening_time', 'closing_time', 'operating_days', 'daily_shipment_capacity',
                'pickup_enabled', 'delivery_enabled', 'pod_enabled', 'return_enabled',
                'manager_user_id', 'approved_by', 'approved_at', 'rejected_by', 'rejected_at',
                'rejection_reason',
            ];

            $existing = array_values(array_filter($columns, fn ($column) => Schema::hasColumn('branches', $column)));

            if ($existing) {
                $table->dropColumn($existing);
            }
        });
    }
};
