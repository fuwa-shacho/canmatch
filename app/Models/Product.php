<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Product extends Model
{
    protected $fillable = [
        'name',
        'maker',
        'volume_ml',
        'category',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'volume_ml' => 'integer',
        ];
    }

    /**
     * 検索用の文章を作る(RAG の索引に登録する文章。Issue 62)
     *
     * 「商品名 / メーカー / カテゴリ / 容量ml / 説明」をつなげる。空の項目は飛ばす。
     * 価格や自販機は変わるので入れない(必ず DB から取る)。
     * 例:綾鷹 / 日本コカ・コーラ / 茶系飲料 / 650ml / 無糖の緑茶。カフェインあり
     */
    public function searchText(): string
    {
        $parts = [
            $this->name,
            $this->maker,
            $this->category,
            $this->volume_ml !== null ? "{$this->volume_ml}ml" : null,
            $this->description,
        ];

        $parts = array_map(fn ($part) => is_string($part) ? trim($part) : $part, $parts);

        return implode(' / ', array_filter($parts, fn ($part) => $part !== null && $part !== ''));
    }

    public function vendingMachines(): BelongsToMany
    {
        return $this->belongsToMany(
            VendingMachine::class,
            'machine_products',
            'product_id',
            'vending_machine_id'
        )
            ->withPivot(['id', 'price', 'temperature', 'recorded_at'])
            ->using(MachineProduct::class);
    }
}
