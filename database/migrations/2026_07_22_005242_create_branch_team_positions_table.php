<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branch_team_positions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('branch_id')
                ->constrained('branches')
                ->cascadeOnDelete();

            /*
             * Every position has a real login account.
             */
            $table->foreignId('user_id')
                ->unique()
                ->constrained('users')
                ->cascadeOnDelete();

            $table->string('role')->index();

            /*
             * Example:
             * KTM-MAIN-DISPATCH-01
             */
            $table->string('position_code')->unique();

            $table->unsignedSmallInteger('position_number')
                ->default(1);

            $table->enum('staffing_status', [
                'vacant',
                'assigned',
                'temporarily_unassigned',
                'disabled',
            ])->default('vacant')->index();

            $table->foreignId('assigned_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('unassigned_at')->nullable();

            /*
             * Stored only until the Branch Manager reveals
             * the generated temporary password.
             */
            $table->text('temporary_password_encrypted')
                ->nullable();

            $table->timestamp('credentials_revealed_at')
                ->nullable();

            $table->timestamps();

            $table->unique([
                'branch_id',
                'role',
                'position_number',
            ], 'branch_role_position_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_team_positions');
    }
};