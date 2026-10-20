<?php

namespace App\Services;

use App\Models\Product;
use App\Models\VendingMachine;
use Illuminate\Support\Facades\DB;

class VendingMachineRegistrar
{
    public function __construct(private ProductMatcher $matcher)
    {
    }

    /**
     * 自販機・商品・価格をまとめて保存する。途中で失敗したら全部取り消す。
     *
     * @param  array{latitude: float, longitude: float, maker: ?string, photo_path: string, user_id: ?int}  $attributes
     * @param  array<int, array{name: string, price: int|string, temperature: string, product_id: ?int}>  $rows
     */
    public function register(array $attributes, array $rows): VendingMachine
    {
        return DB::transaction(function () use ($attributes, $rows) {
            $now = now();

            $machine = VendingMachine::create($attributes + ['last_confirmed_at' => $now]);

            // 同じ送信の中で同じ新商品名が複数行あっても、商品は1つだけ作る
            $createdByName = [];

            foreach ($rows as $row) {
                $productId = $row['product_id'] ?? null;

                if ($productId === null) {
                    $name = $this->matcher->normalize($row['name']);
                    $productId = $createdByName[$name] ??= Product::create(['name' => $name])->id;
                }

                $machine->products()->attach($productId, [
                    'price' => (int) $row['price'],
                    'temperature' => $row['temperature'],
                    'recorded_at' => $now,
                ]);
            }

            return $machine;
        });
    }
}