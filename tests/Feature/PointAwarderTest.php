<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\VendingMachine;
use App\Services\PointAwarder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class PointAwarderTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_awards_the_configured_points(): void
    {
        $user = User::factory()->create();
        $machine = $this->createMachine($user, 1);

        $pointLog = app(PointAwarder::class)->awardForRegistration($user, $machine);

        $this->assertSame('registration', $pointLog->type);
        $this->assertSame(10, $pointLog->points);
        $this->assertSame($user->id, $pointLog->user_id);
        $this->assertSame($machine->id, $pointLog->vending_machine_id);
        $this->assertNotNull($pointLog->created_at);
        $this->assertDatabaseHas('point_logs', [
            'id' => $pointLog->id,
            'type' => 'registration',
            'points' => 10,
        ]);
    }

    public function test_update_awards_the_configured_points(): void
    {
        $user = User::factory()->create();
        $machine = $this->createMachine($user, 1);

        $pointLog = app(PointAwarder::class)->awardForUpdate($user, $machine);

        $this->assertSame('update', $pointLog->type);
        $this->assertSame(5, $pointLog->points);
        $this->assertDatabaseHas('point_logs', [
            'id' => $pointLog->id,
            'type' => 'update',
            'points' => 5,
        ]);
    }

    public function test_point_values_come_from_config(): void
    {
        config([
            'points.registration' => 12,
            'points.update' => 7,
        ]);

        $user = User::factory()->create();
        $firstMachine = $this->createMachine($user, 1);
        $secondMachine = $this->createMachine($user, 2);
        $awarder = app(PointAwarder::class);

        $this->assertSame(12, $awarder->awardForRegistration($user, $firstMachine)->points);
        $this->assertSame(7, $awarder->awardForUpdate($user, $secondMachine)->points);
    }

    public function test_each_call_creates_one_point_log(): void
    {
        $user = User::factory()->create();
        $machine = $this->createMachine($user, 1);
        $awarder = app(PointAwarder::class);

        $awarder->awardForRegistration($user, $machine);
        $awarder->awardForUpdate($user, $machine);

        $this->assertDatabaseCount('point_logs', 2);
    }

    public function test_invalid_point_configuration_is_rejected(): void
    {
        config(['points.registration' => 0]);

        $user = User::factory()->create();
        $machine = $this->createMachine($user, 1);

        $this->expectException(LogicException::class);

        app(PointAwarder::class)->awardForRegistration($user, $machine);
    }

    private function createMachine(User $user, int $index): VendingMachine
    {
        return VendingMachine::query()->create([
            'latitude' => 33.5900000 + ($index / 10000),
            'longitude' => 130.4010000 + ($index / 10000),
            'maker' => '開発用自販機メーカー'.$index,
            'photo_path' => 'vending-machines/development/points-'.$index.'.jpg',
            'user_id' => $user->id,
            'last_confirmed_at' => '2026-10-23 00:00:00',
        ]);
    }
}
