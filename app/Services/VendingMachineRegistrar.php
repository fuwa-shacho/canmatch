<?php

namespace App\Services;

use App\Models\Product;
use App\Models\VendingMachine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class VendingMachineRegistrar
{
    public function __construct(private ProductMatcher $matcher)
    {
    }

    /**
     * 自販機・商品・価格をまとめて保存する。途中で失敗したら全部取り消す。
     */
    public function register(array $attributes, array $rows): VendingMachine
    {
        return DB::transaction(function () use ($attributes, $rows) {
            $now = now();

            $machine = VendingMachine::create($attributes + ['last_confirmed_at' => $now]);
            $this->attachRows($machine, $rows, $now);

            return $machine;
        });
    }

    /**
     * 既存の自販機の商品と価格を入れ替え、最終確認日時を現在にする。
     * 写真を撮り直したときだけ $photoPath を渡す。
     */
    public function update(VendingMachine $machine, array $rows, ?string $photoPath = null): VendingMachine
    {
        return DB::transaction(function () use ($machine, $rows, $photoPath) {
            $now = now();

            $machine->update(
                ($photoPath !== null ? ['photo_path' => $photoPath] : [])
                + ['last_confirmed_at' => $now]
            );

            $machine->products()->detach();
            $this->attachRows($machine, $rows, $now);

            return $machine->refresh();
        });
    }

    private function attachRows(VendingMachine $machine, array $rows, Carbon $now): void
    {
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
    }
}