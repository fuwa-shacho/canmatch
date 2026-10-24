<?php

namespace Tests\Feature\Posts;

use App\Models\User;
use App\Services\VendingMachineReader;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\UnableToWriteFile;
use Livewire\Volt\Volt;
use Tests\TestCase;

class CreatePostTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/posts/create')->assertRedirect('/login');
    }

    public function test_authenticated_user_can_see_form(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/posts/create')
            ->assertOk();
    }

    public function test_valid_photo_is_saved_and_has_a_temporary_preview_url(): void
    {
        Storage::fake('local');
        Storage::fake('photos');
        config(['filesystems.photos_disk' => 'photos']);
        Storage::disk('photos')->buildTemporaryUrlsUsing(
            fn (string $path, $expiration, array $options): string => 'https://photos.test/'.$path
        );
        $this->actingAs(User::factory()->create());
        $this->mockReader();

        $component = Volt::test('posts.create')
            ->call('setLocation', 33.5903547, 130.4017155)
            ->set('photo', UploadedFile::fake()->image('machine.jpg'))
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('step', 'review')
            ->assertSee('読み取り結果を確認する');

        $files = Storage::disk('photos')->allFiles();

        $this->assertCount(1, $files);
        $this->assertStringStartsWith('photos/', $files[0]);
        Storage::disk('photos')->assertExists($files[0]);
        Storage::disk('local')->assertMissing($files[0]);
        $component->assertSet('savedPath', $files[0]);
        $component->assertSet('previewUrl', 'https://photos.test/'.$files[0]);
        $component->assertSee('https://photos.test/'.$files[0]);
    }

    public function test_location_is_required_before_saving(): void
    {
        $this->actingAs(User::factory()->create());

        Volt::test('posts.create')
            ->set('photo', UploadedFile::fake()->image('machine.jpg'))
            ->call('save')
            ->assertHasErrors(['latitude', 'longitude'])
            ->assertSet('step', 'form');
    }

    public function test_location_coordinates_must_be_within_valid_ranges(): void
    {
        $this->actingAs(User::factory()->create());

        Volt::test('posts.create')
            ->set('latitude', 90.0000001)
            ->set('longitude', 180.0000001)
            ->set('photo', UploadedFile::fake()->image('machine.jpg'))
            ->call('save')
            ->assertHasErrors(['latitude', 'longitude'])
            ->assertSet('step', 'form');
    }

    public function test_non_image_file_is_rejected(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create());

        Volt::test('posts.create')
            ->set('photo', UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'))
            ->call('save')
            ->assertHasErrors(['photo'])
            ->assertSet('step', 'form');
    }

    public function test_photo_larger_than_two_megabytes_is_rejected(): void
    {
        $this->actingAs(User::factory()->create());

        Volt::test('posts.create')
            ->set('photo', UploadedFile::fake()->image('large.jpg')->size(2049))
            ->call('save')
            ->assertHasErrors(['photo'])
            ->assertSet('step', 'form');
    }

    public function test_maker_must_be_one_of_the_allowed_values(): void
    {
        $this->actingAs(User::factory()->create());

        Volt::test('posts.create')
            ->call('setLocation', 33.5903547, 130.4017155)
            ->set('photo', UploadedFile::fake()->image('machine.jpg'))
            ->set('maker', 'unlisted-maker')
            ->call('save')
            ->assertHasErrors(['maker'])
            ->assertSet('step', 'form');
    }

    public function test_storage_failure_does_not_mark_the_photo_as_saved(): void
    {
        Storage::fake('local');
        config(['filesystems.photos_disk' => 'photos']);
        $this->actingAs(User::factory()->create());

        $component = Volt::test('posts.create')
            ->call('setLocation', 33.5903547, 130.4017155)
            ->set('photo', UploadedFile::fake()->image('machine.jpg'));

        $temporaryDisk = Storage::disk('tmp-for-tests');
        $disk = \Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('put')->once()->andReturn(false);
        $disk->shouldReceive('exists')->once()->andReturn(false);
        Storage::shouldReceive('disk')->with('photos')->andReturn($disk);
        Storage::shouldReceive('disk')->with('tmp-for-tests')->andReturn($temporaryDisk);

        $component
            ->call('save')
            ->assertHasErrors(['photo'])
            ->assertSet('savedPath', null)
            ->assertSet('step', 'form')
            ->assertSee('写真を保存できませんでした。もう一度お試しください。');

        $this->assertDatabaseCount('vending_machines', 0);
    }

    public function test_storage_exception_is_reported_without_marking_the_photo_as_saved(): void
    {
        Storage::fake('local');
        config(['filesystems.photos_disk' => 'photos']);
        $this->actingAs(User::factory()->create());

        $component = Volt::test('posts.create')
            ->call('setLocation', 33.5903547, 130.4017155)
            ->set('photo', UploadedFile::fake()->image('machine.jpg'));

        $temporaryDisk = Storage::disk('tmp-for-tests');
        $disk = \Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('put')
            ->once()
            ->andThrow(UnableToWriteFile::atLocation('photos/test.jpg'));
        Storage::shouldReceive('disk')->with('photos')->andReturn($disk);
        Storage::shouldReceive('disk')->with('tmp-for-tests')->andReturn($temporaryDisk);

        $component
            ->call('save')
            ->assertHasErrors(['photo'])
            ->assertSet('savedPath', null)
            ->assertSet('step', 'form')
            ->assertSee('写真を保存できませんでした。もう一度お試しください。');
    }

    private function mockReader(): void
    {
        $reader = \Mockery::mock(VendingMachineReader::class);
        $reader->shouldReceive('read')->once()->andReturn([
            'result' => [
                'is_vending_machine' => true,
                'maker' => null,
                'products' => [],
            ],
        ]);

        $this->app->instance(VendingMachineReader::class, $reader);
    }
}
