<?php

namespace Tests\Feature;

use App\Models\Product;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductSearchTextTest extends TestCase
{
    use RefreshDatabase;

    public function test_すべての項目をスラッシュでつなぐ(): void
    {
        $product = new Product([
            'name' => '綾鷹',
            'maker' => '日本コカ・コーラ',
            'category' => '茶系飲料',
            'volume_ml' => 650,
            'description' => '無糖の緑茶。カフェインあり',
        ]);

        $this->assertSame('綾鷹 / 日本コカ・コーラ / 茶系飲料 / 650ml / 無糖の緑茶。カフェインあり', $product->searchText());
    }

    public function test_空の項目は飛ばす(): void
    {
        // 投稿で新しく追加された商品は、メーカー・容量・説明が分からないことがある
        $product = new Product([
            'name' => '珍しいサイダー',
            'maker' => null,
            'category' => '炭酸飲料',
            'volume_ml' => null,
            'description' => '',
        ]);

        $this->assertSame('珍しいサイダー / 炭酸飲料', $product->searchText());
    }

    public function test_商品名だけでも文章を作れる(): void
    {
        $product = new Product(['name' => '珍しいサイダー']);

        $this->assertSame('珍しいサイダー', $product->searchText());
    }

    public function test_前後の空白は取り除く(): void
    {
        $product = new Product(['name' => ' 綾鷹 ', 'maker' => '  ', 'description' => ' 無糖の緑茶 ']);

        $this->assertSame('綾鷹 / 無糖の緑茶', $product->searchText());
    }

    public function test_商品マスタのすべての商品に説明があり文章を作れる(): void
    {
        $this->seed(ProductSeeder::class);

        $products = Product::all();
        $this->assertCount(25, $products);

        foreach ($products as $product) {
            $this->assertNotEmpty($product->description, "{$product->name} の説明が空です");
            $this->assertStringEndsWith($product->description, $product->searchText());
        }
    }

    public function test_シーダーを2回実行しても説明は上書きされ商品は増えない(): void
    {
        $this->seed(ProductSeeder::class);
        Product::where('name', '綾鷹')->update(['description' => null]);

        $this->seed(ProductSeeder::class);

        $this->assertDatabaseCount('products', 25);
        $this->assertSame('無糖の緑茶。甘くない。カフェインあり', Product::where('name', '綾鷹')->value('description'));
    }
}