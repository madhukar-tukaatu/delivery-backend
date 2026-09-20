<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            if (! Schema::hasColumn('merchants', 'hamropay_business_id')) {
                $table->string('hamropay_business_id')->nullable()->after('id');
            }
            if (! Schema::hasColumn('merchants', 'hamropay_merchant_id')) {
                $table->string('hamropay_merchant_id')->nullable();
            }
            if (! Schema::hasColumn('merchants', 'hamropay_qr_payload')) {
                $table->text('hamropay_qr_payload')->nullable();
            }
            if (! Schema::hasColumn('merchants', 'hamropay_enabled_services')) {
                $table->json('hamropay_enabled_services')->nullable();
            }
            if (! Schema::hasColumn('merchants', 'hamropay_kyc_status')) {
                $table->string('hamropay_kyc_status')->nullable();
            }
            if (! Schema::hasColumn('merchants', 'hamropay_registered_at')) {
                $table->timestamp('hamropay_registered_at')->nullable();
            }
            if (! Schema::hasColumn('merchants', 'hamropay_phone')) {
                $table->string('hamropay_phone')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            foreach ([
                'hamropay_business_id',
                'hamropay_merchant_id',
                'hamropay_qr_payload',
                'hamropay_enabled_services',
                'hamropay_kyc_status',
                'hamropay_registered_at',
                'hamropay_phone',
            ] as $col) {
                if (Schema::hasColumn('merchants', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
