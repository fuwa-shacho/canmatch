<?php

namespace Tests\Feature;

use App\Models\PointLog;
use App\Models\User;
use App\Models\VendingMachine;
use App\Services\PointAwarder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class UpdatePointCooldownTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_update_within_seven_days_does_not_award_points(): void
    {
        Carbon::setTestNow('2026-10-23 12:00:00');
        $user = User::factory()->create();
        $machine = $this->createMachine($user, 1);
        $awarder = app(PointAwarder::class);

        $awarder->awardForRegistration($user, $machine);

        $this->assertNull($awarder->awardForUpdate($user, $machine));
        $this->assertDatabaseCount('point_logs', 1);
    }

    public function test_exactly_seven_days_is_still_inside_cooldown(): void
    {
        Carbon::setTestNow('2026-10-23 12:00:00');
        $user = User::factory()->create();
        $machine = $this->createMachine($user, 1);
        $this->createPointLog($user, $machine, Carbon::parse('2026-10-16 12:00:00'));

        $pointLog = app(PointAwarder::class)->awardForUpdate($user, $machine);

        $this->assertNull($pointLog);
        $this->assertDatabaseCount('point_logs', 1);
    }

    public function test_update_after_seven_days_awards_points(): void
    {
        Carbon::setTestNow('2026-10-23 12:00:00');
        $user = User::factory()->create();
        $machine = $this->createMachine($user, 1);
        $this->createPointLog($user, $machine, Carbon::parse('2026-10-15 12:00:00'));
        $this->assertDatabaseHas('point_logs', [
            'vending_machine_id' => $machine->id,
            'created_at' => '2026-10-15 12:00:00',
        ]);

        $pointLog = app(PointAwarder::class)->awardForUpdate($user, $machine);

        $this->assertNotNull($pointLog);
        $this->assertSame('update', $pointLog->type);
        $this->assertDatabaseCount('point_logs', 2);
    }

    public function test_recent_award_by_another_user_still_starts_machine_cooldown(): void
    {
        Carbon::setTestNow('2026-10-23 12:00:00');
        $firstUser = User::factory()->create();
        $secondUser = User::factory()->create();
        $machine = $this->createMachine($firstUser, 1);
        $this->createPointLog($firstUser, $machine, Carbon::parse('2026-10-22 12:00:00'));

        $pointLog = app(PointAwarder::class)->awardForUpdate($secondUser, $machine);

        $this->assertNull($pointLog);
        $this->assertDatabaseCount('point_logs', 1);
    }

    public function test_recent_award_for_another_machine_does_not_block_points(): void
    {
        Carbon::setTestNow('2026-10-23 12:00:00');
        $user = User::factory()->create();
        $firstMachine = $this->createMachine($user, 1);
        $secondMachine = $this->createMachine($user, 2);
        $this->createPointLog($user, $firstMachine, Carbon::parse('2026-10-22 12:00:00'));

        $pointLog = app(PointAwarder::class)->awardForUpdate($user, $secondMachine);

        $this->assertNotNull($pointLog);
        $this->assertSame($secondMachine->id, $pointLog->vending_machine_id);
    }

    private function createMachine(User $user, int $index): VendingMachine
    {
        return VendingMachine::query()->create([
            'latitude' => 33.5900000 + ($index / 10000),
            'longitude' => 130.4010000 + ($index / 10000),
            'maker' => '開発用自販機メーカー'.$index,
            'photo_path' => 'vending-machines/development/cooldown-'.$index.'.jpg',
            'user_id' => $user->id,
            'last_confirmed_at' => '2026-10-23 00:00:00',
        ]);
    }

    private function createPointLog(
        User $user,
        VendingMachine $machine,
        Carbon $createdAt,
    ): PointLog {
        return PointLog::query()->forceCreate([
            'user_id' => $user->id,
            'type' => 'update',
            'points' => 5,
            'vending_machine_id' => $machine->id,
            'created_at' => $createdAt,
        ]);
    }
}
