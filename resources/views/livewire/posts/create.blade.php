<?php

use App\Exceptions\AiReadFailedException;
use App\Models\VendingMachine;
use App\Services\ProductMatcher;
use App\Services\VendingMachineReader;
use App\Services\VendingMachineRegistrar;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use League\Flysystem\UnableToCheckFileExistence;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToSetVisibility;
use League\Flysystem\UnableToWriteFile;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Validate;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new #[Layout('components.layouts.app')] class extends Component
{
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

    #[Locked]
    public ?int $machineId = null;

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
            $existing = VendingMachine::query()->findOrFail($id);
            $this->machineId = $existing->id;
            $this->latitude = (float) $existing->latitude;
            $this->longitude = (float) $existing->longitude;
            $this->maker = $existing->maker ?? 'unknown';
        }
    }

    /**
     * 位置を受け取る(GPS、または地図で選んだ位置)。範囲外なら保存せず false を返す
     */
    public function setLocation(float $latitude, float $longitude): bool
    {
        if ($this->machineId !== null) {
            return true;
        }

        if (! is_finite($latitude) || ! is_finite($longitude)
            || $latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
            $this->addError('latitude', '選択した位置が正しくありません。');

            return false;
        }

        $this->resetErrorBag('latitude');
        $this->latitude = $latitude;
        $this->longitude = $longitude;

        return true;
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

        if ($this->savedPath !== null && ! $this->deleteSavedPhoto()) {
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
            $this->previewUrl = Storage::disk($disk)->temporaryUrl($this->savedPath, now()->addMinutes(10));
        } catch (\RuntimeException $e) {
            report($e);
            $this->deleteSavedPhoto();
            $this->addError('photo', '写真のプレビューを準備できませんでした。もう一度お試しください。');

            return;
        }

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
                'temperature' => $p['temperature'] ?? '',
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
            'temperature' => '',
            'new_volume_ml' => null, // 新しい商品にするときの容量(ml)
            'searching' => false,    // 商品マスタを探す欄を開いているか
            'search' => '',
            'search_results' => [],
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

        if ($field === 'search' && isset($this->rows[$index])) {
            $this->rows[$index]['search_results'] = $this->searchProducts((string) $this->rows[$index]['search']);

            return;
        }

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
        $this->rows[$index]['new_volume_ml'] = null;
        $this->rows[$index]['link'] = 'new';
    }

    /**
     * 商品マスタを探す欄を開く(最初は今の商品名で探す)
     */
    public function startSearch(int $index): void
    {
        if (! isset($this->rows[$index])) {
            return;
        }

        $this->rows[$index]['searching'] = true;
        $this->rows[$index]['search'] = (string) ($this->rows[$index]['name'] ?? '');
        $this->rows[$index]['search_results'] = $this->searchProducts($this->rows[$index]['search']);
    }

    public function closeSearch(int $index): void
    {
        if (! isset($this->rows[$index])) {
            return;
        }

        $this->rows[$index]['searching'] = false;
        $this->rows[$index]['search_results'] = [];
    }

    /**
     * 探した商品を選んで紐づける
     *
     * クライアントから来たIDは信用せず、サーバーで探し直した結果にあるものだけ受け付ける。
     * 商品名も選んだ商品の名前にそろえる(登録前の照合で、選んだ商品が候補に入るようにするため)。
     */
    public function selectSearchResult(int $index, int $productId): void
    {
        if (! isset($this->rows[$index])) {
            return;
        }

        $found = collect($this->searchProducts((string) ($this->rows[$index]['search'] ?? '')))
            ->firstWhere('id', $productId);

        if ($found === null) {
            return;
        }

        $this->rows[$index] = array_merge($this->rows[$index], $this->matchFor($found['name']), [
            'name' => $found['name'],
            'product_id' => $found['id'],
            'product_name' => $found['name'],
            'volume_ml' => $found['volume_ml'],
            'new_volume_ml' => null,
            'link' => 'manual',
            'searching' => false,
            'search_results' => [],
        ]);
    }

    /**
     * @return array<int, array{id: int, name: string, volume_ml: ?int}>
     */
    protected function searchProducts(string $keyword): array
    {
        return app(ProductMatcher::class)->search($keyword)
            ->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'volume_ml' => $p->volume_ml])
            ->values()
            ->all();
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
        } catch (UnableToDeleteFile $e) {
            report($e);
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

        try {
            $this->validate([
                'maker' => $this->machineId === null
                    ? 'required|string|in:unknown,dydo,suntory,cocacola,asahi,kirin,itoen,other'
                    : 'nullable|string|in:unknown,dydo,suntory,cocacola,asahi,kirin,itoen,other',
                'latitude' => $this->machineId === null
                    ? 'required|numeric|between:-90,90'
                    : 'nullable|numeric|between:-90,90',
                'longitude' => $this->machineId === null
                    ? 'required|numeric|between:-180,180'
                    : 'nullable|numeric|between:-180,180',
                'rows' => 'required|array|min:1',
                'rows.*.name' => 'required|string|max:100',
                'rows.*.price' => 'required|integer|min:0|max:100000',
                'rows.*.temperature' => 'required|in:hot,cold',
                'rows.*.new_volume_ml' => 'nullable|integer|min:1|max:5000',
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
                'latitude.required' => '現在地を取得してください',
                'latitude.between' => '緯度が正しくありません',
                'longitude.required' => '現在地を取得してください',
                'longitude.between' => '経度が正しくありません',
                'rows.required' => '商品を1つ以上入力してください',
                'rows.min' => '商品を1つ以上入力してください',
                'rows.*.name.required' => '商品名を入力してください',
                'rows.*.name.max' => '商品名は100文字以内にしてください',
                'rows.*.price.required' => '価格を入力してください',
                'rows.*.price.integer' => '価格は数字で入力してください',
                'rows.*.price.min' => '価格は0円以上にしてください',
                'rows.*.price.max' => '価格が大きすぎます',
                'rows.*.temperature.required' => '温冷を選んでください',
                'rows.*.new_volume_ml.integer' => '容量は数字(ml)で入力してください',
                'rows.*.new_volume_ml.min' => '容量は1ml以上にしてください',
                'rows.*.new_volume_ml.max' => '容量が大きすぎます',
            ]);
        } catch (ValidationException $e) {
            if ($this->machineId === null) {
                throw $e;
            }

            foreach ($e->errors() as $field => $messages) {
                foreach ($messages as $message) {
                    $this->addError($field, $message);
                }
            }

            return;
        }

        if ($this->savedPath === null) {
            $this->step = 'form';
            $this->readError = '更新するには写真を撮り直してください。';

            return;
        }

        $oldPhotoPath = null;
        try {
            $registrar = app(VendingMachineRegistrar::class);

            if ($this->machineId !== null) {
                abort_unless(auth()->check(), 401);

                $machine = VendingMachine::query()->findOrFail($this->machineId);
                $oldPhotoPath = $machine->photo_path;
                $machine = $registrar->update($machine, $this->rows, $this->savedPath, auth()->user());
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
        } catch (ValidationException $e) {
            $message = $e->errors()['rows'][0] ?? '保存内容を確認してください。';
            $this->addError('rows', $message);

            return;
        } catch (\Illuminate\Database\QueryException $e) {
            report($e);
            $this->addError('rows', '保存に失敗しました。もう一度試してください。');

            if ($this->machineId !== null) {
                $this->failedUpdate('保存に失敗しました。写真を撮り直してください。');
            }

            return;
        }

        $this->registered = true;

        if ($oldPhotoPath !== null && $oldPhotoPath !== $this->savedPath && ! $this->deletePhoto($oldPhotoPath)) {
            session()->flash('warning', '更新しましたが、以前の写真を削除できませんでした。');
        }

        if ($this->machineId !== null) {
            $this->redirectRoute('vending-machines.show', $machine, navigate: true);

            return;
        }

        $earnedPoints = $machine->pointLogs()
            ->where('user_id', auth()->id())
            ->orderBy('id')
            ->get(['type', 'points'])
            ->map->only(['type', 'points'])
            ->all();

        session()->flash('status', '自販機を登録しました');
        session()->flash('earned_points', [
            'vending_machine_id' => $machine->id,
            'items' => $earnedPoints,
        ]);

        $this->redirectRoute('vending-machines.show', $machine, navigate: true);
    }

    private function deletePhoto(string $path): bool
    {
        try {
            return Storage::disk(config('filesystems.photos_disk'))->delete($path);
        } catch (UnableToDeleteFile $e) {
            report($e);

            return false;
        }
    }

    private function failedUpdate(?string $reason): void
    {
        $deleted = $this->deleteSavedPhoto();
        $this->photo = null;
        $this->step = 'form';
        $cleanupMessage = $deleted
            ? '新しい写真を削除しました。写真を撮り直してください。'
            : '新しい写真を削除できませんでした。時間をおいて再度お試しください。';
        $this->readError = trim(($reason ?? '更新に失敗しました。').' '.$cleanupMessage);
    }
}; ?>

