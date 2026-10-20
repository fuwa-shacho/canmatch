<?php

use App\Exceptions\AiReadFailedException;
use App\Services\ProductMatcher;
use App\Services\VendingMachineRegistrar;
use App\Services\VendingMachineReader;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToCheckFileExistence;
use League\Flysystem\UnableToSetVisibility;
use League\Flysystem\UnableToWriteFile;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new #[Layout('components.layouts.app')] class extends Component {
    use WithFileUploads;

    #[Validate('required|image|max:2048')]
    public $photo;

    #[Validate('required|string|in:unknown,dydo,suntory,cocacola,asahi,kirin,itoen,other')]
    public string $maker = 'unknown';

    #[Validate('required|numeric|between:-90,90')]
    public ?float $latitude = null;

    #[Validate('required|numeric|between:-180,180')]
    public ?float $longitude = null;

    public string $step = 'form';

    #[Locked]
    public ?string $savedPath = null;

    public ?string $notice = null;
    public ?string $previewUrl = null;
    public ?string $readError = null;
    public ?string $aiMaker = null;
    public array $rows = [];
    public bool $registered = false;

    public function setLocation(float $latitude, float $longitude): void
    {
        $this->latitude = $latitude;
        $this->longitude = $longitude;
    }

    protected function messages(): array
    {
        return [
            'photo.required' => '写真を選んでください',
            'photo.image' => '画像ファイルを選んでください',
            'photo.max' => '写真は2MB以下にしてください',
        ];
    }

    public function save(VendingMachineReader $reader): void
    {
        $this->readError = null;
        $this->notice = null;
        $this->aiMaker = null;
        $this->rows = [];
        $this->registered = false;
        $this->validate();

        $disk = config('filesystems.photos_disk');

        try {
            $savedPath = $this->photo->store('photos/'.now()->format('Y/m'), $disk);
            $stored = $savedPath !== false && Storage::disk($disk)->exists($savedPath);
        } catch (UnableToCheckFileExistence|UnableToSetVisibility|UnableToWriteFile) {
            $this->addError('photo', '写真を保存できませんでした。もう一度お試しください。');

            return;
        }

        if (! $stored) {
            $this->addError('photo', '写真を保存できませんでした。もう一度お試しください。');

            return;
        }

        $this->savedPath = $savedPath;
        $this->previewUrl = Storage::disk($disk)->temporaryUrl($this->savedPath, now()->addMinutes(10));

        $draft = ['is_vending_machine' => true, 'maker' => null, 'products' => []];

        try {
            $read = $reader->read($this->savedPath, $disk);
            $draft = $read['result'];
        } catch (AiReadFailedException $e) {
            $this->notice = $e->reason === 'rate_limit'
                ? 'AIが混み合っています。少し時間をおいて撮り直すか、手入力で登録してください。'
                : 'AIによる読み取りに失敗しました。手入力で登録してください。';
        }

        if (! $draft['is_vending_machine']) {
            $this->readError = $this->deleteSavedPhoto()
                ? '自動販売機が写っていないようです。撮り直してください。'
                : '自動販売機が写っていないようです。保存した写真を削除できませんでした。もう一度お試しください。';
            $this->photo = null;

            return;
        }

        $this->aiMaker = $draft['maker'];
        $this->rows = collect($draft['products'])
            ->map(fn ($p) => $this->newRow([
                'name' => $p['name'] ?? '',
                'price' => $p['price'],
                'temperature' => $p['temperature'],
            ]))
            ->values()
            ->all();

        if ($this->rows === []) {
            $this->addRow();
        }

        $this->step = 'review';
    }

    public function addRow(): void
    {
        $this->rows[] = $this->newRow();
    }

    protected function newRow(array $attrs = []): array
    {
        $row = array_merge([
            'id' => (string) Str::uuid(),
            'name' => '',
            'price' => null,
            'temperature' => null,
        ], $attrs);

        return array_merge($row, $this->matchFor((string) $row['name']));
    }

    protected function matchFor(string $name): array
    {
        $result = app(ProductMatcher::class)->match($name);
        $product = $result['product'];

        return [
            'product_id' => $product?->id,
            'product_name' => $product?->name,
            'volume_ml' => $product?->volume_ml,
            'link' => $product ? 'auto' : 'new', // auto | manual | new
            'candidates' => $result['candidates']->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'volume_ml' => $c->volume_ml,
            ])->all(),
        ];
    }

    // 商品名を直したら照合し直す(紐づけは名前に対するものなので、手動選択もリセットされる)
    public function updatedRows($value, string $key): void
    {
        [$index, $field] = array_pad(explode('.', $key, 2), 2, null);

        if ($field !== 'name' || ! isset($this->rows[$index])) {
            return;
        }

        $this->rows[$index] = array_merge(
            $this->rows[$index],
            $this->matchFor((string) $this->rows[$index]['name']),
        );
    }

    public function selectCandidate(int $index, int $productId): void
    {
        if (! isset($this->rows[$index])) {
            return;
        }

        $candidate = collect($this->matchFor((string) ($this->rows[$index]['name'] ?? ''))['candidates'])
            ->firstWhere('id', $productId);

        // クライアントから来たIDを信用せず、サーバーが出した候補の中にあるものだけ受け付ける
        if ($candidate === null) {
            return;
        }

        $this->rows[$index]['product_id'] = $candidate['id'];
        $this->rows[$index]['product_name'] = $candidate['name'];
        $this->rows[$index]['volume_ml'] = $candidate['volume_ml'];
        $this->rows[$index]['link'] = 'manual';
    }

    public function unlinkProduct(int $index): void
    {
        if (! isset($this->rows[$index])) {
            return;
        }

        $this->rows[$index]['product_id'] = null;
        $this->rows[$index]['product_name'] = null;
        $this->rows[$index]['volume_ml'] = null;
        $this->rows[$index]['link'] = 'new';
    }

    public function removeRow(int $index): void
    {
        unset($this->rows[$index]);
        $this->rows = array_values($this->rows);
    }

    public function backToForm(): void
    {
        if (! $this->deleteSavedPhoto()) {
            return;
        }

        $this->step = 'form';
        $this->photo = null;
        $this->rows = [];
        $this->readError = null;
        $this->notice = null;
        $this->aiMaker = null;
        $this->registered = false;
    }

    private function deleteSavedPhoto(): bool
    {
        if ($this->savedPath === null) {
            return true;
        }

        try {
            $deleted = Storage::disk(config('filesystems.photos_disk'))->delete($this->savedPath);
        } catch (UnableToDeleteFile) {
            $deleted = false;
        }

        if (! $deleted) {
            $this->addError('photo', '保存した写真を削除できませんでした。もう一度お試しください。');

            return false;
        }

        $this->savedPath = null;
        $this->previewUrl = null;

        return true;
    }

    public function register(): void
    {
        if ($this->registered) {
            return; // 二重送信の防止
        }

        $this->validate([
            'rows' => 'required|array|min:1',
            'rows.*.name' => 'required|string|max:100',
            'rows.*.price' => 'required|integer|min:0|max:100000',
            'rows.*.temperature' => 'required|in:hot,cold',
            'rows.*.product_id' => [
                'nullable',
                'integer',
                'exists:products,id',
                function ($attribute, $value, $fail): void {
                    if ($value === null) {
                        return;
                    }

                    $index = (int) explode('.', $attribute)[1];
                    $match = $this->matchFor((string) ($this->rows[$index]['name'] ?? ''));
                    $validProductIds = array_column($match['candidates'], 'id');

                    if ($match['product_id'] !== null) {
                        $validProductIds[] = $match['product_id'];
                    }

                    if (! in_array((int) $value, $validProductIds, true)) {
                        $fail('選択した商品を確認できません。候補から選び直してください。');
                    }
                },
            ],
        ], [
            'rows.required' => '商品を1つ以上入力してください',
            'rows.min' => '商品を1つ以上入力してください',
            'rows.*.name.required' => '商品名を入力してください',
            'rows.*.name.max' => '商品名は100文字以内にしてください',
            'rows.*.price.required' => '価格を入力してください',
            'rows.*.price.integer' => '価格は数字で入力してください',
            'rows.*.price.min' => '価格は0円以上にしてください',
            'rows.*.price.max' => '価格が大きすぎます',
            'rows.*.temperature.required' => '温冷を選んでください',
        ]);

        abort_if(
            $this->savedPath === null || $this->latitude === null || $this->longitude === null,
            422
        );

        try {
            app(VendingMachineRegistrar::class)->register([
                'latitude' => $this->latitude,
                'longitude' => $this->longitude,
                'maker' => $this->maker === 'unknown' ? null : $this->maker,
                'photo_path' => $this->savedPath,
                'user_id' => auth()->id(),
            ], $this->rows);
        } catch (\Illuminate\Database\QueryException $e) {
            report($e);
            $this->addError('rows', '保存に失敗しました。もう一度試してください。');

            return;
        }

        $this->registered = true;

        session()->flash('status', '自販機を登録しました');

        // 詳細ページができるまでの仮の遷移先。できたら machines.show などに差し替える
        $this->redirectRoute('map', navigate: true);
    }
}; ?>

