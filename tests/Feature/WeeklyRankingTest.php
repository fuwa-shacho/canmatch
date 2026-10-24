<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WeeklyRankingTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_weekly_ranking_starts_at_monday_midnight_in_japan(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-22 12:00:00', 'Asia/Tokyo'));

        $viewer = User::factory()->create(['name' => '開発用閲覧者']);
        $beforeBoundary = User::factory()->create(['name' => '開発用先週']);
        $atBoundary = User::factory()->create(['name' => '開発用今週']);

        $this->addPoints($beforeBoundary, 30, '2026-10-18 14:59:59');
        $this->addPoints($atBoundary, 10, '2026-10-18 15:00:00');

        $this->actingAs($viewer)
            ->get(route('ranking.index', ['period' => 'weekly']))
            ->assertOk()
            ->assertViewHas('period', 'weekly')
            ->assertViewHas('ranking', function ($ranking) use ($atBoundary): bool {
                return $ranking->all() === [
                    ['rank' => 1, 'user_id' => $atBoundary->id, 'name' => $atBoundary->name, 'total_points' => 10],
                ];
            });
    }

    public function test_overall_ranking_still_includes_points_from_before_this_week(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-22 12:00:00', 'Asia/Tokyo'));

        $viewer = User::factory()->create(['name' => '開発用閲覧者']);
        $user = User::factory()->create(['name' => '開発用全期間']);

        $this->addPoints($user, 30, '2026-10-18 14:59:59');
        $this->addPoints($user, 10, '2026-10-18 15:00:00');

        $this->actingAs($viewer)
            ->get(route('ranking.index'))
            ->assertOk()
            ->assertViewHas('period', 'overall')
            ->assertViewHas('ranking', function ($ranking) use ($user): bool {
                return $ranking->all() === [
                    ['rank' => 1, 'user_id' => $user->id, 'name' => $user->name, 'total_points' => 40],
                ];
            });
    }

    public function test_weekly_tab_and_empty_message_are_displayed(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-22 12:00:00', 'Asia/Tokyo'));

        $this->actingAs(User::factory()->create())
            ->get(route('ranking.index', ['period' => 'weekly']))
            ->assertOk()
            ->assertSee('総合')
            ->assertSee('週間')
            ->assertSee('今週ポイントを獲得したユーザーはいません。');
    }

    private function addPoints(User $user, int $points, string $createdAt): void
    {
        DB::table('point_logs')->insert([
            'user_id' => $user->id,
            'type' => 'registration',
            'points' => $points,
            'vending_machine_id' => null,
            'created_at' => $createdAt,
        ]);
    }
}
