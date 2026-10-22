<?php

use App\Exceptions\AiReadFailedException;
use App\Models\VendingMachine;
use App\Services\ProductMatcher;
use App\Services\VendingMachineRegistrar;
use App\Services\VendingMachineReader;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use League\Flysystem\UnableToCheckFileExistence;
use League\Flysystem\UnableToDeleteFile;
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

    #[Validate('required|string')]
    public string $maker = 'unknown';

    #[Validate('required|numeric|between:-90,90')]
    public ?float $latitude = null;

    #[Validate('required|numeric|between:-180,180')]
    public ?float $longitude = null;

    public string $step = 'form';

    #[Locked]
    public ?string $savedPath = null;

    #[Locked]
    public ?int $machineId = null;

    #[Locked]
    public array $aiMeta = [];

    public ?string $notice = null;
    public ?string $previewUrl = null;
    public ?string $readError = null;
    public ?string $aiMaker = null;
    public array $rows = [];
    public bool $registered = false;

    public function mount(?int $machine = null): void
    {
        $id = $machine ?? request()->integer('machine');

        if ($id > 0) {
            $existing = VendingMachine::findOrFail($id);

            $this->machineId = $existing->id;
            $this->latitude = (float) $existing->latitude;
            $this->longitude = (float) $existing->longitude;
            $this->maker = $existing->maker ?? 'unknown';
        }
    }

    public function setLocation(float $latitude, float $longitude): void
    {
        // 更新モードでは、既存の自販機の座標を現在地で上書きしない
        if ($this->machineId !== null) {
            return;
        }

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
        $this->aiMeta = [];
        $this->validate();

        if ($this->savedPath !== null && ! $this->removeSavedPhoto()) {
            $this->addError('photo', '前回の写真を削除できませんでした。時間をおいてから再度お試しください。');

            return;
        }

        $disk = config('filesystems.photos_disk');

        try {
            $savedPath = $this->photo->store('photos/'.now()->format('Y/m'), $disk);
            $stored = is_string($savedPath) && Storage::disk($disk)->exists($savedPath);
        } catch (UnableToCheckFileExistence|UnableToSetVisibility|UnableToWriteFile $e) {
            report($e);
            $this->addError('photo', '写真を保存できませんでした。もう一度お試しください。');

            return;
        }

        if (! $stored) {
            $this->addError('photo', '写真を保存できませんでした。もう一度お試しください。');

            return;
        }

        $this->savedPath = $savedPath;

        try {
            $this->previewUrl = Storage::disk($disk)->temporaryUrl($savedPath, now()->addMinutes(10));
        } catch (\RuntimeException $e) {
            report($e);
            $this->removeSavedPhoto();
            $this->addError('photo', '写真のプレビューを準備できませんでした。もう一度お試しください。');

            return;
        }

        $draft = ['is_vending_machine' => true, 'maker' => null, 'products' => []];

        try {
            $read = $reader->read($this->savedPath, $disk);
            $draft = $read['result'];
            $this->aiMeta = collect($read)
                ->only(['raw', 'model', 'prompt_version', 'gateway_log_id'])
                ->all();
        } catch (AiReadFailedException $e) {
            $this->notice = $e->reason === 'rate_limit'
                ? 'AIが混み合っています。少し時間をおいて撮り直すか、手入力で登録してください。'
                : 'AIによる読み取りに失敗しました。手入力で登録してください。';
        }

        if (! $draft['is_vending_machine']) {
            $this->readError = '自動販売機が写っていないようです。撮り直してください。';
            $this->removeSavedPhoto();

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
        $row = $this->rows[$index] ?? null;
        $candidate = $row === null
            ? null
            : collect($this->matchFor((string) ($row['name'] ?? ''))['candidates'])
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
        if ($this->savedPath !== null && ! $this->removeSavedPhoto()) {
            $this->addError('photo', '写真を削除できませんでした。時間をおいてから再度お試しください。');
        }

        $this->step = 'form';
        $this->photo = null;
        $this->rows = [];
        $this->readError = null;
        $this->notice = null;
        $this->aiMeta = [];
        $this->aiMaker = null;
        $this->registered = false;
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

        if ($this->savedPath === null) {
            $this->addError('photo', '写真を撮り直してから登録してください。');

            return;
        }

        $oldPhotoPath = null;
        try {
            $registrar = app(VendingMachineRegistrar::class);

            if ($this->machineId !== null) {
                $machine = VendingMachine::query()->findOrFail($this->machineId);
                $oldPhotoPath = $machine->photo_path;
                $machine = $registrar->update($machine, $this->rows, $this->savedPath);
            } else {
                abort_if($this->latitude === null || $this->longitude === null, 422);

                $machine = $registrar->register([
                    'latitude' => $this->latitude,
                    'longitude' => $this->longitude,
                    'maker' => $this->maker === 'unknown' ? null : $this->maker,
                    'photo_path' => $this->savedPath,
                    'user_id' => auth()->id(),
                ], $this->rows);
            }
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->addError('rows', $e->errors()['rows'][0] ?? '入力内容を確認してください。');

            return;
        } catch (\Illuminate\Database\QueryException $e) {
            report($e);
            $this->addError('rows', '保存に失敗しました。もう一度試してください。');

            if (! $this->removeSavedPhoto()) {
                $this->addError('photo', '写真の削除にも失敗しました。サポートへお問い合わせください。');
            }

            return;
        }

        $this->registered = true;

        if ($oldPhotoPath !== null && $oldPhotoPath !== $this->savedPath && ! $this->deletePhoto($oldPhotoPath)) {
            session()->flash('warning', '更新しましたが、以前の写真を削除できませんでした。');
        }

        $this->redirectRoute('vending-machines.show', $machine, navigate: true);
    }

    protected function removeSavedPhoto(): bool
    {
        if ($this->savedPath === null) {
            return true;
        }

        if (! $this->deletePhoto($this->savedPath)) {
            return false;
        }

        $this->savedPath = null;
        $this->previewUrl = null;

        return true;
    }

    protected function deletePhoto(string $path): bool
    {
        $disk = Storage::disk(config('filesystems.photos_disk'));

        try {
            if (! $disk->exists($path)) {
                return true;
            }

            if ($disk->delete($path)) {
                return true;
            }
        } catch (UnableToCheckFileExistence|UnableToDeleteFile $e) {
            report($e);

            return false;
        }

        report(new \RuntimeException('A stored vending-machine photo could not be deleted.'));

        return false;
    }
}; ?>