<section class="mx-auto w-full max-w-3xl p-4">
    @if ($step === 'form')
        <flux:heading size="lg">{{ $machineId ? '情報を更新する' : '写真を投稿する' }}</flux:heading>

        <form
            wire:submit="save"
            class="mt-6 flex max-w-md flex-col gap-6"
            x-data="{
                locationStatus: @js($machineId ? 'ok' : 'loading'),
                locationMessage: @js($machineId ? '登録済みの位置情報を維持します' : '現在地を取得しています...'),
                uploading: false,
                fallbackMap: null,
                fallbackMarker: null,
                candidate: null,
                showFallback(reason) {
                    this.locationStatus = 'manual';
                    this.locationMessage = reason + ' 地図をタップして自販機の位置を指定し、「この位置で確定」を押してください。';
                    this.$nextTick(() => this.initFallbackMap());
                },
                initFallbackMap() {
                    if (this.fallbackMap || !this.$refs.manualMap) return;
                    // 表示の初期位置(福岡市中心部)。登録される位置ではない
                    const start = [33.589, 130.395];
                    const draw = () => {
                        if (!window.L || !this.$refs.manualMap || this.fallbackMap) return;
                        this.fallbackMap = L.map(this.$refs.manualMap).setView(start, 16);
                        L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
                            maxZoom: 19,
                            attribution: '&copy; OpenStreetMap contributors',
                        }).addTo(this.fallbackMap);
                        this.fallbackMap.on('click', (event) => {
                            this.candidate = { lat: event.latlng.lat, lng: event.latlng.lng };
                            if (this.fallbackMarker) this.fallbackMarker.setLatLng(event.latlng);
                            else this.fallbackMarker = L.marker(event.latlng).addTo(this.fallbackMap);
                        });
                        setTimeout(() => this.fallbackMap?.invalidateSize(), 0);
                    };
                    if (window.L) { draw(); return; }
                    if (!document.querySelector('link[data-manual-leaflet]')) {
                        const css = document.createElement('link');
                        css.rel = 'stylesheet';
                        css.href = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';
                        css.dataset.manualLeaflet = '1';
                        document.head.appendChild(css);
                    }
                    const existing = document.querySelector('script[data-manual-leaflet]');
                    if (existing) { existing.addEventListener('load', draw, { once: true }); return; }
                    const js = document.createElement('script');
                    js.src = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
                    js.dataset.manualLeaflet = '1';
                    js.onload = draw;
                    js.onerror = () => { this.locationMessage = '地図を読み込めませんでした。ページを再読み込みしてください。'; };
                    document.head.appendChild(js);
                },
                async confirmFallback() {
                    if (!this.candidate) return;
                    try {
                        const ok = await $wire.setLocation(this.candidate.lat, this.candidate.lng);
                        if (!ok) {
                            this.locationMessage = '選択した位置が正しくありません。もう一度タップしてください。';
                            return;
                        }
                        this.locationStatus = 'ok';
                        this.locationMessage = '地図で指定した位置を確定しました';
                    } catch (e) {
                        this.locationMessage = '指定した位置を保存できませんでした。もう一度お試しください。';
                    }
                },
            }"
            x-init="
                @unless ($machineId)
                if (!navigator.geolocation) {
                    showFallback('この端末では現在地が使えません。');
                } else {
                    navigator.geolocation.getCurrentPosition(
                        async (pos) => {
                            try {
                                if (await $wire.setLocation(pos.coords.latitude, pos.coords.longitude)) {
                                    locationStatus = 'ok';
                                    locationMessage = '現在地を取得しました';
                                } else {
                                    showFallback('現在地が正しく取得できませんでした。');
                                }
                            } catch (e) {
                                showFallback('現在地を送信できませんでした。');
                            }
                        },
                        () => {
                            showFallback('現在地を取得できませんでした。');
                        },
                        { enableHighAccuracy: true, timeout: 15000 }
                    );
                }
                @endunless
            "
        >
            <aside
                role="note"
                class="rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-950 dark:border-amber-700 dark:bg-amber-950/30 dark:text-amber-100"
            >
                <h2 class="font-semibold">撮影時の注意と写真の利用について</h2>
                <ul class="mt-2 list-disc space-y-1 pl-5">
                    <li>人の顔や車のナンバーが写らないようにしてください。</li>
                    <li>私有地には入らないでください。</li>
                    <li>歩きながら操作しないでください。</li>
                    <li>投稿写真はAIによる商品情報の読み取りに使用します。</li>
                    <li>投稿写真は他の利用者には公開されません。</li>
                </ul>
            </aside>

            <div
                x-data="{
                    error: null,
                    async resize(event) {
                        const file = event.target.files[0];
                        if (!file) return;
                        uploading = true;
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
                        uploading = true;
                        $wire.upload(
                            'photo',
                            resized,
                            () => { uploading = false },
                            () => {
                                uploading = false;
                                this.error = '写真のアップロードに失敗しました。もう一度お試しください。';
                            }
                        );
                    },
                    fail(event, message) {
                        uploading = false;
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
            @endunless

            @unless ($machineId)
            <div>
                <input type="hidden" name="latitude" wire:model="latitude">
                <input type="hidden" name="longitude" wire:model="longitude">
                <p
                    x-text="locationMessage"
                    class="text-sm"
                    :class="locationStatus === 'error' ? 'text-red-500' : (locationStatus === 'ok' ? 'text-green-600' : (locationStatus === 'manual' ? 'text-amber-700 dark:text-amber-300' : 'text-zinc-500'))"
                ></p>
                <div x-show="locationStatus === 'manual'" x-cloak class="mt-3 space-y-2">
                    <div
                        x-ref="manualMap"
                        wire:ignore
                        class="h-72 w-full rounded-lg border border-zinc-300 dark:border-zinc-600"
                        aria-label="自販機の位置を選ぶ地図"
                    ></div>
                    <button
                        type="button"
                        x-bind:disabled="!candidate"
                        @click="confirmFallback()"
                        class="w-full rounded-lg bg-blue-600 px-3 py-2 text-sm font-medium text-white disabled:opacity-50"
                    >この位置で確定</button>
                </div>
                @error('latitude') <flux:text class="text-red-500">{{ $message }}</flux:text> @enderror
            </div>
            @endunless

            <flux:button type="submit" variant="primary" class="w-full"
                :disabled="$latitude === null || $longitude === null"
                x-bind:disabled="uploading || locationStatus !== 'ok' || @js($machineId === null) && ($wire.latitude === null || $wire.longitude === null)"
                wire:loading.attr="disabled" wire:target="save,photo">
                <span wire:loading.remove wire:target="save,photo">{{ $machineId ? '読み取りを開始' : '送信する' }}</span>
                <span wire:loading wire:target="photo">写真を送信中...</span>
                <span wire:loading wire:target="save">読み取り中...</span>
            </flux:button>

            @if ($readError)
                <flux:text class="text-red-500">{{ $readError }}</flux:text>
            @endif
            @error('rows') <flux:text class="text-red-500">{{ $message }}</flux:text> @enderror
        </form>
    @else
        <flux:heading size="lg">{{ $machineId ? '更新内容を確認する' : '読み取り結果を確認する' }}</flux:heading>
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
                    <div wire:key="row-{{ $row['id'] }}-{{ $i }}" class="rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
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
                                <div class="flex flex-wrap gap-2">
                                    <flux:button size="xs" variant="ghost" wire:click="startSearch({{ $i }})">別の商品を選ぶ</flux:button>
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
                                <flux:text>選ばない場合は新しい商品として登録されます。容量が分かれば入力してください(分からなければ空のままで大丈夫です)。</flux:text>
                                <div class="flex flex-col items-start gap-2">
                                    <div class="w-full">
                                        <flux:input wire:model="rows.{{ $i }}.new_volume_ml" label="容量(ml)" type="number" inputmode="numeric" min="1" placeholder="例:525" />
                                    </div>
                                    <flux:button size="xs" variant="ghost" wire:click="startSearch({{ $i }})">商品マスタから探す</flux:button>
                                </div>
                            @endif

                            @if ($row['searching'] ?? false)
                                <div class="mt-2 rounded-lg bg-zinc-50 p-2 dark:bg-zinc-800">
                                    <flux:input wire:model.live.debounce.300ms="rows.{{ $i }}.search" label="商品マスタを探す" placeholder="例:綾鷹" />
                                    @if (($row['search_results'] ?? []) === [])
                                        <flux:text class="mt-2">見つかりません。2文字以上で探してください。</flux:text>
                                    @else
                                        <div class="mt-2 flex flex-wrap gap-2">
                                            @foreach ($row['search_results'] as $r)
                                                <flux:button size="xs" wire:click="selectSearchResult({{ $i }}, {{ $r['id'] }})">
                                                    {{ $r['name'] }}({{ $r['volume_ml'] ? $r['volume_ml'].'ml' : '容量未登録' }})
                                                </flux:button>
                                            @endforeach
                                        </div>
                                    @endif
                                    <flux:button size="xs" variant="ghost" class="mt-2" wire:click="closeSearch({{ $i }})">閉じる</flux:button>
                                </div>
                            @endif
                        </div>
                    </div>
                @endforeach

                <flux:button wire:click="addRow">行を追加する</flux:button>

                <flux:button variant="primary" wire:click="register" wire:loading.attr="disabled" wire:target="register">
                    {{ $machineId ? '更新する' : '登録' }}
                </flux:button>
                <flux:button variant="ghost" wire:click="backToForm">撮り直す</flux:button>


            </div>
        </div>
    @endif
</section>
