<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MapPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $this->get('/map')->assertRedirect('/login');
    }

    public function test_authenticated_users_can_visit_the_map_page(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/map')
            ->assertOk()
            ->assertSee('id="vending-map"', false)
            ->assertSee('OpenStreetMap contributors')
            ->assertSee('data-navigate-once', false);
    }
}
