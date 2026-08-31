<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pickup_requests')) {
            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Store reference
        |--------------------------------------------------------------------------
        |
        | This is the merchant/store's own pickup reference.
        |
        | Example:
        |
        | PR-001
        | STORE-20260831-001
        |
        */

        if (! Schema::hasColumn(
            'pickup_requests',
            'store_reference'
        )) {
            Schema::table(
                'pickup_requests',
                function (Blueprint $table): void {
                    $table
                        ->string('store_reference', 100)
                        ->nullable()
                        ->after('merchant_id');
                }
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Merchant + store reference unique index
        |--------------------------------------------------------------------------
        |
        | A merchant can have the same store reference only once.
        |
        | Example:
        |
        | merchant 1 + PR-001   -> allowed
        | merchant 2 + PR-001   -> allowed
        | merchant 1 + PR-001   -> duplicate
        |
        */

        if (
            ! $this->indexExists(
                'pickup_requests',
                'pickup_requests_merchant_store_reference_unique'
            )
        ) {
            Schema::table(
                'pickup_requests',
                function (Blueprint $table): void {
                    $table->unique(
                        [
                            'merchant_id',
                            'store_reference',
                        ],
                        'pickup_requests_merchant_store_reference_unique'
                    );
                }
            );
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('pickup_requests')) {
            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Remove merchant + store reference unique index
        |--------------------------------------------------------------------------
        */

        if (
            $this->indexExists(
                'pickup_requests',
                'pickup_requests_merchant_store_reference_unique'
            )
        ) {
            Schema::table(
                'pickup_requests',
                function (Blueprint $table): void {
                    $table->dropUnique(
                        'pickup_requests_merchant_store_reference_unique'
                    );
                }
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Remove store_reference column
        |--------------------------------------------------------------------------
        */

        if (
            Schema::hasColumn(
                'pickup_requests',
                'store_reference'
            )
        ) {
            Schema::table(
                'pickup_requests',
                function (Blueprint $table): void {
                    $table->dropColumn('store_reference');
                }
            );
        }
    }

    private function indexExists(
        string $table,
        string $index
    ): bool {
        $connection = Schema::getConnection();

        $database = $connection
            ->getDatabaseName();

        $result = $connection->selectOne(
            '
                SELECT COUNT(*) AS count
                FROM information_schema.statistics
                WHERE table_schema = ?
                AND table_name = ?
                AND index_name = ?
            ',
            [
                $database,
                $table,
                $index,
            ]
        );

        return (int) $result->count > 0;
    }
};