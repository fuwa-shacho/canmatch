<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use App\Models\VendingMachine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RareProductsTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('rare-products.index'))
            ->assertRedirect(route('login'));
    }

    public function test_products_sold_by_three_or_fewer_machines_are_listed(): void
    {
        $rare = $this->createProduct('開発用レア商品');
        $common = $this->createProduct('開発用通常商品');
        $unavailable = $this->createProduct('開発用未販売商品');

        foreach (range(1, 3) as $index) {
            $this->attach($rare, $this->createMachine($index), 120 + $index);
        }

        foreach (range(4, 7) as $index) {
            $this->attach($common, $this->createMachine($index), 130 + $index);
        }

        $response = $this->actingAs(User::factory()->create())
            ->get(route('rare-products.index'));

        $response
            ->assertOk()
            ->assertSee('開発用レア商品')
            ->assertSee('3台')
            ->assertDontSee('開発用通常商品')
            ->assertDontSee('開発用未販売商品');

        $this->assertNotNull($unavailable);
    }

    public function test_hot_and_cold_rows_on_the_same_machine_count_as_one_machine(): void
    {
        $product = $this->createProduct('開発用温冷商品');
        $machine = $this->createMachine(1);
        $this->attach($product, $machine, 150, 'hot');
        $this->attach($product, $machine, 140, 'cold');

        $response = $this->actingAs(User::factory()->create())
            ->get(route('rare-products.index'));

        $response
            ->assertOk()
            ->assertSee('開発用温冷商品')
            ->assertSee('1台');

        $this->assertSame(1, substr_count($response->getContent(), '自販機 #'.$machine->id));
    }

    public function test_rare_product_threshold_comes_from_config(): void
    {
        config(['canmatch.rare_product_max_machines' => 1]);

        $oneMachine = $this->createProduct('開発用1台商品');
        $twoMachines = $this->createProduct('開発用2台商品');
        $first = $this->createMachine(1);
        $second = $this->createMachine(2);
        $this->attach($oneMachine, $first, 120);
        $this->attach($twoMachines, $first, 130);
        $this->attach($twoMachines, $second, 130);

        $this->actingAs(User::factory()->create())
            ->get(route('rare-products.index'))
            ->assertOk()
            ->assertSee('開発用1台商品')
            ->assertDontSee('開発用2台商品');
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

    private function createMachine(int $index): VendingMachine
    {
        return VendingMachine::query()->create([
            'latitude' => 33.5900000 + ($index / 10000),
            'longitude' => 130.4010000 + ($index / 10000),
            'maker' => '開発用自販機メーカー'.$index,
            'photo_path' => 'vending-machines/development/rare-'.$index.'.jpg',
            'last_confirmed_at' => '2026-10-21 00:00:00',
        ]);
    }

    private function attach(
        Product $product,
        VendingMachine $machine,
        int $price,
        string $temperature = 'cold'
    ): void {
        DB::table('machine_products')->insert([
            'vending_machine_id' => $machine->id,
            'product_id' => $product->id,
            'price' => $price,
            'temperature' => $temperature,
            'recorded_at' => '2026-10-21 00:00:00',
        ]);
    }
}
