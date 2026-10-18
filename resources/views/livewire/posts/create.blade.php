<?php

use App\Exceptions\AiReadFailedException;
use App\Services\VendingMachineReader;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
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
    public ?string $savedPath = null;
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
        $this->validate();

        $disk = config('filesystems.photos_disk');

        $this->savedPath = $this->photo->store('photos/'.now()->format('Y/m'), $disk);
        $this->previewUrl = Storage::disk($disk)->temporaryUrl($this->savedPath, now()->addMinutes(10));

        try {
            $read = $reader->read($this->savedPath, $disk);
        } catch (AiReadFailedException $e) {
            $this->readError = $e->getMessage();

            return;
        }
        } catch (AiReadFailedException $e) {
            $this->readError = $e->getMessage();

            return;
        }

        if (! $read['result']['is_vending_machine']) {
            $this->readError = '自動販売機が写っていないようです。撮り直してください。';

            return;
        }

        $this->aiMaker = $read['result']['maker'];
        $this->rows = collect($read['result']['products'])
            ->map(fn ($p) => [
                'id' => (string) Str::uuid(),
                'name' => $p['name'] ?? '',
                'price' => $p['price'],
                'temperature' => $p['temperature'],
            ])
            ->values()
            ->all();

        if ($this->rows === []) {
            $this->addRow();
        }

        $this->step = 'review';
    }

    public function addRow(): void
    {
        $this->rows[] = ['id' => (string) Str::uuid(), 'name' => '', 'price' => null, 'temperature' => null];
    }

    public function removeRow(int $index): void
    {
        unset($this->rows[$index]);
        $this->rows = array_values($this->rows);
    }

    public function backToForm(): void
    {
        $this->step = 'form';
        $this->rows = [];
        $this->readError = null;
        $this->registered = false;
    }

    public function register(): void
    {
        $this->validate([
            'rows' => 'required|array|min:1',
            'rows.*.name' => 'required|string|max:100',
            'rows.*.price' => 'required|integer|min:0|max:100000',
            'rows.*.temperature' => 'required|in:hot,cold',
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

        // 自販機・商品・価格の保存は Issue 45 で実装する。
        $this->registered = true;
    }
}; ?>

<section class="mx-auto w-full max-w-3xl p-4">
    @if ($step === 'form')
        <flux:heading size="lg">写真を投稿する</flux:heading>

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
                <img src="{{ $previewUrl }}" alt="撮った写真" class="mt-2 w-full rounded-lg">
                @if ($aiMaker)
                    <flux:text class="mt-2">AIが読んだメーカー: {{ $aiMaker }}</flux:text>
                @endif
            </div>

            <div class="flex flex-col gap-4">
                <flux:heading size="md">読み取り結果</flux:heading>

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

                <flux:button wire:click="addRow">行を追加する</flux:button>

                <flux:button variant="primary" wire:click="register" wire:loading.attr="disabled" wire:target="register">
                    登録
                </flux:button>
                <flux:button variant="ghost" wire:click="backToForm">撮り直す</flux:button>

                @if ($registered)
                    <flux:text class="text-green-600">入力内容を確認しました(保存は #45 で実装します)。</flux:text>
                @endif
            </div>
        </div>
    @endif
</section>