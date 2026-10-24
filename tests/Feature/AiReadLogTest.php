<?php

namespace Tests\Feature;

use App\Models\AiReadLog;
use App\Models\User;
use App\Models\VendingMachine;
use App\Services\AiReadLogRecorder;
use App\Services\VendingMachineReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Volt;
use Tests\TestCase;

class AiReadLogTest extends TestCase
{
    use RefreshDatabase;

    private function machine(User $user): VendingMachine
    {
        return VendingMachine::create([
            'latitude' => 33.5902,
            'longitude' => 130.4017,
            'photo_path' => 'photos/test.jpg',
            'user_id' => $user->id,
            'last_confirmed_at' => now(),
        ]);
    }

    private function read(): array
    {
        return [
            'model' => 'test-model',
            'prompt_version' => 'test-v1',
            'raw' => '{"products":[]}',
            'gateway_log_id' => 'test-gateway-id',
            'result' => ['products' => [
                ['name' => 'お茶', 'price' => 150, 'temperature' => 'cold'],
            ]],
        ];
    }

    public function test_成功ログを自販機に紐付けて修正件数を記録する(): void
    {
        $user = User::factory()->create();
        $recorder = app(AiReadLogRecorder::class);
        $log = $recorder->success($user->id, 'photos/test.jpg', $this->read());

        $this->assertSame('test-gateway-id', $log->gateway_log_id);
        $this->assertSame('{"products":[]}', $log->raw_response);
        $this->assertTrue($recorder->attach($log->id, $user->id, $this->machine($user), [
            ['name' => 'お茶', 'price' => 160, 'temperature' => 'cold'],
        ]));
        $this->assertSame(1, $log->fresh()->correction_count);
        $this->assertNotNull($log->fresh()->vending_machine_id);
    }

    public function test_写真投稿から自販機登録まで読み取り記録を引き継ぐ(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        Storage::fake('photos');
        config(['filesystems.photos_disk' => 'photos']);
        Storage::disk('photos')->buildTemporaryUrlsUsing(
            fn (string $path, $expiration, array $options): string => 'https://photos.test/'.$path
        );

        $reader = \Mockery::mock(VendingMachineReader::class);
        $reader->shouldReceive('read')->once()->andReturn([
            ...$this->read(),
            'result' => ['is_vending_machine' => true, 'maker' => null, 'products' => [
                ['name' => 'お茶', 'price' => 150, 'temperature' => 'cold'],
            ]],
        ]);
        $this->app->instance(VendingMachineReader::class, $reader);

        Volt::test('posts.create')
            ->call('setLocation', 33.5902, 130.4017)
            ->set('photo', UploadedFile::fake()->image('test.jpg'))
            ->call('save')
            ->assertSet('step', 'review')
            ->set('rows.0.price', 160)
            ->call('register')
            ->assertHasNoErrors();

        $this->assertDatabaseCount('vending_machines', 1);
        $this->assertDatabaseHas('ai_read_logs', [
            'user_id' => $user->id,
            'vending_machine_id' => VendingMachine::firstOrFail()->id,
            'gateway_log_id' => 'test-gateway-id',
            'correction_count' => 1,
        ]);
    }

    public function test_異なる利用者や写真のログを紐付けない(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $recorder = app(AiReadLogRecorder::class);
        $log = $recorder->success($owner->id, 'photos/test.jpg', $this->read());

        $this->assertFalse($recorder->attach($log->id, $other->id, $this->machine($other), []));
        $this->assertNull($log->fresh()->vending_machine_id);
    }

    public function test_退会時に本人の読み取り記録を削除する(): void
    {
        $user = User::factory()->create();
        app(AiReadLogRecorder::class)->success($user->id, 'photos/test.jpg', $this->read());

        $user->delete();

        $this->assertDatabaseCount('ai_read_logs', 0);
    }

    public function test_未登録ログを期限後に削除できるが登録済みは残る(): void
    {
        $user = User::factory()->create();
        $recorder = app(AiReadLogRecorder::class);
        $unlinked = $recorder->failure($user->id, 'photos/unused.jpg', 'timeout');
        $linked = $recorder->success($user->id, 'photos/test.jpg', $this->read());
        $recorder->attach($linked->id, $user->id, $this->machine($user), [
            ['name' => 'お茶', 'price' => 150, 'temperature' => 'cold'],
        ]);
        $unlinked->forceFill(['created_at' => now()->subDays(31)])->save();
        $linked->forceFill(['created_at' => now()->subDays(31)])->save();

        $this->artisan('ai:prune-unlinked --apply')->assertExitCode(0);

        $this->assertNull(AiReadLog::find($unlinked->id));
        $this->assertNotNull(AiReadLog::find($linked->id));
    }

    public function test_集計コマンドで呼び出しと修正率を確認できる(): void
    {
        $user = User::factory()->create();
        $recorder = app(AiReadLogRecorder::class);
        $log = $recorder->success($user->id, 'photos/test.jpg', $this->read());
        $recorder->attach($log->id, $user->id, $this->machine($user), [
            ['name' => 'お茶', 'price' => 160, 'temperature' => 'cold'],
        ]);
        $recorder->failure($user->id, 'photos/failed.jpg', 'timeout');

        $this->artisan('ai:read-stats')->assertExitCode(0);
        $this->assertDatabaseCount('ai_read_logs', 2);
    }
}
