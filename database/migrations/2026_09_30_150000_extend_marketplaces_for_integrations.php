<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-marketplace: each marketplace (e.g. api.tukaatu.com, api.fca.com.np)
 * has its own API/callback URL. HamroPay credentials are stored in
 * payment_gateway_accounts with owner_type=marketplace. Stores (merchants)
 * and shipments can link to a marketplace so POD resolves the right HQ keys.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('marketplaces', function (Blueprint $table) {
            if (! Schema::hasColumn('marketplaces', 'api_base_url')) {
                $table->string('api_base_url', 500)->nullable()->after('email');
            }
            if (! Schema::hasColumn('marketplaces', 'callback_url')) {
                $table->string('callback_url', 500)->nullable()->after('api_base_url');
            }
            if (! Schema::hasColumn('marketplaces', 'callback_secret')) {
                $table->text('callback_secret')->nullable()->after('callback_url');
            }
            if (! Schema::hasColumn('marketplaces', 'is_default')) {
                $table->boolean('is_default')->default(false)->after('is_active');
            }
            if (! Schema::hasColumn('marketplaces', 'meta')) {
                $table->json('meta')->nullable()->after('is_default');
            }
        });

        if (Schema::hasTable('merchants') && ! Schema::hasColumn('merchants', 'marketplace_id')) {
            Schema::table('merchants', function (Blueprint $table) {
                $table->unsignedBigInteger('marketplace_id')->nullable()->after('id')->index();
            });
        }

        if (Schema::hasTable('shipments') && ! Schema::hasColumn('shipments', 'marketplace_id')) {
            Schema::table('shipments', function (Blueprint $table) {
                $table->unsignedBigInteger('marketplace_id')->nullable()->index();
            });
        }

        if (Schema::hasTable('shipments') && ! Schema::hasColumn('shipments', 'external_platform')) {
            Schema::table('shipments', function (Blueprint $table) {
                $table->string('external_platform', 100)->nullable()->index();
            });
        }

        // Seed known marketplace API bases when rows already exist / create FCA.
        $now = now();
        $known = [
            [
                'code' => 'tukaatu-marketplace',
                'name' => 'Tukaatu Marketplace',
                'api_base_url' => 'https://api.tukaatu.com',
                'callback_url' => 'https://api.tukaatu.com/api/v1/integrations/tukaatu-express/callbacks',
                'is_default' => true,
            ],
            [
                'code' => 'fca-marketplace',
                'name' => 'FCA Marketplace',
                'api_base_url' => 'https://api.fca.com.np',
                'callback_url' => 'https://api.fca.com.np/api/v1/integrations/tukaatu-express/callbacks',
                'is_default' => false,
            ],
        ];

        foreach ($known as $row) {
            $existing = DB::table('marketplaces')->where('code', $row['code'])->first();
            if ($existing) {
                DB::table('marketplaces')->where('id', $existing->id)->update([
                    'api_base_url' => $existing->api_base_url ?: $row['api_base_url'],
                    'callback_url' => $existing->callback_url ?: $row['callback_url'],
                    'is_default' => $row['is_default'] ? 1 : (int) ($existing->is_default ?? 0),
                    'is_active' => 1,
                    'updated_at' => $now,
                ]);
            } else {
                DB::table('marketplaces')->insert([
                    'name' => $row['name'],
                    'code' => $row['code'],
                    'email' => null,
                    'api_base_url' => $row['api_base_url'],
                    'callback_url' => $row['callback_url'],
                    'callback_secret' => null,
                    'is_active' => 1,
                    'is_default' => $row['is_default'] ? 1 : 0,
                    'meta' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        // Attach store_manager merchants to default marketplace when unset.
        if (Schema::hasColumn('merchants', 'marketplace_id')) {
            $defaultId = DB::table('marketplaces')->where('is_default', 1)->value('id')
                ?: DB::table('marketplaces')->where('code', 'tukaatu-marketplace')->value('id');
            if ($defaultId) {
                DB::table('merchants')
                    ->whereNull('marketplace_id')
                    ->where(function ($q) {
                        $q->where('external_platform', 'store_manager')
                            ->orWhereNotNull('external_store_id');
                    })
                    ->update(['marketplace_id' => $defaultId, 'updated_at' => $now]);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('shipments', 'marketplace_id')) {
            Schema::table('shipments', function (Blueprint $table) {
                $table->dropColumn('marketplace_id');
            });
        }
        if (Schema::hasColumn('shipments', 'external_platform')) {
            Schema::table('shipments', function (Blueprint $table) {
                $table->dropColumn('external_platform');
            });
        }
        if (Schema::hasColumn('merchants', 'marketplace_id')) {
            Schema::table('merchants', function (Blueprint $table) {
                $table->dropColumn('marketplace_id');
            });
        }
        Schema::table('marketplaces', function (Blueprint $table) {
            foreach (['api_base_url', 'callback_url', 'callback_secret', 'is_default', 'meta'] as $col) {
                if (Schema::hasColumn('marketplaces', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};