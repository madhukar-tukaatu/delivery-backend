<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
|--------------------------------------------------------------------------
| Ensure pickup request_number is GLOBALLY unique
|--------------------------------------------------------------------------
|
| Business rule:
|
|   - store_reference : the store's own reference (e.g. "PICKUP-001").
|                       Stored on the row, unique PER merchant.
|
|   - request_number  : Tukaatu's own identifier (PR-000001, PR-000002...).
|                       Auto-generated, GLOBALLY unique across ALL merchants.
|                       Two pickups can never share a request_number.
|
| The original table already declared request_number as ->unique() (a global
| unique index named "pickup_requests_request_number_unique"). This migration
| simply GUARANTEES that global unique index exists, and makes sure no
| conflicting per-merchant composite index is left behind.
|
*/

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pickup_requests')) {
            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Remove any per-merchant composite index on request_number.
        |--------------------------------------------------------------------------
        |
        | An earlier iteration of this fix may have created
        | (merchant_id, request_number). We do NOT want that, because it would
        | allow two merchants to both hold "PR-000001".
        |
        */

        $compositeIndex = 'pickup_requests_merchant_request_number_unique';

        if ($this->indexExists('pickup_requests', $compositeIndex)) {
            Schema::table(
                'pickup_requests',
                function (Blueprint $table) use ($compositeIndex): void {
                    $table->dropUnique($compositeIndex);
                }
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Ensure the GLOBAL unique index on request_number exists.
        |--------------------------------------------------------------------------
        */

        $globalIndex = 'pickup_requests_request_number_unique';

        if (
            Schema::hasColumn('pickup_requests', 'request_number')
            && ! $this->indexExists('pickup_requests', $globalIndex)
        ) {
            Schema::table(
                'pickup_requests',
                function (Blueprint $table) use ($globalIndex): void {
                    $table->unique('request_number', $globalIndex);
                }
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Ensure store_reference is scoped uniquely PER merchant.
        |--------------------------------------------------------------------------
        |
        | merchant 1 + PICKUP-001  -> allowed
        | merchant 2 + PICKUP-001  -> allowed
        | merchant 1 + PICKUP-001  -> duplicate (rejected)
        |
        | This index may already exist from an earlier migration; we only add it
        | if it is missing.
        |
        */

        $storeRefIndex = 'pickup_requests_merchant_store_reference_unique';

        if (
            Schema::hasColumn('pickup_requests', 'merchant_id')
            && Schema::hasColumn('pickup_requests', 'store_reference')
            && ! $this->indexExists('pickup_requests', $storeRefIndex)
        ) {
            Schema::table(
                'pickup_requests',
                function (Blueprint $table) use ($storeRefIndex): void {
                    $table->unique(
                        [
                            'merchant_id',
                            'store_reference',
                        ],
                        $storeRefIndex
                    );
                }
            );
        }
    }

    public function down(): void
    {
        /*
        |--------------------------------------------------------------------------
        | Non-destructive: the global unique index is part of the original
        | schema, so we leave it in place. Nothing to reverse here.
        |--------------------------------------------------------------------------
        */
    }

    private function indexExists(
        string $table,
        string $index
    ): bool {
        $connection = Schema::getConnection();

        $database = $connection->getDatabaseName();

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
