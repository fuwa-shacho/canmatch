<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_read_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('vending_machine_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('photo_path', 1024);
            $table->string('model')->nullable();
            $table->string('prompt_version')->nullable();
            $table->string('status', 16);
            $table->longText('raw_response')->nullable();
            $table->json('predicted_products')->nullable();
            $table->string('gateway_log_id')->nullable();
            $table->string('error_reason')->nullable();
            $table->unsignedInteger('correction_count')->nullable();
            $table->timestamps();
            $table->index(['vending_machine_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_read_logs');
    }
};
