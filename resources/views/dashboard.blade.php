<x-layouts.app :title="__('ダッシュボード')">
    <div class="mx-auto flex w-full max-w-4xl flex-col gap-6">
        <section class="flex flex-col items-center gap-4 rounded-2xl border border-neutral-200 bg-white px-6 py-8 text-center sm:flex-row sm:gap-8 sm:text-left dark:border-neutral-700 dark:bg-neutral-900">
            <img
                src="{{ asset('images/canmatch-logo.png') }}"
                alt="CanMatch"
                class="w-40 shrink-0 sm:w-48"
            />
            <div class="flex flex-col gap-2">
                <p class="text-sm font-medium text-neutral-500 dark:text-neutral-400">
                    {{ auth()->user()->name }} さん、ようこそ
                </p>
                <h1 class="text-2xl font-bold leading-snug">
                    <span class="inline-block">あなたの「飲みたい」に</span><span class="inline-block">マッチ！</span>
                </h1>
                <p class="text-sm leading-relaxed text-neutral-600 dark:text-neutral-300">
                    身近な自販機を写真に撮って、見つけた飲み物をシェアしましょう！<br class="hidden sm:inline" />近い、安い、珍しい一本を、あなたのために探します！
                </p>
            </div>
        </section>

        <section class="grid grid-cols-3 gap-3" aria-label="登録状況">
            <div class="rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
                <p class="text-xs text-neutral-500 dark:text-neutral-400">登録された自販機</p>
                <p class="mt-1 text-2xl font-bold" style="color: #007FFF">{{ number_format($machineCount) }}<span class="ml-1 text-sm font-medium text-neutral-500">台</span></p>
            </div>
            <div class="rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
                <p class="text-xs text-neutral-500 dark:text-neutral-400">見つかった商品</p>
                <p class="mt-1 text-2xl font-bold" style="color: #FF315A">{{ number_format($productCount) }}<span class="ml-1 text-sm font-medium text-neutral-500">種類</span></p>
            </div>
            <div class="rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
                <p class="text-xs text-neutral-500 dark:text-neutral-400">あなたの登録</p>
                <p class="mt-1 text-2xl font-bold" style="color: #F5B800">{{ number_format($myMachineCount) }}<span class="ml-1 text-sm font-medium text-neutral-500">台</span></p>
            </div>
        </section>

        <div class="grid gap-6 sm:grid-cols-2">
            @foreach ([
                [
                    'heading' => '見つけて、シェアする',
                    'lead' => '登録するほどポイントがたまります',
                    'items' => [
                        ['href' => route('posts.create'), 'icon' => 'camera', 'title' => '自販機を登録する', 'text' => '写真を撮るだけ。商品と値段はAIが読み取ります'],
                        ['href' => route('ranking.index'), 'icon' => 'trophy', 'title' => 'ランキング', 'text' => '今週いちばん見つけたのは誰？'],
                    ],
                ],
                [
                    'heading' => '探して、比べる',
                    'lead' => 'みんなが見つけた一本から探せます',
                    'items' => [
                        ['href' => route('map'), 'icon' => 'map', 'title' => '地図で探す', 'text' => '飲みたい商品の名前で、近くて安い自販機を探そう'],
                        ['href' => route('rare-products.index'), 'icon' => 'star', 'title' => 'レア商品', 'text' => 'なかなか見かけない一本は、ここにあります'],
                    ],
                ],
            ] as $group)
                <section class="flex flex-col gap-3" aria-label="{{ $group['heading'] }}">
                    <div>
                        <h2 class="text-lg font-bold">{{ $group['heading'] }}</h2>
                        <p class="text-sm text-neutral-500 dark:text-neutral-400">{{ $group['lead'] }}</p>
                    </div>
                    @foreach ($group['items'] as $item)
                        <a
                            href="{{ $item['href'] }}"
                            wire:navigate
                            class="group flex items-start gap-4 rounded-xl border border-neutral-200 p-4 transition hover:border-neutral-400 hover:bg-neutral-50 dark:border-neutral-700 dark:hover:border-neutral-500 dark:hover:bg-neutral-800"
                        >
                            <span class="flex size-10 shrink-0 items-center justify-center rounded-full bg-neutral-100 dark:bg-neutral-800">
                                <flux:icon :name="$item['icon']" class="size-5" />
                            </span>
                            <span class="flex flex-col gap-1">
                                <span class="font-semibold">{{ $item['title'] }}</span>
                                <span class="text-sm text-neutral-600 dark:text-neutral-300">{{ $item['text'] }}</span>
                            </span>
                        </a>
                    @endforeach
                </section>
            @endforeach
        </div>
    </div>
</x-layouts.app>