<section class="mx-auto w-full max-w-3xl p-4">
    @if ($step === 'form')
        <flux:heading size="lg">{{ $machineId ? '情報を更新する' : '写真を投稿する' }}</flux:heading>

        <form wire:submit="save" class="mt-6 flex max-w-md flex-col gap-6">
            <div
                x-data="{
                    uploading: false,
                    async resize(event) {
                        const file = event.target.files[0];
                        if (!file) return;
                        const bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' });
                        const scale = Math.min(1, 2000 / Math.max(bitmap.width, bitmap.height));
                        const canvas = document.createElement('canvas');
                        canvas.width = Math.round(bitmap.width * scale);
                        canvas.height = Math.round(bitmap.height * scale);
                        canvas.getContext('2d').drawImage(bitmap, 0, 0, canvas.width, canvas.height);
                        const blob = await new Promise(r => canvas.toBlob(r, 'image/jpeg', 0.92));
                        const resized = new File([blob], 'photo.jpg', { type: 'image/jpeg' });
                        this.uploading = true;
                        $wire.upload('photo', resized, () => { this.uploading = false }, () => { this.uploading = false });
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
                @error('photo') <flux:text class="text-red-500">{{ $message }}</flux:text> @enderror
            </div>
        

        @unless ($machineId)
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

            <div
                x-data="{ status: 'loading', message: '現在地を取得しています...' }"
                x-init="
                    if (!navigator.geolocation) {
                        status = 'error';
                        message = 'この端末では位置情報が使えません';
                    } else {
                        navigator.geolocation.getCurrentPosition(
                            (pos) => {
                                status = 'ok';
                                message = '現在地を取得しました';
                                $wire.setLocation(pos.coords.latitude, pos.coords.longitude);
                            },
                            () => {
                                status = 'error';
                                message = '現在地を取得できませんでした。位置情報の許可を確認してください';
                            },
                            { enableHighAccuracy: true, timeout: 15000 }
                        );
                    }
                "
            >
                <input type="hidden" name="latitude" wire:model="latitude">
                <input type="hidden" name="longitude" wire:model="longitude">
                <p
                    x-text="message"
                    class="text-sm"
                    :class="status === 'error' ? 'text-red-500' : (status === 'ok' ? 'text-green-600' : 'text-zinc-500')"
                ></p>
            </div>
        @endunless

            <flux:button type="submit" variant="primary" class="w-full"
                :disabled="$latitude === null || $longitude === null"
                wire:loading.attr="disabled" wire:target="save,photo">
                <span wire:loading.remove wire:target="save">送信する</span>
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
                @if ($previewUrl)
                    <img src="{{ $previewUrl }}" alt="撮った写真" class="mt-2 w-full rounded-lg">
                @else
                    <flux:text class="mt-2 text-red-500">写真を撮り直してから登録してください。</flux:text>
                @endif
                @if ($aiMaker)
                    <flux:text class="mt-2">AIが読んだメーカー: {{ $aiMaker }}</flux:text>
                @endif
            </div>

            <div class="flex flex-col gap-4">
                <flux:heading size="md">読み取り結果</flux:heading>
                @if ($notice)
                    <flux:text class="text-amber-600">{{ $notice }}</flux:text>
                @endif

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
                    </div>
                @endforeach

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

                <flux:button wire:click="addRow">行を追加する</flux:button>

                <flux:button variant="primary" wire:click="register" wire:loading.attr="disabled" wire:target="register">
                    登録
                </flux:button>
                <flux:button variant="ghost" wire:click="backToForm">撮り直す</flux:button>


            </div>
        </div>
    @endif
</section>