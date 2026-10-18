<?php

use Livewire\Attributes\Layout;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new #[Layout('components.layouts.app')] class extends Component {
    use WithFileUploads;

    #[Validate('required|image')]
    public $photo;

    #[Validate('required|string')]
    public string $maker = 'unknown';

    #[Validate('required|numeric|between:-90,90')]
    public ?float $latitude = null;

    #[Validate('required|numeric|between:-180,180')]
    public ?float $longitude = null;

    public bool $received = false;

    public function setLocation(float $latitude, float $longitude): void
    {
        $this->latitude = $latitude;
        $this->longitude = $longitude;
    }

    public function save(): void
    {
        $this->validate();

        // 保存は Issue 39 で実装する。ここでは受信できたことだけ確認する。
        $this->received = true;
    }
}; ?>

<section class="mx-auto w-full max-w-md p-4">
    <flux:heading size="lg">写真を投稿する</flux:heading>

    <form wire:submit="save" class="mt-6 flex flex-col gap-6">
        <div
            x-data="{
                uploading: false,
                async resize(event) {
                    const file = event.target.files[0];
                    if (!file) return;
                    const bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' });
                    const scale = Math.min(1, 2400 / Math.max(bitmap.width, bitmap.height));
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
                        { enableHighAccuracy: true, timeout: 10000 }
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
            <span wire:loading wire:target="save">送信中...</span>
        </flux:button>

        @if ($received)
            <flux:text class="text-green-600">
                サーバーに届きました(緯度 {{ $latitude }}、経度 {{ $longitude }})
            </flux:text>
        @endif
    </form>
</section>