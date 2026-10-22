<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('question_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->text('question');
            $table->string('status', 32);
            $table->json('product_ids');
            $table->json('evidence');
            $table->json('citations');
            $table->text('answer');
            $table->string('error_reason', 64)->nullable();
            $table->string('model')->nullable();
            $table->string('prompt_version', 64)->nullable();
            $table->float('top_score')->nullable();
            $table->unsignedInteger('duration_ms');
            $table->dateTime('created_at')->index();
            $table->index(['user_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('question_logs');
    }
};
