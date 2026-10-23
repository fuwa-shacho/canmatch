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

    public function test_vending_machine_markers_are_loaded_from_the_api_with_safe_popup_content(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/map')
            ->assertOk()
            ->assertSee("'/api/vending-machines'", false)
            ->assertSee('L.marker([latitude, longitude])', false)
            ->assertSee("maker.textContent = machine.maker || 'メーカー不明'", false)
            ->assertSee('productCount.textContent = `商品数: ${machine.product_count}件`', false)
            ->assertSee('detailLink.href = `/vending-machines/${encodeURIComponent(machine.id)}`', false)
            ->assertSee('content.append(maker, productCount, detailLink)', false);
    }

    public function test_product_search_updates_the_result_list_and_map_markers(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/map')
            ->assertOk()
            ->assertSee('id="product-search-form"', false)
            ->assertSee('name="product_name"', false)
            ->assertSee("parameters.set('product_name', productName)", false)
            ->assertSee('const query = parameters.toString()', false)
            ->assertDontSee('parameters.size', false)
            ->assertSee('markerLayer?.clearLayers()', false)
            ->assertSee('renderSearchResults(machines, productName)', false)
            ->assertSee('商品名の一部で検索');
    }

    public function test_marker_request_is_cancelled_when_leaving_the_map(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/map')
            ->assertOk()
            ->assertSee('markerRequest?.abort()', false)
            ->assertSee('map !== requestedMap', false)
            ->assertSee('requestedMap.getContainer().isConnected', false);
    }
}
