<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Services\Rag\ProductIndexer;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class ProductIndexerTest extends TestCase
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
    }

    /**
     * Cloudflare の代わりに答える
     * - 数値化:送られた文章の数だけ、ダミーのベクトルを返す
     * - 登録:受付IDを返す
     * - 件数:$count を返す
     */
    private function fakeCloudflare(int $count = 0): void
    {
        Http::fake(function (Request $request) use ($count) {
            if (str_contains($request->url(), '/ai/run/')) {
                $vectors = array_map(fn () => [0.1, 0.2, 0.3], $request['text']);

                return Http::response(['result' => ['data' => $vectors]]);
            }
            if (str_ends_with($request->url(), '/upsert')) {
                return Http::response(['result' => ['mutationId' => 'm1']]);
            }
            if (str_ends_with($request->url(), '/info')) {
                return Http::response(['result' => ['vectorCount' => $count]]);
            }

            return Http::response([], 404);
        });
    }

    /**
     * 登録(upsert)で送られたベクトルのIDを、送った順にすべて取り出す
     *
     * @return string[]
     */
    private function upsertedIds(): array
    {
        $ids = [];
        foreach (Http::recorded() as [$request]) {
            if (! str_ends_with($request->url(), '/upsert')) {
                continue;
            }
            foreach (explode("\n", $request->body()) as $line) {
                $ids[] = json_decode($line, true)['id'];
            }
        }

        return $ids;
    }

    public function test_全商品を商品IDで登録する(): void
    {
        $this->seed(ProductSeeder::class);
        $this->fakeCloudflare();

        $done = app(ProductIndexer::class)->reindexAll();

        $this->assertSame(25, $done);
        $this->assertSame(
            Product::orderBy('id')->pluck('id')->map(fn ($id) => (string) $id)->all(),
            $this->upsertedIds(),
        );
    }

    public function test_数値化には検索用の文章を送る(): void
    {
        $product = Product::create(['name' => '綾鷹', 'maker' => '日本コカ・コーラ', 'description' => '無糖の緑茶']);
        $this->fakeCloudflare();

        app(ProductIndexer::class)->reindexAll();

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/ai/run/')
            && $r['text'] === [$product->searchText()]);
    }

    public function test_商品が多いときは小分けにして送る(): void
    {
        // 25件を20件ずつ送るので、数値化と登録がそれぞれ2回になる
        $this->seed(ProductSeeder::class);
        $this->fakeCloudflare();

        app(ProductIndexer::class)->reindexAll();

        $embedCalls = Http::recorded(fn (Request $r) => str_contains($r->url(), '/ai/run/'))->values();
        $this->assertCount(2, $embedCalls);
        $this->assertCount(ProductIndexer::BATCH_SIZE, $embedCalls[0][0]['text']);
        $this->assertCount(5, $embedCalls[1][0]['text']);
    }

    public function test_2回続けて実行しても同じIDで上書きする(): void
    {
        // Vectorize の upsert は同じIDなら上書きするので、IDが同じなら件数は増えない
        $this->seed(ProductSeeder::class);
        $this->fakeCloudflare();

        app(ProductIndexer::class)->reindexAll();
        app(ProductIndexer::class)->reindexAll();

        $ids = $this->upsertedIds();
        $this->assertCount(50, $ids);
        $this->assertCount(25, array_unique($ids));
    }

    public function test_1件の登録に失敗しても例外を投げずfalseを返す(): void
    {
        // 投稿(45)から呼ぶときに、投稿そのものを失敗させないため
        $product = Product::create(['name' => '綾鷹']);
        Http::fake(['*' => Http::response([], 500)]);
        Log::spy();

        $this->assertFalse(app(ProductIndexer::class)->indexQuietly($product));
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_1件の登録に成功したらtrueを返す(): void
    {
        $product = Product::create(['name' => '綾鷹']);
        $this->fakeCloudflare();

        $this->assertTrue(app(ProductIndexer::class)->indexQuietly($product));
        $this->assertSame([(string) $product->id], $this->upsertedIds());
    }

    // ---- コマンド ----

    public function test_コマンドで全商品を登録し索引の件数を表示する(): void
    {
        $this->seed(ProductSeeder::class);
        $this->fakeCloudflare(count: 25);

        $this->artisan('rag:reindex')
            ->expectsOutputToContain('25 件を登録しました')
            ->expectsOutputToContain('索引の件数(反映待ちの分は含まれないことがあります):25')
            ->assertSuccessful();
    }

    public function test_商品がなければCloudflareを呼ばない(): void
    {
        Http::fake();

        $this->artisan('rag:reindex')
            ->expectsOutputToContain('商品が1件もありません')
            ->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_Cloudflareが失敗したらコマンドも失敗にする(): void
    {
        $this->seed(ProductSeeder::class);
        Http::fake(['*' => Http::response([], 500)]);

        $this->artisan('rag:reindex')
            ->expectsOutputToContain('登録に失敗しました')
            ->assertFailed();
    }
}