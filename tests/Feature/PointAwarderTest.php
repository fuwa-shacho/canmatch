<?php

namespace Tests\Feature;

use App\Models\PointLog;
use App\Models\User;
use App\Models\VendingMachine;
use App\Services\PointAwarder;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Symfony\Component\Process\Process;
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

    public function test_rare_bonus_awards_configured_points_only_once_per_machine(): void
    {
        $user = User::factory()->create();
        $machine = $this->createMachine($user, 1);
        $awarder = app(PointAwarder::class);

        $first = $awarder->awardRareBonus($user, $machine);
        $second = $awarder->awardRareBonus($user, $machine);

        $this->assertSame($first->id, $second->id);
        $this->assertSame('rare_bonus', $first->type);
        $this->assertSame(20, $first->points);
        $this->assertDatabaseHas('point_logs', [
            'vending_machine_id' => $machine->id,
            'type' => 'rare_bonus',
            'points' => 20,
        ]);
        $this->assertSame(1, PointLog::where('type', 'rare_bonus')->count());
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

    public function test_registration_award_is_idempotent_for_the_same_machine(): void
    {
        $user = User::factory()->create();
        $machine = $this->createMachine($user, 1);
        $awarder = app(PointAwarder::class);

        $first = $awarder->awardForRegistration($user, $machine);
        $second = $awarder->awardForRegistration($user, $machine);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, PointLog::where('type', 'registration')->count());
    }

    public function test_concurrent_registration_awards_wait_for_the_machine_lock_on_mysql(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('MySQL is required to verify row-lock serialization.');
        }

        $connection = 'point_award_concurrency';
        config(["database.connections.{$connection}" => config('database.connections.mysql')]);
        DB::purge($connection);

        $user = User::factory()->make();
        $user->setConnection($connection);
        $user->save();

        $machine = new VendingMachine([
            'latitude' => 33.59,
            'longitude' => 130.401,
            'maker' => '開発用自販機メーカー',
            'photo_path' => 'vending-machines/development/concurrency.jpg',
            'user_id' => $user->id,
            'last_confirmed_at' => '2026-10-23 00:00:00',
        ]);
        $machine->setConnection($connection);
        $machine->save();

        $holder = DB::connection($connection);
        $holder->beginTransaction();
        $holder->table('vending_machines')
            ->where('id', $machine->id)
            ->lockForUpdate()
            ->first();

        $barrier = sys_get_temp_dir().'/canmatch-pr53-'.bin2hex(random_bytes(8)).'.go';
        $readyFiles = [
            sys_get_temp_dir().'/canmatch-pr53-'.bin2hex(random_bytes(8)).'.ready',
            sys_get_temp_dir().'/canmatch-pr53-'.bin2hex(random_bytes(8)).'.ready',
        ];
        $processes = [];

        try {
            foreach ($readyFiles as $readyFile) {
                $script = implode("\n", [
                    "require 'vendor/autoload.php';",
                    '$app = require "bootstrap/app.php";',
                    '$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();',
                    'file_put_contents('.var_export($readyFile, true).', "ready");',
                    'while (! file_exists('.var_export($barrier, true).')) { usleep(1000); }',
                    '$machine = App\\Models\\VendingMachine::query()->findOrFail('.$machine->id.');',
                    '$user = App\\Models\\User::query()->findOrFail('.$user->id.');',
                    'app(App\\Services\\PointAwarder::class)->awardForRegistration($user, $machine);',
                ]);
                $process = new Process([PHP_BINARY, '-r', $script], base_path(), getenv());
                $process->start();
                $processes[] = $process;
            }

            $readyDeadline = microtime(true) + 10;
            while (count(array_filter($readyFiles, 'file_exists')) !== count($readyFiles)) {
                foreach ($processes as $process) {
                    $this->assertTrue($process->isRunning(), $process->getErrorOutput());
                }

                $this->assertLessThan($readyDeadline, microtime(true), 'Award workers did not become ready.');
                usleep(1000);
            }

            touch($barrier);

            usleep(250000);

            $allBlocked = collect($processes)->every(fn (Process $process): bool => $process->isRunning());

            $holder->commit();

            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
            }

            $this->assertTrue($allBlocked, 'Award workers must wait for the locked machine row.');
            $this->assertSame(
                1,
                PointLog::on($connection)
                    ->where('vending_machine_id', $machine->id)
                    ->where('type', 'registration')
                    ->count(),
            );
        } finally {
            if ($holder->transactionLevel() > 0) {
                $holder->rollBack();
            }

            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }

            @unlink($barrier);
            foreach ($readyFiles as $readyFile) {
                @unlink($readyFile);
            }

            PointLog::on($connection)->where('vending_machine_id', $machine->id)->delete();
            VendingMachine::on($connection)->whereKey($machine->id)->delete();
            User::on($connection)->whereKey($user->id)->delete();
            DB::purge($connection);
        }
    }

    public function test_registration_award_can_use_the_machine_registration_time(): void
    {
        $user = User::factory()->create();
        $machine = $this->createMachine($user, 1);
        $registeredAt = CarbonImmutable::parse('2026-10-01 03:04:05');

        $pointLog = app(PointAwarder::class)->awardForRegistration(
            $user,
            $machine,
            $registeredAt,
        );

        $this->assertTrue($pointLog->created_at->equalTo($registeredAt));
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
