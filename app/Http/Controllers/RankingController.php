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
            ->orderBy('users.id')
            ->limit(20);

        return view('ranking', [
            'period' => $period,
            'ranking' => $this->addRanks($query->get()),
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
}
