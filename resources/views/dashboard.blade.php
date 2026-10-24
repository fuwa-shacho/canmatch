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
                    飲みたい1本が、どの自販機にあるか分かる
                </h1>
                <p class="text-sm leading-relaxed text-neutral-600 dark:text-neutral-300">
                    自販機の写真を撮るだけで、AIが商品と価格を読み取ります。みんなで登録した情報から、近くの自販機や安い1本、珍しい商品を探せます。
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

        <section class="grid gap-3 sm:grid-cols-2" aria-label="できること">
            @foreach ([
                ['href' => route('map'), 'icon' => 'map', 'title' => '地図で探す', 'text' => '商品名で絞り込み、安い順に比べられます'],
                ['href' => route('posts.create'), 'icon' => 'camera', 'title' => '自販機を登録する', 'text' => '写真を撮ると、AIが商品と価格を読み取ります'],
                ['href' => route('rare-products.index'), 'icon' => 'star', 'title' => 'レア商品', 'text' => '取り扱いの少ない、珍しい商品を見つけられます'],
                ['href' => route('ranking.index'), 'icon' => 'trophy', 'title' => 'ランキング', 'text' => '登録でたまったポイントの順位を見られます'],
            ] as $item)
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
    </div>
</x-layouts.app>
