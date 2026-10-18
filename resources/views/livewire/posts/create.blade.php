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

    public bool $received = false;

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
                    console.log('元:', file.size, '縮小後:', resized.size);
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

        <flux:button type="submit" variant="primary" class="w-full"
            wire:loading.attr="disabled" wire:target="save,photo">
            <span wire:loading.remove wire:target="save">送信する</span>
            <span wire:loading wire:target="save">送信中...</span>
        </flux:button>

        @if ($received)
            <flux:text class="text-green-600">サーバーに届きました。</flux:text>
        @endif
    </form>
</section>