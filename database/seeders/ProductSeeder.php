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
     * @return list<array{name: string, maker: string, volume_ml: int, category: string}>
     */
    private function products(): array
    {
        return [
            ['name' => '三ツ矢サイダー', 'maker' => 'アサヒ飲料', 'volume_ml' => 430, 'category' => '炭酸飲料'],
            ['name' => '三ツ矢サイダー', 'maker' => 'アサヒ飲料', 'volume_ml' => 500, 'category' => '炭酸飲料'],
            ['name' => '三ツ矢特濃グレープスカッシュ', 'maker' => 'アサヒ飲料', 'volume_ml' => 500, 'category' => '炭酸飲料'],
            ['name' => 'ウィルキンソン タンサン', 'maker' => 'アサヒ飲料', 'volume_ml' => 500, 'category' => '炭酸水'],
            ['name' => 'ウィルキンソン タンサン レモン', 'maker' => 'アサヒ飲料', 'volume_ml' => 500, 'category' => '炭酸水'],
            ['name' => 'アサヒ 十六茶', 'maker' => 'アサヒ飲料', 'volume_ml' => 600, 'category' => '茶系飲料'],
            ['name' => 'ワンダ モーニングアメリカーノ ブラック', 'maker' => 'アサヒ飲料', 'volume_ml' => 500, 'category' => 'コーヒー'],
            ['name' => 'ワンダ モーニングアメリカーノ ラテ', 'maker' => 'アサヒ飲料', 'volume_ml' => 500, 'category' => 'コーヒー'],
            ['name' => 'ワンダ モーニングショット', 'maker' => 'アサヒ飲料', 'volume_ml' => 185, 'category' => 'コーヒー'],
            ['name' => 'ワンダ 金の微糖', 'maker' => 'アサヒ飲料', 'volume_ml' => 185, 'category' => 'コーヒー'],
            ['name' => 'ワンダ ブラック ザ アロマ', 'maker' => 'アサヒ飲料', 'volume_ml' => 185, 'category' => 'コーヒー'],
            ['name' => 'ワンダ 特製カフェオレ', 'maker' => 'アサヒ飲料', 'volume_ml' => 185, 'category' => 'コーヒー'],
            ['name' => 'ワンダ プレミアムゼロ', 'maker' => 'アサヒ飲料', 'volume_ml' => 185, 'category' => 'コーヒー'],
            ['name' => 'コカ・コーラ', 'maker' => '日本コカ・コーラ', 'volume_ml' => 500, 'category' => '炭酸飲料'],
            ['name' => 'コカ・コーラ ゼロ', 'maker' => '日本コカ・コーラ', 'volume_ml' => 500, 'category' => '炭酸飲料'],
            ['name' => '綾鷹', 'maker' => '日本コカ・コーラ', 'volume_ml' => 650, 'category' => '茶系飲料'],
            ['name' => 'アクエリアス', 'maker' => '日本コカ・コーラ', 'volume_ml' => 500, 'category' => 'スポーツ飲料'],
            ['name' => 'い・ろ・は・す 天然水', 'maker' => '日本コカ・コーラ', 'volume_ml' => 560, 'category' => '水'],
            ['name' => 'GREEN DA・KA・RA', 'maker' => 'サントリー食品インターナショナル', 'volume_ml' => 600, 'category' => 'スポーツ飲料'],
            ['name' => 'GREEN DA・KA・RA やさしい麦茶', 'maker' => 'サントリー食品インターナショナル', 'volume_ml' => 680, 'category' => '茶系飲料'],
            ['name' => 'サントリー緑茶 伊右衛門', 'maker' => 'サントリー食品インターナショナル', 'volume_ml' => 600, 'category' => '茶系飲料'],
            ['name' => 'キリン 午後の紅茶 ストレートティー', 'maker' => 'キリンビバレッジ', 'volume_ml' => 500, 'category' => '紅茶飲料'],
            ['name' => 'キリン 生茶', 'maker' => 'キリンビバレッジ', 'volume_ml' => 525, 'category' => '茶系飲料'],
            ['name' => 'キリン 生茶 カフェインフリー', 'maker' => 'キリンビバレッジ', 'volume_ml' => 500, 'category' => '茶系飲料'],
            ['name' => '健康ミネラルむぎ茶', 'maker' => '伊藤園', 'volume_ml' => 600, 'category' => '茶系飲料'],
        ];
    }
}