<section class="mx-auto w-full max-w-3xl p-4">
    @if ($step === 'form')
        <flux:heading size="lg">写真を投稿する</flux:heading>

        <form
            wire:submit="save"
            class="mt-6 flex max-w-md flex-col gap-6"
            x-data="{ locationStatus: 'loading', locationMessage: '現在地を取得しています...' }"
            x-init="
                if (!navigator.geolocation) {
                    locationStatus = 'error';
                    locationMessage = 'この端末では位置情報が使えません';
                } else {
                    navigator.geolocation.getCurrentPosition(
                        async (pos) => {
                            try {
                                await $wire.setLocation(pos.coords.latitude, pos.coords.longitude);
                                locationStatus = 'ok';
                                locationMessage = '現在地を取得しました';
                            } catch (e) {
                                locationStatus = 'error';
                                locationMessage = '現在地を送信できませんでした。もう一度お試しください';
                            }
                        },
                        () => {
                            locationStatus = 'error';
                            locationMessage = '現在地を取得できませんでした。位置情報の許可を確認してください';
                        },
                        { enableHighAccuracy: true, timeout: 15000 }
                    );
                }
            "
        >
            <div
                x-data="{
                    uploading: false,
                    error: null,
                    async resize(event) {
                        const file = event.target.files[0];
                        if (!file) return;
                        this.error = null;

                        let bitmap;
                        try {
                            bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' });
                        } catch (e) {
                            this.fail(event, 'この写真は読み込めませんでした。別の写真を選ぶか、撮り直してください。');
                            return;
                        }

                        let blob = null;
                        try {
                            const scale = Math.min(1, 2000 / Math.max(bitmap.width, bitmap.height));
                            const canvas = document.createElement('canvas');
                            canvas.width = Math.round(bitmap.width * scale);
                            canvas.height = Math.round(bitmap.height * scale);
                            canvas.getContext('2d').drawImage(bitmap, 0, 0, canvas.width, canvas.height);
                            blob = await new Promise(r => canvas.toBlob(r, 'image/jpeg', 0.92));
                        } catch (e) {
                            blob = null;
                        } finally {
                            bitmap.close();
                        }

                        if (!blob) {
                            this.fail(event, '写真の変換に失敗しました。もう一度お試しください。');
                            return;
                        }
                        if (blob.size > 2 * 1024 * 1024) {
                            this.fail(event, '写真の容量が大きすぎて送信できません。別の写真を選ぶか、撮り直してください。');
                            return;
                        }

                        const resized = new File([blob], 'photo.jpg', { type: 'image/jpeg' });
                        this.uploading = true;
                        $wire.upload(
                            'photo',
                            resized,
                            () => { this.uploading = false },
                            () => {
                                this.uploading = false;
                                this.error = '写真のアップロードに失敗しました。もう一度お試しください。';
                            }
                        );
                    },
                    fail(event, message) {
                        this.error = message;
                        event.target.value = '';
                        $wire.$set('photo', null);
                    }
                }"
            >
                <flux:label>写真</flux:label>
                <input
                    type="file"
                    accept="image/*"
                    capture="environment"
                    @change="resize($event)"
                    class="mt-2 block w-full text-sm"
                />
                <flux:text x-show="error" x-text="error" x-cloak class="text-red-500"></flux:text>
                @error('photo') <flux:text class="text-red-500">{{ $message }}</flux:text> @enderror
            </div>

            <flux:select wire:model="maker" label="メーカー">
                <flux:select.option value="unknown">不明</flux:select.option>
                <flux:select.option value="dydo">ダイドードリンコ</flux:select.option>
                <flux:select.option value="suntory">サントリー</flux:select.option>
                <flux:select.option value="cocacola">コカ・コーラ</flux:select.option>
                <flux:select.option value="asahi">アサヒ</flux:select.option>
                <flux:select.option value="kirin">キリン</flux:select.option>
                <flux:select.option value="itoen">伊藤園</flux:select.option>
                <flux:select.option value="other">その他</flux:select.option>
            </flux:select>

            <div>
                <input type="hidden" name="latitude" wire:model="latitude">
                <input type="hidden" name="longitude" wire:model="longitude">
                <p
                    x-text="locationMessage"
                    class="text-sm"
                    :class="locationStatus === 'error' ? 'text-red-500' : (locationStatus === 'ok' ? 'text-green-600' : 'text-zinc-500')"
                ></p>
            </div>

            <flux:button type="submit" variant="primary" class="w-full"
                :disabled="$latitude === null || $longitude === null"
                x-bind:disabled="locationStatus !== 'ok' || $wire.latitude === null || $wire.longitude === null"
                wire:loading.attr="disabled" wire:target="save,photo">
                <span wire:loading.remove wire:target="save,photo">送信する</span>
                <span wire:loading wire:target="photo">写真を送信中...</span>
                <span wire:loading wire:target="save">読み取り中...</span>
            </flux:button>

            @if ($readError)
                <flux:text class="text-red-500">{{ $readError }}</flux:text>
            @endif
        </form>
    @else
        <flux:heading size="lg">読み取り結果を確認する</flux:heading>
        <flux:text class="mt-1">写真と見比べて、違うところを直してください。</flux:text>

        <div class="mt-6 grid gap-6 md:grid-cols-2">
            <div>
                <flux:heading size="md">撮った写真</flux:heading>
                <img src="{{ $previewUrl }}" alt="撮った写真" class="mt-2 w-full rounded-lg">
                @if ($aiMaker)
                    <flux:text class="mt-2">AIが読んだメーカー: {{ $aiMaker }}</flux:text>
                @endif
            </div>

            <div class="flex flex-col gap-4">
                <flux:heading size="md">読み取り結果</flux:heading>
                @if ($notice)
                    <flux:text class="text-amber-600">{{ $notice }}</flux:text>
                @endif

                @error('photo') <flux:text class="text-red-500">{{ $message }}</flux:text> @enderror
                @error('rows') <flux:text class="text-red-500">{{ $message }}</flux:text> @enderror

                @foreach ($rows as $i => $row)
                    <div wire:key="row-{{ $row['id'] }}" class="rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                        <flux:input wire:model="rows.{{ $i }}.name" label="商品名" />
                        <div class="mt-2 grid grid-cols-2 gap-2">
                            <flux:input wire:model="rows.{{ $i }}.price" label="価格(円)" type="number" inputmode="numeric" min="0" />
                            <flux:select wire:model="rows.{{ $i }}.temperature" label="温冷" placeholder="選んでください">
                                <flux:select.option value="cold">冷たい</flux:select.option>
                                <flux:select.option value="hot">温かい</flux:select.option>
                            </flux:select>
                        </div>
                        <flux:button size="sm" variant="ghost" class="mt-2" wire:click="removeRow({{ $i }})">この行を削除</flux:button>
                        <div class="mt-2 flex flex-col gap-1">
                            @if (in_array($row['link'], ['auto', 'manual'], true))
                                <flux:text>
                                    紐づけ: {{ $row['product_name'] }}
                                    ({{ $row['volume_ml'] ? $row['volume_ml'].'ml' : '容量未登録' }})
                                </flux:text>
                                <div>
                                    <flux:button size="xs" variant="ghost" wire:click="unlinkProduct({{ $i }})">新しい商品にする</flux:button>
                                </div>
                            @else
                                @if ($row['candidates'])
                                    <flux:text>近い商品があります。選ぶと紐づけます。</flux:text>
                                    <div class="flex flex-wrap gap-2">
                                        @foreach ($row['candidates'] as $c)
                                            <flux:button size="xs" wire:click="selectCandidate({{ $i }}, {{ $c['id'] }})">
                                                {{ $c['name'] }}({{ $c['volume_ml'] ? $c['volume_ml'].'ml' : '容量未登録' }})
                                            </flux:button>
                                        @endforeach
                                    </div>
                                @endif
                                <flux:text>選ばない場合は新しい商品として登録されます(容量は空)。</flux:text>
                            @endif
                        </div>
                    </div>
                @endforeach

                <flux:button wire:click="addRow">行を追加する</flux:button>

                <flux:button variant="primary" wire:click="register" wire:loading.attr="disabled" wire:target="register">
                    登録
                </flux:button>
                <flux:button variant="ghost" wire:click="backToForm">撮り直す</flux:button>


            </div>
        </div>
    @endif
</section>