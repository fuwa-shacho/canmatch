<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\VendingMachine;
use App\Services\VendingMachineRegistrar;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VendingMachineRegistrarTest extends TestCase
{
    use RefreshDatabase;

    private function attributes(): array
    {
        return [
            'latitude' => 33.5902,
            'longitude' => 130.4017,
            'maker' => null,
            'photo_path' => 'photos/2026/10/test.jpg',
            'user_id' => null,
        ];
    }

    public function test_自販機と商品と価格をまとめて保存する(): void
    {
        $existing = Product::create(['name' => 'お茶', 'volume_ml' => 500]);

        $machine = app(VendingMachineRegistrar::class)->register($this->attributes(), [
            ['name' => 'お茶', 'price' => 150, 'temperature' => 'cold', 'product_id' => $existing->id],
            ['name' => '珍しいジュース', 'price' => '120', 'temperature' => 'hot', 'product_id' => null],
        ]);

        $this->assertNotNull($machine->last_confirmed_at);
        $this->assertCount(2, $machine->products);
        $this->assertDatabaseHas('machine_products', [
            'vending_machine_id' => $machine->id,
            'product_id' => $existing->id,
            'price' => 150,
            'temperature' => 'cold',
        ]);
    }

    public function test_新しい商品は容量が空で追加される(): void
    {
        app(VendingMachineRegistrar::class)->register($this->attributes(), [
            ['name' => '珍しいジュース', 'price' => 120, 'temperature' => 'cold', 'product_id' => null],
        ]);

        $this->assertDatabaseHas('products', ['name' => '珍しいジュース', 'volume_ml' => null]);
    }

    public function test_同じ新商品名の行は商品を1つだけ作る(): void
    {
        app(VendingMachineRegistrar::class)->register($this->attributes(), [
            ['name' => '新作ドリンク', 'price' => 130, 'temperature' => 'cold', 'product_id' => null],
            ['name' => '新作ドリンク', 'price' => 140, 'temperature' => 'hot', 'product_id' => null],
        ]);

        $this->assertSame(1, Product::where('name', '新作ドリンク')->count());
        $this->assertDatabaseCount('machine_products', 2);
    }

    public function test_途中で失敗したら全部取り消される(): void
    {
        try {
            app(VendingMachineRegistrar::class)->register($this->attributes(), [
                ['name' => '新商品A', 'price' => 100, 'temperature' => 'cold', 'product_id' => null],
                ['name' => '存在しない商品', 'price' => 100, 'temperature' => 'cold', 'product_id' => 999999],
            ]);
            $this->fail('例外が出るはずです');
        } catch (QueryException) {
            // 想定どおり
        }

        $this->assertSame(0, VendingMachine::count());
        $this->assertSame(0, Product::count());
        $this->assertDatabaseCount('machine_products', 0);
    }
}