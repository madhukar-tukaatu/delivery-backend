<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('shipments')) {
            Schema::table('shipments', function (Blueprint $table) {
                $this->addColumnIfMissing($table, 'source', 'string', ['nullable' => true, 'after' => 'id']);
                $this->addColumnIfMissing($table, 'external_order_id', 'string', ['nullable' => true]);
                $this->addColumnIfMissing($table, 'order_reference', 'string', ['nullable' => true]);
                $this->addColumnIfMissing($table, 'pickup_location_id', 'unsignedBigInteger', ['nullable' => true]);

                $this->addColumnIfMissing($table, 'receiver_name', 'string', ['nullable' => true]);
                $this->addColumnIfMissing($table, 'receiver_phone', 'string', ['nullable' => true]);
                $this->addColumnIfMissing($table, 'receiver_email', 'string', ['nullable' => true]);
                $this->addColumnIfMissing($table, 'receiver_address', 'text', ['nullable' => true]);
                $this->addColumnIfMissing($table, 'receiver_city', 'string', ['nullable' => true]);
                $this->addColumnIfMissing($table, 'receiver_area', 'string', ['nullable' => true]);
                $this->addColumnIfMissing($table, 'receiver_latitude', 'decimal', ['total' => 10, 'places' => 7, 'nullable' => true]);
                $this->addColumnIfMissing($table, 'receiver_longitude', 'decimal', ['total' => 10, 'places' => 7, 'nullable' => true]);

                $this->addColumnIfMissing($table, 'package_type', 'string', ['nullable' => true]);
                $this->addColumnIfMissing($table, 'package_description', 'text', ['nullable' => true]);
                $this->addColumnIfMissing($table, 'actual_weight', 'decimal', ['total' => 10, 'places' => 2, 'default' => 0]);
                $this->addColumnIfMissing($table, 'volumetric_weight', 'decimal', ['total' => 10, 'places' => 2, 'default' => 0]);
                $this->addColumnIfMissing($table, 'chargeable_weight', 'decimal', ['total' => 10, 'places' => 2, 'default' => 0]);
                $this->addColumnIfMissing($table, 'length_cm', 'decimal', ['total' => 10, 'places' => 2, 'default' => 0]);
                $this->addColumnIfMissing($table, 'width_cm', 'decimal', ['total' => 10, 'places' => 2, 'default' => 0]);
                $this->addColumnIfMissing($table, 'height_cm', 'decimal', ['total' => 10, 'places' => 2, 'default' => 0]);
                $this->addColumnIfMissing($table, 'pieces', 'integer', ['default' => 1]);
                $this->addColumnIfMissing($table, 'declared_value', 'decimal', ['total' => 12, 'places' => 2, 'default' => 0]);

                $this->addColumnIfMissing($table, 'payment_type', 'string', ['default' => 'prepaid']);
                $this->addColumnIfMissing($table, 'cod_amount', 'decimal', ['total' => 12, 'places' => 2, 'default' => 0]);
                $this->addColumnIfMissing($table, 'delivery_charge', 'decimal', ['total' => 12, 'places' => 2, 'default' => 0]);
                $this->addColumnIfMissing($table, 'delivery_charge_paid_by', 'string', ['default' => 'merchant']);
                $this->addColumnIfMissing($table, 'total_collectable', 'decimal', ['total' => 12, 'places' => 2, 'default' => 0]);

                $this->addColumnIfMissing($table, 'origin_branch_id', 'unsignedBigInteger', ['nullable' => true]);
                $this->addColumnIfMissing($table, 'origin_sub_branch_id', 'unsignedBigInteger', ['nullable' => true]);
                $this->addColumnIfMissing($table, 'destination_branch_id', 'unsignedBigInteger', ['nullable' => true]);
                $this->addColumnIfMissing($table, 'destination_sub_branch_id', 'unsignedBigInteger', ['nullable' => true]);
                $this->addColumnIfMissing($table, 'current_branch_id', 'unsignedBigInteger', ['nullable' => true]);
                $this->addColumnIfMissing($table, 'current_sub_branch_id', 'unsignedBigInteger', ['nullable' => true]);

                $this->addColumnIfMissing($table, 'pickup_status', 'string', ['default' => 'pending']);
                $this->addColumnIfMissing($table, 'delivery_status', 'string', ['default' => 'not_ready']);
                $this->addColumnIfMissing($table, 'cod_status', 'string', ['default' => 'none']);
                $this->addColumnIfMissing($table, 'settlement_status', 'string', ['default' => 'unsettled']);
                $this->addColumnIfMissing($table, 'failed_attempts', 'integer', ['default' => 0]);
                $this->addColumnIfMissing($table, 'delivered_at', 'timestamp', ['nullable' => true]);
                $this->addColumnIfMissing($table, 'created_by', 'unsignedBigInteger', ['nullable' => true]);
            });
        }

        if (!Schema::hasTable('shipment_charge_breakdowns')) {
            Schema::create('shipment_charge_breakdowns', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('shipment_id')->index();
                $table->decimal('base_fee', 12, 2)->default(0);
                $table->decimal('distance_km', 10, 2)->default(0);
                $table->decimal('distance_fee', 12, 2)->default(0);
                $table->decimal('actual_weight', 10, 2)->default(0);
                $table->decimal('volumetric_weight', 10, 2)->default(0);
                $table->decimal('chargeable_weight', 10, 2)->default(0);
                $table->decimal('weight_fee', 12, 2)->default(0);
                $table->decimal('cod_fee', 12, 2)->default(0);
                $table->decimal('delivery_charge', 12, 2)->default(0);
                $table->decimal('cod_amount', 12, 2)->default(0);
                $table->decimal('total_collectable', 12, 2)->default(0);
                $table->json('meta')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('shipment_lifecycle_events')) {
            Schema::create('shipment_lifecycle_events', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('shipment_id')->index();
                $table->string('event');
                $table->string('status')->nullable();
                $table->unsignedBigInteger('branch_id')->nullable();
                $table->unsignedBigInteger('sub_branch_id')->nullable();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->text('remarks')->nullable();
                $table->json('meta')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('shipment_route_steps')) {
            Schema::create('shipment_route_steps', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('shipment_id')->index();
                $table->integer('sequence')->default(1);
                $table->string('type')->default('branch');
                $table->unsignedBigInteger('from_branch_id')->nullable();
                $table->unsignedBigInteger('from_sub_branch_id')->nullable();
                $table->unsignedBigInteger('to_branch_id')->nullable();
                $table->unsignedBigInteger('to_sub_branch_id')->nullable();
                $table->string('status')->default('pending');
                $table->timestamp('dispatched_at')->nullable();
                $table->timestamp('received_at')->nullable();
                $table->unsignedBigInteger('dispatched_by')->nullable();
                $table->unsignedBigInteger('received_by')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('pickup_requests')) {
            Schema::create('pickup_requests', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('shipment_id')->index();
                $table->unsignedBigInteger('merchant_id')->nullable()->index();
                $table->unsignedBigInteger('pickup_location_id')->nullable();
                $table->unsignedBigInteger('branch_id')->nullable()->index();
                $table->unsignedBigInteger('sub_branch_id')->nullable()->index();
                $table->unsignedBigInteger('assigned_to')->nullable()->index();
                $table->string('status')->default('pending');
                $table->text('pickup_address')->nullable();
                $table->string('contact_person')->nullable();
                $table->string('phone')->nullable();
                $table->integer('parcel_count')->default(1);
                $table->timestamp('accepted_at')->nullable();
                $table->timestamp('picked_up_at')->nullable();
                $table->timestamp('received_at_origin_at')->nullable();
                $table->text('remarks')->nullable();
                $table->timestamps();
            });
        } else {
            Schema::table('pickup_requests', function (Blueprint $table) {
                $this->addColumnIfMissing($table, 'merchant_id', 'unsignedBigInteger', ['nullable' => true]);
                $this->addColumnIfMissing($table, 'pickup_location_id', 'unsignedBigInteger', ['nullable' => true]);
                $this->addColumnIfMissing($table, 'branch_id', 'unsignedBigInteger', ['nullable' => true]);
                $this->addColumnIfMissing($table, 'sub_branch_id', 'unsignedBigInteger', ['nullable' => true]);
                $this->addColumnIfMissing($table, 'assigned_to', 'unsignedBigInteger', ['nullable' => true]);
                $this->addColumnIfMissing($table, 'accepted_at', 'timestamp', ['nullable' => true]);
                $this->addColumnIfMissing($table, 'received_at_origin_at', 'timestamp', ['nullable' => true]);
            });
        }

        if (!Schema::hasTable('delivery_assignments')) {
            Schema::create('delivery_assignments', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('shipment_id')->index();
                $table->unsignedBigInteger('branch_id')->nullable()->index();
                $table->unsignedBigInteger('sub_branch_id')->nullable()->index();
                $table->unsignedBigInteger('rider_id')->nullable()->index();
                $table->string('status')->default('assigned');
                $table->integer('attempt_no')->default(1);
                $table->timestamp('accepted_at')->nullable();
                $table->timestamp('out_for_delivery_at')->nullable();
                $table->timestamp('delivered_at')->nullable();
                $table->timestamp('failed_at')->nullable();
                $table->string('failure_reason')->nullable();
                $table->text('remarks')->nullable();
                $table->string('otp')->nullable();
                $table->string('proof_photo_path')->nullable();
                $table->string('signature_path')->nullable();
                $table->timestamps();
            });
        } else {
            Schema::table('delivery_assignments', function (Blueprint $table) {
                $this->addColumnIfMissing($table, 'sub_branch_id', 'unsignedBigInteger', ['nullable' => true]);
                $this->addColumnIfMissing($table, 'attempt_no', 'integer', ['default' => 1]);
                $this->addColumnIfMissing($table, 'accepted_at', 'timestamp', ['nullable' => true]);
                $this->addColumnIfMissing($table, 'otp', 'string', ['nullable' => true]);
                $this->addColumnIfMissing($table, 'proof_photo_path', 'string', ['nullable' => true]);
            });
        }

        if (!Schema::hasTable('shipment_transfer_batches')) {
            Schema::create('shipment_transfer_batches', function (Blueprint $table) {
                $table->id();
                $table->string('batch_number')->unique();
                $table->unsignedBigInteger('from_branch_id')->nullable();
                $table->unsignedBigInteger('from_sub_branch_id')->nullable();
                $table->unsignedBigInteger('to_branch_id')->nullable();
                $table->unsignedBigInteger('to_sub_branch_id')->nullable();
                $table->string('vehicle_number')->nullable();
                $table->unsignedBigInteger('driver_id')->nullable();
                $table->string('status')->default('created');
                $table->timestamp('dispatched_at')->nullable();
                $table->timestamp('received_at')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('shipment_transfer_batch_items')) {
            Schema::create('shipment_transfer_batch_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('transfer_batch_id')->index();
                $table->unsignedBigInteger('shipment_id')->index();
                $table->string('status')->default('created');
                $table->timestamp('scanned_out_at')->nullable();
                $table->timestamp('scanned_in_at')->nullable();
                $table->timestamps();
                $table->unique(['transfer_batch_id', 'shipment_id'], 'transfer_batch_shipment_unique');
            });
        }

        if (!Schema::hasTable('cod_collections')) {
            Schema::create('cod_collections', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('shipment_id')->index();
                $table->unsignedBigInteger('merchant_id')->index();
                $table->unsignedBigInteger('rider_id')->nullable()->index();
                $table->decimal('cod_amount', 12, 2)->default(0);
                $table->decimal('delivery_charge_collected', 12, 2)->default(0);
                $table->decimal('total_collected', 12, 2)->default(0);
                $table->string('payment_method')->default('cash');
                $table->string('status')->default('pending');
                $table->timestamp('collected_at')->nullable();
                $table->timestamp('deposited_at')->nullable();
                $table->unsignedBigInteger('deposit_id')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('rider_cash_deposits')) {
            Schema::create('rider_cash_deposits', function (Blueprint $table) {
                $table->id();
                $table->string('deposit_number')->unique();
                $table->unsignedBigInteger('rider_id')->index();
                $table->unsignedBigInteger('branch_id')->nullable();
                $table->decimal('amount', 12, 2)->default(0);
                $table->string('status')->default('pending');
                $table->unsignedBigInteger('confirmed_by')->nullable();
                $table->timestamp('confirmed_at')->nullable();
                $table->text('remarks')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('merchant_settlements')) {
            Schema::create('merchant_settlements', function (Blueprint $table) {
                $table->id();
                $table->string('settlement_number')->unique();
                $table->unsignedBigInteger('merchant_id')->index();
                $table->decimal('gross_cod', 12, 2)->default(0);
                $table->decimal('delivery_charge_deduction', 12, 2)->default(0);
                $table->decimal('return_fee_deduction', 12, 2)->default(0);
                $table->decimal('other_deduction', 12, 2)->default(0);
                $table->decimal('net_payable', 12, 2)->default(0);
                $table->string('status')->default('created');
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('paid_by')->nullable();
                $table->timestamp('paid_at')->nullable();
                $table->text('remarks')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('merchant_settlement_items')) {
            Schema::create('merchant_settlement_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('merchant_settlement_id')->index();
                $table->unsignedBigInteger('shipment_id')->index();
                $table->unsignedBigInteger('cod_collection_id')->nullable();
                $table->decimal('cod_amount', 12, 2)->default(0);
                $table->decimal('delivery_charge_deduction', 12, 2)->default(0);
                $table->decimal('net_amount', 12, 2)->default(0);
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('merchant_api_idempotency_keys')) {
            Schema::create('merchant_api_idempotency_keys', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('merchant_id')->index();
                $table->string('idempotency_key');
                $table->unsignedBigInteger('shipment_id')->nullable();
                $table->string('request_hash')->nullable();
                $table->json('response_payload')->nullable();
                $table->timestamps();
                $table->unique(['merchant_id', 'idempotency_key'], 'merchant_idempotency_unique');
            });
        }

        if (!Schema::hasTable('branch_service_areas')) {
            Schema::create('branch_service_areas', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('branch_id')->index();
                $table->unsignedBigInteger('sub_branch_id')->nullable()->index();
                $table->string('city')->index();
                $table->string('area')->nullable()->index();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_service_areas');
        Schema::dropIfExists('merchant_api_idempotency_keys');
        Schema::dropIfExists('merchant_settlement_items');
        Schema::dropIfExists('merchant_settlements');
        Schema::dropIfExists('rider_cash_deposits');
        Schema::dropIfExists('cod_collections');
        Schema::dropIfExists('shipment_transfer_batch_items');
        Schema::dropIfExists('shipment_transfer_batches');
        Schema::dropIfExists('shipment_route_steps');
        Schema::dropIfExists('shipment_lifecycle_events');
        Schema::dropIfExists('shipment_charge_breakdowns');
    }

    private function addColumnIfMissing(Blueprint $table, string $column, string $type, array $options = []): void
    {
        if (Schema::hasColumn($table->getTable(), $column)) {
            return;
        }

        $definition = match ($type) {
            'string' => $table->string($column),
            'text' => $table->text($column),
            'integer' => $table->integer($column),
            'unsignedBigInteger' => $table->unsignedBigInteger($column),
            'boolean' => $table->boolean($column),
            'timestamp' => $table->timestamp($column),
            'decimal' => $table->decimal($column, $options['total'] ?? 12, $options['places'] ?? 2),
            default => $table->string($column),
        };

        if (($options['nullable'] ?? false) === true) {
            $definition->nullable();
        }

        if (array_key_exists('default', $options)) {
            $definition->default($options['default']);
        }

        if (isset($options['after'])) {
            $definition->after($options['after']);
        }
    }
};
