<?php

namespace Tests\Feature;

use App\Services\Rag\EmbeddingService;
use App\Services\Rag\VectorIndex;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class RagServicesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.cloudflare.account_id', 'acc');
        config()->set('services.cloudflare.api_token', 'token');
        config()->set('services.cloudflare.embedding_model', '@cf/baai/bge-m3');
        config()->set('services.cloudflare.vectorize_index', 'canmatch-products');
        config()->set('services.cloudflare.timeout', 30);
    }

    // ---- EmbeddingService ----

    public function test_文章をベクトルにする(): void
    {
        Http::fake(['*' => Http::response(['result' => ['data' => [[0.1, 0.2], [0.3, 0.4]]]])]);

        $vectors = app(EmbeddingService::class)->embed(['a', 'b']);

        $this->assertCount(2, $vectors);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/ai/run/@cf/baai/bge-m3')
            && $r->hasHeader('Authorization', 'Bearer token'));
    }

    public function test_応答の数が合わなければ例外(): void
    {
        Http::fake(['*' => Http::response(['result' => ['data' => [[0.1, 0.2]]]])]);

        $this->expectException(RuntimeException::class);

        app(EmbeddingService::class)->embed(['a', 'b']);
    }

    public function test_空の配列は数値化せず例外(): void
    {
        Http::fake();

        try {
            app(EmbeddingService::class)->embed([]);
            $this->fail('例外が投げられなかった');
        } catch (InvalidArgumentException) {
            Http::assertNothingSent();
        }
    }

    public function test_空白だけの文章は数値化せず例外(): void
    {
        Http::fake();

        try {
            app(EmbeddingService::class)->embed(['a', '   ']);
            $this->fail('例外が投げられなかった');
        } catch (InvalidArgumentException) {
            Http::assertNothingSent();
        }
    }

    public function test_応答のベクトルが空なら例外(): void
    {
        Http::fake(['*' => Http::response(['result' => ['data' => [[]]]])]);

        $this->expectException(RuntimeException::class);

        app(EmbeddingService::class)->embed(['a']);
    }

    public function test_応答のベクトルが数値でなければ例外(): void
    {
        Http::fake(['*' => Http::response(['result' => ['data' => [['x', 'y']]]])]);

        $this->expectException(RuntimeException::class);

        app(EmbeddingService::class)->embed(['a']);
    }

    // ---- VectorIndex ----

    public function test_索引への登録は1行1件の形で送る(): void
    {
        Http::fake(['*' => Http::response(['result' => ['mutationId' => 'm1']])]);

        $id = app(VectorIndex::class)->upsert([
            ['id' => '1', 'values' => [0.1, 0.2]],
            ['id' => 2, 'values' => [0.3, 0.4]],
        ]);

        $this->assertSame('m1', $id);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/indexes/canmatch-products/upsert')
            && $r->hasHeader('Content-Type', 'application/x-ndjson')
            && count(explode("\n", $r->body())) === 2
            && str_contains($r->body(), '"id":"2"')); // 整数のIDは文字列に直して送る
    }

    public function test_登録するものが空なら送らず例外(): void
    {
        Http::fake();

        try {
            app(VectorIndex::class)->upsert([]);
            $this->fail('例外が投げられなかった');
        } catch (InvalidArgumentException) {
            Http::assertNothingSent();
        }
    }

    public function test_数値でないベクトルは登録せず例外(): void
    {
        Http::fake();

        try {
            app(VectorIndex::class)->upsert([['id' => '1', 'values' => ['x']]]);
            $this->fail('例外が投げられなかった');
        } catch (InvalidArgumentException) {
            Http::assertNothingSent();
        }
    }

    public function test_検索結果のIDとscoreを返す(): void
    {
        Http::fake(['*' => Http::response(['result' => ['matches' => [['id' => '3', 'score' => 0.8]]]])]);

        $matches = app(VectorIndex::class)->query([0.1, 0.2], 10);

        $this->assertSame('3', $matches[0]['id']);
        Http::assertSent(fn ($r) => $r['topK'] === 10);
    }

    public function test_空のベクトルでは検索せず例外(): void
    {
        Http::fake();

        try {
            app(VectorIndex::class)->query([]);
            $this->fail('例外が投げられなかった');
        } catch (InvalidArgumentException) {
            Http::assertNothingSent();
        }
    }
}