<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('machine_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vending_machine_id')
                ->index()
                ->constrained()
                ->cascadeOnDelete();
            $table->foreignId('product_id')
                ->index()
                ->constrained()
                ->restrictOnDelete();
            $table->unsignedInteger('price');
            $table->string('temperature', 16)->nullable();
            $table->dateTime('recorded_at');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement(
                'ALTER TABLE machine_products '
                .'ADD CONSTRAINT machine_products_temperature_check '
                ."CHECK (temperature IS NULL OR temperature IN ('hot', 'cold'))"
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('machine_products');
    }
};
