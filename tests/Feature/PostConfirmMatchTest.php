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
            ->set('rows.0.price', 150)
            ->set('rows.0.temperature', 'cold')
            ->assertSet('rows.0.link', 'new')
            ->call('selectCandidate', 0, $p->id)
            ->assertSet('rows.0.link', 'manual')
            ->assertSet('rows.0.product_id', $p->id)
            ->call('register')
            ->assertSet('registered', true);
    }

    public function test_候補にない_i_dは受け付けない(): void
    {
        Product::create(['name' => '伊右衛門 650ml']);
        $other = Product::create(['name' => 'コーラ']);

        Volt::test('posts.create')
            ->call('addRow')
            ->set('rows.0.name', '伊右衛門')
            ->set('rows.0.candidates', [[
                'id' => $other->id,
                'name' => $other->name,
                'volume_ml' => null,
            ]])
            ->call('selectCandidate', 0, $other->id)
            ->assertSet('rows.0.product_id', null);
    }

    public function test_各商品行にそれぞれの候補が表示される(): void
    {
        Product::create(['name' => '伊右衛門 650ml', 'volume_ml' => 650]);
        Product::create(['name' => 'コカ・コーラ 350ml', 'volume_ml' => 350]);

        Volt::test('posts.create')
            ->call('addRow')
            ->set('rows.0.name', '伊右衛門')
            ->call('addRow')
            ->set('rows.1.name', 'コカ・コーラ')
            ->call('addRow')
            ->set('rows.2.name', '珍しいジュース')
            ->set('step', 'review')
            ->assertSet('rows.0.candidates.0.name', '伊右衛門 650ml')
            ->assertSet('rows.1.candidates.0.name', 'コカ・コーラ 350ml')
            ->assertSet('rows.2.candidates', [])
            ->assertSet('rows.2.link', 'new')
            ->assertSee('伊右衛門 650ml')
            ->assertSee('コカ・コーラ 350ml');
    }

    public function test_候補にない既存商品_i_dを登録に使えない(): void
    {
        $other = Product::create(['name' => 'コーラ']);

        Volt::test('posts.create')
            ->call('addRow')
            ->set('rows.0.name', '伊右衛門')
            ->set('rows.0.price', 150)
            ->set('rows.0.temperature', 'cold')
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
}
