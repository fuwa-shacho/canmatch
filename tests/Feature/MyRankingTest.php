<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MyRankingTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_user_below_top_twenty_can_see_their_rank_and_gap(): void
    {
        $viewer = User::factory()->create(['name' => '開発用21位']);

        foreach (range(1, 20) as $index) {
            $user = User::factory()->create([
                'name' => sprintf('開発用上位%02d', $index),
            ]);
            $this->addPoints($user, 22 - $index);
        }

        $this->addPoints($viewer, 1);

        $this->actingAs($viewer)
            ->get(route('ranking.index'))
            ->assertOk()
            ->assertViewHas('ranking', fn ($ranking): bool => $ranking->count() === 20)
            ->assertViewHas('selfRanking', [
                'rank' => 21,
                'total_points' => 1,
                'points_to_next_rank' => 1,
            ])
            ->assertSee('21位')
            ->assertSee('1つ上の順位まであと1ポイントです。');
    }

    public function test_tied_user_sees_the_shared_rank_and_gap_to_the_next_higher_group(): void
    {
        $first = User::factory()->create(['name' => '開発用1位']);
        $viewer = User::factory()->create(['name' => '開発用同点']);
        $tied = User::factory()->create(['name' => '開発用同点相手']);

        $this->addPoints($first, 30);
        $this->addPoints($viewer, 20);
        $this->addPoints($tied, 20);

        $this->actingAs($viewer)
            ->get(route('ranking.index'))
            ->assertOk()
            ->assertViewHas('selfRanking', [
                'rank' => 2,
                'total_points' => 20,
                'points_to_next_rank' => 10,
            ]);
    }

    public function test_first_place_user_sees_first_place_message(): void
    {
        $viewer = User::factory()->create(['name' => '開発用1位']);
        $this->addPoints($viewer, 30);

        $this->actingAs($viewer)
            ->get(route('ranking.index'))
            ->assertOk()
            ->assertViewHas('selfRanking', [
                'rank' => 1,
                'total_points' => 30,
                'points_to_next_rank' => null,
            ])
            ->assertSee('現在1位です。');
    }

    public function test_user_without_points_has_no_rank(): void
    {
        $viewer = User::factory()->create(['name' => '開発用未獲得']);

        $this->actingAs($viewer)
            ->get(route('ranking.index'))
            ->assertOk()
            ->assertViewHas('selfRanking', [
                'rank' => null,
                'total_points' => 0,
                'points_to_next_rank' => null,
            ])
            ->assertSee('順位なし・0 pt');
    }

    public function test_self_ranking_changes_with_the_selected_period(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-22 12:00:00', 'Asia/Tokyo'));

        $viewer = User::factory()->create(['name' => '開発用閲覧者']);
        $other = User::factory()->create(['name' => '開発用他ユーザー']);

        $this->addPoints($viewer, 30, '2026-10-18 14:59:59');
        $this->addPoints($viewer, 5, '2026-10-18 15:00:00');
        $this->addPoints($other, 10, '2026-10-18 15:00:00');

        $this->actingAs($viewer)
            ->get(route('ranking.index'))
            ->assertViewHas('selfRanking', [
                'rank' => 1,
                'total_points' => 35,
                'points_to_next_rank' => null,
            ]);

        $this->get(route('ranking.index', ['period' => 'weekly']))
            ->assertViewHas('selfRanking', [
                'rank' => 2,
                'total_points' => 5,
                'points_to_next_rank' => 5,
            ]);
    }

    private function addPoints(User $user, int $points, string $createdAt = '2026-10-22 00:00:00'): void
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
