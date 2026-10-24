<?php

namespace App\Services;

use App\Models\Product;
use App\Models\User;
use App\Models\VendingMachine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class VendingMachineRegistrar
{
    public function __construct(
        private ProductMatcher $matcher,
        private PointAwarder $pointAwarder,
        private RareProductDetector $rareProductDetector,
    ) {}

    /**
     * 自販機・商品・価格をまとめて保存する。途中で失敗したら全部取り消す。
     *
     * @param  array{latitude: float, longitude: float, maker: ?string, photo_path: string, user_id: ?int}  $attributes
     * @param  array<int, array{name: string, price: int|string, temperature: string, product_id: ?int, new_volume_ml?: int|string|null}>  $rows
     *
     * 紐づけのない行(product_id が null)は、同じ名前・同じ容量の商品が商品マスタにあればそれを使い、
     * なければ新しい商品を作る(容量違いは別商品。docs/data-contract.md #22)。
     */
    public function register(array $attributes, array $rows): VendingMachine
    {
        return DB::transaction(function () use ($attributes, $rows) {
            $now = now();

            $machine = VendingMachine::create($attributes + ['last_confirmed_at' => $now]);

            // 同じ送信の中で同じ新商品(名前と容量)が複数行あっても、商品は1つだけ作る
            $createdByName = [];
            $registeredProducts = [];
            $checkedRareProducts = [];
            $containsRareProduct = false;
            $retroactiveDiscoveries = [];

            foreach ($rows as $row) {
                $productId = $row['product_id'] ?? null;

                if ($productId === null) {
                    $name = $this->matcher->normalize($row['name']);
                    $volume = self::volumeOf($row['new_volume_ml'] ?? null);
                    $productId = $createdByName[$name.'|'.$volume] ??= $this->findOrCreateProduct($name, $volume);
                } else {
                    $productId = Product::query()->whereKey($productId)->value('id');

                    if ($productId === null) {
                        throw ValidationException::withMessages([
                            'rows' => '選択した商品が見つかりません。候補から選び直してください。',
                        ]);
                    }
                }

                if (! isset($checkedRareProducts[$productId])) {
                    $machineCount = $this->rareProductDetector->machineCount($productId);
                    $withinRareRange = $machineCount >= 1
                        && $machineCount <= $this->rareProductDetector->maximumMachineCount();
                    $discoveryConfirmed = $withinRareRange
                        && $this->rareProductDetector->hasConfirmedDiscovery($productId);
                    $confirmedByCurrentRegistration = false;

                    if ($withinRareRange && ! $discoveryConfirmed && $machine->user_id !== null) {
                        $firstDiscovery = $this->rareProductDetector->firstDiscovery($productId);

                        if ($firstDiscovery !== null
                            && $firstDiscovery['user_id'] !== null
                            && $firstDiscovery['user_id'] !== $machine->user_id) {
                            $retroactiveDiscoveries[$productId] = $firstDiscovery;
                            $confirmedByCurrentRegistration = true;
                        }
                    }

                    $containsRareProduct = $containsRareProduct
                        || $discoveryConfirmed
                        || $confirmedByCurrentRegistration;

                    $checkedRareProducts[$productId] = true;
                }

                $key = $productId.':'.$row['temperature'];

                if (isset($registeredProducts[$key])) {
                    throw ValidationException::withMessages([
                        'rows' => '同じ商品・温冷区分は重複して登録できません。',
                    ]);
                }

                $registeredProducts[$key] = true;

                $machine->products()->attach($productId, [
                    'price' => (int) $row['price'],
                    'temperature' => $row['temperature'],
                    'recorded_at' => $now,
                ]);
            }

            if ($machine->user !== null) {
                $this->pointAwarder->awardForRegistration($machine->user, $machine);

                if ($containsRareProduct) {
                    $this->pointAwarder->awardRareBonus($machine->user, $machine);
                }

                foreach ($retroactiveDiscoveries as $productId => $firstDiscovery) {
                    $firstUser = User::query()->find($firstDiscovery['user_id']);
                    $firstMachine = VendingMachine::query()->find($firstDiscovery['machine_id']);

                    if ($firstUser !== null && $firstMachine !== null) {
                        $this->pointAwarder->awardRetroactiveRareBonus(
                            $firstUser,
                            $firstMachine,
                            Product::query()->findOrFail($productId),
                        );
                    }
                }
            }

            return $machine;
        });
    }

    public function update(VendingMachine $machine, array $rows, string $photoPath, User $updater): VendingMachine
    {
        return DB::transaction(function () use ($machine, $rows, $photoPath, $updater) {
            $machine = VendingMachine::query()
                ->whereKey($machine->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            $now = now();

            $machine->update([
                'photo_path' => $photoPath,
                'last_confirmed_at' => $now,
            ]);

            $machine->products()->detach();
            $this->attachRows($machine, $rows, $now);
            $this->pointAwarder->awardForUpdate($updater, $machine);

            return $machine->refresh();
        });
    }

    /**
     * @param  array<int, array{name: string, price: int|string, temperature: string, product_id: ?int, new_volume_ml?: int|string|null}>  $rows
     */
    private function attachRows(VendingMachine $machine, array $rows, Carbon $now): void
    {
        $createdByName = [];
        $registeredProducts = [];

        foreach ($rows as $row) {
            $productId = $row['product_id'] ?? null;

            if ($productId === null) {
                $name = $this->matcher->normalize($row['name']);
                $volume = self::volumeOf($row['new_volume_ml'] ?? null);
                $key = $name.'|'.($volume ?? '');
                $productId = $createdByName[$key] ??= $this->findOrCreateProduct($name, $volume);
            } else {
                $productId = Product::query()->whereKey($productId)->value('id');

                if ($productId === null) {
                    throw ValidationException::withMessages([
                        'rows' => '選択した商品が見つかりません。候補から選び直してください。',
                    ]);
                }
            }

            $key = $productId.':'.$row['temperature'];

            if (isset($registeredProducts[$key])) {
                throw ValidationException::withMessages([
                    'rows' => '同じ商品・温冷区分は重複して登録できません。',
                ]);
            }

            $registeredProducts[$key] = true;
            $machine->products()->attach($productId, [
                'price' => (int) $row['price'],
                'temperature' => $row['temperature'],
                'recorded_at' => $now,
            ]);
        }
    }

    /**
     * 入力された容量を整数にする。空や不正な値は「不明」(null)
     */
    public static function volumeOf(mixed $value): ?int
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        $volume = (int) $value;

        return $volume >= 1 ? $volume : null;
    }

    /**
     * 同じ名前・同じ容量の商品があればそのID、なければ新しく作ったID
     */
    private function findOrCreateProduct(string $name, ?int $volume): int
    {
        $existing = Product::query()
            ->where('name', $name)
            ->when(
                $volume === null,
                fn ($query) => $query->whereNull('volume_ml'),
                fn ($query) => $query->where('volume_ml', $volume),
            )
            ->orderBy('id')
            ->value('id');

        return $existing ?? Product::create(['name' => $name, 'volume_ml' => $volume])->id;
    }
}
