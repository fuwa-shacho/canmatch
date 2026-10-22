<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use App\Models\VendingMachine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
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

    public function test_候補にない_i_dは受け付けない(): void
    {
        Product::create(['name' => '伊右衛門 650ml']);
        $other = Product::create(['name' => 'コーラ']);

        Volt::test('posts.create')
            ->call('addRow')
            ->set('rows.0.name', '伊右衛門')
            ->call('selectCandidate', 0, $other->id)
            ->assertSet('rows.0.product_id', null);
    }

    public function test_候補外の商品_i_dは登録時に拒否する(): void
    {
        $other = Product::create(['name' => 'コーラ']);

        Volt::test('posts.create')
            ->call('addRow')
            ->set('rows.0.name', '珍しいジュース')
            ->set('rows.0.product_id', $other->id)
            ->call('register')
            ->assertHasErrors(['rows.0.product_id']);
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
        $machine = VendingMachine::create([
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

    public function test_存在しない自販機_i_dでは更新画面を開けない(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/posts/create?machine=999999')
            ->assertNotFound();
    }

    public function test_更新対象の自販機_i_dは_livewireから変更できない(): void
    {
        $this->actingAs(User::factory()->create());
        $machine = $this->createMachine();
        $otherMachine = $this->createMachine();

        $this->expectException(CannotUpdateLockedPropertyException::class);

        Volt::test('posts.create', ['machine' => $machine->id])
            ->set('machineId', $otherMachine->id);
    }

    private function createMachine(): VendingMachine
    {
        return VendingMachine::create([
            'latitude' => 33.5902,
            'longitude' => 130.4017,
            'maker' => 'itoen',
            'photo_path' => 'photos/test.jpg',
            'last_confirmed_at' => now(),
        ]);
    }
}
