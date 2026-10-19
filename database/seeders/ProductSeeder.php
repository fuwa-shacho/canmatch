<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ProductSeeder extends Seeder
{
    /**
     * Seed the product master with researched, real products.
     */
    public function run(): void
    {
        $now = now();

        foreach ($this->products() as $product) {
            $existingProduct = DB::table('products')
                ->where('name', $product['name'])
                ->where('maker', $product['maker'])
                ->where('volume_ml', $product['volume_ml']);

            if ($existingProduct->exists()) {
                $existingProduct->update([
                    'category' => $product['category'],
                    'description' => $product['description'],
                    'updated_at' => $now,
                ]);

                continue;
            }

            DB::table('products')->insert([
                ...$product,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * @return list<array{name: string, maker: string, volume_ml: int, category: string, description: string}>
     */
    private function products(): array
    {
        return [
            ['name' => '三ツ矢サイダー', 'maker' => 'アサヒ飲料', 'volume_ml' => 430, 'category' => '炭酸飲料', 'description' => '甘い炭酸飲料。サイダー。カフェインなし'],
            ['name' => '三ツ矢サイダー', 'maker' => 'アサヒ飲料', 'volume_ml' => 500, 'category' => '炭酸飲料', 'description' => '甘い炭酸飲料。サイダー。カフェインなし'],
            ['name' => '三ツ矢特濃グレープスカッシュ', 'maker' => 'アサヒ飲料', 'volume_ml' => 500, 'category' => '炭酸飲料', 'description' => '甘い炭酸飲料。ぶどう味。カフェインなし'],
            ['name' => 'ウィルキンソン タンサン', 'maker' => 'アサヒ飲料', 'volume_ml' => 500, 'category' => '炭酸水', 'description' => '糖類ゼロの炭酸水。刺激が強め。甘くない。カフェインなし'],
            ['name' => 'ウィルキンソン タンサン レモン', 'maker' => 'アサヒ飲料', 'volume_ml' => 500, 'category' => '炭酸水', 'description' => '糖類ゼロの炭酸水。レモンの香り。刺激が強め。甘くない。カフェインなし'],
            ['name' => 'アサヒ 十六茶', 'maker' => 'アサヒ飲料', 'volume_ml' => 600, 'category' => '茶系飲料', 'description' => '無糖のブレンド茶。甘くない。炭酸なし。カフェインゼロ'],
            ['name' => 'ワンダ モーニングアメリカーノ ブラック', 'maker' => 'アサヒ飲料', 'volume_ml' => 500, 'category' => 'コーヒー', 'description' => '無糖のブラックコーヒー。甘くない。カフェインあり'],
            ['name' => 'ワンダ モーニングアメリカーノ ラテ', 'maker' => 'アサヒ飲料', 'volume_ml' => 500, 'category' => 'コーヒー', 'description' => '甘いミルク入りコーヒー。加糖。カフェインあり'],
            ['name' => 'ワンダ モーニングショット', 'maker' => 'アサヒ飲料', 'volume_ml' => 185, 'category' => 'コーヒー', 'description' => '甘い缶コーヒー。ミルク入り。カフェインあり'],
                        ['name' => 'ワンダ 金の微糖', 'maker' => 'アサヒ飲料', 'volume_ml' => 185, 'category' => 'コーヒー', 'description' => '微糖の缶コーヒー。甘さ控えめ。ミルク入り。甘味料使用。カフェインあり'],
            ['name' => 'ワンダ ブラック ザ アロマ', 'maker' => 'アサヒ飲料', 'volume_ml' => 185, 'category' => 'コーヒー', 'description' => '無糖のブラック缶コーヒー。甘くない。カフェインあり'],
            ['name' => 'ワンダ 特製カフェオレ', 'maker' => 'アサヒ飲料', 'volume_ml' => 185, 'category' => 'コーヒー', 'description' => '甘いカフェオレ。ミルク入り。カフェインあり'],
            ['name' => 'ワンダ プレミアムゼロ', 'maker' => 'アサヒ飲料', 'volume_ml' => 185, 'category' => 'コーヒー', 'description' => 'ミルク入りの缶コーヒー。砂糖不使用。甘味料で甘い。糖類ゼロ。カフェインあり'],
            ['name' => 'コカ・コーラ', 'maker' => '日本コカ・コーラ', 'volume_ml' => 500, 'category' => '炭酸飲料', 'description' => '甘いコーラ。炭酸飲料。カフェインあり'],
            ['name' => 'コカ・コーラ ゼロ', 'maker' => '日本コカ・コーラ', 'volume_ml' => 500, 'category' => '炭酸飲料', 'description' => 'カロリーゼロのコーラ。甘味料で甘い。炭酸飲料。カフェインあり'],
            ['name' => '綾鷹', 'maker' => '日本コカ・コーラ', 'volume_ml' => 650, 'category' => '茶系飲料', 'description' => '無糖の緑茶。甘くない。カフェインあり'],
            ['name' => 'アクエリアス', 'maker' => '日本コカ・コーラ', 'volume_ml' => 500, 'category' => 'スポーツ飲料', 'description' => '甘いスポーツドリンク。炭酸なし。カフェインなし'],
            ['name' => 'い・ろ・は・す 天然水', 'maker' => '日本コカ・コーラ', 'volume_ml' => 560, 'category' => '水', 'description' => '天然水。無糖。炭酸なし。カフェインなし'],
            ['name' => 'GREEN DA・KA・RA', 'maker' => 'サントリー食品インターナショナル', 'volume_ml' => 600, 'category' => 'スポーツ飲料', 'description' => '甘いスポーツドリンク。すっきりした甘さ。炭酸なし。カフェインなし'],
            ['name' => 'GREEN DA・KA・RA やさしい麦茶', 'maker' => 'サントリー食品インターナショナル', 'volume_ml' => 680, 'category' => '茶系飲料', 'description' => '無糖の麦茶。甘くない。カフェインゼロ'],
            ['name' => 'サントリー緑茶 伊右衛門', 'maker' => 'サントリー食品インターナショナル', 'volume_ml' => 600, 'category' => '茶系飲料', 'description' => '無糖の緑茶。甘くない。カフェインあり'],
            ['name' => 'キリン 午後の紅茶 ストレートティー', 'maker' => 'キリンビバレッジ', 'volume_ml' => 500, 'category' => '紅茶飲料', 'description' => '甘い紅茶。加糖。カフェインあり'],
            ['name' => 'キリン 生茶', 'maker' => 'キリンビバレッジ', 'volume_ml' => 525, 'category' => '茶系飲料', 'description' => '無糖の緑茶。甘くない。カフェインあり'],
            ['name' => 'キリン 生茶 カフェインフリー', 'maker' => 'キリンビバレッジ', 'volume_ml' => 500, 'category' => '茶系飲料', 'description' => '無糖の緑茶。甘くない。カフェインゼロ'],
            ['name' => '健康ミネラルむぎ茶', 'maker' => '伊藤園', 'volume_ml' => 600, 'category' => '茶系飲料', 'description' => '無糖の麦茶。甘くない。カフェインゼロ'],
        ];
    }
}