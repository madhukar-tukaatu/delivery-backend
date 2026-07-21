<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username')
                ->nullable()
                ->unique()
                ->after('id');

            /*
             * Automatically created branch positions do not
             * initially have personal email addresses.
             */
            $table->string('email')
                ->nullable()
                ->change();

            $table->string('account_status')
                ->default('active')
                ->index()
                ->after('is_active');

            $table->boolean('must_change_password')
                ->default(false)
                ->after('account_status');

            $table->timestamp('assigned_at')
                ->nullable()
                ->after('must_change_password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'username',
                'account_status',
                'must_change_password',
                'assigned_at',
            ]);
        });
    }
};