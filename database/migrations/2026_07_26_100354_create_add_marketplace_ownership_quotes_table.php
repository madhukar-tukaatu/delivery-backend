<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checkout_quotes', function (Blueprint $table): void {
            if (! Schema::hasColumn('checkout_quotes', 'marketplace_id')) {
                $table->unsignedBigInteger('marketplace_id')
                    ->nullable()
                    ->after('merchant_id')
                    ->index();
            }

            if (! Schema::hasColumn('checkout_quotes', 'external_checkout_id')) {
                $table->string('external_checkout_id', 150)
                    ->nullable()
                    ->after('quote_number')
                    ->index();
            }
        });

        Schema::table('pricing_quotes', function (Blueprint $table): void {
            if (! Schema::hasColumn('pricing_quotes', 'marketplace_id')) {
                $table->unsignedBigInteger('marketplace_id')
                    ->nullable()
                    ->after('merchant_id')
                    ->index();
            }

            if (! Schema::hasColumn('pricing_quotes', 'external_store_id')) {
                $table->string('external_store_id', 150)
                    ->nullable()
                    ->after('store_id')
                    ->index();
            }

            // Optional: only if you also need this column
            if (! Schema::hasColumn('pricing_quotes', 'packet_count')) {
                $table->unsignedInteger('packet_count')
                    ->nullable()
                    ->after('parcel_type');
            }
        });
    }

    public function down(): void
    {
        Schema::table('pricing_quotes', function (Blueprint $table): void {
            $columns = [];

            foreach (['marketplace_id', 'external_store_id', 'packet_count'] as $column) {
                if (Schema::hasColumn('pricing_quotes', $column)) {
                    $columns[] = $column;
                }
            }

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });

        Schema::table('checkout_quotes', function (Blueprint $table): void {
            $columns = [];

            foreach (['marketplace_id', 'external_checkout_id'] as $column) {
                if (Schema::hasColumn('checkout_quotes', $column)) {
                    $columns[] = $column;
                }
            }

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};