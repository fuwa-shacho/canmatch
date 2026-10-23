<?php

namespace App\Services;

use App\Models\Product;
use App\Models\VendingMachine;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class VendingMachineRegistrar
{
    public function __construct(private ProductMatcher $matcher) {}

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

            return $machine;
        });
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
