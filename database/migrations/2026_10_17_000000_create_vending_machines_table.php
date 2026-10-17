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
        Schema::create('vending_machines', function (Blueprint $table) {
            $table->id();
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->string('maker')->nullable();
            $table->string('photo_path', 1024);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->dateTime('last_confirmed_at');
            $table->timestamps();
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement(
                'ALTER TABLE vending_machines '
                .'ADD CONSTRAINT vending_machines_latitude_range CHECK (latitude BETWEEN -90 AND 90), '
                .'ADD CONSTRAINT vending_machines_longitude_range CHECK (longitude BETWEEN -180 AND 180)'
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vending_machines');
    }
};
