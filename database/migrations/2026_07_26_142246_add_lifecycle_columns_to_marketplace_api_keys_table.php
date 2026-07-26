<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $hasRevokedAt = Schema::hasColumn(
            'marketplace_api_keys',
            'revoked_at'
        );

        $hasLastUsedAt = Schema::hasColumn(
            'marketplace_api_keys',
            'last_used_at'
        );

        Schema::table(
            'marketplace_api_keys',
            function (Blueprint $table) use (
                $hasRevokedAt,
                $hasLastUsedAt
            ): void {
                if (!$hasRevokedAt) {
                    $table->timestamp('revoked_at')
                        ->nullable()
                        ->after('is_active')
                        ->index();
                }

                if (!$hasLastUsedAt) {
                    $table->timestamp('last_used_at')
                        ->nullable()
                        ->after(
                            $hasRevokedAt
                                ? 'revoked_at'
                                : 'is_active'
                        );
                }
            }
        );
    }

    public function down(): void
    {
        $columns = [];

        if (
            Schema::hasColumn(
                'marketplace_api_keys',
                'revoked_at'
            )
        ) {
            $columns[] = 'revoked_at';
        }

        if (
            Schema::hasColumn(
                'marketplace_api_keys',
                'last_used_at'
            )
        ) {
            $columns[] = 'last_used_at';
        }

        if ($columns === []) {
            return;
        }

        Schema::table(
            'marketplace_api_keys',
            function (Blueprint $table) use (
                $columns
            ): void {
                $table->dropColumn($columns);
            }
        );
    }
};