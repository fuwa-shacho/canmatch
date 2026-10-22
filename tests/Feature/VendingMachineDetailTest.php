<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use App\Models\VendingMachine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VendingMachineDetailTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $machine = $this->createMachine();

        $this->get(route('vending-machines.show', $machine))
            ->assertRedirect('/login');
    }

    public function test_authenticated_users_can_view_machine_products_and_location(): void
    {
        $viewer = User::factory()->create();
        $owner = User::factory()->create(['name' => '開発用登録者']);
        $machine = $this->createMachine($owner, '開発用メーカー');
        $product = Product::create([
            'name' => '開発用コーヒー',
            'maker' => '開発用飲料',
            'volume_ml' => 185,
            'category' => 'コーヒー',
        ]);

        $machine->products()->attach($product->id, [
            'price' => 130,
            'temperature' => 'hot',
            'recorded_at' => '2026-10-20 00:00:00',
        ]);

        $this->actingAs($viewer)
            ->get(route('vending-machines.show', $machine))
            ->assertOk()
            ->assertSee('開発用メーカー')
            ->assertSee('開発用登録者')
            ->assertSee('開発用コーヒー')
            ->assertSee('185ml')
            ->assertSee('130円')
            ->assertSee('温かい')
            ->assertSee(route('posts.create', ['machine' => $machine->id]))
            ->assertSee('id="vending-machine-detail-map"', false)
            ->assertSee('data-latitude="33.5890000"', false)
            ->assertSee('data-longitude="130.3950000"', false);
    }

    public function test_hot_and_cold_prices_for_the_same_product_are_displayed_separately(): void
    {
        $viewer = User::factory()->create();
        $machine = $this->createMachine();
        $product = Product::create(['name' => '開発用お茶']);

        $machine->products()->attach($product->id, [
            'price' => 140,
            'temperature' => 'hot',
            'recorded_at' => '2026-10-20 00:00:00',
        ]);
        $machine->products()->attach($product->id, [
            'price' => 150,
            'temperature' => 'cold',
            'recorded_at' => '2026-10-20 00:00:00',
        ]);

        $response = $this->actingAs($viewer)
            ->get(route('vending-machines.show', $machine))
            ->assertOk()
            ->assertSee('140円')
            ->assertSee('150円')
            ->assertSee('温かい')
            ->assertSee('冷たい');

        $this->assertSame(2, substr_count($response->getContent(), '開発用お茶'));
    }

    public function test_unknown_machine_information_and_empty_products_are_explained(): void
    {
        $viewer = User::factory()->create();
        $machine = $this->createMachine();

        $this->actingAs($viewer)
            ->get(route('vending-machines.show', $machine))
            ->assertOk()
            ->assertSee('メーカー不明')
            ->assertSee('登録者不明')
            ->assertSee('商品情報はまだ登録されていません。');
    }

    public function test_unknown_machine_returns_not_found(): void
    {
        $viewer = User::factory()->create();

        $this->actingAs($viewer)
            ->get('/vending-machines/999999')
            ->assertNotFound();
    }

    private function createMachine(?User $owner = null, ?string $maker = null): VendingMachine
    {
        return VendingMachine::create([
            'latitude' => '33.5890000',
            'longitude' => '130.3950000',
            'maker' => $maker,
            'photo_path' => 'vending-machines/development/detail.jpg',
            'user_id' => $owner?->id,
            'last_confirmed_at' => '2026-10-20 00:00:00',
        ]);
    }
}
