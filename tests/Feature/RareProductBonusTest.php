<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use App\Models\VendingMachine;
use App\Services\PointAwarder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RareProductBonusTest extends TestCase
{
    use RefreshDatabase;

    public function test_rare_products_award_one_configured_bonus(): void
    {
        $user = User::factory()->create();
        $machine = $this->createMachine($user, 1);
        $firstRareProduct = $this->createProduct('開発用レア商品1');
        $secondRareProduct = $this->createProduct('開発用レア商品2');

        $pointLog = app(PointAwarder::class)->awardRareProductBonus(
            $user,
            $machine,
            [$firstRareProduct, $secondRareProduct],
        );

        $this->assertNotNull($pointLog);
        $this->assertSame('rare_bonus', $pointLog->type);
        $this->assertSame(20, $pointLog->points);
        $this->assertDatabaseCount('point_logs', 1);
    }

    public function test_product_sold_by_threshold_number_of_machines_is_rare(): void
    {
        config(['canmatch.rare_product_max_machines' => 3]);

        $user = User::factory()->create();
        $newMachine = $this->createMachine($user, 1);
        $product = $this->createProduct('開発用3台商品');

        foreach (range(2, 4) as $index) {
            $this->attachProduct($product, $this->createMachine($user, $index));
        }

        $pointLog = app(PointAwarder::class)->awardRareProductBonus($user, $newMachine, [$product]);

        $this->assertNotNull($pointLog);
        $this->assertSame('rare_bonus', $pointLog->type);
    }

    public function test_product_sold_by_more_than_threshold_machines_does_not_award_bonus(): void
    {
        config(['canmatch.rare_product_max_machines' => 3]);

        $user = User::factory()->create();
        $newMachine = $this->createMachine($user, 1);
        $product = $this->createProduct('開発用4台商品');

        foreach (range(2, 5) as $index) {
            $this->attachProduct($product, $this->createMachine($user, $index));
        }

        $pointLog = app(PointAwarder::class)->awardRareProductBonus($user, $newMachine, [$product]);

        $this->assertNull($pointLog);
        $this->assertDatabaseCount('point_logs', 0);
    }

    public function test_empty_product_list_does_not_award_bonus(): void
    {
        $user = User::factory()->create();
        $machine = $this->createMachine($user, 1);

        $pointLog = app(PointAwarder::class)->awardRareProductBonus($user, $machine, []);

        $this->assertNull($pointLog);
        $this->assertDatabaseCount('point_logs', 0);
    }

    private function createMachine(User $user, int $index): VendingMachine
    {
        return VendingMachine::query()->create([
            'latitude' => 33.5900000 + ($index / 10000),
            'longitude' => 130.4010000 + ($index / 10000),
            'maker' => '開発用自販機メーカー'.$index,
            'photo_path' => 'vending-machines/development/rare-bonus-'.$index.'.jpg',
            'user_id' => $user->id,
            'last_confirmed_at' => '2026-10-23 00:00:00',
        ]);
    }

    private function createProduct(string $name): Product
    {
        return Product::query()->create([
            'name' => $name,
            'maker' => '開発用商品メーカー',
            'volume_ml' => 500,
            'category' => '開発用カテゴリ',
        ]);
    }

    private function attachProduct(Product $product, VendingMachine $machine): void
    {
        DB::table('machine_products')->insert([
            'vending_machine_id' => $machine->id,
            'product_id' => $product->id,
            'price' => 120,
            'temperature' => 'cold',
            'recorded_at' => '2026-10-23 00:00:00',
        ]);
    }
}
