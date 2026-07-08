<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('merchants')) {
            Schema::table('merchants', function (Blueprint $table) {
                if (!Schema::hasColumn('merchants', 'pickup_address')) {
                    $table->string('pickup_address')->nullable()->after('address');
                }
                if (!Schema::hasColumn('merchants', 'pickup_city')) {
                    $table->string('pickup_city')->nullable()->after('pickup_address');
                }
                if (!Schema::hasColumn('merchants', 'pickup_area')) {
                    $table->string('pickup_area')->nullable()->after('pickup_city');
                }
                if (!Schema::hasColumn('merchants', 'pickup_lat')) {
                    $table->decimal('pickup_lat', 11, 7)->nullable()->after('pickup_area');
                }
                if (!Schema::hasColumn('merchants', 'pickup_lng')) {
                    $table->decimal('pickup_lng', 11, 7)->nullable()->after('pickup_lat');
                }
                if (!Schema::hasColumn('merchants', 'suggested_branch_id')) {
                    $table->foreignId('suggested_branch_id')->nullable()->after('default_sub_branch_id')->constrained('branches')->nullOnDelete();
                }
                if (!Schema::hasColumn('merchants', 'suggested_sub_branch_id')) {
                    $table->foreignId('suggested_sub_branch_id')->nullable()->after('suggested_branch_id')->constrained('branches')->nullOnDelete();
                }
                if (!Schema::hasColumn('merchants', 'verification_status')) {
                    $table->string('verification_status')->default('pending')->after('status')->index();
                }
                if (!Schema::hasColumn('merchants', 'verified_by')) {
                    $table->foreignId('verified_by')->nullable()->after('verification_status')->constrained('users')->nullOnDelete();
                }
                if (!Schema::hasColumn('merchants', 'verified_at')) {
                    $table->timestamp('verified_at')->nullable()->after('verified_by');
                }
                if (!Schema::hasColumn('merchants', 'rejected_reason')) {
                    $table->text('rejected_reason')->nullable()->after('verified_at');
                }
                if (!Schema::hasColumn('merchants', 'admin_remarks')) {
                    $table->text('admin_remarks')->nullable()->after('rejected_reason');
                }
            });
        }

        if (!Schema::hasTable('merchant_documents')) {
            Schema::create('merchant_documents', function (Blueprint $table) {
                $table->id();
                $table->foreignId('merchant_id')->constrained('merchants')->cascadeOnDelete();
                $table->string('document_type');
                $table->string('file_path');
                $table->string('original_name')->nullable();
                $table->string('mime_type')->nullable();
                $table->unsignedBigInteger('size')->nullable();
                $table->string('status')->default('pending')->index();
                $table->text('remarks')->nullable();
                $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('verified_at')->nullable();
                $table->timestamps();
            });
        }

        if (Schema::hasTable('shipments')) {
            Schema::table('shipments', function (Blueprint $table) {
                if (!Schema::hasColumn('shipments', 'pickup_location_id')) {
                    $table->foreignId('pickup_location_id')->nullable()->after('merchant_id')->constrained('merchant_pickup_locations')->nullOnDelete();
                }
            });
        }

        if (Schema::hasTable('pickup_requests')) {
            Schema::table('pickup_requests', function (Blueprint $table) {
                if (!Schema::hasColumn('pickup_requests', 'merchant_id')) {
                    $table->foreignId('merchant_id')->nullable()->after('shipment_id')->constrained('merchants')->nullOnDelete();
                }
                if (!Schema::hasColumn('pickup_requests', 'branch_id')) {
                    $table->foreignId('branch_id')->nullable()->after('merchant_id')->constrained('branches')->nullOnDelete();
                }
                if (!Schema::hasColumn('pickup_requests', 'sub_branch_id')) {
                    $table->foreignId('sub_branch_id')->nullable()->after('branch_id')->constrained('branches')->nullOnDelete();
                }
                if (!Schema::hasColumn('pickup_requests', 'assigned_to')) {
                    $table->foreignId('assigned_to')->nullable()->after('sub_branch_id')->constrained('users')->nullOnDelete();
                }
                if (!Schema::hasColumn('pickup_requests', 'pickup_name')) {
                    $table->string('pickup_name')->nullable()->after('assigned_to');
                }
                if (!Schema::hasColumn('pickup_requests', 'pickup_phone')) {
                    $table->string('pickup_phone', 40)->nullable()->after('pickup_name');
                }
                if (!Schema::hasColumn('pickup_requests', 'pickup_address')) {
                    $table->text('pickup_address')->nullable()->after('pickup_phone');
                }
                if (!Schema::hasColumn('pickup_requests', 'pickup_city')) {
                    $table->string('pickup_city')->nullable()->after('pickup_address');
                }
                if (!Schema::hasColumn('pickup_requests', 'pickup_area')) {
                    $table->string('pickup_area')->nullable()->after('pickup_city');
                }
                if (!Schema::hasColumn('pickup_requests', 'pickup_lat')) {
                    $table->decimal('pickup_lat', 11, 7)->nullable()->after('pickup_area');
                }
                if (!Schema::hasColumn('pickup_requests', 'pickup_lng')) {
                    $table->decimal('pickup_lng', 11, 7)->nullable()->after('pickup_lat');
                }
                if (!Schema::hasColumn('pickup_requests', 'requested_at')) {
                    $table->timestamp('requested_at')->nullable()->after('status');
                }
                if (!Schema::hasColumn('pickup_requests', 'assigned_at')) {
                    $table->timestamp('assigned_at')->nullable()->after('requested_at');
                }
                if (!Schema::hasColumn('pickup_requests', 'picked_up_by')) {
                    $table->foreignId('picked_up_by')->nullable()->after('assigned_at')->constrained('users')->nullOnDelete();
                }
                if (!Schema::hasColumn('pickup_requests', 'picked_up_at')) {
                    $table->timestamp('picked_up_at')->nullable()->after('picked_up_by');
                }
                if (!Schema::hasColumn('pickup_requests', 'failed_at')) {
                    $table->timestamp('failed_at')->nullable()->after('picked_up_at');
                }
                if (!Schema::hasColumn('pickup_requests', 'failed_reason')) {
                    $table->text('failed_reason')->nullable()->after('failed_at');
                }
                if (!Schema::hasColumn('pickup_requests', 'remarks')) {
                    $table->text('remarks')->nullable()->after('failed_reason');
                }
            });
        }

        if (Schema::hasTable('delivery_assignments')) {
            Schema::table('delivery_assignments', function (Blueprint $table) {
                if (!Schema::hasColumn('delivery_assignments', 'branch_id')) {
                    $table->foreignId('branch_id')->nullable()->after('shipment_id')->constrained('branches')->nullOnDelete();
                }
                if (!Schema::hasColumn('delivery_assignments', 'sub_branch_id')) {
                    $table->foreignId('sub_branch_id')->nullable()->after('branch_id')->constrained('branches')->nullOnDelete();
                }
                if (!Schema::hasColumn('delivery_assignments', 'assigned_at')) {
                    $table->timestamp('assigned_at')->nullable()->after('status');
                }
                if (!Schema::hasColumn('delivery_assignments', 'out_for_delivery_at')) {
                    $table->timestamp('out_for_delivery_at')->nullable()->after('assigned_at');
                }
                if (!Schema::hasColumn('delivery_assignments', 'delivered_at')) {
                    $table->timestamp('delivered_at')->nullable()->after('out_for_delivery_at');
                }
                if (!Schema::hasColumn('delivery_assignments', 'failed_at')) {
                    $table->timestamp('failed_at')->nullable()->after('delivered_at');
                }
                if (!Schema::hasColumn('delivery_assignments', 'failed_reason')) {
                    $table->text('failed_reason')->nullable()->after('failed_at');
                }
                if (!Schema::hasColumn('delivery_assignments', 'remarks')) {
                    $table->text('remarks')->nullable()->after('failed_reason');
                }
                if (!Schema::hasColumn('delivery_assignments', 'cod_collected_amount')) {
                    $table->decimal('cod_collected_amount', 12, 2)->default(0)->after('remarks');
                }
            });
        }
    }

    public function down(): void
    {
        // Intentionally non-destructive. Rollback manually if needed.
    }
};
