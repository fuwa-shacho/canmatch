<?php

namespace Tests\Feature\Posts;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class ManualLocationTest extends TestCase
{
    use RefreshDatabase;

    public function test_地図で選んだ位置を受け取る(): void
    {
        $this->actingAs(User::factory()->create());

        Volt::test('posts.create')
            ->call('setLocation', 33.5902, 130.4017)
            ->assertReturned(true)
            ->assertSet('latitude', 33.5902)
            ->assertSet('longitude', 130.4017)
            ->assertHasNoErrors();
    }

    public function test_範囲外の位置は保存しない(): void
    {
        $this->actingAs(User::factory()->create());

        Volt::test('posts.create')
            ->call('setLocation', 99, 130.4017)
            ->assertReturned(false)
            ->assertSet('latitude', null)
            ->assertSet('longitude', null)
            ->assertHasErrors(['latitude']);

        Volt::test('posts.create')
            ->call('setLocation', 33.59, 181)
            ->assertReturned(false)
            ->assertSet('longitude', null);
    }

    public function test_正しい位置を選び直すとエラーが消える(): void
    {
        $this->actingAs(User::factory()->create());

        Volt::test('posts.create')
            ->call('setLocation', 99, 130.4017)
            ->assertHasErrors(['latitude'])
            ->call('setLocation', 33.5902, 130.4017)
            ->assertHasNoErrors(['latitude'])
            ->assertSet('latitude', 33.5902);
    }

    public function test_投稿画面に地図で選ぶ手段がある(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/posts/create')
            ->assertOk()
            ->assertSee('この位置で確定')
            ->assertSee('showFallback', false);
    }
}
