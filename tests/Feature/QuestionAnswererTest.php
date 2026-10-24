<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\VendingMachine;
use App\Services\Rag\AnswerGenerator;
use App\Services\Rag\QuestionAnswerer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class QuestionAnswererTest extends TestCase
{
    use RefreshDatabase;

    private const GENERATION_MODEL = '@cf/test/generation';

    private Product $soda;

    private Product $cola;

    private VendingMachine $first;

    private VendingMachine $second;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.cloudflare.account_id', 'acc');
        config()->set('services.cloudflare.api_token', 'token');
        config()->set('services.cloudflare.embedding_model', '@cf/baai/bge-m3');
        config()->set('services.cloudflare.vectorize_index', 'canmatch-products');
        config()->set('services.cloudflare.timeout', 30);
        config()->set('services.cloudflare.generation_model', self::GENERATION_MODEL);
        config()->set('services.cloudflare.max_tokens', 400);
        config()->set('services.cloudflare.answer_prompt', 'rag_answer_v1');
        config()->set('services.cloudflare.rag_score_threshold', 0.45);

        Carbon::setTestNow(Carbon::parse('2026-10-22 03:00:00', 'UTC'));

        $this->soda = Product::create(['name' => '開発用無糖炭酸', 'description' => '無糖の炭酸水']);
        $this->cola = Product::create(['name' => '開発用コーラ', 'description' => '甘い炭酸飲料']);
        $this->first = $this->machine();
        $this->second = $this->machine();

        $this->sell($this->first, $this->soda, 120);
        $this->sell($this->second, $this->cola, 160);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function machine(): VendingMachine
    {
        return VendingMachine::create([
            'latitude' => '33.5890000',
            'longitude' => '130.3950000',
            'photo_path' => 'vending-machines/development/question.jpg',
            'last_confirmed_at' => '2026-10-20 00:00:00',
        ]);
    }

    private function sell(VendingMachine $machine, Product $product, int $price): void
    {
        $machine->products()->attach($product->id, [
            'price' => $price,
            'temperature' => 'cold',
            'recorded_at' => '2026-10-22 01:00:00',
        ]);
    }

    /**
     * Cloudflare の代わりに答える
     *
     * @param  array<int, array{id: string, score: float}>  $matches  検索の結果
     * @param  array|null  $citations  生成の結果(null なら生成は HTTP $generationStatus で失敗する)
     */
    private function fakeCloudflare(array $matches, ?array $citations = [], int $generationStatus = 200, int $searchStatus = 200): void
    {
        Http::fake(function (Request $request) use ($matches, $citations, $generationStatus, $searchStatus) {
            if (str_contains($request->url(), self::GENERATION_MODEL)) {
                if ($citations === null) {
                    return Http::response(['errors' => ['x']], $generationStatus);
                }

                return Http::response(['result' => ['response' => ['citations' => $citations]]]);
            }
            if (str_contains($request->url(), '/ai/run/')) {
                return Http::response(['result' => ['data' => [[0.1, 0.2, 0.3]]]], $searchStatus);
            }
            if (str_ends_with($request->url(), '/query')) {
                return Http::response(['result' => ['matches' => $matches]]);
            }

            return Http::response([], 404);
        });
    }

    private function searchMatches(): array
    {
        return [
            ['id' => (string) $this->soda->id, 'score' => 0.62],
            ['id' => (string) $this->cola->id, 'score' => 0.58],
        ];
    }

    private function answer(string $question = '甘くない炭酸'): array
    {
        return app(QuestionAnswerer::class)->answer($question);
    }

    public function test_answer_uses_only_the_cited_rows(): void
    {
        $this->fakeCloudflare($this->searchMatches(), [
            ['machine_id' => $this->first->id, 'product_id' => $this->soda->id, 'price' => 120],
        ]);

        $result = $this->answer();

        $this->assertSame('answered', $result['status']);
        $this->assertStringStartsWith(AnswerGenerator::LEAD, $result['answer']);
        $this->assertStringContainsString("開発用無糖炭酸:自販機{$this->first->id}で120円", $result['answer']);
        $this->assertSame([$this->soda->id], array_column($result['rows'], 'product_id'));
        $this->assertCount(2, $result['evidence']);
        $this->assertSame([$this->soda->id, $this->cola->id], $result['product_ids']);
        $this->assertSame(self::GENERATION_MODEL, $result['model']);
        $this->assertNull($result['notice']);
    }

    public function test_no_match_when_no_row_is_cited(): void
    {
        $this->fakeCloudflare($this->searchMatches(), []);

        $result = $this->answer();

        $this->assertSame('no_match', $result['status']);
        $this->assertSame(AnswerGenerator::NO_MATCH, $result['answer']);
        $this->assertSame([], $result['rows']);
    }

    public function test_generation_is_not_called_without_evidence(): void
    {
        $this->fakeCloudflare([['id' => (string) $this->soda->id, 'score' => 0.40]]);

        $result = $this->answer('明日の天気は');

        $this->assertSame('no_evidence', $result['status']);
        $this->assertSame(QuestionAnswerer::NO_EVIDENCE, $result['answer']);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), self::GENERATION_MODEL));
    }

    public function test_generation_failure_shows_the_evidence_in_price_order(): void
    {
        $this->fakeCloudflare($this->searchMatches(), null, 429);

        $result = $this->answer();

        $this->assertSame('fallback', $result['status']);
        $this->assertSame('rate_limit', $result['error_reason']);
        $this->assertSame(QuestionAnswerer::QUOTA_NOTICE, $result['notice']);
        $this->assertSame([120, 160], array_column($result['rows'], 'price'));
    }

    public function test_other_generation_failures_use_the_general_notice(): void
    {
        $this->fakeCloudflare($this->searchMatches(), null, 500);

        $result = $this->answer();

        $this->assertSame('fallback', $result['status']);
        $this->assertSame('request_failed', $result['error_reason']);
        $this->assertSame(QuestionAnswerer::FALLBACK_NOTICE, $result['notice']);
        $this->assertSame([120, 160], array_column($result['rows'], 'price'));
    }

    public function test_search_failure_is_reported_as_unavailable(): void
    {
        $this->fakeCloudflare($this->searchMatches(), [], 200, 500);

        $result = $this->answer();

        $this->assertSame('unavailable', $result['status']);
        $this->assertSame(QuestionAnswerer::UNAVAILABLE, $result['answer']);
        $this->assertSame('retrieval_failed', $result['error_reason']);
        $this->assertSame([], $result['rows']);
    }
}
