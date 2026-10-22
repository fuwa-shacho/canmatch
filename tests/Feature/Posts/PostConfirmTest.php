<?php

namespace Tests\Feature\Posts;

use App\Exceptions\AiReadFailedException;
use App\Models\User;
use App\Services\VendingMachineReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Volt\Volt;
use Tests\TestCase;

class PostConfirmTest extends TestCase
{
    use RefreshDatabase;

    public function test_ai_products_are_displayed_as_separate_rows(): void
    {
        $this->preparePhotoStorage();
        $this->mockReader($this->vendingMachineResult([
            ['name' => '開発用お茶', 'price' => 150, 'temperature' => 'cold'],
            ['name' => '開発用コーヒー', 'price' => 140, 'temperature' => 'hot'],
        ]));

        $component = $this->submitPhoto();

        $component
            ->assertSet('step', 'review')
            ->assertSet('rows.0.name', '開発用お茶')
            ->assertSet('rows.1.name', '開発用コーヒー')
            ->assertSee('開発用お茶')
            ->assertSee('開発用コーヒー')
            ->assertDontSee('AI生応答の開発用secret');
    }

    public function test_rows_can_be_edited_added_and_removed(): void
    {
        $this->preparePhotoStorage();
        $this->mockReader($this->vendingMachineResult([
            ['name' => '開発用お茶', 'price' => 150, 'temperature' => 'cold'],
            ['name' => '開発用コーヒー', 'price' => 140, 'temperature' => 'hot'],
        ]));

        $component = $this->submitPhoto()
            ->set('rows.0.name', '編集後のお茶')
            ->set('rows.0.price', 180)
            ->set('rows.0.temperature', 'hot')
            ->call('addRow')
            ->set('rows.2.name', '開発用追加商品')
            ->call('removeRow', 0);

        $component
            ->assertSet('rows.0.name', '開発用コーヒー')
            ->assertSet('rows.1.name', '開発用追加商品')
            ->assertSet('rows.1.price', null);
    }

    public function test_invalid_price_and_temperature_are_rejected(): void
    {
        $this->preparePhotoStorage();
        $this->mockReader($this->vendingMachineResult([
            ['name' => '開発用お茶', 'price' => 150, 'temperature' => 'cold'],
        ]));

        $this->submitPhoto()
            ->set('rows.0.price', -1)
            ->set('rows.0.temperature', 'ambient')
            ->call('register')
            ->assertHasErrors(['rows.0.price', 'rows.0.temperature'])
            ->assertSet('registered', false);
    }

    public function test_ai_failure_shows_a_safe_manual_entry_message(): void
    {
        $this->preparePhotoStorage();
        $reader = \Mockery::mock(VendingMachineReader::class);
        $reader->shouldReceive('read')
            ->once()
            ->andThrow(new AiReadFailedException('timeout', 'AI生応答の開発用secret'));
        $this->app->instance(VendingMachineReader::class, $reader);

        $this->submitPhoto()
            ->assertSet('step', 'review')
            ->assertSet('rows.0.name', '')
            ->assertSee('AIによる読み取りに失敗しました。手入力で登録してください。')
            ->assertDontSee('AI生応答の開発用secret');
    }

    public function test_non_vending_machine_photo_is_deleted_and_does_not_continue(): void
    {
        $this->preparePhotoStorage();
        $this->mockReader($this->vendingMachineResult([], isVendingMachine: false));

        $this->submitPhoto()
            ->assertSet('step', 'form')
            ->assertSet('savedPath', null)
            ->assertSet('photo', null)
            ->assertSee('自動販売機が写っていないようです。撮り直してください。');

        $this->assertSame([], Storage::disk('photos')->allFiles());
    }

    public function test_retake_deletes_the_saved_photo(): void
    {
        $this->preparePhotoStorage();
        $this->mockReader($this->vendingMachineResult([
            ['name' => '開発用お茶', 'price' => 150, 'temperature' => 'cold'],
        ]));

        $component = $this->submitPhoto();
        $files = Storage::disk('photos')->allFiles();

        $this->assertCount(1, $files);

        $component->call('backToForm')
            ->assertSet('step', 'form')
            ->assertSet('savedPath', null)
            ->assertSet('previewUrl', null)
            ->assertSet('photo', null);

        Storage::disk('photos')->assertMissing($files[0]);
    }

    public function test_photo_is_retained_after_review_for_registration(): void
    {
        $this->preparePhotoStorage();
        $this->mockReader($this->vendingMachineResult([
            ['name' => '開発用お茶', 'price' => 150, 'temperature' => 'cold'],
        ]));

        $component = $this->submitPhoto();
        $savedPath = $component->get('savedPath');

        $component->call('register')
            ->assertSet('registered', true)
            ->assertSet('savedPath', $savedPath);

        Storage::disk('photos')->assertExists($savedPath);
    }

    private function preparePhotoStorage(): void
    {
        Storage::fake('photos');
        config(['filesystems.photos_disk' => 'photos']);
        Storage::disk('photos')->buildTemporaryUrlsUsing(
            fn (string $path, $expiration, array $options): string => 'https://photos.test/'.$path
        );
    }

    private function mockReader(array $result): void
    {
        $reader = \Mockery::mock(VendingMachineReader::class);
        $reader->shouldReceive('read')->once()->andReturn([
            'result' => $result,
            'raw' => 'AI生応答の開発用secret',
            'model' => 'development-model',
            'prompt_version' => 'development-prompt',
            'gateway_log_id' => 'development-log-id',
        ]);
        $this->app->instance(VendingMachineReader::class, $reader);
    }

    private function submitPhoto(): Testable
    {
        return Volt::test('posts.create')
            ->call('setLocation', 33.5903547, 130.4017155)
            ->set('photo', UploadedFile::fake()->image('development-machine.jpg'))
            ->call('save');
    }

    private function vendingMachineResult(array $products, bool $isVendingMachine = true): array
    {
        return [
            'is_vending_machine' => $isVendingMachine,
            'maker' => $isVendingMachine ? 'suntory' : null,
            'products' => $products,
        ];
    }
}
