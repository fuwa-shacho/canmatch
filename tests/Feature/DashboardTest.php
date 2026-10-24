<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use App\Models\VendingMachine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $response = $this->get('/dashboard');
        $response->assertRedirect('/login');
    }

    public function test_authenticated_users_can_visit_the_dashboard(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get('/dashboard');
        $response->assertStatus(200);
    }

    public function test_dashboard_shows_logo_counts_and_links(): void
    {
        $user = User::factory()->create(['name' => '開発用ユーザー']);
        $other = User::factory()->create();
        $sold = Product::create(['name' => '開発用お茶']);
        Product::create(['name' => '開発用まだ売っていない商品']);

        foreach ([$user, $user, $other] as $owner) {
            $machine = VendingMachine::create([
                'latitude' => 33.5902,
                'longitude' => 130.4017,
                'photo_path' => 'photos/development.jpg',
                'user_id' => $owner->id,
                'last_confirmed_at' => now(),
            ]);
            $machine->products()->attach($sold->id, [
                'price' => 150,
                'temperature' => 'cold',
                'recorded_at' => now(),
            ]);
        }

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('images/canmatch-logo.png', false)
            ->assertSee('開発用ユーザー さん、ようこそ')
            ->assertSeeInOrder(['登録された自販機', '3', '見つかった商品', '1', 'あなたの登録', '2'])
            ->assertSee(route('map'), false)
            ->assertSee(route('posts.create'), false)
            ->assertSee(route('rare-products.index'), false)
            ->assertSee(route('ranking.index'), false);
    }
}
