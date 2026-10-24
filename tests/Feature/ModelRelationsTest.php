<?php

namespace Tests\Feature;

use App\Models\MachineProduct;
use App\Models\PointLog;
use App\Models\Product;
use App\Models\User;
use App\Models\VendingMachine;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ModelRelationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_retrieve_vending_machines_and_point_logs(): void
    {
        $user = User::factory()->create();
        $machine = $this->createVendingMachine($user);
        $pointLog = $this->createPointLog($user, $machine);

        $this->assertTrue($user->vendingMachines->contains($machine));
        $this->assertTrue($user->pointLogs->contains($pointLog));
    }

    public function test_vending_machine_can_retrieve_user_products_and_point_logs(): void
    {
        $user = User::factory()->create();
        $machine = $this->createVendingMachine($user);
        $product = $this->createProduct();
        $this->createMachineProduct($machine, $product, 175, 'cold');
        $pointLog = $this->createPointLog($user, $machine);

        $this->assertTrue($machine->user->is($user));
        $this->assertTrue($machine->products->contains($product));
        $this->assertTrue($machine->pointLogs->contains($pointLog));
    }

    public function test_product_can_retrieve_vending_machines(): void
    {
        $machine = $this->createVendingMachine();
        $product = $this->createProduct();
        $this->createMachineProduct($machine, $product, 175, 'cold');

        $this->assertTrue($product->vendingMachines->contains($machine));
    }

    public function test_point_log_can_retrieve_user_vending_machine_and_product(): void
    {
        $user = User::factory()->create();
        $machine = $this->createVendingMachine($user);
        $product = $this->createProduct();
        $pointLog = $this->createPointLog($user, $machine, product: $product);

        $this->assertTrue($pointLog->user->is($user));
        $this->assertTrue($pointLog->vendingMachine->is($machine));
        $this->assertTrue($pointLog->product->is($product));
    }

    public function test_hot_and_cold_rows_are_retrieved_in_both_directions_with_cast_pivot_values(): void
    {
        $machine = $this->createVendingMachine();
        $product = $this->createProduct();
        $recordedAt = Carbon::parse('2026-10-18 06:00:00', 'UTC');

        $this->createMachineProduct($machine, $product, 175, 'hot', $recordedAt);
        $this->createMachineProduct($machine, $product, 160, 'cold', $recordedAt);

        $products = $machine->products()->orderByPivot('id')->get();
        $machines = $product->vendingMachines()->orderByPivot('id')->get();

        $this->assertCount(2, $products);
        $this->assertCount(2, $machines);
        $this->assertSame(['hot', 'cold'], $products->map(
            fn (Product $relatedProduct): ?string => $relatedProduct->pivot->temperature
        )->all());
        $this->assertSame(['hot', 'cold'], $machines->map(
            fn (VendingMachine $relatedMachine): ?string => $relatedMachine->pivot->temperature
        )->all());

        foreach ([$products, $machines] as $relatedModels) {
            foreach ($relatedModels as $relatedModel) {
                $pivot = $relatedModel->pivot;

                $this->assertInstanceOf(MachineProduct::class, $pivot);
                $this->assertIsInt($pivot->price);
                $this->assertSame('2026-10-18 06:00:00', $pivot->recorded_at->utc()->format('Y-m-d H:i:s'));
            }
        }

        $this->assertSame([175, 160], $products->map(
            fn (Product $relatedProduct): int => $relatedProduct->pivot->price
        )->all());
        $this->assertSame([175, 160], $machines->map(
            fn (VendingMachine $relatedMachine): int => $relatedMachine->pivot->price
        )->all());
    }

    public function test_nullable_machine_user_and_point_log_machine_are_handled(): void
    {
        $machine = $this->createVendingMachine();
        $user = User::factory()->create();
        $pointLog = $this->createPointLog($user);

        $this->assertNull($machine->user);
        $this->assertNull($pointLog->vendingMachine);
    }

    public function test_model_attributes_are_cast_to_their_declared_types(): void
    {
        $machine = $this->createVendingMachine();
        $product = $this->createProduct();
        $user = User::factory()->create();
        $pointLog = $this->createPointLog($user, $machine);

        $this->assertSame('33.5903547', $machine->latitude);
        $this->assertSame('130.4017155', $machine->longitude);
        $this->assertInstanceOf(Carbon::class, $machine->last_confirmed_at);
        $this->assertIsInt($product->volume_ml);
        $this->assertIsInt($pointLog->points);
        $this->assertInstanceOf(Carbon::class, $pointLog->created_at);
        $this->assertNull($pointLog->getUpdatedAtColumn());
        $this->assertFalse(Schema::hasColumn('point_logs', 'updated_at'));
    }

    public function test_user_point_total_can_be_calculated_from_point_logs(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $this->createPointLog($user, null, 10);
        $this->createPointLog($user, null, 25);
        $this->createPointLog($otherUser, null, 100);

        $this->assertSame(35, (int) $user->pointLogs()->sum('points'));
    }

    public function test_registration_user_and_point_log_machine_can_be_null(): void
    {
        $machine = $this->createVendingMachine();
        $user = User::factory()->create();
        $pointLog = $this->createPointLog($user);

        $this->assertNull($machine->user_id);
        $this->assertNull($machine->user);
        $this->assertNull($pointLog->vending_machine_id);
        $this->assertNull($pointLog->vendingMachine);
    }

    private function createVendingMachine(?User $user = null): VendingMachine
    {
        return VendingMachine::query()->create([
            'latitude' => 33.5903547,
            'longitude' => 130.4017155,
            'maker' => '開発用メーカー',
            'photo_path' => 'vending-machines/development/sample.jpg',
            'user_id' => $user?->id,
            'last_confirmed_at' => '2026-10-18 06:00:00',
        ]);
    }

    private function createProduct(): Product
    {
        return Product::query()->create([
            'name' => '開発用商品',
            'maker' => '開発用メーカー',
            'volume_ml' => '500',
            'category' => '開発用カテゴリ',
        ]);
    }

    private function createPointLog(
        User $user,
        ?VendingMachine $machine = null,
        int $points = 10,
        ?Product $product = null,
    ): PointLog {
        return PointLog::query()->create([
            'user_id' => $user->id,
            'type' => 'registration',
            'points' => $points,
            'vending_machine_id' => $machine?->id,
            'product_id' => $product?->id,
        ]);
    }

    private function createMachineProduct(
        VendingMachine $machine,
        Product $product,
        int $price,
        string $temperature,
        ?Carbon $recordedAt = null
    ): void {
        DB::table('machine_products')->insert([
            'vending_machine_id' => $machine->id,
            'product_id' => $product->id,
            'price' => $price,
            'temperature' => $temperature,
            'recorded_at' => $recordedAt ?? Carbon::parse('2026-10-18 06:00:00', 'UTC'),
        ]);
    }
}
