<?php

namespace Tests\Feature;

use App\Exceptions\AiReadFailedException;
use App\Services\VendingMachineReader;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class VendingMachineReaderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.gemini.key', 'test-key');
        config()->set('services.gemini.model', 'test-model');
        config()->set('services.gemini.retry_wait', 0); // テストでは待たない

        Storage::fake('local');
        Storage::disk('local')->put('photos/test.jpg', 'dummy');
    }

    // Geminiの応答の形を作る
    private function geminiResponse(string $text): array
    {
        return ['candidates' => [['content' => ['parts' => [['text' => $text]]]]]];
    }

    // 成功時の応答(商品1つ)
    private function okBody(): array
    {
        return $this->geminiResponse(json_encode([
            'is_vending_machine' => true,
            'maker' => 'コカ・コーラ',
            'products' => [['name' => '綾鷹', 'price' => 160, 'temperature' => 'cold']],
        ]));
    }

    // 読み取りを実行し、投げられた AiReadFailedException を受け取る
    private function readFailure(): AiReadFailedException
    {
        try {
            app(VendingMachineReader::class)->read('photos/test.jpg');
        } catch (AiReadFailedException $e) {
            return $e;
        }

        $this->fail('AiReadFailedException が投げられなかった');
    }

    public function test_写真から商品の配列を返す(): void
    {
        Http::fake(['*' => Http::response($this->geminiResponse(json_encode([
            'is_vending_machine' => true,
            'maker' => 'コカ・コーラ',
            'products' => [
                ['name' => '綾鷹', 'price' => 160, 'temperature' => 'cold'],
                ['name' => 'ジョージア 微糖', 'price' => null, 'temperature' => 'hot'],
            ],
        ])))]);

        $r = app(VendingMachineReader::class)->read('photos/test.jpg');

        $this->assertCount(2, $r['result']['products']);
        $this->assertSame(160, $r['result']['products'][0]['price']);
        $this->assertNull($r['result']['products'][1]['price']);

        Http::assertSent(fn ($request) => $request->hasHeader('x-goog-api-key', 'test-key'));
    }

    public function test_想定外の値はnullにそろえる(): void
    {
        Http::fake(['*' => Http::response($this->geminiResponse(json_encode([
            'is_vending_machine' => true,
            'products' => [
                ['name' => '綾鷹', 'price' => '160円', 'temperature' => '冷'],
            ],
        ])))]);

        $r = app(VendingMachineReader::class)->read('photos/test.jpg');

        $this->assertNull($r['result']['products'][0]['price']);
        $this->assertNull($r['result']['products'][0]['temperature']);
    }

    public function test_JSONでない応答なら読み取り失敗の例外になる(): void
    {
        Http::fake(['*' => Http::response($this->geminiResponse('読めませんでした'))]);

        $e = $this->readFailure();

        $this->assertSame('invalid_response', $e->reason);
    }

    public function test_429のあと1回だけ再試行して成功する(): void
    {
        Http::fake(['*' => Http::sequence()
            ->pushStatus(429)
            ->push($this->okBody())]);

        $r = app(VendingMachineReader::class)->read('photos/test.jpg');

        $this->assertCount(1, $r['result']['products']);
        Http::assertSentCount(2);
    }

    public function test_429が続いたら再試行は1回だけで例外になる(): void
    {
        Http::fake(['*' => Http::sequence()
            ->pushStatus(429)
            ->pushStatus(429)
            ->push($this->okBody())]);

        $e = $this->readFailure();

        $this->assertSame('rate_limit', $e->reason);
        $this->assertSame(429, $e->status);
        Http::assertSentCount(2); // 3回目は呼ばない
    }

    public function test_キーの間違いなどは再試行せず例外になる(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'API key not valid']], 400)]);

        $e = $this->readFailure();

        $this->assertSame('request_rejected', $e->reason);
        $this->assertSame(400, $e->status);
        Http::assertSentCount(1);
    }

    public function test_モデル名の間違いは例外になる(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'not found']], 404)]);

        $this->assertSame('model_not_found', $this->readFailure()->reason);
    }

    public function test_AI側の不調は例外になる(): void
    {
        Http::fake(['*' => Http::response('error', 500)]);

        $this->assertSame('server_error', $this->readFailure()->reason);
    }

    public function test_接続の時間切れは例外になる(): void
    {
        Http::fake(fn () => throw new ConnectionException('timed out'));

        $this->assertSame('timeout', $this->readFailure()->reason);
    }

    public function test_自販機が写っていないときは商品が空で返る(): void
    {
        Http::fake(['*' => Http::response($this->geminiResponse(json_encode([
            'is_vending_machine' => false,
            'maker' => null,
            'products' => [['name' => '綾鷹', 'price' => 160, 'temperature' => 'cold']],
        ])))]);

        $r = app(VendingMachineReader::class)->read('photos/test.jpg');

        $this->assertFalse($r['result']['is_vending_machine']);
        $this->assertSame([], $r['result']['products']);
    }
}