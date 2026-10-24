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
            ->assertSee('100mlあたり70.3円')
            ->assertSee('温かい')
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
            ->assertSee('退会済みユーザー')
            ->assertSee('商品情報はまだ登録されていません。');
    }

    public function test_unit_price_is_not_displayed_when_product_volume_is_unknown(): void
    {
        $viewer = User::factory()->create();
        $machine = $this->createMachine();
        $product = Product::create(['name' => '容量不明の開発用商品']);

        $machine->products()->attach($product->id, [
            'price' => 150,
            'temperature' => 'cold',
            'recorded_at' => '2026-10-20 00:00:00',
        ]);

        $this->actingAs($viewer)
            ->get(route('vending-machines.show', $machine))
            ->assertOk()
            ->assertSee('容量: 不明')
            ->assertDontSee('100mlあたり');
    }

    public function test_points_earned_by_registration_are_displayed_with_breakdown_and_ranking_link(): void
    {
        $viewer = User::factory()->create();
        $machine = $this->createMachine($viewer);

        $this->actingAs($viewer)
            ->withSession([
                'earned_points' => [
                    'vending_machine_id' => $machine->id,
                    'items' => [
                        ['type' => 'registration', 'points' => 10],
                        ['type' => 'rare_bonus', 'points' => 20],
                    ],
                ],
            ])
            ->get(route('vending-machines.show', $machine))
            ->assertOk()
            ->assertSee('今回 +30ポイント獲得しました')
            ->assertSee('自販機の新規登録')
            ->assertSee('+10ポイント')
            ->assertSee('レア商品発見ボーナス')
            ->assertSee('+20ポイント')
            ->assertSee('ランキングを見る')
            ->assertSee('href="'.route('ranking.index').'"', false);
    }

    public function test_earned_points_for_another_machine_are_not_displayed(): void
    {
        $viewer = User::factory()->create();
        $machine = $this->createMachine($viewer);

        $this->actingAs($viewer)
            ->withSession(['earned_points' => ['vending_machine_id' => $machine->id + 1, 'items' => []]])
            ->get(route('vending-machines.show', $machine))
            ->assertOk()
            ->assertDontSee('今回 +');
    }

    public function test_unknown_machine_returns_not_found(): void
    {
        $viewer = User::factory()->create();

        $this->actingAs($viewer)
            ->get('/vending-machines/999999')
            ->assertNotFound();
    }

    public function test_recent_information_displays_the_number_of_days_since_confirmation(): void
    {
        $this->travelTo('2026-10-20 12:00:00');
        $viewer = User::factory()->create();
        $machine = $this->createMachine(lastConfirmedAt: '2026-10-13 23:59:59');

        $this->actingAs($viewer)
            ->get(route('vending-machines.show', $machine))
            ->assertOk()
            ->assertSee('7日前の情報')
            ->assertDontSee('古い情報の可能性があります');
    }

    public function test_information_at_least_thirty_days_old_displays_a_warning(): void
    {
        $this->travelTo('2026-10-20 12:00:00');
        $viewer = User::factory()->create();
        $machine = $this->createMachine(lastConfirmedAt: '2026-09-20 23:59:59');

        $this->actingAs($viewer)
            ->get(route('vending-machines.show', $machine))
            ->assertOk()
            ->assertSee('30日前の情報')
            ->assertSee('古い情報の可能性があります');
    }

    public function test_future_confirmation_date_is_not_displayed_as_a_negative_age(): void
    {
        $this->travelTo('2026-10-20 12:00:00');
        $viewer = User::factory()->create();
        $machine = $this->createMachine(lastConfirmedAt: '2026-10-21 00:00:00');

        $this->actingAs($viewer)
            ->get(route('vending-machines.show', $machine))
            ->assertOk()
            ->assertSee('0日前の情報');
    }

    private function createMachine(
        ?User $owner = null,
        ?string $maker = null,
        string $lastConfirmedAt = '2026-10-20 00:00:00'
    ): VendingMachine {
        return VendingMachine::create([
            'latitude' => '33.5890000',
            'longitude' => '130.3950000',
            'maker' => $maker,
            'photo_path' => 'vending-machines/development/detail.jpg',
            'user_id' => $owner?->id,
            'last_confirmed_at' => $lastConfirmedAt,
        ]);
    }
}
