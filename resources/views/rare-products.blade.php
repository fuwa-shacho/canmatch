<x-layouts.app :title="__('レア商品')">
    <section class="mx-auto w-full max-w-4xl p-4 sm:p-6">
        <flux:heading size="xl">レア商品</flux:heading>
        <flux:text class="mt-2">
            販売している自販機が{{ $maximumMachineCount }}台以下の商品を表示しています。
        </flux:text>

        @if ($products->isEmpty())
            <div class="mt-6 rounded-xl border border-zinc-200 p-6 dark:border-zinc-700">
                <flux:heading size="md">レア商品はまだありません</flux:heading>
                <flux:text class="mt-2">自販機の登録が増えると、ここにレア商品が表示されます。</flux:text>
            </div>
        @else
            <div class="mt-6 grid gap-4">
                @foreach ($products as $product)
                    <article class="rounded-xl border border-zinc-200 p-5 dark:border-zinc-700">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <flux:heading size="lg">{{ $product->name }}</flux:heading>
                                <flux:text class="mt-1">
                                    {{ $product->maker ?? 'メーカー不明' }}
                                    ／
                                    {{ $product->volume_ml !== null ? $product->volume_ml.'ml' : '容量不明' }}
                                </flux:text>
                            </div>
                            <flux:badge color="amber">{{ $product->vending_machines_count }}台</flux:badge>
                        </div>

                        <div class="mt-4">
                            <flux:heading size="sm">買える自販機</flux:heading>
                            <ul class="mt-2 grid gap-2 sm:grid-cols-2">
                                @foreach ($product->vendingMachines as $machine)
                                    <li class="rounded-lg bg-zinc-50 px-3 py-2 text-sm dark:bg-zinc-800">
                                        自販機 #{{ $machine->id }}
                                        <span class="text-zinc-500 dark:text-zinc-400">
                                            （{{ $machine->maker ?? 'メーカー不明' }}）
                                        </span>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    </article>
                @endforeach
            </div>
        @endif
    </section>
</x-layouts.app>
