<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Services\ProductMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductMatcherTest extends TestCase
{
    use RefreshDatabase;

    public function test_完全一致が1件なら自動で紐づける(): void
    {
        $p = Product::create(['name' => 'ファンタ オレンジ', 'volume_ml' => 500]);

        $result = app(ProductMatcher::class)->match('　ファンタ　オレンジ ');

        $this->assertSame($p->id, $result['product']?->id);
        $this->assertTrue($result['candidates']->isEmpty());
    }

    public function test_完全一致が複数なら候補にして自動では紐づけない(): void
    {
        Product::create(['name' => 'お茶', 'volume_ml' => 500]);
        Product::create(['name' => 'お茶', 'volume_ml' => 350]);

        $result = app(ProductMatcher::class)->match('お茶');

        $this->assertNull($result['product']);
        $this->assertCount(2, $result['candidates']);
    }

    public function test_部分一致は候補として出すだけ(): void
    {
        Product::create(['name' => '伊右衛門 650ml']);

        $result = app(ProductMatcher::class)->match('伊右衛門');

        $this->assertNull($result['product']);
        $this->assertCount(1, $result['candidates']);
    }

    public function test_一致しなければ候補なし(): void
    {
        Product::create(['name' => 'お茶']);

        $result = app(ProductMatcher::class)->match('コーヒー');

        $this->assertNull($result['product']);
        $this->assertTrue($result['candidates']->isEmpty());
    }

    public function test_ワイルドカード記号は普通の文字として扱う(): void
    {
        Product::create(['name' => 'お茶']);

        $result = app(ProductMatcher::class)->match('%%');

        $this->assertTrue($result['candidates']->isEmpty());
    }

    public function test_商品マスタを部分一致で探し名前と容量の順に並べる(): void
    {
        Product::create(['name' => '綾鷹', 'volume_ml' => 650]);
        Product::create(['name' => '綾鷹', 'volume_ml' => 525]);
        Product::create(['name' => '綾鷹 茶葉のあまみ', 'volume_ml' => 525]);
        Product::create(['name' => 'コカ・コーラ', 'volume_ml' => 500]);

        $found = app(ProductMatcher::class)->search(' 綾鷹 ');

        $this->assertSame(
            ['綾鷹 525', '綾鷹 650', '綾鷹 茶葉のあまみ 525'],
            $found->map(fn ($p) => $p->name.' '.$p->volume_ml)->all(),
        );
    }

    public function test_1文字では探さない(): void
    {
        Product::create(['name' => 'お茶']);

        $this->assertTrue(app(ProductMatcher::class)->search('茶')->isEmpty());
    }
}
