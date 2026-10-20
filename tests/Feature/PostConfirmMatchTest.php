<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Volt\Volt;
use Tests\TestCase;

class PostConfirmMatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_商品名を直すと照合し直して容量を表示する(): void
    {
        Product::create(['name' => '伊右衛門', 'volume_ml' => 600]);

        Volt::test('posts.create')
            ->call('addRow')
            ->set('rows.0.name', '伊右衛門')
            ->assertSet('rows.0.link', 'auto')
            ->assertSet('rows.0.volume_ml', 600);
    }

    public function test_候補から選び直せる(): void
    {
        $p = Product::create(['name' => '伊右衛門 650ml', 'volume_ml' => 650]);

        Volt::test('posts.create')
            ->call('addRow')
            ->set('rows.0.name', '伊右衛門')
            ->assertSet('rows.0.link', 'new')
            ->call('selectCandidate', 0, $p->id)
            ->assertSet('rows.0.link', 'manual')
            ->assertSet('rows.0.product_id', $p->id);
    }

    public function test_候補にないIDは受け付けない(): void
    {
        Product::create(['name' => '伊右衛門 650ml']);
        $other = Product::create(['name' => 'コーラ']);

        Volt::test('posts.create')
            ->call('addRow')
            ->set('rows.0.name', '伊右衛門')
            ->call('selectCandidate', 0, $other->id)
            ->assertSet('rows.0.product_id', null);
    }

    public function test_一致しない商品は新規扱いで容量は空(): void
    {
        Volt::test('posts.create')
            ->call('addRow')
            ->set('rows.0.name', '珍しいジュース')
            ->assertSet('rows.0.link', 'new')
            ->assertSet('rows.0.volume_ml', null);
    }

    public function test_更新モードでは既存の座標を使い現在地で上書きしない(): void
    {
        $machine = \App\Models\VendingMachine::create([
            'latitude' => 33.5902,
            'longitude' => 130.4017,
            'maker' => 'itoen',
            'photo_path' => 'photos/test.jpg',
            'last_confirmed_at' => now(),
        ]);

        Volt::test('posts.create', ['machine' => $machine->id])
            ->assertSet('machineId', $machine->id)
            ->assertSet('latitude', 33.5902)
            ->assertSet('maker', 'itoen')
            ->call('setLocation', 35.0, 139.0)
            ->assertSet('latitude', 33.5902);
    }
}