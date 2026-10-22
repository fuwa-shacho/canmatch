<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\VendingMachine;
use App\Services\Rag\EvidenceRetriever;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EvidenceRetrieverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.cloudflare.account_id', 'acc');
        config()->set('services.cloudflare.api_token', 'token');
        config()->set('services.cloudflare.embedding_model', '@cf/baai/bge-m3');
        config()->set('services.cloudflare.vectorize_index', 'canmatch-products');
        config()->set('services.cloudflare.timeout', 30);
        config()->set('services.cloudflare.rag_score_threshold', 0.45);
        config()->set('services.cloudflare.rag_top_k', 10);
        config()->set('services.cloudflare.rag_max_evidence', 20);

        // 2026-10-22 12:00 JST に固定する
        Carbon::setTestNow(Carbon::parse('2026-10-22 03:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Cloudflare の代わりに答える
     * - 数値化:ダミーのベクトルを返す
     * - 検索:$matches を返す
     *
     * @param  array<int, array{id: string, score: float}>  $matches
     */
    private function fakeCloudflare(array $matches): void
    {
        Http::fake(function (Request $request) use ($matches) {
            if (str_contains($request->url(), '/ai/run/')) {
                return Http::response(['result' => ['data' => [[0.1, 0.2, 0.3]]]]);
            }
            if (str_ends_with($request->url(), '/query')) {
                return Http::response(['result' => ['matches' => $matches]]);
            }

            return Http::response([], 404);
        });
    }

    private function product(string $name, ?string $description = null, ?int $volume = null): Product
    {
        return Product::create([
            'name' => $name,
            'maker' => '開発用飲料',
            'volume_ml' => $volume,
            'category' => '開発用',
            'description' => $description,
        ]);
    }

    private function machine(): VendingMachine
    {
        return VendingMachine::create([
            'latitude' => '33.5890000',
            'longitude' => '130.3950000',
            'maker' => null,
            'photo_path' => 'vending-machines/development/evidence.jpg',
            'user_id' => null,
            'last_confirmed_at' => '2026-10-20 00:00:00',
        ]);
    }

    private function sell(VendingMachine $machine, Product $product, int $price, ?string $temperature = 'cold', string $recordedAt = '2026-10-22 01:00:00'): void
    {
        $machine->products()->attach($product->id, [
            'price' => $price,
            'temperature' => $temperature,
            'recorded_at' => $recordedAt,
        ]);
    }

    private function retrieve(string $question = '甘くない炭酸'): array
    {
        return app(EvidenceRetriever::class)->retrieve($question);
    }

    public function test_rows_are_returned_with_machine_price_and_record_date_in_price_order(): void
    {
        $soda = $this->product('開発用無糖炭酸', '無糖の炭酸水。甘くない', 500);
        $cola = $this->product('開発用コーラ', '甘い炭酸飲料');
        $first = $this->machine();
        $second = $this->machine();

        $this->sell($first, $soda, 120, 'cold', '2026-10-22 01:00:00');
        $this->sell($second, $soda, 100, 'cold', '2026-10-19 01:00:00');
        $this->sell($second, $cola, 160, null, '2026-10-21 14:30:00'); // 日本時間では 10/21 23:30

        $this->fakeCloudflare([
            ['id' => (string) $soda->id, 'score' => 0.62],
            ['id' => (string) $cola->id, 'score' => 0.58],
        ]);

        $result = $this->retrieve();

        $this->assertTrue(EvidenceRetriever::found($result));
        $this->assertSame([$soda->id, $cola->id], $result['product_ids']);
        $this->assertSame(0.62, $result['top_score']);
        $this->assertSame([
            [
                'machine_id' => $second->id,
                'product_id' => $soda->id,
                'product_name' => '開発用無糖炭酸',
                'volume_ml' => 500,
                'description' => '無糖の炭酸水。甘くない',
                'price' => 100,
                'temperature' => 'cold',
                'recorded_at' => '2026-10-19',
                'days_ago' => 3,
            ],
            [
                'machine_id' => $first->id,
                'product_id' => $soda->id,
                'product_name' => '開発用無糖炭酸',
                'volume_ml' => 500,
                'description' => '無糖の炭酸水。甘くない',
                'price' => 120,
                'temperature' => 'cold',
                'recorded_at' => '2026-10-22',
                'days_ago' => 0,
            ],
            [
                'machine_id' => $second->id,
                'product_id' => $cola->id,
                'product_name' => '開発用コーラ',
                'volume_ml' => null,
                'description' => '甘い炭酸飲料',
                'price' => 160,
                'temperature' => null,
                'recorded_at' => '2026-10-21',
                'days_ago' => 1,
            ],
        ], $result['evidence']);
    }

    public function test_products_below_the_threshold_are_dropped(): void
    {
        $soda = $this->product('開発用無糖炭酸');
        $tea = $this->product('開発用お茶');
        $machine = $this->machine();
        $this->sell($machine, $soda, 120);
        $this->sell($machine, $tea, 90);

        $this->fakeCloudflare([
            ['id' => (string) $soda->id, 'score' => 0.60],
            ['id' => (string) $tea->id, 'score' => 0.44],
        ]);

        $result = $this->retrieve();

        $this->assertSame([$soda->id], $result['product_ids']);
        $this->assertSame([$soda->id], array_column($result['evidence'], 'product_id'));
    }

    public function test_question_unrelated_to_drinks_returns_no_evidence(): void
    {
        $tea = $this->product('開発用お茶');
        $this->sell($this->machine(), $tea, 90);

        $this->fakeCloudflare([
            ['id' => (string) $tea->id, 'score' => 0.40],
        ]);

        $result = $this->retrieve('明日の天気は');

        $this->assertFalse(EvidenceRetriever::found($result));
        $this->assertSame([], $result['product_ids']);
        $this->assertSame(0.40, $result['top_score']);
    }

    public function test_products_without_machines_or_missing_from_database_return_no_evidence(): void
    {
        $unsold = $this->product('開発用未販売');

        $this->fakeCloudflare([
            ['id' => (string) $unsold->id, 'score' => 0.70],
            ['id' => '999999', 'score' => 0.65], // 索引にだけ残っている商品
        ]);

        $result = $this->retrieve();

        $this->assertFalse(EvidenceRetriever::found($result));
        $this->assertSame([], $result['evidence']);
    }

    public function test_evidence_is_limited_to_the_cheapest_rows(): void
    {
        config()->set('services.cloudflare.rag_max_evidence', 2);

        $soda = $this->product('開発用無糖炭酸');
        foreach ([150, 110, 130] as $price) {
            $this->sell($this->machine(), $soda, $price);
        }

        $this->fakeCloudflare([['id' => (string) $soda->id, 'score' => 0.60]]);

        $result = $this->retrieve();

        $this->assertSame([110, 130], array_column($result['evidence'], 'price'));
    }

    public function test_query_asks_vectorize_for_the_configured_top_k(): void
    {
        config()->set('services.cloudflare.rag_top_k', 7);

        $this->fakeCloudflare([]);

        $result = $this->retrieve('  冷たいお茶  ');

        $this->assertNull($result['top_score']);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/ai/run/')
            && $request['text'] === ['冷たいお茶']);
        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/query')
            && $request['topK'] === 7);
    }

    public function test_evidence_command_prints_rows(): void
    {
        $soda = $this->product('開発用無糖炭酸', '無糖の炭酸水');
        $this->sell($this->machine(), $soda, 120);

        $this->fakeCloudflare([['id' => (string) $soda->id, 'score' => 0.60]]);

        $this->artisan('rag:evidence', ['question' => '甘くない炭酸'])
            ->expectsOutputToContain('1位の score:0.600')
            ->expectsOutputToContain('開発用無糖炭酸')
            ->assertSuccessful();
    }

    public function test_evidence_command_reports_no_evidence(): void
    {
        $this->fakeCloudflare([]);

        $this->artisan('rag:evidence', ['question' => '明日の天気は'])
            ->expectsOutputToContain('根拠は0行です')
            ->assertSuccessful();
    }

    public function test_cloudflare_failure_is_thrown_to_the_caller(): void
    {
        Http::fake(['*' => Http::response(['errors' => ['x']], 500)]);

        $this->expectException(RequestException::class);

        $this->retrieve();
    }
}
