<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('point_logs', function (Blueprint $table) {
            $table->foreignId('product_id')
                ->nullable()
                ->after('vending_machine_id')
                ->constrained()
                ->nullOnDelete();
            $table->unique(['type', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::table('point_logs', function (Blueprint $table) {
            $table->dropUnique(['type', 'product_id']);
            $table->dropConstrainedForeignId('product_id');
        });
    }
};
