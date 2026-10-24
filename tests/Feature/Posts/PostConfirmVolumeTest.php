<?php

namespace Tests\Feature\Posts;

use App\Models\Product;
use App\Models\User;
use App\Services\VendingMachineReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Volt\Volt;
use Tests\TestCase;

/**
 * 同じ名前で容量が違う商品(例:綾鷹 650ml と 525ml)を、確認画面で区別して登録できること
 */
class PostConfirmVolumeTest extends TestCase
{
    use RefreshDatabase;

    private Product $ayataka650;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('photos');
        config(['filesystems.photos_disk' => 'photos']);
        Storage::disk('photos')->buildTemporaryUrlsUsing(
            fn (string $path, $expiration, array $options): string => 'https://photos.test/'.$path
        );

        $this->actingAs(User::factory()->create());
        $this->ayataka650 = Product::create(['name' => '綾鷹', 'volume_ml' => 650]);
    }

    /**
     * AI が「綾鷹」を2列読んだ確認画面を開く(2行とも綾鷹 650ml に自動で紐づく)
     */
    private function reviewWithTwoAyataka(): Testable
    {
        $reader = \Mockery::mock(VendingMachineReader::class);
        $reader->shouldReceive('read')->once()->andReturn([
            'result' => [
                'is_vending_machine' => true,
                'maker' => null,
                'products' => [
                    ['name' => '綾鷹', 'price' => 160, 'temperature' => 'cold'],
                    ['name' => '綾鷹', 'price' => 140, 'temperature' => 'cold'],
                ],
            ],
            'raw' => '開発用',
            'model' => 'development-model',
            'prompt_version' => 'development-prompt',
            'gateway_log_id' => null,
        ]);
        $this->app->instance(VendingMachineReader::class, $reader);

        return Volt::test('posts.create')
            ->call('setLocation', 33.5903547, 130.4017155)
            ->set('photo', UploadedFile::fake()->image('development-machine.jpg'))
            ->call('save')
            ->assertSet('rows.0.product_id', $this->ayataka650->id)
            ->assertSet('rows.1.product_id', $this->ayataka650->id);
    }

    public function test_別の商品を選ぶで紐づけ先を選び直して登録できる(): void
    {
        // AI は「綾鷹」と読んだが、実際は別の商品だった場合
        $other = Product::create(['name' => '綾鷹 茶葉のあまみ', 'volume_ml' => 525]);

        $this->reviewWithTwoAyataka()
            ->call('startSearch', 1)
            ->assertSet('rows.1.searching', true)
            ->assertSee('綾鷹 茶葉のあまみ(525ml)')
            ->call('selectSearchResult', 1, $other->id)
            ->assertSet('rows.1.product_id', $other->id)
            ->assertSet('rows.1.name', '綾鷹 茶葉のあまみ')
            ->assertSet('rows.1.volume_ml', 525)
            ->assertSet('rows.1.link', 'manual')
            ->assertSet('rows.1.searching', false)
            ->call('register')
            ->assertHasNoErrors()
            ->assertSet('registered', true);

        $this->assertDatabaseHas('machine_products', ['product_id' => $this->ayataka650->id, 'price' => 160]);
        $this->assertDatabaseHas('machine_products', ['product_id' => $other->id, 'price' => 140]);
        $this->assertSame(2, Product::count());
    }

    public function test_同名で容量違いが複数あれば自動で決めず候補から選べる(): void
    {
        $ayataka525 = Product::create(['name' => '綾鷹', 'volume_ml' => 525]);

        $reader = \Mockery::mock(VendingMachineReader::class);
        $reader->shouldReceive('read')->once()->andReturn([
            'result' => ['is_vending_machine' => true, 'maker' => null, 'products' => [
                ['name' => '綾鷹', 'price' => 160, 'temperature' => 'cold'],
                ['name' => '綾鷹', 'price' => 140, 'temperature' => 'cold'],
            ]],
            'raw' => '開発用', 'model' => 'm', 'prompt_version' => 'p', 'gateway_log_id' => null,
        ]);
        $this->app->instance(VendingMachineReader::class, $reader);

        Volt::test('posts.create')
            ->call('setLocation', 33.5903547, 130.4017155)
            ->set('photo', UploadedFile::fake()->image('development-machine.jpg'))
            ->call('save')
            ->assertSet('rows.0.product_id', null)
            ->call('selectCandidate', 0, $this->ayataka650->id)
            ->call('selectCandidate', 1, $ayataka525->id)
            ->call('register')
            ->assertHasNoErrors()
            ->assertSet('registered', true);

        $this->assertSame(2, Product::count());
    }

    public function test_検索語を変えると探し直す(): void
    {
        Product::create(['name' => 'コカ・コーラ', 'volume_ml' => 500]);

        $this->reviewWithTwoAyataka()
            ->call('startSearch', 0)
            ->set('rows.0.search', 'コーラ')
            ->assertSee('コカ・コーラ(500ml)')
            ->assertDontSee('綾鷹(650ml)');
    }

    public function test_探した結果にない商品_i_dは受け付けない(): void
    {
        $other = Product::create(['name' => 'コカ・コーラ', 'volume_ml' => 500]);

        $this->reviewWithTwoAyataka()
            ->call('startSearch', 1)
            ->call('selectSearchResult', 1, $other->id)
            ->assertSet('rows.1.product_id', $this->ayataka650->id)
            ->assertSet('rows.1.name', '綾鷹');
    }

    public function test_新しい商品にして容量を入れると容量付きで作られる(): void
    {
        $this->reviewWithTwoAyataka()
            ->call('unlinkProduct', 1)
            ->set('rows.1.new_volume_ml', 525)
            ->call('register')
            ->assertHasNoErrors()
            ->assertSet('registered', true);

        $created = Product::where('name', '綾鷹')->where('volume_ml', 525)->sole();
        $this->assertDatabaseHas('machine_products', ['product_id' => $created->id, 'price' => 140]);
    }

    public function test_容量が分からなければ空のまま容量未登録の商品として登録できる(): void
    {
        $this->reviewWithTwoAyataka()
            ->call('unlinkProduct', 1)
            ->call('register')
            ->assertHasNoErrors()
            ->assertSet('registered', true);

        $unknown = Product::where('name', '綾鷹')->whereNull('volume_ml')->sole();
        $this->assertDatabaseHas('machine_products', ['product_id' => $this->ayataka650->id, 'price' => 160]);
        $this->assertDatabaseHas('machine_products', ['product_id' => $unknown->id, 'price' => 140]);
    }

    public function test_容量未登録の同名商品は次の登録でも増えない(): void
    {
        $unknown = Product::create(['name' => '綾鷹']);

        // 「綾鷹」が2件(650ml と容量未登録)あるので、自動では決めず候補になる
        $reader = \Mockery::mock(VendingMachineReader::class);
        $reader->shouldReceive('read')->once()->andReturn([
            'result' => ['is_vending_machine' => true, 'maker' => null, 'products' => [
                ['name' => '綾鷹', 'price' => 140, 'temperature' => 'cold'],
            ]],
            'raw' => '開発用', 'model' => 'm', 'prompt_version' => 'p', 'gateway_log_id' => null,
        ]);
        $this->app->instance(VendingMachineReader::class, $reader);

        Volt::test('posts.create')
            ->call('setLocation', 33.5903547, 130.4017155)
            ->set('photo', UploadedFile::fake()->image('development-machine.jpg'))
            ->call('save')
            ->assertSet('rows.0.product_id', null)
            ->call('register')
            ->assertHasNoErrors()
            ->assertSet('registered', true);

        $this->assertSame(2, Product::where('name', '綾鷹')->count());
        $this->assertDatabaseHas('machine_products', ['product_id' => $unknown->id, 'price' => 140]);
    }

    public function test_容量は1以上の数字(): void
    {
        $this->reviewWithTwoAyataka()
            ->call('unlinkProduct', 1)
            ->set('rows.1.new_volume_ml', 0)
            ->call('register')
            ->assertHasErrors(['rows.1.new_volume_ml' => 'min']);
    }
}
