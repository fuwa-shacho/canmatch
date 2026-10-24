<?php

namespace App\Http\Controllers;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class RankingController extends Controller
{
    public function __invoke(Request $request): View
    {
        $period = $request->query('period') === 'weekly' ? 'weekly' : 'overall';
        $query = User::query()
            ->select(['users.id', 'users.name'])
            ->selectRaw('SUM(point_logs.points) as total_points')
            ->join('point_logs', function ($join) use ($period): void {
                $join->on('point_logs.user_id', '=', 'users.id');

                if ($period === 'weekly') {
                    $join->where('point_logs.created_at', '>=', $this->startOfWeekUtc());
                }
            })
            ->groupBy('users.id', 'users.name')
            ->orderByDesc('total_points')
            ->orderBy('users.name')
            ->orderBy('users.id');

        $rankedUsers = $this->addRanks($query->get());

        return view('ranking', [
            'period' => $period,
            'ranking' => $rankedUsers->take(20)->values(),
            'selfRanking' => $this->selfRanking($rankedUsers, $request->user()->id),
        ]);
    }

    private function startOfWeekUtc(): string
    {
        return now('Asia/Tokyo')->startOfWeek(CarbonInterface::MONDAY)->utc()->format('Y-m-d H:i:s');
    }

    /**
     * @param  Collection<int, User>  $users
     * @return Collection<int, array{rank: int, user_id: int, name: string, total_points: int}>
     */
    private function addRanks(Collection $users): Collection
    {
        $previousPoints = null;
        $previousRank = 0;

        return $users->values()->map(function (User $user, int $index) use (&$previousPoints, &$previousRank): array {
            $totalPoints = (int) $user->getAttribute('total_points');

            if ($previousPoints === null || $totalPoints !== $previousPoints) {
                $previousPoints = $totalPoints;
                $previousRank = $index + 1;
            }

            return [
                'rank' => $previousRank,
                'user_id' => $user->id,
                'name' => $user->name,
                'total_points' => $totalPoints,
            ];
        });
    }

    /**
     * @param  Collection<int, array{rank: int, user_id: int, name: string, total_points: int}>  $ranking
     * @return array{rank: int|null, total_points: int, points_to_next_rank: int|null}
     */
    private function selfRanking(Collection $ranking, int $userId): array
    {
        $self = $ranking->firstWhere('user_id', $userId);

        if ($self === null) {
            return [
                'rank' => null,
                'total_points' => 0,
                'points_to_next_rank' => null,
            ];
        }

        if ($self['rank'] === 1) {
            return [
                'rank' => 1,
                'total_points' => $self['total_points'],
                'points_to_next_rank' => null,
            ];
        }

        $nextHigher = $ranking
            ->where('total_points', '>', $self['total_points'])
            ->last();

        return [
            'rank' => $self['rank'],
            'total_points' => $self['total_points'],
            'points_to_next_rank' => $nextHigher['total_points'] - $self['total_points'],
        ];
    }
}
