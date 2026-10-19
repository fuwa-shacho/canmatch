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

    public function test_location_callbacks_only_use_the_map_that_started_the_request(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/map')->assertOk();
        preg_match('/<script data-navigate-once>(.*?)<\/script>/s', $response->getContent(), $matches);

        self::assertArrayHasKey(1, $matches);

        $script = preg_replace('/\s+/', '', $matches[1]);

        self::assertIsString($script);
        self::assertStringContainsString('constrequestedMap=map;', $script);
        self::assertStringContainsString('requestedMap.setView(', $script);
        self::assertStringContainsString(
            'if(map===requestedMap&&requestedMap.getContainer().isConnected)',
            $script
        );
        self::assertStringContainsString(
            'if(map!==requestedMap||!requestedMap.getContainer().isConnected){return;}',
            $script
        );
        self::assertStringContainsString(
            '位置情報の使用が許可されていません。ブラウザの設定を確認してください。',
            $script
        );
    }

    public function test_livewire_navigation_destroys_and_reinitializes_the_map(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/map')
            ->assertOk()
            ->assertSee("document.addEventListener('livewire:navigating', destroyMap)", false)
            ->assertSee("document.addEventListener('livewire:navigated', initializeMap)", false);
    }
}
