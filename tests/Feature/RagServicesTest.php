<?php

namespace Tests\Feature;

use App\Services\Rag\EmbeddingService;
use App\Services\Rag\VectorIndex;
use Illuminate\Support\Facades\Http;
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
    }

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

    public function test_索引への登録は1行1件の形で送る(): void
    {
        Http::fake(['*' => Http::response(['result' => ['mutationId' => 'm1']])]);

        $id = app(VectorIndex::class)->upsert([
            ['id' => '1', 'values' => [0.1, 0.2]],
            ['id' => '2', 'values' => [0.3, 0.4]],
        ]);

        $this->assertSame('m1', $id);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/indexes/canmatch-products/upsert')
            && $r->hasHeader('Content-Type', 'application/x-ndjson')
            && count(explode("\n", $r->body())) === 2);
    }

    public function test_検索結果のIDとscoreを返す(): void
    {
        Http::fake(['*' => Http::response(['result' => ['matches' => [['id' => '3', 'score' => 0.8]]]])]);

        $matches = app(VectorIndex::class)->query([0.1, 0.2], 10);

        $this->assertSame('3', $matches[0]['id']);
        Http::assertSent(fn ($r) => $r['topK'] === 10);
    }
}