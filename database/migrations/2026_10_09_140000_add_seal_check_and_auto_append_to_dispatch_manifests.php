<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TR seal check at the receiving branch (ok / mismatch / tampered) and the
 * auto-append switch of an open TR (newly sorted parcels for the same next
 * hop join it until it is dispatched).
 *
 * Also creates staff_notifications when missing: transfer alerts (TR
 * dispatched, parcels missing, seal mismatch / tampered) and the admin
 * notifications page already read / write it, but no migration created it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('staff_notifications')) {
            Schema::create('staff_notifications', function (Blueprint $table) {
                $table->id();
                $table->string('title', 255);
                $table->text('message')->nullable();
                $table->unsignedBigInteger('branch_id')->nullable()->index();
                $table->unsignedBigInteger('shipment_id')->nullable()->index();
                $table->unsignedBigInteger('shipment_task_id')->nullable();
                $table->string('type', 50)->nullable()->index();
                $table->boolean('is_read')->default(false)->index();
                $table->timestamp('read_at')->nullable();
                $table->json('data_json')->nullable();
                $table->timestamps();
            });
        }

        Schema::table('dispatch_manifests', function (Blueprint $table) {
            if (! Schema::hasColumn('dispatch_manifests', 'seal_status')) {
                // null = not checked | ok | mismatch | tampered
                $table->string('seal_status', 20)->nullable()->index();
            }
            if (! Schema::hasColumn('dispatch_manifests', 'seal_checked_value')) {
                $table->string('seal_checked_value', 50)->nullable();
            }
            if (! Schema::hasColumn('dispatch_manifests', 'seal_checked_by')) {
                $table->unsignedBigInteger('seal_checked_by')->nullable();
            }
            if (! Schema::hasColumn('dispatch_manifests', 'seal_checked_at')) {
                $table->timestamp('seal_checked_at')->nullable();
            }
            if (! Schema::hasColumn('dispatch_manifests', 'seal_remark')) {
                $table->string('seal_remark', 500)->nullable();
            }
            if (! Schema::hasColumn('dispatch_manifests', 'auto_append')) {
                $table->boolean('auto_append')->default(false);
            }
        });
    }

    public function down(): void
    {
        // staff_notifications is kept: other modules write to it.
        Schema::table('dispatch_manifests', function (Blueprint $table) {
            if (Schema::hasColumn('dispatch_manifests', 'seal_status')) {
                $table->dropIndex(['seal_status']);
            }
            foreach (['seal_status', 'seal_checked_value', 'seal_checked_by', 'seal_checked_at', 'seal_remark', 'auto_append'] as $col) {
                if (Schema::hasColumn('dispatch_manifests', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
