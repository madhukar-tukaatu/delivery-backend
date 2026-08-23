<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pickup_requests', function (Blueprint $table) {

            if (! Schema::hasColumn('pickup_requests', 'request_number')) {
                $table->string('request_number', 50)
                    ->nullable()
                    ->unique()
                    ->after('id');
            }

            if (! Schema::hasColumn('pickup_requests', 'pickup_location_id')) {
                $table->foreignId('pickup_location_id')
                    ->nullable()
                    ->after('merchant_id')
                    ->constrained('merchant_pickup_locations')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('pickup_requests', 'requested_at')) {
                $table->timestamp('requested_at')
                    ->nullable();
            }

            if (! Schema::hasColumn('pickup_requests', 'assigned_at')) {
                $table->timestamp('assigned_at')
                    ->nullable();
            }

            if (! Schema::hasColumn('pickup_requests', 'assigned_by')) {
                $table->foreignId('assigned_by')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('pickup_requests', 'arrived_at')) {
                $table->timestamp('arrived_at')
                    ->nullable();
            }

            if (! Schema::hasColumn('pickup_requests', 'completed_at')) {
                $table->timestamp('completed_at')
                    ->nullable();
            }

            if (! Schema::hasColumn('pickup_requests', 'cancelled_at')) {
                $table->timestamp('cancelled_at')
                    ->nullable();
            }

            if (! Schema::hasColumn('pickup_requests', 'status')) {
                $table->string('status', 40)
                    ->default('draft');
            }
        });
    }

    public function down(): void
    {
        Schema::table('pickup_requests', function (Blueprint $table) {
            $columns = [
                'request_number',
                'pickup_location_id',
                'requested_at',
                'assigned_at',
                'assigned_by',
                'arrived_at',
                'completed_at',
                'cancelled_at',
                'status',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('pickup_requests', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};