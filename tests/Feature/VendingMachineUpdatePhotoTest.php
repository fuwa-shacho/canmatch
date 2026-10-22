<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\VendingMachine;
use App\Services\VendingMachineReader;
use App\Services\VendingMachineRegistrar;
use Illuminate\Database\QueryException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use PDOException;
use Tests\TestCase;

class VendingMachineUpdatePhotoTest extends TestCase
{
    use RefreshDatabase;

    public function test_写真保存に失敗したらレビュー画面へ進まない(): void
    {
        Storage::fake('local');
        config(['filesystems.photos_disk' => 'photos']);
        $this->actingAs(User::factory()->create());
        $component = Volt::test('posts.create')
            ->call('setLocation', 33.5902, 130.4017)
            ->set('photo', UploadedFile::fake()->image('failed.jpg'));

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

    public function test_更新成功時に旧写真を削除し詳細画面へ遷移する(): void
    {
        $user = User::factory()->create();
        $machine = $this->createMachine();
        $this->fakePhotoStorage($machine->photo_path);
        $this->mockReader();

        $this->actingAs($user);
        $component = Volt::test('posts.create', ['machine' => $machine->id])
            ->set('photo', UploadedFile::fake()->image('replacement.jpg'))
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('step', 'review');

        $newPath = $component->get('savedPath');

        $component
            ->call('register')
            ->assertRedirect(route('vending-machines.show', $machine));

        $machine->refresh();
        $this->assertSame($newPath, $machine->photo_path);
        Storage::disk('photos')->assertExists($newPath);
        Storage::disk('photos')->assertMissing('photos/old.jpg');
        $this->assertDatabaseHas('machine_products', [
            'vending_machine_id' => $machine->id,
            'price' => 120,
            'temperature' => 'cold',
        ]);
    }

    public function test_更新失敗時は旧写真を維持して新写真を削除する(): void
    {
        $user = User::factory()->create();
        $machine = $this->createMachine();
        $before = $machine->last_confirmed_at;
        $this->fakePhotoStorage($machine->photo_path);
        $this->mockReader();

        $registrar = \Mockery::mock(VendingMachineRegistrar::class);
        $registrar->shouldReceive('update')
            ->once()
            ->andThrow(new QueryException('sqlite', 'update vending_machines', [], new PDOException('failure')));
        $this->app->instance(VendingMachineRegistrar::class, $registrar);

        $this->actingAs($user);
        $component = Volt::test('posts.create', ['machine' => $machine->id])
            ->set('photo', UploadedFile::fake()->image('replacement.jpg'))
            ->call('save')
            ->assertSet('step', 'review');

        $newPath = $component->get('savedPath');
        $component
            ->call('register')
            ->assertHasErrors(['rows'])
            ->assertSet('savedPath', null)
            ->assertSet('previewUrl', null);

        $machine->refresh();
        $this->assertSame('photos/old.jpg', $machine->photo_path);
        $this->assertTrue($machine->last_confirmed_at->equalTo($before));
        Storage::disk('photos')->assertExists('photos/old.jpg');
        Storage::disk('photos')->assertMissing($newPath);
    }

    private function createMachine(): VendingMachine
    {
        return VendingMachine::create([
            'latitude' => 33.5902,
            'longitude' => 130.4017,
            'maker' => 'itoen',
            'photo_path' => 'photos/old.jpg',
            'last_confirmed_at' => now()->subDay(),
        ]);
    }

    private function fakePhotoStorage(string $oldPhotoPath): void
    {
        Storage::fake('photos');
        config(['filesystems.photos_disk' => 'photos']);
        Storage::disk('photos')->put($oldPhotoPath, 'old photo');
        Storage::disk('photos')->buildTemporaryUrlsUsing(
            fn (string $path, $expiration, array $options): string => 'https://photos.test/'.$path
        );
    }

    private function mockReader(): void
    {
        $reader = \Mockery::mock(VendingMachineReader::class);
        $reader->shouldReceive('read')->once()->andReturn([
            'result' => [
                'is_vending_machine' => true,
                'maker' => 'itoen',
                'products' => [
                    ['name' => '新しいお茶', 'price' => 120, 'temperature' => 'cold'],
                ],
            ],
            'raw' => '{}',
            'model' => 'test',
            'prompt_version' => 'test',
            'gateway_log_id' => null,
        ]);
        $this->app->instance(VendingMachineReader::class, $reader);
    }
}
