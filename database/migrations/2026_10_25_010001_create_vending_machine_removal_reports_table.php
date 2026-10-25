<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vending_machine_removal_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vending_machine_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['vending_machine_id', 'user_id'], 'removal_reports_machine_user_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vending_machine_removal_reports');
    }
};
