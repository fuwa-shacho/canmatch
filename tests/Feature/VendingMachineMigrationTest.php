<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class VendingMachineMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_vending_machine_can_be_registered_manually(): void
    {
        $user = User::factory()->create();
        $confirmedAt = now()->utc()->startOfSecond();

        $id = DB::table('vending_machines')->insertGetId([
            'latitude' => 33.5903547,
            'longitude' => 130.4017155,
            'maker' => '開発用メーカー',
            'photo_path' => 'vending-machines/development/sample.jpg',
            'user_id' => $user->id,
            'last_confirmed_at' => $confirmedAt,
            'created_at' => $confirmedAt,
            'updated_at' => $confirmedAt,
        ]);

        $this->assertDatabaseHas('vending_machines', [
            'id' => $id,
            'maker' => '開発用メーカー',
            'photo_path' => 'vending-machines/development/sample.jpg',
            'user_id' => $user->id,
        ]);
    }

    public function test_registered_machine_remains_after_its_user_is_deleted(): void
    {
        $user = User::factory()->create();
        $machineId = DB::table('vending_machines')->insertGetId([
            'latitude' => 33.5903547,
            'longitude' => 130.4017155,
            'maker' => null,
            'photo_path' => 'vending-machines/development/sample.jpg',
            'user_id' => $user->id,
            'last_confirmed_at' => now()->utc(),
            'created_at' => now()->utc(),
            'updated_at' => now()->utc(),
        ]);

        $user->delete();

        $this->assertDatabaseHas('vending_machines', [
            'id' => $machineId,
            'user_id' => null,
        ]);
    }

    public function test_coordinates_use_seven_decimal_places(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('DECIMALの精度は対象環境のMySQL 8で確認する。');
        }

        $this->assertSame('decimal(10,7)', Schema::getColumnType('vending_machines', 'latitude', true));
        $this->assertSame('decimal(10,7)', Schema::getColumnType('vending_machines', 'longitude', true));
    }

    public function test_mysql_rejects_coordinates_outside_the_valid_range(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('MySQL 8のCHECK制約はMySQL環境で確認する。');
        }

        $this->expectException(QueryException::class);

        DB::table('vending_machines')->insert([
            'latitude' => 90.0000001,
            'longitude' => 130.4017155,
            'maker' => null,
            'photo_path' => 'vending-machines/development/out-of-range.jpg',
            'user_id' => null,
            'last_confirmed_at' => now()->utc(),
            'created_at' => now()->utc(),
            'updated_at' => now()->utc(),
        ]);
    }
}
