<?php

use App\Models\VendingMachine;
use App\Services\Rag\QuestionDesk;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;

new #[Layout('components.layouts.app')] class extends Component
{
    /** 質問の例(押すとそのまま質問する) */
    public const EXAMPLES = [
        '甘くない炭酸を安く買える場所は?',
        '冷たいお茶はある?',
        '温かいコーヒーが飲みたい',
    ];

    #[Validate('required|string|max:200', as: '質問')]
    public string $question = '';

    /** 最後に質問した文章(回答の上に表示する) */
    public string $asked = '';

    /** QuestionDesk::ask() の結果 */
    public ?array $result = null;

    /** 例のボタンを押したとき(番号で受け取り、文章は画面から受け取らない) */
    public function askExample(int $index): void
    {
        if (! isset(self::EXAMPLES[$index])) {
            return;
        }

        $this->question = self::EXAMPLES[$index];
        $this->ask();
    }

    public function ask(): void
    {
        $this->question = trim($this->question);
        $this->validate();

        $this->asked = $this->question;
        $this->result = app(QuestionDesk::class)->ask(auth()->user(), $this->asked);
    }

    public function with(): array
    {
        $rows = $this->result['rows'] ?? [];

        $machines = VendingMachine::query()
            ->whereIn('id', array_values(array_unique(array_column($rows, 'machine_id'))))
            ->get(['id', 'latitude', 'longitude', 'maker'])
            ->keyBy('id');

        $markers = $machines->map(fn (VendingMachine $machine) => [
            'lat' => (float) $machine->latitude,
            'lng' => (float) $machine->longitude,
            'label' => "自販機 #{$machine->id}",
            'url' => url("/vending-machines/{$machine->id}"),
        ])->values()->all();

        return [
            'examples' => self::EXAMPLES,
            'remaining' => $this->result['remaining'] ?? QuestionDesk::remainingToday(auth()->user()),
            'limit' => QuestionDesk::dailyLimit(),
            'rows' => $rows,
            'machines' => $machines,
            'markers' => $markers,
        ];
    }

    public function freshness(int $daysAgo): string
    {
        return match (true) {
            $daysAgo <= 0 => '今日の情報',
            $daysAgo === 1 => '昨日の情報',
            default => "{$daysAgo}日前の情報",
        };
    }

    public function temperatureLabel(?string $temperature): string
    {
        return match ($temperature) {
            'hot' => '温かい',
            'cold' => '冷たい',
            default => '温冷不明',
        };
    }
}; ?>

<section class="mx-auto flex w-full max-w-3xl flex-col gap-6">
    @assets
        <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
        <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
        <script>
            // 根拠の自販機を小さな地図に表示する(質問ページ用)
            window.canmatchEvidenceMap = (component) => {
                if (!window.L || !component.markers.length) return;

                const map = L.map(component.$el);
                L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    maxZoom: 19,
                    attribution: '&copy; OpenStreetMap contributors',
                }).addTo(map);

                const points = [];
                component.markers.forEach((m) => {
                    const link = document.createElement('a');
                    link.href = m.url;
                    link.textContent = m.label;
                    L.marker([m.lat, m.lng]).addTo(map).bindPopup(link);
                    points.push([m.lat, m.lng]);
                });

                if (points.length === 1) {
                    map.setView(points[0], 17);
                } else {
                    map.fitBounds(points, { padding: [24, 24], maxZoom: 17 });
                }

                component.map = map;
            };
        </script>
    @endassets

    <div>
        <flux:heading size="xl">質問して探す</flux:heading>
        <flux:text class="mt-2">
            登録された自販機のデータから、条件に合う飲み物と売っている場所を探します。
        </flux:text>
    </div>

    <form wire:submit="ask()" class="flex flex-col gap-3">
        <flux:input wire:model="question" label="質問" placeholder="例:甘くない炭酸を安く買える場所は?" maxlength="200" />

        <div>
            <flux:text class="text-sm">質問の例(押すとそのまま質問します)</flux:text>
            <div class="mt-2 flex flex-wrap gap-2">
                @foreach ($examples as $index => $example)
                    <flux:button size="sm" variant="filled" type="button" wire:click="askExample({{ $index }})">
                        {{ $example }}
                    </flux:button>
                @endforeach
            </div>
        </div>

        <flux:text class="text-sm">今日はあと {{ $remaining }} 回質問できます(1日 {{ $limit }} 回まで)。</flux:text>

        <flux:button type="submit" variant="primary" class="w-full" wire:loading.attr="disabled" wire:target="ask,askExample">
            <span wire:loading.remove wire:target="ask,askExample">質問する</span>
            <span wire:loading wire:target="ask,askExample">探しています...</span>
        </flux:button>
    </form>

    @if ($result !== null)
        <div class="flex flex-col gap-4" data-status="{{ $result['status'] }}">
            <div class="rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
                <flux:text class="text-sm">質問:{{ $asked }}</flux:text>

                @if ($result['notice'] !== null)
                    <p class="mt-3 rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900">
                        {{ $result['notice'] }}
                    </p>
                @else
                    <div class="mt-3 flex flex-col gap-1 font-semibold">
                        @foreach (explode("\n", $result['answer']) as $line)
                            <p>{{ $line }}</p>
                        @endforeach
                    </div>
                @endif
            </div>

            @if ($rows !== [])
                <div>
                    <flux:heading size="lg">根拠</flux:heading>

                    <ul class="mt-3 flex flex-col gap-3">
                        @foreach ($rows as $row)
                            <li class="rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
                                <div class="flex items-start justify-between gap-4">
                                    <div>
                                        <p class="font-semibold">{{ $row['product_name'] }}</p>
                                        <p class="mt-1 text-sm text-neutral-600 dark:text-neutral-300">
                                            {{ $this->temperatureLabel($row['temperature']) }}
                                            @if ($row['volume_ml'] !== null)
                                                ・{{ $row['volume_ml'] }}ml
                                            @endif
                                            ・{{ $this->freshness($row['days_ago']) }}({{ $row['recorded_at'] }})
                                        </p>
                                        <a href="{{ url('/vending-machines/'.$row['machine_id']) }}" class="mt-1 inline-block text-sm text-blue-600 hover:underline dark:text-blue-400">
                                            自販機 #{{ $row['machine_id'] }}
                                            @if ($machines->get($row['machine_id'])?->maker)
                                                ({{ $machines->get($row['machine_id'])->maker }})
                                            @endif
                                            の詳細
                                        </a>
                                    </div>
                                    <p class="whitespace-nowrap text-lg font-bold">{{ number_format($row['price']) }}円</p>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </div>

                @if ($markers !== [])
                    <div
                        wire:key="evidence-map-{{ md5(json_encode($markers)) }}"
                        wire:ignore
                        x-data="{ markers: @js($markers), map: null, init() { window.canmatchEvidenceMap(this) }, destroy() { this.map?.remove() } }"
                        class="w-full overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700"
                        style="height: 260px; position: relative; z-index: 0;"
                        aria-label="根拠の自販機の地図"
                    ></div>
                @endif
            @endif

            @if (in_array($result['status'], ['answered', 'no_match', 'fallback'], true))
                <flux:text class="text-xs">
                    回答は、登録されたデータから AI が選んだ内容です。価格は変わっている場合があります。
                </flux:text>
            @endif
        </div>
    @endif
</section>
