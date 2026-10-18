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
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name')->index();
            $table->string('maker')->nullable();
            $table->unsignedInteger('volume_ml')->nullable();
            $table->string('category')->nullable();
            $table->timestamps();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement(
                'ALTER TABLE products '
                .'ADD CONSTRAINT products_name_not_blank CHECK (CHAR_LENGTH(TRIM(name)) > 0), '
                .'ADD CONSTRAINT products_volume_positive CHECK (volume_ml IS NULL OR volume_ml >= 1)'
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
