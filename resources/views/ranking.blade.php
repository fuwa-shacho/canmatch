<x-layouts.app>
    <div class="mx-auto flex w-full max-w-3xl flex-col gap-6">
        <div>
            <h1 class="text-2xl font-bold">総合ランキング</h1>
            <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-300">
                これまでに獲得したポイントの上位20人です。
            </p>
        </div>

        @if ($ranking->isEmpty())
            <div class="rounded-xl border border-neutral-200 p-6 text-center text-zinc-600 dark:border-neutral-700 dark:text-zinc-300">
                まだポイントを獲得したユーザーはいません。
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
