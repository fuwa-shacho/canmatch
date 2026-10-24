<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use App\Models\VendingMachine;
use App\Services\VendingMachineReader;
use App\Services\VendingMachineRegistrar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Volt\Volt;
use Tests\TestCase;

class VendingMachineUpdatePhotoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('photos');
        config(['filesystems.photos_disk' => 'photos']);
        Storage::disk('photos')->buildTemporaryUrlsUsing(
            fn (string $path, $expiration, array $options): string => 'https://photos.test/'.$path
        );
        $this->actingAs(User::factory()->create());
    }

    public function test_detail_update_replaces_photo_and_products_then_returns_to_fresh_detail(): void
    {
        $this->travelTo('2026-10-24 12:00:00');
        $oldPhoto = 'photos/old-machine.jpg';
        Storage::disk('photos')->put($oldPhoto, 'old photo');

        $oldProduct = Product::create(['name' => '古い商品']);
        $newProduct = Product::create(['name' => '更新後の商品', 'volume_ml' => 500]);
        $machine = $this->createMachine($oldPhoto, '2026-10-01 12:00:00');
        $machine->products()->attach($oldProduct->id, [
            'price' => 100,
            'temperature' => 'cold',
            'recorded_at' => '2026-10-01 12:00:00',
        ]);

        $this->get(route('vending-machines.show', $machine))
            ->assertOk()
            ->assertSee('情報を更新する');

        $this->mockReader([
            ['name' => '更新後の商品', 'price' => 150, 'temperature' => 'cold'],
            ['name' => '更新後の商品', 'price' => 160, 'temperature' => 'hot'],
        ]);

        $component = Volt::test('posts.create', ['machine' => $machine->id])
            ->assertSet('machineId', $machine->id)
            ->call('setLocation', 1, 1)
            ->assertSet('latitude', (float) $machine->latitude)
            ->set('photo', UploadedFile::fake()->image('new-machine.jpg'))
            ->call('save')
            ->assertSet('rows.0.product_id', $newProduct->id)
            ->assertSet('rows.1.product_id', $newProduct->id)
            ->set('rows.0.new_volume_ml', 525)
            ->call('register')
            ->assertHasNoErrors()
            ->assertRedirect(route('vending-machines.show', $machine));

        $newPhoto = $component->get('savedPath');
        Storage::disk('photos')->assertExists($newPhoto);
        Storage::disk('photos')->assertMissing($oldPhoto);

        $updated = $machine->fresh();
        $this->assertSame($newPhoto, $updated->photo_path);
        $this->assertTrue($updated->last_confirmed_at->equalTo(now()));
        $this->assertSame(auth()->id(), $updated->user_id);
        $this->assertSame('33.5890000', $updated->latitude);
        $this->assertSame('130.3950000', $updated->longitude);
        $this->assertSame('suntory', $updated->maker);
        $this->assertDatabaseMissing('machine_products', [
            'vending_machine_id' => $machine->id,
            'product_id' => $oldProduct->id,
        ]);
        $this->assertDatabaseHas('machine_products', [
            'vending_machine_id' => $machine->id,
            'product_id' => $newProduct->id,
            'price' => 150,
            'temperature' => 'cold',
        ]);
        $this->assertDatabaseHas('machine_products', [
            'vending_machine_id' => $machine->id,
            'product_id' => $newProduct->id,
            'price' => 160,
            'temperature' => 'hot',
        ]);

        $this->get(route('vending-machines.show', $machine))
            ->assertOk()
            ->assertSee('更新後の商品')
            ->assertSee('150円')
            ->assertSee('160円')
            ->assertSee('0日前の情報')
            ->assertDontSee('古い商品');
    }

    public function test_another_authenticated_user_can_update_machine_without_changing_original_owner(): void
    {
        $owner = User::factory()->create();
        $updater = User::factory()->create();
        $machine = $this->createMachine('photos/old-machine.jpg', '2026-10-01 12:00:00', $owner);
        $this->actingAs($updater);
        $product = Product::create(['name' => '更新後の商品']);
        $this->mockReader([
            ['name' => '更新後の商品', 'price' => 150, 'temperature' => 'cold'],
        ]);

        $component = Volt::test('posts.create', ['machine' => $machine->id])
            ->set('photo', UploadedFile::fake()->image('new-machine.jpg'))
            ->call('save')
            ->call('register')
            ->assertHasNoErrors()
            ->assertRedirect(route('vending-machines.show', $machine));

        $updated = $machine->fresh();
        $this->assertSame($owner->id, $updated->user_id);
        $this->assertSame('33.5890000', $updated->latitude);
        $this->assertSame('130.3950000', $updated->longitude);
        $this->assertSame('suntory', $updated->maker);
        $this->assertDatabaseHas('machine_products', [
            'vending_machine_id' => $machine->id,
            'product_id' => $product->id,
            'price' => 150,
            'temperature' => 'cold',
        ]);
    }

    public function test_unauthenticated_user_cannot_open_machine_update_form(): void
    {
        $machine = $this->createMachine('photos/old-machine.jpg', '2026-10-01 12:00:00');

        auth()->logout();

        $this->get(route('posts.create', ['machine' => $machine->id]))
            ->assertRedirect('/login');

        $this->assertSame($machine->photo_path, $machine->fresh()->photo_path);
    }

    public function test_unauthenticated_user_cannot_run_machine_update_action(): void
    {
        $oldPhoto = 'photos/old-machine.jpg';
        Storage::disk('photos')->put($oldPhoto, 'old photo');

        $oldProduct = Product::create(['name' => '既存商品']);
        $machine = $this->createMachine($oldPhoto, '2026-10-01 12:00:00');
        $machine->products()->attach($oldProduct->id, [
            'price' => 100,
            'temperature' => 'cold',
            'recorded_at' => '2026-10-01 12:00:00',
        ]);
        $this->mockReader([
            ['name' => '更新後の商品', 'price' => 150, 'temperature' => 'cold'],
        ]);

        $component = Volt::test('posts.create', ['machine' => $machine->id])
            ->set('photo', UploadedFile::fake()->image('new-machine.jpg'))
            ->call('save');
        $newPhoto = $component->get('savedPath');

        auth()->logout();

        $this->withHeader('X-Livewire', 'true')
            ->postJson(app('livewire')->getUpdateUri(), [
                'components' => [[
                    'snapshot' => json_encode($component->snapshot),
                    'updates' => [],
                    'calls' => [[
                        'method' => 'register',
                        'params' => [],
                        'path' => '',
                    ]],
                ]],
            ])
            ->assertUnauthorized();

        Storage::disk('photos')->assertExists($oldPhoto);
        Storage::disk('photos')->assertExists($newPhoto);
        $unchanged = $machine->fresh();
        $this->assertSame($oldPhoto, $unchanged->photo_path);
        $this->assertTrue($unchanged->last_confirmed_at->equalTo('2026-10-01 12:00:00'));
        $this->assertDatabaseHas('machine_products', [
            'vending_machine_id' => $machine->id,
            'product_id' => $oldProduct->id,
            'price' => 100,
            'temperature' => 'cold',
        ]);
        $this->assertDatabaseMissing('machine_products', [
            'vending_machine_id' => $machine->id,
            'price' => 150,
            'temperature' => 'cold',
        ]);
    }

    public function test_machine_update_form_returns_not_found_for_missing_machine(): void
    {
        $this->get(route('posts.create', ['machine' => 999999]))
            ->assertNotFound();
    }

    public function test_duplicate_update_rolls_back_everything_and_deletes_only_the_new_photo(): void
    {
        $oldPhoto = 'photos/old-machine.jpg';
        Storage::disk('photos')->put($oldPhoto, 'old photo');

        $oldProduct = Product::create(['name' => '既存商品']);
        $machine = $this->createMachine($oldPhoto, '2026-10-01 12:00:00');
        $machine->products()->attach($oldProduct->id, [
            'price' => 100,
            'temperature' => 'cold',
            'recorded_at' => '2026-10-01 12:00:00',
        ]);
        $this->travelTo('2026-10-24 12:00:00');
        $this->mockReader([
            ['name' => '新規商品', 'price' => 150, 'temperature' => 'cold'],
            ['name' => '新規商品', 'price' => 160, 'temperature' => 'cold'],
        ]);

        $component = Volt::test('posts.create', ['machine' => $machine->id])
            ->set('photo', UploadedFile::fake()->image('new-machine.jpg'))
            ->call('save')
            ->assertSet('rows.0.product_id', null)
            ->assertSet('rows.1.product_id', null);

        $newPhoto = $component->get('savedPath');
        $component->call('register')
            ->assertHasErrors('rows')
            ->assertSet('savedPath', null);

        Storage::disk('photos')->assertMissing($newPhoto);
        Storage::disk('photos')->assertExists($oldPhoto);
        $this->assertSame($oldPhoto, $machine->fresh()->photo_path);
        $this->assertTrue($machine->fresh()->last_confirmed_at->equalTo('2026-10-01 12:00:00'));
        $this->assertDatabaseHas('machine_products', [
            'vending_machine_id' => $machine->id,
            'product_id' => $oldProduct->id,
            'price' => 100,
            'temperature' => 'cold',
        ]);
        $this->assertSame(1, Product::count());
        $this->assertDatabaseCount('machine_products', 1);
    }

    public function test_invalid_update_input_deletes_new_photo_without_changing_old_machine_data(): void
    {
        $oldPhoto = 'photos/old-machine.jpg';
        Storage::disk('photos')->put($oldPhoto, 'old photo');

        $product = Product::create(['name' => '既存商品']);
        $machine = $this->createMachine($oldPhoto, '2026-10-01 12:00:00');
        $machine->products()->attach($product->id, [
            'price' => 100,
            'temperature' => 'cold',
            'recorded_at' => '2026-10-01 12:00:00',
        ]);
        $this->mockReader([
            ['name' => '既存商品', 'price' => 150, 'temperature' => 'cold'],
        ]);

        $component = Volt::test('posts.create', ['machine' => $machine->id])
            ->set('photo', UploadedFile::fake()->image('new-machine.jpg'))
            ->call('save');

        $newPhoto = $component->get('savedPath');
        $component->set('rows.0.price', -1)
            ->call('register')
            ->assertHasErrors('rows.0.price')
            ->assertSet('savedPath', null);

        Storage::disk('photos')->assertMissing($newPhoto);
        Storage::disk('photos')->assertExists($oldPhoto);
        $this->assertSame($oldPhoto, $machine->fresh()->photo_path);
        $this->assertTrue($machine->fresh()->last_confirmed_at->equalTo('2026-10-01 12:00:00'));
        $this->assertDatabaseHas('machine_products', [
            'vending_machine_id' => $machine->id,
            'product_id' => $product->id,
            'price' => 100,
            'temperature' => 'cold',
        ]);
    }

    public function test_database_failure_rolls_back_machine_products_and_photo_files(): void
    {
        $oldPhoto = 'photos/old-machine.jpg';
        Storage::disk('photos')->put($oldPhoto, 'old photo');

        $oldProduct = Product::create(['name' => '既存商品']);
        $machine = $this->createMachine($oldPhoto, '2026-10-01 12:00:00');
        $machine->products()->attach($oldProduct->id, [
            'price' => 100,
            'temperature' => 'cold',
            'recorded_at' => '2026-10-01 12:00:00',
        ]);
        $this->travelTo('2026-10-24 12:00:00');
        $this->mockReader([
            ['name' => '更新失敗商品', 'price' => 150, 'temperature' => 'cold'],
        ]);

        DB::unprepared(
            'CREATE TRIGGER fail_machine_product_insert '
            .'BEFORE INSERT ON machine_products '
            ."BEGIN SELECT RAISE(ABORT, 'forced database failure'); END"
        );

        $component = Volt::test('posts.create', ['machine' => $machine->id])
            ->set('photo', UploadedFile::fake()->image('new-machine.jpg'))
            ->call('save');
        $newPhoto = $component->get('savedPath');

        $component->call('register')
            ->assertHasErrors('rows')
            ->assertSet('step', 'form')
            ->assertSet('savedPath', null);

        Storage::disk('photos')->assertExists($oldPhoto);
        Storage::disk('photos')->assertMissing($newPhoto);

        $unchanged = $machine->fresh();
        $this->assertSame($oldPhoto, $unchanged->photo_path);
        $this->assertTrue($unchanged->last_confirmed_at->equalTo('2026-10-01 12:00:00'));
        $this->assertSame(auth()->id(), $unchanged->user_id);
        $this->assertSame('33.5890000', $unchanged->latitude);
        $this->assertSame('130.3950000', $unchanged->longitude);
        $this->assertSame('suntory', $unchanged->maker);
        $this->assertDatabaseHas('machine_products', [
            'vending_machine_id' => $machine->id,
            'product_id' => $oldProduct->id,
            'price' => 100,
            'temperature' => 'cold',
        ]);
        $this->assertDatabaseMissing('products', ['name' => '更新失敗商品']);
        $this->assertDatabaseCount('machine_products', 1);
    }

    public function test_duplicate_service_update_rolls_back_photo_timestamp_products_and_created_products(): void
    {
        $oldProduct = Product::create(['name' => '既存商品']);
        $machine = $this->createMachine('photos/old-machine.jpg', '2026-10-01 12:00:00');
        $machine->products()->attach($oldProduct->id, [
            'price' => 100,
            'temperature' => 'cold',
            'recorded_at' => '2026-10-01 12:00:00',
        ]);
        $originalTimestamp = $machine->last_confirmed_at->copy();

        try {
            app(VendingMachineRegistrar::class)->update($machine, [
                ['name' => '新規商品', 'price' => 150, 'temperature' => 'cold', 'product_id' => null],
                ['name' => '新規商品', 'price' => 160, 'temperature' => 'cold', 'product_id' => null],
            ], 'photos/new-machine.jpg');
            $this->fail('同一商品・同一温冷区分は拒否されるはずです');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('rows', $exception->errors());
        }

        $this->assertSame('photos/old-machine.jpg', $machine->fresh()->photo_path);
        $this->assertTrue($machine->fresh()->last_confirmed_at->equalTo($originalTimestamp));
        $this->assertDatabaseHas('machine_products', [
            'vending_machine_id' => $machine->id,
            'product_id' => $oldProduct->id,
            'price' => 100,
            'temperature' => 'cold',
        ]);
        $this->assertSame(1, Product::count());
        $this->assertDatabaseCount('machine_products', 1);
    }

    public function test_update_reuses_same_name_and_volume_product_and_creates_new_volume(): void
    {
        $existing = Product::create(['name' => '綾鷹', 'volume_ml' => 525]);
        $machine = $this->createMachine('photos/old-machine.jpg', '2026-10-01 12:00:00');

        app(VendingMachineRegistrar::class)->update($machine, [
            ['name' => '綾鷹', 'price' => 150, 'temperature' => 'cold', 'product_id' => null, 'new_volume_ml' => 525],
            ['name' => '綾鷹', 'price' => 160, 'temperature' => 'hot', 'product_id' => null, 'new_volume_ml' => 650],
        ], 'photos/new-machine.jpg');

        $newVolumeProduct = Product::where('name', '綾鷹')->where('volume_ml', 650)->sole();
        $this->assertSame(2, Product::where('name', '綾鷹')->count());
        $this->assertDatabaseHas('machine_products', [
            'vending_machine_id' => $machine->id,
            'product_id' => $existing->id,
            'price' => 150,
            'temperature' => 'cold',
        ]);
        $this->assertDatabaseHas('machine_products', [
            'vending_machine_id' => $machine->id,
            'product_id' => $newVolumeProduct->id,
            'price' => 160,
            'temperature' => 'hot',
        ]);
    }

    private function createMachine(
        string $photoPath,
        string $lastConfirmedAt,
        ?User $owner = null
    ): VendingMachine {
        return VendingMachine::create([
            'latitude' => 33.589,
            'longitude' => 130.395,
            'maker' => 'suntory',
            'photo_path' => $photoPath,
            'user_id' => $owner?->id ?? auth()->id(),
            'last_confirmed_at' => $lastConfirmedAt,
        ]);
    }

    private function mockReader(array $products): void
    {
        $reader = \Mockery::mock(VendingMachineReader::class);
        $reader->shouldReceive('read')->once()->andReturn([
            'result' => [
                'is_vending_machine' => true,
                'maker' => null,
                'products' => $products,
            ],
        ]);
        $this->app->instance(VendingMachineReader::class, $reader);
    }
}
