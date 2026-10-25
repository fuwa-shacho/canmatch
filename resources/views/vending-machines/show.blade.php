<x-layouts.app>
    @php
        $earnedPoints = session('earned_points');
        $earnedPointItems = is_array($earnedPoints)
            && ($earnedPoints['vending_machine_id'] ?? null) === $vendingMachine->id
            ? ($earnedPoints['items'] ?? [])
            : [];
    @endphp

    <div class="mx-auto flex w-full max-w-4xl flex-col gap-6">
        <div>
            <a href="{{ route('map') }}" class="text-sm text-blue-600 hover:underline dark:text-blue-400">
                ← 地図へ戻る
            </a>
            <h1 class="mt-2 text-2xl font-bold">自販機の詳細</h1>
        </div>

        @if (session('status'))
            <p role="status" class="rounded-xl border border-emerald-300 bg-emerald-50 p-4 text-emerald-950 dark:border-emerald-700 dark:bg-emerald-950/30 dark:text-emerald-100">
                {{ session('status') }}
            </p>
        @endif

        @if ($vendingMachine->hidden_at !== null)
            <section class="rounded-xl border border-amber-300 bg-amber-50 p-4 text-amber-950 dark:border-amber-700 dark:bg-amber-950/30 dark:text-amber-100">
                <h2 class="font-semibold">この自販機は撤去済みとして非表示です</h2>
                <p class="mt-1 text-sm">地図、検索、レア商品、質問の回答には表示されません。データは確認のため残しています。</p>
            </section>
        @else
            <section class="rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
                <h2 class="font-semibold">この自販機がなくなっていた場合</h2>
                <p class="mt-1 text-sm text-neutral-600 dark:text-neutral-300">報告すると地図や検索から非表示になります。報告者は確認のため記録されます。</p>
                <form method="POST" action="{{ route('vending-machines.removal-reports.store', $vendingMachine) }}" class="mt-3">
                    @csrf
                    <button type="submit" class="rounded-lg border border-red-300 px-4 py-2 text-sm font-medium text-red-700 hover:bg-red-50 dark:border-red-700 dark:text-red-300 dark:hover:bg-red-950/30">
                        なくなっていたと報告する
                    </button>
                </form>
            </section>
        @endif

        @if ($earnedPointItems !== [])
            <section
                role="status"
                class="rounded-xl border border-emerald-300 bg-emerald-50 p-4 text-emerald-950 dark:border-emerald-700 dark:bg-emerald-950/30 dark:text-emerald-100"
            >
                <h2 class="text-lg font-semibold">
                    今回 +{{ number_format(collect($earnedPointItems)->sum('points')) }}ポイント獲得しました
                </h2>
                <ul class="mt-2 space-y-1 text-sm">
                    @foreach ($earnedPointItems as $item)
                        <li class="flex justify-between gap-4">
                            <span>
                                {{ match ($item['type']) {
                                    'registration' => '自販機の新規登録',
                                    'update' => '自販機情報の更新',
                                    'rare_bonus' => 'レア商品発見ボーナス',
                                    default => 'ポイント',
                                } }}
                            </span>
                            <span class="font-semibold">+{{ number_format($item['points']) }}ポイント</span>
                        </li>
                    @endforeach
                </ul>
                <a
                    href="{{ route('ranking.index') }}"
                    class="mt-3 inline-flex text-sm font-medium text-emerald-800 underline hover:no-underline dark:text-emerald-200"
                >
                    ランキングを見る
                </a>
            </section>
        @endif

        <section class="rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
            <h2 class="text-lg font-semibold">基本情報</h2>
            <dl class="mt-3 grid gap-3 sm:grid-cols-2">
                <div>
                    <dt class="text-sm text-neutral-500 dark:text-neutral-400">メーカー</dt>
                    <dd>{{ $vendingMachine->maker ?? 'メーカー不明' }}</dd>
                </div>
                <div>
                    <dt class="text-sm text-neutral-500 dark:text-neutral-400">登録者</dt>
                    <dd>{{ $vendingMachine->user?->name ?? '退会済みユーザー' }}</dd>
                </div>
                <div class="sm:col-span-2">
                    <dt class="text-sm text-neutral-500 dark:text-neutral-400">情報の鮮度</dt>
                    <dd
                        @class([
                            'mt-1 inline-flex rounded-full px-3 py-1 text-sm font-medium',
                            'bg-amber-100 text-amber-900 dark:bg-amber-900/40 dark:text-amber-100' => $daysSinceConfirmation >= 30,
                            'bg-emerald-100 text-emerald-900 dark:bg-emerald-900/40 dark:text-emerald-100' => $daysSinceConfirmation < 30,
                        ])
                    >
                        {{ $daysSinceConfirmation }}日前の情報
                        @if ($daysSinceConfirmation >= 30)
                            （古い情報の可能性があります）
                        @endif
                    </dd>
                </div>
            </dl>
        </section>

        <section>
            <h2 class="text-lg font-semibold">販売商品</h2>

            @forelse ($vendingMachine->products as $product)
                @php
                    $pricePer100Ml = $product->pivot->pricePer100Ml($product->volume_ml);
                @endphp
                <article class="mt-3 rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <h3 class="font-semibold">{{ $product->name }}</h3>
                            <p class="mt-1 text-sm text-neutral-600 dark:text-neutral-300">
                                容量: {{ $product->volume_ml !== null ? $product->volume_ml.'ml' : '不明' }}
                            </p>
                            <p class="text-sm text-neutral-600 dark:text-neutral-300">
                                温冷:
                                {{ match ($product->pivot->temperature) {
                                    'hot' => '温かい',
                                    'cold' => '冷たい',
                                    default => '不明',
                                } }}
                            </p>
                            @if ($pricePer100Ml !== null)
                                <p class="text-sm font-medium text-blue-700 dark:text-blue-300">
                                    100mlあたり{{ number_format($pricePer100Ml, $pricePer100Ml === floor($pricePer100Ml) ? 0 : 1) }}円
                                </p>
                            @endif
                        </div>
                        <p class="whitespace-nowrap text-lg font-bold">
                            {{ number_format($product->pivot->price) }}円
                        </p>
                    </div>
                </article>
            @empty
                <p class="mt-3 rounded-xl border border-neutral-200 p-4 text-neutral-600 dark:border-neutral-700 dark:text-neutral-300">
                    商品情報はまだ登録されていません。
                </p>
            @endforelse
        </section>

        <section>
            <h2 class="text-lg font-semibold">場所</h2>
            <div
                id="vending-machine-detail-map"
                data-latitude="{{ $vendingMachine->latitude }}"
                data-longitude="{{ $vendingMachine->longitude }}"
                wire:ignore
                class="mt-3 w-full overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700"
                style="height: 280px; position: relative; z-index: 0;"
            ></div>
        </section>
    </div>

    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" data-navigate-once></script>

    <script data-navigate-once>
        (() => {
            let detailMap = null;

            const destroyDetailMap = () => {
                if (!detailMap) return;

                detailMap.remove();
                detailMap = null;
            };

            const initializeDetailMap = () => {
                if (!window.L) {
                    document
                        .querySelector('script[src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"]')
                        ?.addEventListener('load', initializeDetailMap, { once: true });

                    return;
                }

                const container = document.getElementById('vending-machine-detail-map');

                if (!container) {
                    destroyDetailMap();
                    return;
                }

                if (detailMap?.getContainer() === container) {
                    detailMap.invalidateSize();
                    return;
                }

                destroyDetailMap();

                const latitude = Number(container.dataset.latitude);
                const longitude = Number(container.dataset.longitude);

                if (!Number.isFinite(latitude) || !Number.isFinite(longitude)) return;

                detailMap = L.map(container).setView([latitude, longitude], 17);

                L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    maxZoom: 19,
                    attribution: '&copy; OpenStreetMap contributors'
                }).addTo(detailMap);

                L.marker([latitude, longitude]).addTo(detailMap);
            };

            document.addEventListener('livewire:navigating', destroyDetailMap);
            document.addEventListener('livewire:navigated', initializeDetailMap);
            initializeDetailMap();
        })();
    </script>
</x-layouts.app>
