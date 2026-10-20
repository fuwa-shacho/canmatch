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

    public function test_LIKEの記号は普通の文字として扱う(): void
    {
        Product::create(['name' => 'お茶']);

        $result = app(ProductMatcher::class)->match('%%');

        $this->assertTrue($result['candidates']->isEmpty());
    }
}