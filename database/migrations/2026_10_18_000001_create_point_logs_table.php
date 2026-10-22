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
        Schema::create('point_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                ->index()
                ->constrained()
                ->cascadeOnDelete();
            $table->string('type', 32);
            $table->integer('points');
            $table->foreignId('vending_machine_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();
            $table->dateTime('created_at')->index();
            $table->index(['user_id', 'created_at']);
            $table->index(['vending_machine_id', 'created_at']);
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement(
                'ALTER TABLE point_logs '
                .'ADD CONSTRAINT point_logs_type_check '
                ."CHECK (type IN ('registration', 'update', 'rare_bonus'))"
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('point_logs');
    }
};
