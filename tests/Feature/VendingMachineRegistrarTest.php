<?php

namespace Tests\Feature;

use App\Models\PointLog;
use App\Models\Product;
use App\Models\User;
use App\Models\VendingMachine;
use App\Services\VendingMachineRegistrar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
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

    public function test_利用者の自販機登録と同じ処理で10ポイントを付与する(): void
    {
        $user = User::factory()->create();
        $attributes = $this->attributes();
        $attributes['user_id'] = $user->id;

        $machine = app(VendingMachineRegistrar::class)->register($attributes, [
            ['name' => 'お茶', 'price' => 150, 'temperature' => 'cold', 'product_id' => null],
        ]);

        $this->assertDatabaseHas('point_logs', [
            'user_id' => $user->id,
            'vending_machine_id' => $machine->id,
            'type' => 'registration',
            'points' => 10,
        ]);
        $this->assertSame(1, PointLog::where('type', 'registration')->count());
    }

    public function test_レア商品を含む登録では通常点とボーナスを付与する(): void
    {
        $user = User::factory()->create();
        $product = Product::create(['name' => 'レア商品', 'volume_ml' => 500]);
        $otherRareProduct = Product::create(['name' => '別のレア商品', 'volume_ml' => 350]);
        $attributes = $this->attributes();
        $attributes['user_id'] = $user->id;

        foreach (range(1, 3) as $index) {
            $existingMachine = VendingMachine::create([
                'latitude' => 33.5800000 + ($index / 10000),
                'longitude' => 130.3900000 + ($index / 10000),
                'photo_path' => "photos/existing-{$index}.jpg",
                'last_confirmed_at' => now(),
            ]);
            DB::table('machine_products')->insert([
                'vending_machine_id' => $existingMachine->id,
                'product_id' => $product->id,
                'price' => 120,
                'temperature' => 'cold',
                'recorded_at' => now(),
            ]);
        }

        $machine = app(VendingMachineRegistrar::class)->register($attributes, [
            ['name' => 'レア商品', 'price' => 130, 'temperature' => 'cold', 'product_id' => $product->id],
            ['name' => '別のレア商品', 'price' => 140, 'temperature' => 'cold', 'product_id' => $otherRareProduct->id],
        ]);

        $this->assertSame(4, $product->vendingMachines()->distinct()->count('vending_machines.id'));
        $this->assertDatabaseHas('point_logs', [
            'vending_machine_id' => $machine->id,
            'type' => 'registration',
            'points' => 10,
        ]);
        $this->assertDatabaseHas('point_logs', [
            'vending_machine_id' => $machine->id,
            'type' => 'rare_bonus',
            'points' => 20,
        ]);
        $this->assertSame(1, PointLog::where('type', 'rare_bonus')->count());
    }

    public function test_通常商品の登録ではレアボーナスを付与しない(): void
    {
        $user = User::factory()->create();
        $product = Product::create(['name' => '通常商品', 'volume_ml' => 500]);
        $attributes = $this->attributes();
        $attributes['user_id'] = $user->id;

        foreach (range(1, 4) as $index) {
            $existingMachine = VendingMachine::create([
                'latitude' => 33.5800000 + ($index / 10000),
                'longitude' => 130.3900000 + ($index / 10000),
                'photo_path' => "photos/common-{$index}.jpg",
                'last_confirmed_at' => now(),
            ]);
            DB::table('machine_products')->insert([
                'vending_machine_id' => $existingMachine->id,
                'product_id' => $product->id,
                'price' => 120,
                'temperature' => 'cold',
                'recorded_at' => now(),
            ]);
        }

        app(VendingMachineRegistrar::class)->register($attributes, [
            ['name' => '通常商品', 'price' => 130, 'temperature' => 'cold', 'product_id' => $product->id],
        ]);

        $this->assertSame(1, PointLog::where('type', 'registration')->count());
        $this->assertSame(0, PointLog::where('type', 'rare_bonus')->count());
    }

    public function test_利用者がない登録にはポイントを付与しない(): void
    {
        app(VendingMachineRegistrar::class)->register($this->attributes(), [
            ['name' => 'お茶', 'price' => 150, 'temperature' => 'cold', 'product_id' => null],
        ]);

        $this->assertSame(0, PointLog::count());
    }

    public function test_ポイント付与に失敗したら自販機と商品も取り消す(): void
    {
        config(['points.registration' => 0]);
        $user = User::factory()->create();
        $attributes = $this->attributes();
        $attributes['user_id'] = $user->id;

        try {
            app(VendingMachineRegistrar::class)->register($attributes, [
                ['name' => '新商品', 'price' => 150, 'temperature' => 'cold', 'product_id' => null],
            ]);
            $this->fail('ポイント設定が不正なら失敗するはずです');
        } catch (LogicException) {
            $this->assertTrue(true);
        }

        $this->assertSame(0, VendingMachine::count());
        $this->assertSame(0, Product::count());
        $this->assertSame(0, PointLog::count());
    }

    public function test_レアボーナス付与に失敗したら登録全体を取り消す(): void
    {
        config(['points.rare_bonus' => 0]);
        $user = User::factory()->create();
        $attributes = $this->attributes();
        $attributes['user_id'] = $user->id;

        try {
            app(VendingMachineRegistrar::class)->register($attributes, [
                ['name' => '新しいレア商品', 'price' => 150, 'temperature' => 'cold', 'product_id' => null],
            ]);
            $this->fail('レアボーナス設定が不正なら失敗するはずです');
        } catch (LogicException) {
            $this->assertTrue(true);
        }

        $this->assertSame(0, VendingMachine::count());
        $this->assertSame(0, Product::count());
        $this->assertSame(0, PointLog::count());
    }

    public function test_新しい商品は容量が空で追加される(): void
    {
        app(VendingMachineRegistrar::class)->register($this->attributes(), [
            ['name' => '珍しいジュース', 'price' => 120, 'temperature' => 'cold', 'product_id' => null],
        ]);

        $this->assertDatabaseHas('products', ['name' => '珍しいジュース', 'volume_ml' => null]);
    }

    public function test_新しい商品は入力した容量で作られる(): void
    {
        app(VendingMachineRegistrar::class)->register($this->attributes(), [
            ['name' => '綾鷹', 'price' => 140, 'temperature' => 'cold', 'product_id' => null, 'new_volume_ml' => '525'],
        ]);

        $this->assertDatabaseHas('products', ['name' => '綾鷹', 'volume_ml' => 525]);
    }

    public function test_同じ名前と容量の商品があれば新しく作らずに使う(): void
    {
        $existing = Product::create(['name' => '綾鷹', 'volume_ml' => 525]);

        app(VendingMachineRegistrar::class)->register($this->attributes(), [
            ['name' => ' 綾鷹 ', 'price' => 140, 'temperature' => 'cold', 'product_id' => null, 'new_volume_ml' => 525],
        ]);

        $this->assertSame(1, Product::count());
        $this->assertDatabaseHas('machine_products', ['product_id' => $existing->id, 'price' => 140]);
    }

    public function test_容量違いは別の商品として作る(): void
    {
        Product::create(['name' => '綾鷹', 'volume_ml' => 650]);

        app(VendingMachineRegistrar::class)->register($this->attributes(), [
            ['name' => '綾鷹', 'price' => 140, 'temperature' => 'cold', 'product_id' => null, 'new_volume_ml' => 525],
        ]);

        $this->assertSame(2, Product::where('name', '綾鷹')->count());
    }

    public function test_容量不明の同名商品があれば新しく作らずに使う(): void
    {
        $existing = Product::create(['name' => '珍しいジュース']);

        app(VendingMachineRegistrar::class)->register($this->attributes(), [
            ['name' => '珍しいジュース', 'price' => 120, 'temperature' => 'cold', 'product_id' => null],
        ]);

        $this->assertSame(1, Product::count());
        $this->assertDatabaseHas('machine_products', ['product_id' => $existing->id]);
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

    public function test_既存商品は温冷が異なれば両方登録できる(): void
    {
        $product = Product::create(['name' => 'お茶']);

        $machine = app(VendingMachineRegistrar::class)->register($this->attributes(), [
            ['name' => 'お茶', 'price' => 150, 'temperature' => 'cold', 'product_id' => $product->id],
            ['name' => 'お茶', 'price' => 160, 'temperature' => 'hot', 'product_id' => $product->id],
        ]);

        $this->assertCount(2, $machine->products);
        $this->assertDatabaseCount('machine_products', 2);
    }

    public function test_既存商品の同じ温冷区分の重複を拒否して全体を取り消す(): void
    {
        $product = Product::create(['name' => 'お茶']);

        try {
            app(VendingMachineRegistrar::class)->register($this->attributes(), [
                ['name' => 'お茶', 'price' => 150, 'temperature' => 'cold', 'product_id' => $product->id],
                ['name' => 'お茶', 'price' => 160, 'temperature' => 'cold', 'product_id' => $product->id],
            ]);
            $this->fail('重複行は拒否されるはずです');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('rows', $exception->errors());
        }

        $this->assertSame(1, Product::count());
        $this->assertSame(0, VendingMachine::count());
        $this->assertDatabaseCount('machine_products', 0);
    }

    public function test_新商品の同じ温冷区分の重複を拒否して全体を取り消す(): void
    {
        try {
            app(VendingMachineRegistrar::class)->register($this->attributes(), [
                ['name' => '新作ドリンク', 'price' => 130, 'temperature' => 'cold', 'product_id' => null],
                ['name' => '新作ドリンク', 'price' => 140, 'temperature' => 'cold', 'product_id' => null],
            ]);
            $this->fail('重複行は拒否されるはずです');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('rows', $exception->errors());
        }

        $this->assertSame(0, VendingMachine::count());
        $this->assertSame(0, Product::count());
        $this->assertDatabaseCount('machine_products', 0);
    }

    public function test_途中で不正な商品_i_dがあれば全部取り消される(): void
    {
        try {
            app(VendingMachineRegistrar::class)->register($this->attributes(), [
                ['name' => '新商品A', 'price' => 100, 'temperature' => 'cold', 'product_id' => null],
                ['name' => '存在しない商品', 'price' => 100, 'temperature' => 'cold', 'product_id' => 999999],
            ]);
            $this->fail('例外が出るはずです');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('rows', $exception->errors());
        }

        $this->assertSame(0, VendingMachine::count());
        $this->assertSame(0, Product::count());
        $this->assertDatabaseCount('machine_products', 0);
    }
}
