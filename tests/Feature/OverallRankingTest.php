<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OverallRankingTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('ranking.index'))
            ->assertRedirect(route('login'));
    }

    public function test_users_are_ranked_by_total_points(): void
    {
        $viewer = User::factory()->create(['name' => '開発用閲覧者']);
        $first = User::factory()->create(['name' => '開発用1位']);
        $second = User::factory()->create(['name' => '開発用2位']);
        $third = User::factory()->create(['name' => '開発用3位']);

        $this->addPoints($first, 10);
        $this->addPoints($first, 20, 'rare_bonus');
        $this->addPoints($second, 20, 'rare_bonus');
        $this->addPoints($third, 5, 'update');

        $this->actingAs($viewer)
            ->get(route('ranking.index'))
            ->assertOk()
            ->assertViewHas('ranking', function ($ranking) use ($first, $second, $third): bool {
                return $ranking->all() === [
                    ['rank' => 1, 'user_id' => $first->id, 'name' => $first->name, 'total_points' => 30],
                    ['rank' => 2, 'user_id' => $second->id, 'name' => $second->name, 'total_points' => 20],
                    ['rank' => 3, 'user_id' => $third->id, 'name' => $third->name, 'total_points' => 5],
                ];
            });
    }

    public function test_tied_users_share_a_rank_and_the_next_rank_is_skipped(): void
    {
        $viewer = User::factory()->create(['name' => '開発用閲覧者']);
        $alpha = User::factory()->create(['name' => '開発用A']);
        $bravo = User::factory()->create(['name' => '開発用B']);
        $charlie = User::factory()->create(['name' => '開発用C']);

        $this->addPoints($bravo, 30);
        $this->addPoints($alpha, 30);
        $this->addPoints($charlie, 10);

        $this->actingAs($viewer)
            ->get(route('ranking.index'))
            ->assertOk()
            ->assertViewHas('ranking', function ($ranking) use ($alpha, $bravo, $charlie): bool {
                return $ranking->all() === [
                    ['rank' => 1, 'user_id' => $alpha->id, 'name' => $alpha->name, 'total_points' => 30],
                    ['rank' => 1, 'user_id' => $bravo->id, 'name' => $bravo->name, 'total_points' => 30],
                    ['rank' => 3, 'user_id' => $charlie->id, 'name' => $charlie->name, 'total_points' => 10],
                ];
            });
    }

    public function test_only_the_top_twenty_users_are_listed(): void
    {
        $viewer = User::factory()->create(['name' => '開発用閲覧者']);
        $users = collect();

        foreach (range(1, 21) as $index) {
            $user = User::factory()->create([
                'name' => sprintf('開発用順位%02d', $index),
            ]);
            $this->addPoints($user, 22 - $index);
            $users->push($user);
        }

        $this->actingAs($viewer)
            ->get(route('ranking.index'))
            ->assertOk()
            ->assertViewHas('ranking', function ($ranking) use ($users): bool {
                return $ranking->count() === 20
                    && $ranking->first()['user_id'] === $users->first()->id
                    && ! $ranking->contains('user_id', $users->last()->id);
            });
    }

    public function test_empty_ranking_is_displayed_when_no_points_exist(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('ranking.index'))
            ->assertOk()
            ->assertViewHas('ranking', fn ($ranking): bool => $ranking->isEmpty())
            ->assertSee('まだポイントを獲得したユーザーはいません。');
    }

    private function addPoints(User $user, int $points, string $type = 'registration'): void
    {
        DB::table('point_logs')->insert([
            'user_id' => $user->id,
            'type' => $type,
            'points' => $points,
            'vending_machine_id' => null,
            'created_at' => '2026-10-22 00:00:00',
        ]);
    }
}
