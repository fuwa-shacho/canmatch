<x-layouts.app>
    <div class="mx-auto flex w-full max-w-3xl flex-col gap-6">
        <div>
            <h1 class="text-2xl font-bold">ランキング</h1>
            <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-300">
                {{ $period === 'weekly' ? '今週獲得したポイント' : 'これまでに獲得したポイント' }}の上位20人です。
            </p>
        </div>

        <nav aria-label="ランキングの期間" class="flex gap-2">
            <a
                href="{{ route('ranking.index') }}"
                wire:navigate
                @class([
                    'rounded-lg px-4 py-2 text-sm font-semibold',
                    'bg-zinc-900 text-white dark:bg-white dark:text-zinc-900' => $period === 'overall',
                    'border border-neutral-300 dark:border-neutral-600' => $period !== 'overall',
                ])
                @if ($period === 'overall') aria-current="page" @endif
            >総合</a>
            <a
                href="{{ route('ranking.index', ['period' => 'weekly']) }}"
                wire:navigate
                @class([
                    'rounded-lg px-4 py-2 text-sm font-semibold',
                    'bg-zinc-900 text-white dark:bg-white dark:text-zinc-900' => $period === 'weekly',
                    'border border-neutral-300 dark:border-neutral-600' => $period !== 'weekly',
                ])
                @if ($period === 'weekly') aria-current="page" @endif
            >週間</a>
        </nav>

        <section class="rounded-xl border border-neutral-200 p-4 dark:border-neutral-700" aria-labelledby="self-ranking-heading">
            <h2 id="self-ranking-heading" class="text-sm font-semibold text-zinc-600 dark:text-zinc-300">あなたの順位</h2>

            @if ($selfRanking['rank'] === null)
                <p class="mt-2 text-lg font-bold">順位なし・0 pt</p>
                <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-300">この期間には、まだポイントを獲得していません。</p>
            @else
                <p class="mt-2 text-lg font-bold">
                    {{ $selfRanking['rank'] }}位・{{ number_format($selfRanking['total_points']) }} pt
                </p>

                @if ($selfRanking['points_to_next_rank'] === null)
                    <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-300">現在1位です。</p>
                @else
                    <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-300">
                        1つ上の順位まであと{{ number_format($selfRanking['points_to_next_rank']) }}ポイントです。
                    </p>
                @endif
            @endif
        </section>

        @if ($ranking->isEmpty())
            <div class="rounded-xl border border-neutral-200 p-6 text-center text-zinc-600 dark:border-neutral-700 dark:text-zinc-300">
                {{ $period === 'weekly' ? '今週' : 'まだ' }}ポイントを獲得したユーザーはいません。
            </div>
        @else
            <div class="overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700">
                <table class="w-full text-left">
                    <thead class="bg-zinc-100 text-sm text-zinc-700 dark:bg-zinc-900 dark:text-zinc-200">
                        <tr>
                            <th scope="col" class="px-4 py-3">順位</th>
                            <th scope="col" class="px-4 py-3">ユーザー</th>
                            <th scope="col" class="px-4 py-3 text-right">ポイント</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-200 dark:divide-neutral-700">
                        @foreach ($ranking as $entry)
                            <tr>
                                <td class="px-4 py-3 font-semibold">{{ $entry['rank'] }}位</td>
                                <td class="px-4 py-3">{{ $entry['name'] }}</td>
                                <td class="px-4 py-3 text-right font-semibold">{{ number_format($entry['total_points']) }} pt</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</x-layouts.app>
