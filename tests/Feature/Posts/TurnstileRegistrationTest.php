<?php

namespace Tests\Feature\Posts;

use App\Models\User;
use App\Services\VendingMachineReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Volt\Volt;
use Tests\TestCase;

class TurnstileRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private $reader;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('photos');
        config(['filesystems.photos_disk' => 'photos']);
        Storage::disk('photos')->buildTemporaryUrlsUsing(
            fn (string $path, $expiration, array $options): string => 'https://photos.test/'.$path
        );

        $this->reader = \Mockery::mock(VendingMachineReader::class);
        $this->app->instance(VendingMachineReader::class, $this->reader);
    }

    public function test_無効のときはこれまでどおり写真を送れる(): void
    {
        config(['services.turnstile.enabled' => false]);
        Http::fake();
        $this->expectRead();

        $this->submit()->assertHasNoErrors()->assertSet('step', 'review');

        $this->assertCount(1, Storage::disk('photos')->allFiles());
        Http::assertNothingSent();
    }

    public function test_有効でトークンがなければ写真を保存せず読み取りもしない(): void
    {
        $this->enable();
        Http::fake();
        $this->reader->shouldNotReceive('read');

        $this->submit()->assertHasErrors(['turnstileToken'])->assertSet('step', 'form');

        $this->assertSame([], Storage::disk('photos')->allFiles());
    }

    public function test_有効で確認に失敗すれば写真を保存せず読み取りもしない(): void
    {
        $this->enable();
        Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => false])]);
        $this->reader->shouldNotReceive('read');

        $this->submit('invalid-token')->assertHasErrors(['turnstileToken'])->assertSet('turnstileToken', null);

        $this->assertSame([], Storage::disk('photos')->allFiles());
    }

    public function test_有効で確認に成功すれば写真を送れてトークンを使い捨てる(): void
    {
        $this->enable();
        Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => true])]);
        $this->expectRead();

        $this->submit('valid-token')
            ->assertHasNoErrors()
            ->assertSet('step', 'review')
            ->assertSet('turnstileToken', null)
            ->assertDispatched('turnstile-reset');

        $this->assertCount(1, Storage::disk('photos')->allFiles());
    }

    public function test_写真が不正なときはトークンを使わない(): void
    {
        $this->enable();
        Http::fake();
        $this->reader->shouldNotReceive('read');

        Volt::actingAs(User::factory()->create())
            ->test('posts.create')
            ->call('setLocation', 33.5903547, 130.4017155)
            ->set('turnstileToken', 'valid-token')
            ->call('save')
            ->assertHasErrors(['photo'])
            ->assertSet('turnstileToken', 'valid-token');

        Http::assertNothingSent();
    }

    public function test_有効でトークンがなければユーザー登録しない(): void
    {
        $this->enable();
        Http::fake();

        Volt::test('auth.register')
            ->set('name', '開発用ユーザー')
            ->set('email', 'dev-turnstile@example.com')
            ->set('password', 'password')
            ->set('password_confirmation', 'password')
            ->call('register')
            ->assertHasErrors(['turnstileToken']);

        $this->assertDatabaseMissing('users', ['email' => 'dev-turnstile@example.com']);
        $this->assertGuest();
    }

    public function test_有効で確認に成功すればユーザー登録できる(): void
    {
        $this->enable();
        Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => true])]);

        Volt::test('auth.register')
            ->set('name', '開発用ユーザー')
            ->set('email', 'dev-turnstile@example.com')
            ->set('password', 'password')
            ->set('password_confirmation', 'password')
            ->set('turnstileToken', 'valid-token')
            ->call('register')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('users', ['email' => 'dev-turnstile@example.com']);
    }

    public function test_有効なら投稿画面と登録画面にチェック欄が出る(): void
    {
        $this->enable();

        $this->get('/register')->assertOk()->assertSee('data-canmatch-turnstile', false);
        $this->actingAs(User::factory()->create())
            ->get('/posts/create')->assertOk()->assertSee('data-canmatch-turnstile', false);
    }

    public function test_無効なら投稿画面にチェック欄は出ない(): void
    {
        config(['services.turnstile.enabled' => false]);

        $this->actingAs(User::factory()->create())
            ->get('/posts/create')->assertOk()->assertDontSee('data-canmatch-turnstile', false);
    }

    private function enable(): void
    {
        config([
            'services.turnstile.enabled' => true,
            'services.turnstile.site_key' => 'test-site-key',
            'services.turnstile.secret' => 'test-secret',
        ]);
    }

    private function expectRead(): void
    {
        $this->reader->shouldReceive('read')->once()->andReturn([
            'result' => [
                'is_vending_machine' => true,
                'maker' => null,
                'products' => [['name' => '開発用お茶', 'price' => 150, 'temperature' => 'cold']],
            ],
            'raw' => '{}',
            'model' => 'development-model',
            'prompt_version' => 'development-prompt',
            'gateway_log_id' => null,
        ]);
    }

    private function submit(?string $token = null): Testable
    {
        return Volt::actingAs(User::factory()->create())
            ->test('posts.create')
            ->call('setLocation', 33.5903547, 130.4017155)
            ->set('turnstileToken', $token)
            ->set('photo', UploadedFile::fake()->image('development-machine.jpg'))
            ->call('save');
    }
}
