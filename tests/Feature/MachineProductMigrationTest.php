<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MachineProductMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_machine_product_can_be_saved(): void
    {
        [$machineId, $productId] = $this->createMachineAndProduct();
        $recordedAt = '2026-10-18 06:00:00';

        $id = DB::table('machine_products')->insertGetId([
            'vending_machine_id' => $machineId,
            'product_id' => $productId,
            'price' => 150,
            'temperature' => null,
            'recorded_at' => $recordedAt,
        ]);

        $this->assertDatabaseHas('machine_products', [
            'id' => $id,
            'vending_machine_id' => $machineId,
            'product_id' => $productId,
            'price' => 150,
            'temperature' => null,
            'recorded_at' => $recordedAt,
        ]);
    }

    public function test_hot_and_cold_products_can_have_different_prices(): void
    {
        [$machineId, $productId] = $this->createMachineAndProduct();

        DB::table('machine_products')->insert([
            [
                'vending_machine_id' => $machineId,
                'product_id' => $productId,
                'price' => 150,
                'temperature' => 'hot',
                'recorded_at' => '2026-10-18 06:00:00',
            ],
            [
                'vending_machine_id' => $machineId,
                'product_id' => $productId,
                'price' => 140,
                'temperature' => 'cold',
                'recorded_at' => '2026-10-18 06:00:00',
            ],
        ]);

        $this->assertDatabaseHas('machine_products', [
            'vending_machine_id' => $machineId,
            'product_id' => $productId,
            'price' => 150,
            'temperature' => 'hot',
        ]);
        $this->assertDatabaseHas('machine_products', [
            'vending_machine_id' => $machineId,
            'product_id' => $productId,
            'price' => 140,
            'temperature' => 'cold',
        ]);
        $this->assertSame(
            2,
            DB::table('machine_products')
                ->where('vending_machine_id', $machineId)
                ->where('product_id', $productId)
                ->count()
        );
    }

    public function test_mysql_rejects_an_invalid_temperature(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('温冷のCHECK制約は対象環境のMySQL 8で確認する。');
        }

        [$machineId, $productId] = $this->createMachineAndProduct();

        $this->expectException(QueryException::class);

        DB::table('machine_products')->insert([
            'vending_machine_id' => $machineId,
            'product_id' => $productId,
            'price' => 150,
            'temperature' => 'warm',
            'recorded_at' => '2026-10-18 06:00:00',
        ]);
    }

    public function test_nonexistent_vending_machine_is_rejected(): void
    {
        $productId = $this->createProduct();

        $this->expectException(QueryException::class);

        DB::table('machine_products')->insert([
            'vending_machine_id' => 999999,
            'product_id' => $productId,
            'price' => 150,
            'temperature' => null,
            'recorded_at' => '2026-10-18 06:00:00',
        ]);
    }

    public function test_nonexistent_product_is_rejected(): void
    {
        $machineId = $this->createMachine();

        $this->expectException(QueryException::class);

        DB::table('machine_products')->insert([
            'vending_machine_id' => $machineId,
            'product_id' => 999999,
            'price' => 150,
            'temperature' => null,
            'recorded_at' => '2026-10-18 06:00:00',
        ]);
    }

    public function test_deleting_a_machine_cascades_to_its_products(): void
    {
        [$machineId, $productId] = $this->createMachineAndProduct();
        $machineProductId = $this->createMachineProduct($machineId, $productId);

        DB::table('vending_machines')->where('id', $machineId)->delete();

        $this->assertDatabaseMissing('machine_products', ['id' => $machineProductId]);
    }

    public function test_product_cannot_be_deleted_while_associated_with_a_machine(): void
    {
        [$machineId, $productId] = $this->createMachineAndProduct();
        $this->createMachineProduct($machineId, $productId);

        $this->expectException(QueryException::class);

        DB::table('products')->where('id', $productId)->delete();
    }

    /**
     * @return array{int, int}
     */
    private function createMachineAndProduct(): array
    {
        return [$this->createMachine(), $this->createProduct()];
    }

    private function createMachine(): int
    {
        $now = now()->utc();

        return DB::table('vending_machines')->insertGetId([
            'latitude' => 33.5903547,
            'longitude' => 130.4017155,
            'maker' => null,
            'photo_path' => 'vending-machines/development/sample.jpg',
            'user_id' => null,
            'last_confirmed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function createProduct(): int
    {
        $now = now()->utc();

        return DB::table('products')->insertGetId([
            'name' => '開発用商品',
            'maker' => null,
            'volume_ml' => null,
            'category' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function createMachineProduct(int $machineId, int $productId): int
    {
        return DB::table('machine_products')->insertGetId([
            'vending_machine_id' => $machineId,
            'product_id' => $productId,
            'price' => 150,
            'temperature' => null,
            'recorded_at' => '2026-10-18 06:00:00',
        ]);
    }
}
