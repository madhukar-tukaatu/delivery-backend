<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('delivery_assignments')) {
            return;
        }

        Schema::table('delivery_assignments', function (Blueprint $table): void {
            $this->addIfMissing($table, 'arrived_at', 'timestamp');
            $this->addIfMissing($table, 'customer_confirmed_at', 'timestamp');
            $this->addIfMissing($table, 'customer_confirmed_name', 'string');
            $this->addIfMissing($table, 'customer_signature_path', 'string');
            $this->addIfMissing($table, 'customer_signature_hash', 'string');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('delivery_assignments')) {
            return;
        }

        $columns = array_values(array_filter([
            Schema::hasColumn('delivery_assignments', 'arrived_at') ? 'arrived_at' : null,
            Schema::hasColumn('delivery_assignments', 'customer_confirmed_at') ? 'customer_confirmed_at' : null,
            Schema::hasColumn('delivery_assignments', 'customer_confirmed_name') ? 'customer_confirmed_name' : null,
            Schema::hasColumn('delivery_assignments', 'customer_signature_path') ? 'customer_signature_path' : null,
            Schema::hasColumn('delivery_assignments', 'customer_signature_hash') ? 'customer_signature_hash' : null,
        ]));

        if ($columns) {
            Schema::table('delivery_assignments', function (Blueprint $table) use ($columns): void {
                $table->dropColumn($columns);
            });
        }
    }

    private function addIfMissing(Blueprint $table, string $column, string $type): void
    {
        if (Schema::hasColumn('delivery_assignments', $column)) {
            return;
        }

        $definition = $type === 'timestamp'
            ? $table->timestamp($column)
            : $table->string($column);

        $definition->nullable();
    }
};
