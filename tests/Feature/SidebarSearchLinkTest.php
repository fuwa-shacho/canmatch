<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SidebarSearchLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_サイドバーの検索は地図の検索欄へ移動する(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('href="'.route('map', ['search' => 1]).'"', false)
            ->assertDontSee('href="/search"', false);
    }

    public function test_検索欄にカーソルを置く地図ページが開ける(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('map', ['search' => 1]))
            ->assertOk()
            ->assertSee('id="product-search"', false)
            ->assertSee("has('search')", false);
    }
}
