<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('menu_items')) {
            Schema::create('menu_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('parent_id')->nullable();
                $table->string('section', 50)->default('admin')->index();
                $table->string('label');
                $table->string('path')->nullable();
                $table->string('icon')->nullable();
                $table->string('permission')->nullable()->index();
                $table->unsignedInteger('sort_order')->default(999);
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->index(['section', 'is_active', 'sort_order']);
            });

            return;
        }

        Schema::table('menu_items', function (Blueprint $table) {
            if (!Schema::hasColumn('menu_items', 'parent_id')) {
                $table->unsignedBigInteger('parent_id')->nullable();
            }

            if (!Schema::hasColumn('menu_items', 'section')) {
                $table->string('section', 50)->default('admin')->index();
            }

            if (!Schema::hasColumn('menu_items', 'label')) {
                $table->string('label');
            }

            if (!Schema::hasColumn('menu_items', 'path')) {
                $table->string('path')->nullable();
            }

            if (!Schema::hasColumn('menu_items', 'icon')) {
                $table->string('icon')->nullable();
            }

            if (!Schema::hasColumn('menu_items', 'permission')) {
                $table->string('permission')->nullable()->index();
            }

            if (!Schema::hasColumn('menu_items', 'sort_order')) {
                $table->unsignedInteger('sort_order')->default(999);
            }

            if (!Schema::hasColumn('menu_items', 'is_active')) {
                $table->boolean('is_active')->default(true);
            }
        });
    }

    public function down(): void
    {
        // Safety patch: do not drop production menu columns automatically.
    }
};
