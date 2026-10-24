<?php

namespace Tests\Feature;

use App\Models\PointLog;
use App\Models\User;
use App\Models\VendingMachine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BackfillRegistrationPointsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_既定では対象件数だけ表示して変更しない(): void
    {
        $user = User::factory()->create();
        $this->createMachine($user, '2026-10-01 03:04:05');

        $this->artisan('points:backfill-registrations')
            ->expectsOutputToContain('補完対象: 1件')
            ->expectsOutputToContain('プレビューのみ')
            ->assertSuccessful();

        $this->assertSame(0, PointLog::count());
    }

    public function test_applyで不足する登録ポイントを登録時刻で補完する(): void
    {
        $user = User::factory()->create();
        $machine = $this->createMachine($user, '2026-10-01 03:04:05');

        $this->artisan('points:backfill-registrations', ['--apply' => true])
            ->expectsOutputToContain('補完対象: 1件')
            ->expectsOutputToContain('1件補完しました')
            ->assertSuccessful();

        $log = PointLog::sole();
        $this->assertSame($user->id, $log->user_id);
        $this->assertSame($machine->id, $log->vending_machine_id);
        $this->assertSame('registration', $log->type);
        $this->assertSame(10, $log->points);
        $this->assertTrue($log->created_at->equalTo($machine->created_at));
    }

    public function test_利用者なしと既存履歴は補完せず再実行しても増えない(): void
    {
        $user = User::factory()->create();
        $completed = $this->createMachine($user, '2026-10-01 03:04:05');
        $missing = $this->createMachine($user, '2026-10-02 03:04:05');
        $this->createMachine(null, '2026-10-03 03:04:05');

        PointLog::create([
            'user_id' => $user->id,
            'type' => 'registration',
            'points' => 10,
            'vending_machine_id' => $completed->id,
        ]);

        $this->artisan('points:backfill-registrations', ['--apply' => true])
            ->expectsOutputToContain('補完対象: 1件')
            ->assertSuccessful();

        $this->artisan('points:backfill-registrations', ['--apply' => true])
            ->expectsOutputToContain('補完対象: 0件')
            ->expectsOutputToContain('0件補完しました')
            ->assertSuccessful();

        $this->assertSame(2, PointLog::where('type', 'registration')->count());
        $this->assertSame(
            1,
            PointLog::where('vending_machine_id', $missing->id)
                ->where('type', 'registration')
                ->count(),
        );
    }

    private function createMachine(?User $user, string $createdAt): VendingMachine
    {
        $machine = new VendingMachine([
            'latitude' => 33.5900000,
            'longitude' => 130.4010000,
            'maker' => '開発用自販機メーカー',
            'photo_path' => 'vending-machines/development/backfill.jpg',
            'user_id' => $user?->id,
            'last_confirmed_at' => $createdAt,
        ]);
        $machine->created_at = $createdAt;
        $machine->updated_at = $createdAt;
        $machine->save();

        return $machine;
    }
}
