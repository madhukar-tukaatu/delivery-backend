<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipment_number_sequences', function (Blueprint $table) {
            $table->id();

            $table->date('sequence_date')->unique();

            $table->unsignedBigInteger('last_number')->default(0);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_number_sequences');
    }
};