<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;

class RankingController extends Controller
{
    public function __invoke(): View
    {
        $users = User::query()
            ->select(['users.id', 'users.name'])
            ->selectRaw('SUM(point_logs.points) as total_points')
            ->join('point_logs', 'point_logs.user_id', '=', 'users.id')
            ->groupBy('users.id', 'users.name')
            ->orderByDesc('total_points')
            ->orderBy('users.name')
            ->orderBy('users.id')
            ->limit(20)
            ->get();

        return view('ranking', [
            'ranking' => $this->addRanks($users),
        ]);
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
}
