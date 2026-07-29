<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Branch invitation tracking fields.
         */
        if (Schema::hasTable('branches')) {
            Schema::table(
                'branches',
                function (Blueprint $table): void {
                    if (
                        !Schema::hasColumn(
                            'branches',
                            'account_invitation_status'
                        )
                    ) {
                        $table
                            ->string(
                                'account_invitation_status',
                                40
                            )
                            ->default(
                                'pending_admin_approval'
                            )
                            ->after('approved_at');
                    }

                    if (
                        !Schema::hasColumn(
                            'branches',
                            'account_invitation_email'
                        )
                    ) {
                        $table
                            ->string(
                                'account_invitation_email'
                            )
                            ->nullable()
                            ->after(
                                'account_invitation_status'
                            );
                    }

                    if (
                        !Schema::hasColumn(
                            'branches',
                            'account_invitation_queued_at'
                        )
                    ) {
                        $table
                            ->timestamp(
                                'account_invitation_queued_at'
                            )
                            ->nullable()
                            ->after(
                                'account_invitation_email'
                            );
                    }

                    if (
                        !Schema::hasColumn(
                            'branches',
                            'account_invitation_sent_at'
                        )
                    ) {
                        $table
                            ->timestamp(
                                'account_invitation_sent_at'
                            )
                            ->nullable()
                            ->after(
                                'account_invitation_queued_at'
                            );
                    }

                    if (
                        !Schema::hasColumn(
                            'branches',
                            'account_invitation_failed_at'
                        )
                    ) {
                        $table
                            ->timestamp(
                                'account_invitation_failed_at'
                            )
                            ->nullable()
                            ->after(
                                'account_invitation_sent_at'
                            );
                    }

                    if (
                        !Schema::hasColumn(
                            'branches',
                            'account_invitation_error'
                        )
                    ) {
                        $table
                            ->text(
                                'account_invitation_error'
                            )
                            ->nullable()
                            ->after(
                                'account_invitation_failed_at'
                            );
                    }

                    if (
                        !Schema::hasColumn(
                            'branches',
                            'account_invitation_count'
                        )
                    ) {
                        $table
                            ->unsignedInteger(
                                'account_invitation_count'
                            )
                            ->default(0)
                            ->after(
                                'account_invitation_error'
                            );
                    }
                }
            );
        }

        /*
         * Manager account setup tracking fields.
         */
        if (Schema::hasTable('users')) {
            Schema::table(
                'users',
                function (Blueprint $table): void {
                    if (
                        !Schema::hasColumn(
                            'users',
                            'account_setup_completed_at'
                        )
                    ) {
                        $table
                            ->timestamp(
                                'account_setup_completed_at'
                            )
                            ->nullable()
                            ->after(
                                'email_verified_at'
                            );
                    }

                    if (
                        !Schema::hasColumn(
                            'users',
                            'last_login_at'
                        )
                    ) {
                        $table
                            ->timestamp(
                                'last_login_at'
                            )
                            ->nullable()
                            ->after(
                                'account_setup_completed_at'
                            );
                    }
                }
            );
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('branches')) {
            $branchColumns = [
                'account_invitation_status',
                'account_invitation_email',
                'account_invitation_queued_at',
                'account_invitation_sent_at',
                'account_invitation_failed_at',
                'account_invitation_error',
                'account_invitation_count',
            ];

            $existingBranchColumns = array_values(
                array_filter(
                    $branchColumns,
                    static fn (string $column): bool =>
                        Schema::hasColumn(
                            'branches',
                            $column
                        )
                )
            );

            if ($existingBranchColumns !== []) {
                Schema::table(
                    'branches',
                    function (
                        Blueprint $table
                    ) use (
                        $existingBranchColumns
                    ): void {
                        $table->dropColumn(
                            $existingBranchColumns
                        );
                    }
                );
            }
        }

        if (Schema::hasTable('users')) {
            $userColumns = [
                'account_setup_completed_at',
                'last_login_at',
            ];

            $existingUserColumns = array_values(
                array_filter(
                    $userColumns,
                    static fn (string $column): bool =>
                        Schema::hasColumn(
                            'users',
                            $column
                        )
                )
            );

            if ($existingUserColumns !== []) {
                Schema::table(
                    'users',
                    function (
                        Blueprint $table
                    ) use (
                        $existingUserColumns
                    ): void {
                        $table->dropColumn(
                            $existingUserColumns
                        );
                    }
                );
            }
        }
    }
};